"""Text rendering functions for bot messages."""
import json
from pathlib import Path

from db.models import QuizAttempt, UserProfile

_data_path = Path(__file__).parent.parent / "data" / "quizzes.json"
with open(_data_path, encoding="utf-8") as f:
    _quiz_data = json.load(f)

TYPES = _quiz_data["types"]
QUIZ_CONFIGS = {q["code"]: q for q in _quiz_data["quizzes"]}
SOUL_LEVELS = _quiz_data["soul_path_levels"]
SCREENS = _quiz_data["screens"]


def welcome_text() -> str:
    return SCREENS["welcome"]


def quiz_intro_text(quiz_code: str, quiz_number: int) -> str:
    config = QUIZ_CONFIGS.get(quiz_code, {})
    return (
        f"📋 Тест {quiz_number} из 5: {config.get('title', quiz_code)}\n\n"
        f"{config.get('description', '')}\n\n"
        f"7 вопросов, ~2 минуты"
    )


def question_text(question_number: int, total: int, text: str) -> str:
    progress = "▓" * question_number + "░" * (total - question_number)
    return f"Вопрос {question_number} из {total}\n{progress}\n\n{text}"


def quiz_result_text(attempt: QuizAttempt) -> str:
    payload = attempt.result_payload or {}
    emoji = payload.get("emoji", "")
    title = payload.get("title", attempt.result_label or "")
    level = payload.get("level", "")
    description = payload.get("description", "")
    pcts = payload.get("percentages", [])
    type_titles = payload.get("type_titles", [t["title"] for t in TYPES])

    # Build percentage breakdown
    breakdown_lines = []
    type_emojis = ["👁", "🔍", "🧭", "✨"]
    if pcts:
        sorted_items = sorted(
            enumerate(pcts),
            key=lambda x: x[1],
            reverse=True,
        )
        for idx, pct in sorted_items:
            if pct > 0:
                bar = "█" * (pct // 5) + "░" * (20 - pct // 5)
                t_emoji = type_emojis[idx] if idx < len(type_emojis) else ""
                t_title = type_titles[idx] if idx < len(type_titles) else ""
                breakdown_lines.append(f"{t_emoji} {t_title}: {bar} {pct}%")

    breakdown = "\n".join(breakdown_lines)

    return (
        f"✅ Тест завершён!\n\n"
        f"{emoji} Уровень {level}\n"
        f"<b>{title}</b>\n\n"
        f"{breakdown}\n\n"
        f"{description}"
    )


def profile_text(profile: UserProfile) -> str:
    payload = profile.profile_payload or {}
    if not payload:
        return "Профиль ещё не готов. Пройдите все 5 тестов."

    lines = ["📊 <b>Ваш профиль</b>\n"]

    quiz_labels = {
        "quiz1": "Этап поиска",
        "quiz2": "Тип познания",
        "quiz3": "Главный вопрос",
        "quiz4": "Глубина восприятия",
        "quiz5": "Готовность",
    }

    for quiz_code, label in quiz_labels.items():
        data = payload.get(quiz_code)
        if data:
            lines.append(f"{data.get('emoji', '')} {label}: <b>{data.get('title', '—')}</b>")
        else:
            lines.append(f"◻️ {label}: <i>не пройден</i>")

    return "\n".join(lines)


def soul_map_text(profile: UserProfile) -> str:
    level_index = int(profile.soul_path_score or 0)
    level_data = SOUL_LEVELS[level_index] if level_index < len(SOUL_LEVELS) else SOUL_LEVELS[0]

    # Build path visualization
    path_parts = []
    for i, level in enumerate(SOUL_LEVELS):
        if i < level_index:
            path_parts.append(f"✅ {level['emoji']} {level['title']}")
        elif i == level_index:
            path_parts.append(f"👉 {level['emoji']} <b>{level['title']}</b> ← вы здесь")
        else:
            path_parts.append(f"◻️ {level['emoji']} {level['title']}")

    path = "\n".join(path_parts)

    return (
        f"🗺 <b>Карта «Путь души»</b>\n\n"
        f"{level_data['emoji']} Ваш уровень: <b>{level_data['title']}</b>\n\n"
        f"{path}"
    )


def recommendation_text(title: str, text: str) -> str:
    return f"💡 <b>{title}</b>\n\n{text}"
