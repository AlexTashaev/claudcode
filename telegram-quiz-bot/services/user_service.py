import datetime

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from db.models import BotState, User


async def get_or_create_user(
    session: AsyncSession,
    telegram_id: int,
    username: str | None = None,
    first_name: str | None = None,
    last_name: str | None = None,
    language_code: str | None = None,
    source: str | None = None,
    entry_point: str | None = None,
) -> User:
    stmt = select(User).where(User.telegram_id == telegram_id)
    result = await session.execute(stmt)
    user = result.scalar_one_or_none()

    if user is None:
        user = User(
            telegram_id=telegram_id,
            username=username,
            first_name=first_name,
            last_name=last_name,
            language_code=language_code,
            source=source,
            entry_point=entry_point,
            status="new",
        )
        session.add(user)
        await session.flush()

        bot_state = BotState(user_id=user.id, state_code="welcome")
        session.add(bot_state)
        await session.commit()
    else:
        user.last_activity_at = datetime.datetime.now(datetime.UTC)
        user.username = username
        user.first_name = first_name
        user.last_name = last_name
        await session.commit()

    return user


async def set_user_status(
    session: AsyncSession, user_id: int, status: str
) -> None:
    stmt = select(User).where(User.id == user_id)
    result = await session.execute(stmt)
    user = result.scalar_one()
    user.status = status
    await session.commit()


async def get_bot_state(session: AsyncSession, user_id: int) -> BotState | None:
    stmt = select(BotState).where(BotState.user_id == user_id)
    result = await session.execute(stmt)
    return result.scalar_one_or_none()


async def set_bot_state(
    session: AsyncSession,
    user_id: int,
    state_code: str,
    state_payload: dict | None = None,
) -> None:
    stmt = select(BotState).where(BotState.user_id == user_id)
    result = await session.execute(stmt)
    bot_state = result.scalar_one_or_none()

    if bot_state is None:
        bot_state = BotState(
            user_id=user_id, state_code=state_code, state_payload=state_payload
        )
        session.add(bot_state)
    else:
        bot_state.state_code = state_code
        bot_state.state_payload = state_payload

    await session.commit()
