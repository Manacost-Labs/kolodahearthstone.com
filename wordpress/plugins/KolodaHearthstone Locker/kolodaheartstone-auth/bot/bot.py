"""
Telegram bot for kolodahearthstone.ru VIP content unlock.

Flow:
  /start  -> check subscription via getChatMember in channel + group
            -> if subscribed: load lockers from WP, show inline menu
            -> on selection: POST /wp-json/vip/v1/issue, return one-time link
"""
import asyncio
import logging
import os
import sys
from typing import Any

import httpx
from aiogram import Bot, Dispatcher, F
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.filters import Command, CommandStart
from aiogram.types import (
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)


def env(name: str, default: str | None = None, *, required: bool = True) -> str:
    val = os.environ.get(name, default)
    if required and not val:
        sys.exit(f"missing required env var: {name}")
    return val or ""


BOT_TOKEN = env("BOT_TOKEN")
WP_BASE_URL = env("WP_BASE_URL").rstrip("/")
WP_BEARER = env("WP_BEARER")
CHANNEL_ID = int(env("CHANNEL_ID"))
GROUP_ID = int(env("GROUP_ID"))
SUBSCRIBE_LINKS = env("SUBSCRIBE_LINKS", "", required=False)
HTTP_TIMEOUT = float(env("HTTP_TIMEOUT", "10", required=False))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
log = logging.getLogger("vipbot")

bot = Bot(BOT_TOKEN, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
dp = Dispatcher()

USER_LOCKERS: dict[int, list[dict[str, Any]]] = {}

ALLOWED_STATUSES = {"member", "administrator", "creator"}


async def is_subscribed(user_id: int) -> bool:
    for chat_id in (CHANNEL_ID, GROUP_ID):
        try:
            m = await bot.get_chat_member(chat_id=chat_id, user_id=user_id)
        except Exception as e:
            log.warning("getChatMember failed for chat=%s user=%s: %s", chat_id, user_id, e)
            continue
        status = m.status
        if status in ALLOWED_STATUSES:
            return True
        if status == "restricted" and getattr(m, "is_member", False):
            return True
    return False


async def wp_get(path: str) -> Any:
    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
        r = await client.get(
            f"{WP_BASE_URL}{path}",
            headers={"Authorization": f"Bearer {WP_BEARER}"},
        )
        r.raise_for_status()
        return r.json()


async def wp_post(path: str, payload: dict) -> Any:
    async with httpx.AsyncClient(timeout=HTTP_TIMEOUT) as client:
        r = await client.post(
            f"{WP_BASE_URL}{path}",
            headers={"Authorization": f"Bearer {WP_BEARER}"},
            json=payload,
        )
        r.raise_for_status()
        return r.json()


def build_subscribe_text() -> str:
    base = (
        "Чтобы получить доступ к VIP-статьям, подпишитесь на наш канал или группу.\n"
        "После подписки нажмите /start ещё раз."
    )
    if SUBSCRIBE_LINKS:
        links = "\n".join(f"• {x.strip()}" for x in SUBSCRIBE_LINKS.split(",") if x.strip())
        if links:
            base += "\n\n" + links
    return base


def lockers_keyboard(lockers: list[dict[str, Any]]) -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for i, l in enumerate(lockers[:50]):
        title = (l.get("title") or "(без названия)").strip()
        if len(title) > 60:
            title = title[:57] + "…"
        rows.append([InlineKeyboardButton(text=title, callback_data=f"art:{i}")])
    rows.append([InlineKeyboardButton(text="🔄 Обновить", callback_data="refresh")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


async def show_articles(target: Message, user_id: int) -> None:
    try:
        lockers = await wp_get("/wp-json/vip/v1/lockers")
    except httpx.HTTPStatusError as e:
        log.error("lockers HTTP %s: %s", e.response.status_code, e.response.text[:300])
        await target.answer(f"Ошибка загрузки списка: HTTP {e.response.status_code}")
        return
    except Exception as e:
        log.exception("lockers failed")
        await target.answer(f"Ошибка загрузки списка: {e}")
        return

    if not isinstance(lockers, list):
        await target.answer("Не удалось разобрать ответ сервера.")
        return
    if not lockers:
        await target.answer("Пока нет VIP-статей.")
        return

    USER_LOCKERS[user_id] = lockers
    await target.answer(
        f"Доступно статей: <b>{len(lockers)}</b>\nВыберите, чтобы получить ссылку:",
        reply_markup=lockers_keyboard(lockers),
    )


@dp.message(CommandStart())
async def cmd_start(msg: Message) -> None:
    user_id = msg.from_user.id
    log.info("/start user=%s", user_id)
    if not await is_subscribed(user_id):
        await msg.answer(build_subscribe_text())
        return
    await show_articles(msg, user_id)


@dp.message(Command("list"))
async def cmd_list(msg: Message) -> None:
    if not await is_subscribed(msg.from_user.id):
        await msg.answer(build_subscribe_text())
        return
    await show_articles(msg, msg.from_user.id)


@dp.message(Command("help"))
async def cmd_help(msg: Message) -> None:
    await msg.answer(
        "Команды:\n"
        "/start — проверить подписку и показать список статей\n"
        "/list — обновить список статей\n"
        "/help — эта справка"
    )


@dp.callback_query(F.data == "refresh")
async def cb_refresh(q: CallbackQuery) -> None:
    if not await is_subscribed(q.from_user.id):
        await q.answer("Подписка не подтверждена.", show_alert=True)
        return
    await q.answer("Обновляю…")
    await show_articles(q.message, q.from_user.id)


@dp.callback_query(F.data.startswith("art:"))
async def cb_article(q: CallbackQuery) -> None:
    try:
        idx = int(q.data.split(":", 1)[1])
    except ValueError:
        await q.answer("Неверный выбор.", show_alert=True)
        return

    lockers = USER_LOCKERS.get(q.from_user.id) or []
    if idx < 0 or idx >= len(lockers):
        await q.answer("Список устарел, нажмите /start.", show_alert=True)
        return

    if not await is_subscribed(q.from_user.id):
        await q.answer("Подписка не подтверждена.", show_alert=True)
        return

    item = lockers[idx]
    await q.answer("Готовлю ссылку…")
    try:
        res = await wp_post(
            "/wp-json/vip/v1/issue",
            {
                "post_id": item["post_id"],
                "code": item["code"],
                "telegram_user_id": q.from_user.id,
            },
        )
    except httpx.HTTPStatusError as e:
        log.error("issue HTTP %s: %s", e.response.status_code, e.response.text[:300])
        await q.message.answer(f"Не удалось выдать ссылку: HTTP {e.response.status_code}")
        return
    except Exception as e:
        log.exception("issue failed")
        await q.message.answer(f"Не удалось выдать ссылку: {e}")
        return

    url = res.get("url")
    ttl = res.get("ttl") or 900
    minutes = max(1, ttl // 60)
    title = item.get("title") or "статья"
    if not url:
        await q.message.answer("Сервер не вернул ссылку. Попробуйте позже.")
        return
    await q.message.answer(
        f"🔓 <b>{title}</b>\n"
        f"Одноразовая ссылка (действует {minutes} мин):\n"
        f"{url}",
        disable_web_page_preview=True,
    )


async def main() -> None:
    me = await bot.get_me()
    log.info("starting bot @%s id=%s", me.username, me.id)
    log.info("WP base: %s", WP_BASE_URL)
    await bot.delete_webhook(drop_pending_updates=False)
    await dp.start_polling(bot, allowed_updates=dp.resolve_used_update_types())


if __name__ == "__main__":
    asyncio.run(main())
