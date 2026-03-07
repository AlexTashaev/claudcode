"""Telegram bot handlers for the quiz ecosystem."""
import logging

from aiogram import F, Router
from aiogram.filters import CommandStart
from aiogram.types import CallbackQuery, Message

from bot.keyboards import (
    main_menu_keyboard,
    profile_keyboard,
    question_keyboard,
    quiz_intro_keyboard,
    quiz_result_keyboard,
    recommendation_keyboard,
    soul_map_keyboard,
    welcome_keyboard,
)
from bot.texts import (
    profile_text,
    question_text,
    quiz_intro_text,
    quiz_result_text,
    recommendation_text,
    soul_map_text,
    welcome_text,
)
from db import async_session
from services.analytics_service import track_event
from services.profile_service import (
    TYPES,
    compute_user_profile,
    generate_recommendation,
    get_user_profile,
)
from services.quiz_service import (
    complete_quiz,
    get_active_attempt,
    get_all_quizzes,
    get_available_quiz_for_user,
    get_completed_quizzes_count,
    get_current_question,
    get_quiz_by_code,
    get_quiz_questions_count,
    start_quiz,
    submit_answer,
)
from services.user_service import (
    get_or_create_user,
    set_bot_state,
    set_user_status,
)

logger = logging.getLogger(__name__)

router = Router()


# === /start command ===


@router.message(CommandStart())
async def cmd_start(message: Message) -> None:
    async with async_session() as session:
        tg_user = message.from_user
        user = await get_or_create_user(
            session,
            telegram_id=tg_user.id,
            username=tg_user.username,
            first_name=tg_user.first_name,
            last_name=tg_user.last_name,
            language_code=tg_user.language_code,
            source=message.text.split(" ", 1)[1] if " " in (message.text or "") else None,
        )
        await set_bot_state(session, user.id, "welcome")
        await track_event(session, "bot_started", user.id)

    await message.answer(
        welcome_text(),
        reply_markup=welcome_keyboard(),
    )


# === Start next quiz ===


@router.callback_query(F.data == "quiz:start_next")
async def on_start_next_quiz(callback: CallbackQuery) -> None:
    await callback.answer()

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        quiz = await get_available_quiz_for_user(session, user.id)

        if quiz is None:
            # All quizzes completed — show profile
            profile = await get_user_profile(session, user.id)
            if profile is None:
                profile = await compute_user_profile(session, user.id)

            if profile:
                await callback.message.edit_text(
                    profile_text(profile),
                    reply_markup=profile_keyboard(),
                    parse_mode="HTML",
                )
            else:
                await callback.message.edit_text(
                    "Все тесты пройдены! Используйте меню для просмотра профиля.",
                    reply_markup=main_menu_keyboard(),
                )
            return

        quiz_number = quiz.order_index
        await set_bot_state(session, user.id, f"quiz_intro_{quiz.code}")
        await track_event(session, "quiz_intro_viewed", user.id, {"quiz_code": quiz.code})

    await callback.message.edit_text(
        quiz_intro_text(quiz.code, quiz_number),
        reply_markup=quiz_intro_keyboard(quiz.code),
    )


# === Begin specific quiz ===


@router.callback_query(F.data.startswith("quiz:begin:"))
async def on_begin_quiz(callback: CallbackQuery) -> None:
    await callback.answer()
    quiz_code = callback.data.split(":")[2]

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        quiz = await get_quiz_by_code(session, quiz_code)
        if not quiz:
            await callback.message.edit_text("Тест не найден.")
            return

        attempt = await start_quiz(session, user.id, quiz.id)
        question = await get_current_question(session, attempt)
        total = await get_quiz_questions_count(session, quiz.id)

        await set_bot_state(session, user.id, f"quiz_{quiz_code}_q_1")
        await set_user_status(session, user.id, "active")
        await track_event(session, "quiz_started", user.id, {"quiz_code": quiz_code})

    await callback.message.edit_text(
        question_text(1, total, question.text),
        reply_markup=question_keyboard(question),
    )


# === Restart quiz ===


@router.callback_query(F.data.startswith("quiz:restart:"))
async def on_restart_quiz(callback: CallbackQuery) -> None:
    await callback.answer()
    quiz_code = callback.data.split(":")[2]

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        quiz = await get_quiz_by_code(session, quiz_code)
        if not quiz:
            await callback.message.edit_text("Тест не найден.")
            return

        attempt = await start_quiz(session, user.id, quiz.id)
        question = await get_current_question(session, attempt)
        total = await get_quiz_questions_count(session, quiz.id)

        await set_bot_state(session, user.id, f"quiz_{quiz_code}_q_1")
        await track_event(session, "quiz_restarted", user.id, {"quiz_code": quiz_code})

    await callback.message.edit_text(
        question_text(1, total, question.text),
        reply_markup=question_keyboard(question),
    )


# === Answer question ===


@router.callback_query(F.data.startswith("ans:"))
async def on_answer(callback: CallbackQuery) -> None:
    await callback.answer()
    parts = callback.data.split(":")
    question_id = int(parts[1])
    option_id = int(parts[2])

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)

        # Find active attempt for any quiz
        quizzes = await get_all_quizzes(session)
        attempt = None
        for quiz in quizzes:
            attempt = await get_active_attempt(session, user.id, quiz.id)
            if attempt:
                break

        if not attempt:
            await callback.message.edit_text(
                "Что-то пошло не так. Нажмите /start чтобы начать заново.",
                reply_markup=main_menu_keyboard(),
            )
            return

        try:
            next_question = await submit_answer(session, attempt, question_id, option_id)
        except ValueError:
            await callback.message.edit_text(
                "Что-то пошло не так. Нажмите /start.",
                reply_markup=main_menu_keyboard(),
            )
            return

        quiz = None
        for q in quizzes:
            if q.id == attempt.quiz_id:
                quiz = q
                break

        total = await get_quiz_questions_count(session, attempt.quiz_id)

        await track_event(session, "question_answered", user.id, {
            "quiz_code": quiz.code if quiz else "",
            "question_id": question_id,
            "option_id": option_id,
        })

        if next_question:
            # Show next question
            answered = total - len([o for o in next_question.options]) + total  # Calculate current q number
            # Count answers for this attempt to get question number
            from sqlalchemy import select, func
            from db.models import UserAnswer
            stmt = select(func.count()).where(UserAnswer.quiz_attempt_id == attempt.id)
            result = await session.execute(stmt)
            answered_count = result.scalar()

            current_q = answered_count + 1
            await set_bot_state(session, user.id, f"quiz_{quiz.code}_q_{current_q}")

            await callback.message.edit_text(
                question_text(current_q, total, next_question.text),
                reply_markup=question_keyboard(next_question),
            )
        else:
            # Quiz complete — calculate result
            attempt = await complete_quiz(session, attempt)
            next_quiz = await get_available_quiz_for_user(session, user.id)
            completed_count = await get_completed_quizzes_count(session, user.id)

            await set_bot_state(session, user.id, f"quiz_{quiz.code}_result")
            await track_event(session, "quiz_completed", user.id, {
                "quiz_code": quiz.code if quiz else "",
                "result_code": attempt.result_code,
            })

            # If all quizzes done, compute profile
            if completed_count >= 5:
                await compute_user_profile(session, user.id)
                await set_user_status(session, user.id, "completed_quizzes")

            # Get CTA info from result
            payload = attempt.result_payload or {}
            dominant_idx = payload.get("dominant_index", 0)
            type_info = TYPES[dominant_idx] if dominant_idx < len(TYPES) else TYPES[0]
            cta = type_info.get("cta", {})
            cta_url = cta.get("url") if cta.get("type") == "url" else None
            if cta.get("type") == "video" and cta.get("video_id"):
                cta_url = f"https://www.youtube.com/watch?v={cta['video_id']}"
            cta_text = cta.get("text")

            await callback.message.edit_text(
                quiz_result_text(attempt),
                reply_markup=quiz_result_keyboard(
                    has_next_quiz=next_quiz is not None,
                    quiz_code=quiz.code if quiz else "",
                    cta_url=cta_url if cta_url else None,
                    cta_text=cta_text,
                ),
                parse_mode="HTML",
            )


# === Menu: Profile ===


@router.callback_query(F.data == "menu:profile")
async def on_profile(callback: CallbackQuery) -> None:
    await callback.answer()

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        profile = await get_user_profile(session, user.id)

        if profile is None:
            profile = await compute_user_profile(session, user.id)

        if profile:
            await set_bot_state(session, user.id, "profile_view")
            await track_event(session, "profile_viewed", user.id)
            await callback.message.edit_text(
                profile_text(profile),
                reply_markup=profile_keyboard(),
                parse_mode="HTML",
            )
        else:
            completed = await get_completed_quizzes_count(session, user.id)
            await callback.message.edit_text(
                f"Профиль будет доступен после прохождения всех 5 тестов.\n"
                f"Пройдено: {completed}/5",
                reply_markup=main_menu_keyboard(),
            )


# === Menu: Soul Map ===


@router.callback_query(F.data == "menu:soul_map")
async def on_soul_map(callback: CallbackQuery) -> None:
    await callback.answer()

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        profile = await get_user_profile(session, user.id)

        if profile is None:
            profile = await compute_user_profile(session, user.id)

        if profile:
            await set_bot_state(session, user.id, "soul_map_view")
            await track_event(session, "soul_map_viewed", user.id)
            await callback.message.edit_text(
                soul_map_text(profile),
                reply_markup=soul_map_keyboard(),
                parse_mode="HTML",
            )
        else:
            completed = await get_completed_quizzes_count(session, user.id)
            await callback.message.edit_text(
                f"Карта «Путь души» будет доступна после прохождения всех 5 тестов.\n"
                f"Пройдено: {completed}/5",
                reply_markup=main_menu_keyboard(),
            )


# === Menu: Recommendation ===


@router.callback_query(F.data == "menu:recommendation")
async def on_recommendation(callback: CallbackQuery) -> None:
    await callback.answer()

    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=callback.from_user.id)
        rec = await generate_recommendation(session, user.id)

        if rec:
            await set_bot_state(session, user.id, "recommendation_view")
            await track_event(session, "recommendation_viewed", user.id)
            await callback.message.edit_text(
                recommendation_text(rec.title, rec.text),
                reply_markup=recommendation_keyboard(rec.cta_url if rec.cta_url else None),
                parse_mode="HTML",
            )
        else:
            await callback.message.edit_text(
                "Рекомендация будет доступна после прохождения всех 5 тестов.",
                reply_markup=main_menu_keyboard(),
            )


# === Fallback for unknown callbacks ===


@router.callback_query()
async def on_unknown_callback(callback: CallbackQuery) -> None:
    await callback.answer("Что-то пошло не так")
    await callback.message.edit_text(
        "Используйте /start чтобы начать заново.",
        reply_markup=main_menu_keyboard(),
    )


# === Fallback for text messages ===


@router.message()
async def on_unknown_message(message: Message) -> None:
    async with async_session() as session:
        user = await get_or_create_user(session, telegram_id=message.from_user.id)

    await message.answer(
        "Используйте кнопки для навигации или /start чтобы начать.",
        reply_markup=main_menu_keyboard(),
    )
