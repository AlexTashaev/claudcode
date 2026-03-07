"""Load quiz data from JSON config into the database."""
import json
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from db.models import AnswerOption, Question, Quiz


async def seed_quizzes(session: AsyncSession) -> None:
    """Seed quizzes, questions, and answer options from quizzes.json."""
    data_path = Path(__file__).parent.parent / "data" / "quizzes.json"
    with open(data_path, encoding="utf-8") as f:
        data = json.load(f)

    types = data["types"]

    for quiz_data in data["quizzes"]:
        # Check if quiz already exists
        stmt = select(Quiz).where(Quiz.code == quiz_data["code"])
        result = await session.execute(stmt)
        existing = result.scalar_one_or_none()

        if existing:
            continue

        quiz = Quiz(
            code=quiz_data["code"],
            title=quiz_data["title"],
            description=quiz_data.get("description", ""),
            order_index=quiz_data["order_index"],
            is_active=True,
        )
        session.add(quiz)
        await session.flush()

        for q_data in quiz_data["questions"]:
            question = Question(
                quiz_id=quiz.id,
                order_index=q_data["order_index"] if "order_index" in q_data else quiz_data["questions"].index(q_data) + 1,
                text=q_data["text"],
                question_type="single_choice",
                is_active=True,
            )
            session.add(question)
            await session.flush()

            for opt_idx, opt_text in enumerate(q_data["options"]):
                # Option index maps to type index (0=observer, 1=seeker, etc.)
                type_info = types[opt_idx]
                option = AnswerOption(
                    question_id=question.id,
                    code=str(opt_idx),  # "0", "1", "2", "3"
                    text=opt_text,
                    score_value=float(opt_idx),  # Store type index as score
                    score_dimension=type_info["code"],
                    order_index=opt_idx,
                    is_active=True,
                )
                session.add(option)

    await session.commit()
