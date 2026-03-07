import datetime
from typing import Optional

from sqlalchemy import (
    JSON,
    Boolean,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    func,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(primary_key=True)
    telegram_id: Mapped[int] = mapped_column(Integer, unique=True, index=True)
    username: Mapped[Optional[str]] = mapped_column(String(255))
    first_name: Mapped[Optional[str]] = mapped_column(String(255))
    last_name: Mapped[Optional[str]] = mapped_column(String(255))
    language_code: Mapped[Optional[str]] = mapped_column(String(10))
    source: Mapped[Optional[str]] = mapped_column(String(255))
    entry_point: Mapped[Optional[str]] = mapped_column(String(255))
    status: Mapped[str] = mapped_column(String(50), default="new")
    created_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )
    last_activity_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    attempts: Mapped[list["QuizAttempt"]] = relationship(back_populates="user")
    answers: Mapped[list["UserAnswer"]] = relationship(back_populates="user")
    profile: Mapped[Optional["UserProfile"]] = relationship(back_populates="user")
    bot_state: Mapped[Optional["BotState"]] = relationship(back_populates="user")


class Quiz(Base):
    __tablename__ = "quizzes"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(100), unique=True)
    title: Mapped[str] = mapped_column(String(500))
    description: Mapped[Optional[str]] = mapped_column(Text)
    order_index: Mapped[int] = mapped_column(Integer)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )
    updated_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    questions: Mapped[list["Question"]] = relationship(
        back_populates="quiz", order_by="Question.order_index"
    )
    result_definitions: Mapped[list["QuizResultDefinition"]] = relationship(
        back_populates="quiz"
    )


class Question(Base):
    __tablename__ = "questions"

    id: Mapped[int] = mapped_column(primary_key=True)
    quiz_id: Mapped[int] = mapped_column(ForeignKey("quizzes.id"))
    order_index: Mapped[int] = mapped_column(Integer)
    text: Mapped[str] = mapped_column(Text)
    question_type: Mapped[str] = mapped_column(String(50), default="single_choice")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    quiz: Mapped["Quiz"] = relationship(back_populates="questions")
    options: Mapped[list["AnswerOption"]] = relationship(
        back_populates="question", order_by="AnswerOption.order_index"
    )

    __table_args__ = (Index("ix_questions_quiz_order", "quiz_id", "order_index"),)


class AnswerOption(Base):
    __tablename__ = "answer_options"

    id: Mapped[int] = mapped_column(primary_key=True)
    question_id: Mapped[int] = mapped_column(ForeignKey("questions.id"))
    code: Mapped[str] = mapped_column(String(10))
    text: Mapped[str] = mapped_column(Text)
    score_value: Mapped[float] = mapped_column(Float, default=0)
    score_dimension: Mapped[Optional[str]] = mapped_column(String(100))
    order_index: Mapped[int] = mapped_column(Integer)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    question: Mapped["Question"] = relationship(back_populates="options")

    __table_args__ = (
        Index("ix_answer_options_question_order", "question_id", "order_index"),
    )


class QuizAttempt(Base):
    __tablename__ = "quiz_attempts"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"))
    quiz_id: Mapped[int] = mapped_column(ForeignKey("quizzes.id"))
    status: Mapped[str] = mapped_column(String(50), default="not_started")
    started_at: Mapped[Optional[datetime.datetime]] = mapped_column(DateTime)
    completed_at: Mapped[Optional[datetime.datetime]] = mapped_column(DateTime)
    current_question_id: Mapped[Optional[int]] = mapped_column(
        ForeignKey("questions.id")
    )
    raw_score: Mapped[Optional[float]] = mapped_column(Float)
    result_code: Mapped[Optional[str]] = mapped_column(String(100))
    result_label: Mapped[Optional[str]] = mapped_column(String(500))
    result_payload: Mapped[Optional[dict]] = mapped_column(JSON)

    user: Mapped["User"] = relationship(back_populates="attempts")
    quiz: Mapped["Quiz"] = relationship()
    answers: Mapped[list["UserAnswer"]] = relationship(back_populates="attempt")

    __table_args__ = (
        Index("ix_quiz_attempts_user_quiz", "user_id", "quiz_id", "status"),
    )


class UserAnswer(Base):
    __tablename__ = "user_answers"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"))
    quiz_attempt_id: Mapped[int] = mapped_column(ForeignKey("quiz_attempts.id"))
    quiz_id: Mapped[int] = mapped_column(ForeignKey("quizzes.id"))
    question_id: Mapped[int] = mapped_column(ForeignKey("questions.id"))
    answer_option_id: Mapped[int] = mapped_column(ForeignKey("answer_options.id"))
    answer_code: Mapped[str] = mapped_column(String(10))
    score_value: Mapped[float] = mapped_column(Float, default=0)
    score_dimension: Mapped[Optional[str]] = mapped_column(String(100))
    created_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    user: Mapped["User"] = relationship(back_populates="answers")
    attempt: Mapped["QuizAttempt"] = relationship(back_populates="answers")

    __table_args__ = (Index("ix_user_answers_attempt", "quiz_attempt_id"),)


class UserProfile(Base):
    __tablename__ = "user_profiles"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), unique=True)
    quiz_1_result_code: Mapped[Optional[str]] = mapped_column(String(100))
    quiz_2_result_code: Mapped[Optional[str]] = mapped_column(String(100))
    quiz_3_result_code: Mapped[Optional[str]] = mapped_column(String(100))
    quiz_4_result_code: Mapped[Optional[str]] = mapped_column(String(100))
    quiz_5_result_code: Mapped[Optional[str]] = mapped_column(String(100))
    soul_path_score: Mapped[Optional[float]] = mapped_column(Float)
    soul_path_level_code: Mapped[Optional[str]] = mapped_column(String(100))
    soul_path_level_label: Mapped[Optional[str]] = mapped_column(String(500))
    profile_payload: Mapped[Optional[dict]] = mapped_column(JSON)
    computed_at: Mapped[Optional[datetime.datetime]] = mapped_column(DateTime)

    user: Mapped["User"] = relationship(back_populates="profile")


class Recommendation(Base):
    __tablename__ = "recommendations"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"))
    recommendation_code: Mapped[str] = mapped_column(String(100))
    title: Mapped[str] = mapped_column(String(500))
    text: Mapped[str] = mapped_column(Text)
    cta_type: Mapped[Optional[str]] = mapped_column(String(100))
    cta_url: Mapped[Optional[str]] = mapped_column(String(1000))
    created_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )


class BotState(Base):
    __tablename__ = "bot_states"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), unique=True)
    state_code: Mapped[str] = mapped_column(String(100))
    state_payload: Mapped[Optional[dict]] = mapped_column(JSON)
    updated_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    user: Mapped["User"] = relationship(back_populates="bot_state")


class QuizResultDefinition(Base):
    __tablename__ = "quiz_result_definitions"

    id: Mapped[int] = mapped_column(primary_key=True)
    quiz_id: Mapped[int] = mapped_column(ForeignKey("quizzes.id"))
    result_code: Mapped[str] = mapped_column(String(100))
    result_label: Mapped[str] = mapped_column(String(500))
    description: Mapped[Optional[str]] = mapped_column(Text)
    min_score: Mapped[Optional[float]] = mapped_column(Float)
    max_score: Mapped[Optional[float]] = mapped_column(Float)
    dominant_dimension: Mapped[Optional[str]] = mapped_column(String(100))
    order_index: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    quiz: Mapped["Quiz"] = relationship(back_populates="result_definitions")


class SoulPathLevel(Base):
    __tablename__ = "soul_path_levels"

    id: Mapped[int] = mapped_column(primary_key=True)
    level_code: Mapped[str] = mapped_column(String(100), unique=True)
    level_label: Mapped[str] = mapped_column(String(500))
    min_score: Mapped[float] = mapped_column(Float)
    max_score: Mapped[float] = mapped_column(Float)
    description: Mapped[Optional[str]] = mapped_column(Text)
    order_index: Mapped[int] = mapped_column(Integer)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class EventLog(Base):
    __tablename__ = "event_logs"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[Optional[int]] = mapped_column(ForeignKey("users.id"))
    event_name: Mapped[str] = mapped_column(String(100))
    event_payload: Mapped[Optional[dict]] = mapped_column(JSON)
    created_at: Mapped[datetime.datetime] = mapped_column(
        DateTime, server_default=func.now()
    )

    __table_args__ = (Index("ix_event_logs_user_event", "user_id", "event_name"),)
