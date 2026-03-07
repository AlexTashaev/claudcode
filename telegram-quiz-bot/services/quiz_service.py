import datetime
import json
import math
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from db.models import (
    AnswerOption,
    Question,
    Quiz,
    QuizAttempt,
    UserAnswer,
)

# Load quiz config for type/result info
_data_path = Path(__file__).parent.parent / "data" / "quizzes.json"
with open(_data_path, encoding="utf-8") as f:
    _quiz_data = json.load(f)

TYPES = _quiz_data["types"]
QUIZ_CONFIGS = {q["code"]: q for q in _quiz_data["quizzes"]}


async def get_all_quizzes(session: AsyncSession) -> list[Quiz]:
    stmt = (
        select(Quiz).where(Quiz.is_active.is_(True)).order_by(Quiz.order_index)
    )
    result = await session.execute(stmt)
    return list(result.scalars().all())


async def get_quiz_by_code(session: AsyncSession, quiz_code: str) -> Quiz | None:
    stmt = select(Quiz).where(Quiz.code == quiz_code)
    result = await session.execute(stmt)
    return result.scalar_one_or_none()


async def get_available_quiz_for_user(
    session: AsyncSession, user_id: int
) -> Quiz | None:
    """Get the next uncompleted quiz for the user."""
    quizzes = await get_all_quizzes(session)

    for quiz in quizzes:
        stmt = select(QuizAttempt).where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.quiz_id == quiz.id,
            QuizAttempt.status == "completed",
        )
        result = await session.execute(stmt)
        if result.scalar_one_or_none() is None:
            return quiz

    return None


async def get_completed_quizzes_count(
    session: AsyncSession, user_id: int
) -> int:
    quizzes = await get_all_quizzes(session)
    count = 0
    for quiz in quizzes:
        stmt = select(QuizAttempt).where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.quiz_id == quiz.id,
            QuizAttempt.status == "completed",
        )
        result = await session.execute(stmt)
        if result.scalar_one_or_none() is not None:
            count += 1
    return count


async def start_quiz(
    session: AsyncSession, user_id: int, quiz_id: int
) -> QuizAttempt:
    """Start a new quiz attempt. Abandons any in-progress attempt."""
    stmt = select(QuizAttempt).where(
        QuizAttempt.user_id == user_id,
        QuizAttempt.quiz_id == quiz_id,
        QuizAttempt.status == "in_progress",
    )
    result = await session.execute(stmt)
    existing = result.scalar_one_or_none()
    if existing:
        existing.status = "abandoned"

    stmt = (
        select(Question)
        .where(Question.quiz_id == quiz_id, Question.is_active.is_(True))
        .order_by(Question.order_index)
        .limit(1)
    )
    result = await session.execute(stmt)
    first_question = result.scalar_one()

    attempt = QuizAttempt(
        user_id=user_id,
        quiz_id=quiz_id,
        status="in_progress",
        started_at=datetime.datetime.now(datetime.UTC),
        current_question_id=first_question.id,
    )
    session.add(attempt)
    await session.commit()
    return attempt


async def get_current_question(
    session: AsyncSession, attempt: QuizAttempt
) -> Question | None:
    if attempt.current_question_id is None:
        return None
    stmt = (
        select(Question)
        .where(Question.id == attempt.current_question_id)
        .options(selectinload(Question.options))
    )
    result = await session.execute(stmt)
    return result.scalar_one_or_none()


async def get_active_attempt(
    session: AsyncSession, user_id: int, quiz_id: int
) -> QuizAttempt | None:
    stmt = select(QuizAttempt).where(
        QuizAttempt.user_id == user_id,
        QuizAttempt.quiz_id == quiz_id,
        QuizAttempt.status == "in_progress",
    )
    result = await session.execute(stmt)
    return result.scalar_one_or_none()


async def submit_answer(
    session: AsyncSession,
    attempt: QuizAttempt,
    question_id: int,
    answer_option_id: int,
) -> Question | None:
    """Submit an answer and return the next question, or None if quiz is done."""
    stmt = select(AnswerOption).where(AnswerOption.id == answer_option_id)
    result = await session.execute(stmt)
    option = result.scalar_one()

    stmt = select(Question).where(Question.id == question_id)
    result = await session.execute(stmt)
    question = result.scalar_one()
    if question.quiz_id != attempt.quiz_id:
        raise ValueError("Question does not belong to this quiz")

    user_answer = UserAnswer(
        user_id=attempt.user_id,
        quiz_attempt_id=attempt.id,
        quiz_id=attempt.quiz_id,
        question_id=question_id,
        answer_option_id=answer_option_id,
        answer_code=option.code,
        score_value=option.score_value,
        score_dimension=option.score_dimension,
    )
    session.add(user_answer)

    stmt = (
        select(Question)
        .where(
            Question.quiz_id == attempt.quiz_id,
            Question.is_active.is_(True),
            Question.order_index > question.order_index,
        )
        .options(selectinload(Question.options))
        .order_by(Question.order_index)
        .limit(1)
    )
    result = await session.execute(stmt)
    next_question = result.scalar_one_or_none()

    if next_question:
        attempt.current_question_id = next_question.id
    else:
        attempt.current_question_id = None

    await session.commit()
    return next_question


def calculate_percentages(answer_indices: list[int]) -> list[int]:
    """Calculate percentages for 4 types, matching web version logic."""
    counts = [0, 0, 0, 0]
    for a in answer_indices:
        if 0 <= a <= 3:
            counts[a] += 1

    total = len(answer_indices)
    if total == 0:
        return [25, 25, 25, 25]

    raw = [c / total * 100 for c in counts]
    floored = [math.floor(v) for v in raw]
    remainder = 100 - sum(floored)

    decimals = sorted(
        [(i, raw[i] - floored[i]) for i in range(4)],
        key=lambda x: x[1],
        reverse=True,
    )
    for k in range(remainder):
        floored[decimals[k][0]] += 1

    return floored


async def complete_quiz(
    session: AsyncSession, attempt: QuizAttempt
) -> QuizAttempt:
    """Calculate score using frequency-based scoring (matching web version)."""
    stmt = (
        select(UserAnswer)
        .where(UserAnswer.quiz_attempt_id == attempt.id)
        .order_by(UserAnswer.created_at)
    )
    result = await session.execute(stmt)
    answers = list(result.scalars().all())

    stmt = select(Quiz).where(Quiz.id == attempt.quiz_id)
    result = await session.execute(stmt)
    quiz = result.scalar_one()

    # Each answer's score_value is the option index (0-3)
    answer_indices = [int(a.score_value) for a in answers]
    pcts = calculate_percentages(answer_indices)

    # Find dominant type (highest percentage, tie-break: higher index wins)
    max_pct = max(pcts)
    dominant_index = 0
    for i in range(3, -1, -1):  # Reverse order for tie-breaking
        if pcts[i] == max_pct:
            dominant_index = i
            break

    dominant_type = TYPES[dominant_index]
    quiz_config = QUIZ_CONFIGS.get(quiz.code, {})
    result_descriptions = quiz_config.get("result_descriptions", {})
    description = result_descriptions.get(dominant_type["code"], "")

    attempt.raw_score = dominant_index
    attempt.result_code = dominant_type["code"]
    attempt.result_label = dominant_type["title"]
    attempt.result_payload = {
        "dominant_index": dominant_index,
        "title": dominant_type["title"],
        "emoji": dominant_type["emoji"],
        "level": dominant_type["level"],
        "percentages": pcts,
        "description": description,
        "type_titles": [t["title"] for t in TYPES],
    }

    attempt.status = "completed"
    attempt.completed_at = datetime.datetime.now(datetime.UTC)
    await session.commit()
    return attempt


async def get_quiz_questions_count(
    session: AsyncSession, quiz_id: int
) -> int:
    stmt = select(Question).where(
        Question.quiz_id == quiz_id, Question.is_active.is_(True)
    )
    result = await session.execute(stmt)
    return len(list(result.scalars().all()))


async def get_completed_attempt(
    session: AsyncSession, user_id: int, quiz_id: int
) -> QuizAttempt | None:
    stmt = (
        select(QuizAttempt)
        .where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.quiz_id == quiz_id,
            QuizAttempt.status == "completed",
        )
        .order_by(QuizAttempt.completed_at.desc())
        .limit(1)
    )
    result = await session.execute(stmt)
    return result.scalar_one_or_none()
