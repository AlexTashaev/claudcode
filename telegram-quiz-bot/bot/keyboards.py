"""Inline keyboard builders for the quiz bot."""
from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup

from db.models import AnswerOption, Question, Quiz


def welcome_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="Начать тест", callback_data="quiz:start_next")],
    ])


def quiz_intro_keyboard(quiz_code: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="Начать", callback_data=f"quiz:begin:{quiz_code}")],
    ])


def question_keyboard(question: Question) -> InlineKeyboardMarkup:
    buttons = []
    for option in sorted(question.options, key=lambda o: o.order_index):
        buttons.append([
            InlineKeyboardButton(
                text=option.text,
                callback_data=f"ans:{question.id}:{option.id}",
            )
        ])
    return InlineKeyboardMarkup(inline_keyboard=buttons)


def quiz_result_keyboard(
    has_next_quiz: bool,
    quiz_code: str,
    cta_url: str | None = None,
    cta_text: str | None = None,
) -> InlineKeyboardMarkup:
    buttons = []

    if cta_url and cta_text:
        buttons.append([
            InlineKeyboardButton(text=cta_text, url=cta_url)
        ])

    if has_next_quiz:
        buttons.append([
            InlineKeyboardButton(text="Следующий тест →", callback_data="quiz:start_next")
        ])
    else:
        buttons.append([
            InlineKeyboardButton(text="Мой профиль", callback_data="menu:profile")
        ])

    buttons.append([
        InlineKeyboardButton(text="Пройти заново", callback_data=f"quiz:restart:{quiz_code}")
    ])

    return InlineKeyboardMarkup(inline_keyboard=buttons)


def profile_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🗺 Путь души", callback_data="menu:soul_map")],
        [InlineKeyboardButton(text="💡 Рекомендация", callback_data="menu:recommendation")],
    ])


def soul_map_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="📊 Мой профиль", callback_data="menu:profile")],
        [InlineKeyboardButton(text="💡 Рекомендация", callback_data="menu:recommendation")],
    ])


def recommendation_keyboard(cta_url: str | None = None) -> InlineKeyboardMarkup:
    buttons = []
    if cta_url:
        buttons.append([
            InlineKeyboardButton(text="Перейти", url=cta_url)
        ])
    buttons.append([
        InlineKeyboardButton(text="📊 Мой профиль", callback_data="menu:profile")
    ])
    return InlineKeyboardMarkup(inline_keyboard=buttons)


def main_menu_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="Начать тесты", callback_data="quiz:start_next")],
        [InlineKeyboardButton(text="📊 Мой профиль", callback_data="menu:profile")],
        [InlineKeyboardButton(text="🗺 Путь души", callback_data="menu:soul_map")],
    ])
