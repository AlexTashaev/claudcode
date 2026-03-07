"""Entry point for the Telegram Quiz Bot."""
import asyncio
import logging
import sys

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode

from bot.handlers import router
from config import settings
from db import init_db, async_session
from services.data_loader import seed_quizzes

logging.basicConfig(
    level=getattr(logging, settings.log_level),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger(__name__)


async def on_startup() -> None:
    """Initialize database and seed quiz data."""
    logger.info("Initializing database...")
    await init_db()

    logger.info("Seeding quiz data...")
    async with async_session() as session:
        await seed_quizzes(session)

    logger.info("Startup complete.")


async def main() -> None:
    if not settings.bot_token:
        logger.error(
            "QUIZ_BOT_BOT_TOKEN is not set. "
            "Create a .env file with QUIZ_BOT_BOT_TOKEN=your_token"
        )
        sys.exit(1)

    bot = Bot(
        token=settings.bot_token,
        default=DefaultBotProperties(parse_mode=ParseMode.HTML),
    )
    dp = Dispatcher()
    dp.include_router(router)

    dp.startup.register(on_startup)

    logger.info("Starting bot in polling mode...")
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
