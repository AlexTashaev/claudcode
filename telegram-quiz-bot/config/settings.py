from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    bot_token: str = ""
    database_url: str = "sqlite+aiosqlite:///quiz_bot.db"
    webhook_secret: str = ""
    # Reminder intervals (hours)
    incomplete_quiz_reminder_hours: int = 12
    next_quiz_reminder_hours: int = 24
    conversion_reminder_hours: int = 48
    # Logging
    log_level: str = "INFO"

    model_config = {"env_file": ".env", "env_prefix": "QUIZ_BOT_"}


settings = Settings()
