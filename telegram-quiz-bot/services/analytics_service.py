from sqlalchemy.ext.asyncio import AsyncSession

from db.models import EventLog


async def track_event(
    session: AsyncSession,
    event_name: str,
    user_id: int | None = None,
    payload: dict | None = None,
) -> None:
    event = EventLog(
        user_id=user_id,
        event_name=event_name,
        event_payload=payload,
    )
    session.add(event)
    await session.commit()
