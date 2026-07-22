"""
payment_listener.py — StarKerak to'lov tinglash servisi
VPS (206.189.61.55) da 24/7 ishlaydi.
To'lov kelganda Plesk veb-hookiga (https://jqbmalumotlarbazasi.uz/neuroinson/api/webhook.php) POST yuboradi.
"""

import asyncio
import logging
import aiohttp
from starkerak import StarKerakClient

# ============================================
# SOZLAMALAR
# ============================================
STARKERAK_API_KEY = "your_starkerak_api_key_here"
HUMO_CARD_LAST4   = "6905"
WEBHOOK_URL       = "https://jqbmalumotlarbazasi.uz/neuroinson/api/webhook.php"
WEBHOOK_SECRET    = "neuroinson_secret_2025_xavfsiz"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s"
)
log = logging.getLogger("PaymentListener")

client = StarKerakClient(STARKERAK_API_KEY)

@client.on_payment
async def payment_received(payment):
    """To'lov kelganda avtomatik chaqiriladi va Plesk veb-hookiga yuboradi."""
    log.info(f"Yangi to'lov: {payment}")
    
    pay_id     = payment.get("id") or payment.get("payment_id") or payment.get("transaction_id")
    amount     = payment.get("amount", 0)
    card_last4 = payment.get("card_last4", "")

    if not pay_id:
        import hashlib
        timestamp = payment.get("timestamp") or ""
        balance_val = payment.get("balance") or ""
        if timestamp:
            unique_str = f"starkerak_{timestamp}_{amount}_{balance_val}"
            pay_id = hashlib.md5(unique_str.encode('utf-8')).hexdigest()
            log.info(f"Generated fallback pay_id: {pay_id}")

    if not pay_id:
        log.warning("To'lov ID raqami topilmadi, e'tiborsiz qoldiriladi.")
        return

    log.info(f"To'lov yuborilmoqda: ID={pay_id} | Summa={amount} so'm | Karta oxiri=*{card_last4}")

    if card_last4 and card_last4 != HUMO_CARD_LAST4:
        log.warning(f"Boshqa karta ({card_last4}) dan kelgan to'lov. Tinglovchi karta oxiri: *{HUMO_CARD_LAST4}")
        return

    # Plesk Webhook payload
    payload = {
        "pay_id":        str(pay_id),
        "amount":        int(amount),
        "secret_token":  WEBHOOK_SECRET
    }

    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(
                WEBHOOK_URL,
                json=payload,
                ssl=False,
                timeout=aiohttp.ClientTimeout(total=15)
            ) as resp:
                res = await resp.json()
                log.info(f"Webhook javobi: {res}")
    except Exception as e:
        log.error(f"Webhook yuborishda xatolik: {e}")

if __name__ == "__main__":
    log.info("🚀 Neuroinson AI — To'lov tinglash servisi ishga tushdi!")
    log.info(f"💳 Karta: *{HUMO_CARD_LAST4} | Webhook: {WEBHOOK_URL}")
    client.start_listening()
