import datetime
import json
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from db.models import (
    Quiz,
    QuizAttempt,
    Recommendation,
    UserProfile,
)

# Load quiz config for type definitions
_data_path = Path(__file__).parent.parent / "data" / "quizzes.json"
with open(_data_path, encoding="utf-8") as f:
    _quiz_data = json.load(f)

TYPES = _quiz_data["types"]  # 4 archetypes
TYPE_BY_INDEX = {t["index"]: t for t in TYPES}
SOUL_LEVELS = _quiz_data["soul_path_levels"]  # 7 levels


def get_soul_level(completed_quizzes: list[dict]) -> dict:
    """Calculate soul level using the same formula as the web version.

    Each completed quiz contributes its dominant type index (0-3).
    Formula: min(6, max(1, round(avg * 1.2 + (done / 5) * 2.5)))
    """
    if not completed_quizzes:
        return SOUL_LEVELS[0]

    done = len(completed_quizzes)
    avg = sum(q["dominant_index"] for q in completed_quizzes) / done
    level_index = min(6, max(1, round(avg * 1.2 + (done / 5) * 2.5)))
    return SOUL_LEVELS[level_index]


async def compute_user_profile(
    session: AsyncSession, user_id: int
) -> UserProfile | None:
    """Compute and save the user's profile based on completed quizzes."""
    stmt = select(Quiz).where(Quiz.is_active.is_(True)).order_by(Quiz.order_index)
    result = await session.execute(stmt)
    quizzes = list(result.scalars().all())

    quiz_results: dict[str, str | None] = {}
    completed_quizzes: list[dict] = []
    profile_payload: dict[str, dict] = {}
    all_completed = True

    for i, quiz in enumerate(quizzes, 1):
        stmt = (
            select(QuizAttempt)
            .where(
                QuizAttempt.user_id == user_id,
                QuizAttempt.quiz_id == quiz.id,
                QuizAttempt.status == "completed",
            )
            .order_by(QuizAttempt.completed_at.desc())
            .limit(1)
        )
        result = await session.execute(stmt)
        attempt = result.scalar_one_or_none()

        if attempt and attempt.result_code:
            quiz_results[f"quiz_{i}_result_code"] = attempt.result_code
            dominant_index = attempt.result_payload.get("dominant_index", 0) if attempt.result_payload else 0
            completed_quizzes.append({
                "quiz_code": quiz.code,
                "dominant_index": dominant_index,
                "result_code": attempt.result_code,
            })
            type_info = TYPE_BY_INDEX.get(dominant_index, TYPES[0])
            profile_payload[quiz.code] = {
                "code": attempt.result_code,
                "title": type_info["title"],
                "emoji": type_info["emoji"],
                "dominant_index": dominant_index,
            }
        else:
            quiz_results[f"quiz_{i}_result_code"] = None
            all_completed = False

    if not all_completed:
        return None

    soul_level = get_soul_level(completed_quizzes)

    # Upsert profile
    stmt = select(UserProfile).where(UserProfile.user_id == user_id)
    result = await session.execute(stmt)
    profile = result.scalar_one_or_none()

    if profile is None:
        profile = UserProfile(user_id=user_id)
        session.add(profile)

    profile.quiz_1_result_code = quiz_results.get("quiz_1_result_code")
    profile.quiz_2_result_code = quiz_results.get("quiz_2_result_code")
    profile.quiz_3_result_code = quiz_results.get("quiz_3_result_code")
    profile.quiz_4_result_code = quiz_results.get("quiz_4_result_code")
    profile.quiz_5_result_code = quiz_results.get("quiz_5_result_code")
    profile.soul_path_score = soul_level["index"]
    profile.soul_path_level_code = soul_level["code"]
    profile.soul_path_level_label = soul_level["title"]
    profile.profile_payload = profile_payload
    profile.computed_at = datetime.datetime.now(datetime.UTC)

    await session.commit()
    return profile


async def get_user_profile(
    session: AsyncSession, user_id: int
) -> UserProfile | None:
    stmt = select(UserProfile).where(UserProfile.user_id == user_id)
    result = await session.execute(stmt)
    return result.scalar_one_or_none()


async def generate_recommendation(
    session: AsyncSession, user_id: int
) -> Recommendation | None:
    """Generate a rule-based recommendation based on dominant types."""
    profile = await get_user_profile(session, user_id)
    if not profile or not profile.profile_payload:
        return None

    # Determine recommendation based on soul path level
    level_index = int(profile.soul_path_score or 0)

    if level_index >= 4:
        rec_code = "course_direct"
        title = "Вы готовы к глубокому изучению"
        text = (
            "Ваш профиль показывает высокую готовность и глубину поиска. "
            "Рекомендуем начать полный курс обучения."
        )
        cta_type = "course"
        cta_url = "https://kabacademy.com/mak-fall-2025/step/lp-test-mir-v3/"
    elif level_index >= 2:
        rec_code = "mini_course"
        title = "Начните с мини-курса"
        text = (
            "У вас хорошая база для развития. "
            "Начните с мини-курса, чтобы углубить понимание."
        )
        cta_type = "lesson"
        cta_url = ""
    else:
        rec_code = "intro_video"
        title = "Познакомьтесь с основами"
        text = (
            "Рекомендуем начать с вводного видео, "
            "чтобы определить наиболее подходящий путь развития."
        )
        cta_type = "video"
        cta_url = ""

    # Check if recommendation already exists
    stmt = select(Recommendation).where(
        Recommendation.user_id == user_id,
        Recommendation.recommendation_code == rec_code,
    )
    result = await session.execute(stmt)
    existing = result.scalar_one_or_none()
    if existing:
        return existing

    recommendation = Recommendation(
        user_id=user_id,
        recommendation_code=rec_code,
        title=title,
        text=text,
        cta_type=cta_type,
        cta_url=cta_url,
    )
    session.add(recommendation)
    await session.commit()
    return recommendation
