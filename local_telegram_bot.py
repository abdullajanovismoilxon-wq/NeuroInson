import time
import json
import os
import urllib.request
import urllib.parse
import random
import datetime

def load_config():
    config = {}
    paths = [
        os.path.join(os.path.dirname(__file__), "config.json"),
        os.path.join(os.path.dirname(__file__), "api", "config.json"),
    ]
    for path in paths:
        if os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8") as f:
                    config.update(json.load(f))
            except Exception:
                pass
    return config


config = load_config()
BOT_TOKEN = config.get("BOT_TOKEN", "your_telegram_bot_token_here")
API_URL = f"https://api.telegram.org/bot{BOT_TOKEN}/"

# VPS local API base - calls PHP files directly via localhost
VPS_API_BASE = "http://localhost/api/"

# Web App URL — Cloudflare HTTPS Tunnel URL
WEB_APP_URL = "https://glance-proud-learned-housewares.trycloudflare.com"

# State manager for admin panel
ADMIN_STATES = {}
ADMIN_DATA = {}

# In-memory daily limits for chatbot in Telegram chat
DAILY_CHAT_LIMITS = {}

def send_telegram_request(method, payload):
    url = API_URL + method
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode('utf-8'),
        headers={'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0'}
    )
    try:
        with urllib.request.urlopen(req, timeout=25) as response:
            return json.loads(response.read().decode('utf-8'))
    except Exception as e:
        print(f"[Telegram Error] {method}: {e}")
        return None

def send_message(chat_id, text, reply_markup=None):
    payload = {
        "chat_id": chat_id,
        "text": text,
        "parse_mode": "HTML",
        "disable_web_page_preview": True
    }
    if reply_markup:
        payload["reply_markup"] = reply_markup
    return send_telegram_request("sendMessage", payload)

def answer_callback_query(callback_query_id, text=None):
    payload = {"callback_query_id": callback_query_id}
    if text:
        payload["text"] = text
    return send_telegram_request("answerCallbackQuery", payload)

def edit_message_text(chat_id, message_id, text, reply_markup=None):
    payload = {
        "chat_id": chat_id,
        "message_id": message_id,
        "text": text,
        "parse_mode": "HTML",
        "disable_web_page_preview": True
    }
    if reply_markup:
        payload["reply_markup"] = reply_markup
    return send_telegram_request("editMessageText", payload)

def send_chat_action(chat_id, action="typing"):
    payload = {"chat_id": chat_id, "action": action}
    return send_telegram_request("sendChatAction", payload)

def call_vps_api(endpoint, params=None, payload=None, is_post=False):
    """Call VPS local PHP API (localhost)"""
    url = VPS_API_BASE + endpoint
    if params:
        url += "?" + urllib.parse.urlencode(params)
        
    req_data = None
    headers = {'User-Agent': 'Mozilla/5.0'}
    
    if is_post and payload is not None:
        req_data = json.dumps(payload).encode('utf-8')
        headers['Content-Type'] = 'application/json'
        
    req = urllib.request.Request(url, data=req_data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=25) as response:
            return json.loads(response.read().decode('utf-8'))
    except Exception as e:
        print(f"[VPS API Error] {endpoint}: {e}")
        return {"error": str(e)}# Global dict for circuit breaker: key -> blocked_until_timestamp
BLOCKED_KEYS = {}

def call_chatbot_api(user_message):
    global BLOCKED_KEYS
    now = time.time()
    
    # Filter out blocked keys
    free_keys = []
    for k in config.get("GEMINI_FREE_KEYS", []):
        if k not in BLOCKED_KEYS or now > BLOCKED_KEYS[k]:
            free_keys.append(k)
            
    single_key = config.get("GEMINI_API_KEY", "")
    if single_key and single_key not in free_keys:
        if single_key not in BLOCKED_KEYS or now > BLOCKED_KEYS[single_key]:
            free_keys.append(single_key)
            
    paid_keys = []
    for k in config.get("GEMINI_PAID_KEYS", []):
        if k not in BLOCKED_KEYS or now > BLOCKED_KEYS[k]:
            paid_keys.append(k)
            
    if not free_keys and not paid_keys:
        # Fallback to paid keys if all are blocked
        paid_keys = list(config.get("GEMINI_PAID_KEYS", []))
        if not paid_keys:
            return "Tizim sozlanmagan. Gemini API kalitlari topilmadi."

    current_date_info = f"Bugungi sana: {datetime.datetime.now().strftime('%Y-%yil %d-%B')}. Amerika Qo'shma Shtatlarining (AQSH) hozirgi (47-chi) prezidenti — Donald Trump."

    system_prompt = (
        "Siz 'Neuroinson' platformasining rasmiy aqlli yordamchi chatbotisiz.\n"
        "Muloqot boshlanganda yoki o'zingizni tanishtirganda, har doim 'Men Neuroinson AI loyihasining rasmiy yordamchi chatbotiman' deb javob bering.\n"
        "Foydalanuvchi — o'zbekistonlik talaba, frilanser yoki yosh tadbirkor.\n\n"
        "Qoidalar:\n"
        "1. Salomlashuvlarda har doim quyidagi formatda muloyim javob bering: 'Salom! (yoki Assalomu alaykum!) Yaxshimisiz? Men Neuroinson AI loyihasining rasmiy yordamchi chatbotiman. Sizga bugun qanday yordam bera olaman?'.\n"
        "2. Do'stona, samimiy va o'zbekona ohangda muloqot qiling.\n"
        "3. Tizim, narxlar va imkoniyatlar haqida samimiy va o'zbekona tilda maslahat bering.\n"
        "4. QAT'IY CHEKLOVLAR: Siz foydalanuvchi uchun veb-sayt yoki bot yaratib bermaysiz, dasturlash kodlari yozmaysiz, kurs ishi yoki diplom ishi yozmaysiz, slayd (taqdimot) tayyorlamaysiz.\n"
        "5. Agar foydalanuvchi sizdan kod yozishni, bot yaratishni yoki diplom yozishni so'rasa, muloyimlik bilan rad eting va Neuroinson Mini App orqali maxsus pullik xizmatlardan foydalanishni taklif qiling.\n\n"
        f"Kontekst: {current_date_info}"
    )

    # Try free keys pool first, then paid keys pool
    all_key_pools = [free_keys, paid_keys]
    last_error = "Barcha Gemini kalitlari band yoki cheklovga uchragan."

    for pool in all_key_pools:
        # Randomize keys order to balance load
        random.shuffle(pool)
        for selected_key in pool:
            url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key={selected_key}"
            payload = json.dumps({

                "systemInstruction": {
                    "parts": [{"text": system_prompt}]
                },
                "contents": [
                    {
                        "role": "user",
                        "parts": [{"text": user_message}]
                    }
                ]
            }).encode('utf-8')
            
            req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'})
            try:
                # Shorter timeout to fail fast if connection hangs
                with urllib.request.urlopen(req, timeout=6) as response:
                    res = json.loads(response.read().decode('utf-8'))
                    text = res['candidates'][0]['content']['parts'][0]['text'].strip()
                    if text:
                        return text
            except Exception as e:
                error_body = ""
                is_rate_limit = False
                if hasattr(e, "read"):
                    try:
                        err_body = e.read().decode('utf-8')
                        error_body = " | " + err_body
                        if "429" in error_body or "RESOURCE_EXHAUSTED" in error_body or "quota" in error_body:
                            is_rate_limit = True
                    except Exception:
                        pass
                
                if is_rate_limit or (hasattr(e, "code") and e.code == 429):
                    BLOCKED_KEYS[selected_key] = time.time() + 300
                    print(f"[Gemini Key Blocked] Key {selected_key[:8]}... blocked for 5 minutes.")
                
                print(f"[Gemini Key Fail] Key: {selected_key[:8]}... Error: {e}{error_body}")
                last_error = f"{e}{error_body}"
                continue

    return f"Xatolik (Gemini bandligi): {last_error}"



def show_admin_menu_keyboard(chat_id, msg_id=None):
    text = "<b>⚙️ Admin Control Panel</b>\n\n🤖 Neuroinson AI boshqaruv tizimiga xush kelibsiz. Quyidagi menyudan foydalaning:"
    reply_markup = {
        "inline_keyboard": [
            [
                {"text": "📊 Statistika", "callback_data": "admin_stats"},
                {"text": "👥 Foydalanuvchilar", "callback_data": "admin_users"}
            ],
            [
                {"text": "🔍 Qidirish", "callback_data": "admin_find"},
                {"text": "💳 Balans Qo'shish", "callback_data": "admin_pay"}
            ],
            [
                {"text": "🧾 Oxirgi To'lovlar", "callback_data": "admin_payments"},
                {"text": "📢 Reklama Tarqatish", "callback_data": "admin_broadcast"}
            ],
            [
                {"text": "🏠 Asosiy Menyu", "callback_data": "main_menu"}
            ]
        ]
    }
    if msg_id:
        edit_message_text(chat_id, msg_id, text, reply_markup)
    else:
        send_message(chat_id, text, reply_markup)

def show_main_menu(chat_id, username="", first_name=""):
    user_display = first_name if first_name else (username if username else "Foydalanuvchi")
    welcome = (
        f"👋 <b>Assalomu alaykum, {user_display}!</b>\n\n"
        "🤖 <b>NEUROINSON AI</b> rasmiy platformasiga xush kelibsiz!\n\n"
        "🚀 Ushbu ilg'or bot va veb-ilova yordamida quyidagi imkoniyatlardan foydalanishingiz mumkin:\n"
        "💬 <b>AI Chatbot</b> — Barcha savollaringizga tezkor va aqlli javoblar\n"
        "✍️ <b>Prompt Muharriri</b> — Generatsiyalar uchun mukammal promptlar\n"
        "🎨 <b>Rasm AI</b> — Yuqori sifatli va kreativ rasmlar yaratish\n"
        "🎥 <b>Video AI</b> — Matn asosida ajoyib videolar generatsiyasi\n"
        "📁 <b>Loyihalar Markazi</b> — Diplom va kurs ishlari, saytlar yaratish\n\n"
        "✨ Qulay interfeys va barcha bo'limlardan foydalanish uchun quyidagi tugmani bosing 👇"
    )
    web_app_full_url = f"{WEB_APP_URL}/?v=1.15&telegram_id={chat_id}&username={urllib.parse.quote(username)}&first_name={urllib.parse.quote(first_name)}"
    reply_markup = {

        "inline_keyboard": [
            [
                {"text": "🚀 Mini App-ni ochish", "web_app": {"url": web_app_full_url}}
            ],
            [
                {"text": "🛠️ Qo'llab-quvvatlash", "url": "https://t.me/mr_sobirjanov"}
            ]
        ]
    }
    send_message(chat_id, welcome, reply_markup)

def process_callback_query(callback_query):
    chat_id = callback_query["message"]["chat"]["id"]
    msg_id = callback_query["message"]["message_id"]
    data = callback_query["data"]
    cb_id = callback_query["id"]
    
    ADMIN_IDS = [799317334]
    
    if data == "main_menu":
        answer_callback_query(cb_id)
        from_user = callback_query.get("from", {})
        username = from_user.get("username", "")
        first_name = from_user.get("first_name", "")
        show_main_menu(chat_id, username, first_name)
        return
        
    if data == "admin_menu" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        show_admin_menu_keyboard(chat_id, msg_id)
        return

    if data == "admin_stats" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        send_chat_action(chat_id, "typing")
        res = call_vps_api("admin-stats.php", {"telegram_id": chat_id})
        if "error" in res:
            edit_message_text(chat_id, msg_id, f"Xatolik: {res['error']}", {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]})
            return
            
        stats = res.get("stats", {})
        mrr = stats.get("mrr", 0)
        stats_text = (
            "<b>NEUROINSON STATISTIKA</b>\n"
            "------------------------\n"
            f"Jami foydalanuvchilar: <b>{stats.get('total_users', 0)} ta</b>\n"
            f"Jami foydalanuvchilar balansi: <b>{stats.get('total_balance', 0):,} so'm</b>\n"
            f"Jami muvaffaqiyatli to'lovlar: <b>{stats.get('total_topups', 0):,} so'm</b>\n"
            f"Jami sarflangan summa: <b>{stats.get('total_spent', 0):,} so'm</b>\n"
            f"MRR (bu oy): <b>{mrr:,} so'm</b>\n"
        )
        reply_markup = {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, stats_text, reply_markup)
        return

    if data == "admin_users" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        send_chat_action(chat_id, "typing")
        res = call_vps_api("admin-stats.php", {"telegram_id": chat_id})
        if "error" in res:
            edit_message_text(chat_id, msg_id, f"Xatolik: {res['error']}", {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]})
            return
            
        users = res.get("users", [])
        users_text = "<b>OXIRGI RO'YXATDAN O'TGANLAR:</b>\n\n"
        for u in users[:15]:
            uname = f"@{u['username']}" if u['username'] else "Noma'lum"
            users_text += f"ID: <code>{u['telegram_id']}</code> | {uname} | <b>{u['balance']:,} so'm</b>\n"
        reply_markup = {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, users_text, reply_markup)
        return

    if data == "admin_find" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        ADMIN_STATES[chat_id] = "waiting_for_search"
        text = "<b>Foydalanuvchi qidirish</b>\n\nID raqami yoki username yozib yuboring:"
        reply_markup = {"inline_keyboard": [[{"text": "Bekor qilish", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, text, reply_markup)
        return

    if data == "admin_pay" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        ADMIN_STATES[chat_id] = "waiting_for_balance_id"
        text = "<b>Balansni o'zgartirish</b>\n\nFoydalanuvchining Telegram ID-sini kiriting:"
        reply_markup = {"inline_keyboard": [[{"text": "Bekor qilish", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, text, reply_markup)
        return

    if data == "admin_payments" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        send_chat_action(chat_id, "typing")
        res = call_vps_api("admin-stats.php", {"telegram_id": chat_id})
        if "error" in res:
            edit_message_text(chat_id, msg_id, f"Xatolik: {res['error']}", {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]})
            return
            
        payments = res.get("recent_topups", [])
        if not payments:
            tx_text = "Oxirgi to'lovlar mavjud emas."
        else:
            tx_text = "<b>OXIRGI TO'LOVLAR:</b>\n\n"
            for tx in payments[:15]:
                uname = f" (@{tx['username']})" if tx['username'] else ""
                tx_text += f"ID: <code>{tx['telegram_id']}</code>{uname} | <b>{int(tx['amount']):,} so'm</b> | {tx['created_at']}\n"
        reply_markup = {"inline_keyboard": [[{"text": "Orqaga", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, tx_text, reply_markup)
        return

    if data == "admin_broadcast" and chat_id in ADMIN_IDS:
        answer_callback_query(cb_id)
        ADMIN_STATES[chat_id] = "waiting_for_broadcast"
        text = "<b>Xabar yuborish</b>\n\nBarcha foydalanuvchilarga yuboriladigan xabar matnini yozib yuboring:"
        reply_markup = {"inline_keyboard": [[{"text": "Bekor qilish", "callback_data": "admin_menu"}]]}
        edit_message_text(chat_id, msg_id, text, reply_markup)
        return

def process_update(update):
    if "callback_query" in update:
        process_callback_query(update["callback_query"])
        return

    if "message" not in update:
        return
    msg = update["message"]
    chat_id = msg["chat"]["id"]
    text = msg.get("text", "")
    caption = msg.get("caption", "")
    full_text = text if text else caption
    
    if not full_text:
        return

    from_user = msg.get("from", {})
    username = from_user.get("username", "")
    first_name = from_user.get("first_name", "")

    # Register user in VPS DB
    call_vps_api("get-user.php", {"telegram_id": chat_id, "username": username})

    ADMIN_IDS = [799317334]
    
    if chat_id in ADMIN_IDS:
        # Direct slash command /pay [telegram_id] [amount]
        if full_text.startswith("/pay"):
            parts = full_text.split()
            if len(parts) < 3:
                send_message(chat_id, "⚠️ Foydalanish: `/pay [telegram_id] [miqdor]` (Masalan: `/pay 799317334 5000` yoki `-2000`)")
                return
            try:
                target_uid = int(parts[1])
                amount = int(parts[2])
            except ValueError:
                send_message(chat_id, "⚠️ Xatolik: ID va miqdor faqat son bo'lishi kerak!")
                return
            
            send_chat_action(chat_id, "typing")
            payload = {
                "admin_id": chat_id,
                "action": "update_balance",
                "user_id": target_uid,
                "amount": amount
            }
            res = call_vps_api("admin-action.php", payload=payload, is_post=True)
            if "error" in res:
                send_message(chat_id, f"❌ Xatolik: {res['error']}")
            else:
                send_message(chat_id, f"✅ Balans muvaffaqiyatli o'zgartirildi!\nID: <code>{target_uid}</code> balansi {amount:+,} so'mga o'zgartirildi.")
            return

        # Direct slash command /find [query]
        if full_text.startswith("/find"):
            parts = full_text.split(maxsplit=1)
            if len(parts) < 2:
                send_message(chat_id, "⚠️ Foydalanish: `/find [ism_yoki_id]`")
                return
            query = parts[1].strip()
            send_chat_action(chat_id, "typing")
            res = call_vps_api("admin-stats.php", {"telegram_id": chat_id, "search": query})
            if "error" in res:
                send_message(chat_id, f"❌ Xatolik: {res['error']}")
                return
            users = res.get("users", [])
            if not users:
                send_message(chat_id, f"🔍 <b>'{query}'</b> bo'yicha hech qanday foydalanuvchi topilmadi.")
                return
            msg_lines = [f"🔍 <b>Qidiruv natijalari ('{query}'):</b>\n"]
            for u in users[:15]:
                username_str = f"@{u['username']}" if u['username'] else "Noma'lum"
                msg_lines.append(f"• ID: <code>{u['telegram_id']}</code> | {username_str} | Balans: <b>{int(u['balance']):,} so'm</b>")
            send_message(chat_id, "\n".join(msg_lines))
            return

        # Direct slash command /send [text]
        if full_text.startswith("/send"):
            parts = full_text.split(maxsplit=1)
            if len(parts) < 2:
                send_message(chat_id, "⚠️ Foydalanish: `/send [xabar matni]`")
                return
            msg_text = parts[1].strip()
            send_chat_action(chat_id, "typing")
            payload = {
                "admin_id": chat_id,
                "action": "broadcast",
                "message_text": msg_text
            }
            res = call_vps_api("admin-action.php", payload=payload, is_post=True)
            if "error" in res:
                send_message(chat_id, f"❌ Xatolik: {res['error']}")
            else:
                success_count = res.get("success_count", 0)
                fail_count = res.get("fail_count", 0)
                send_message(chat_id, f"📢 E'lon muvaffaqiyatli tarqatildi!\n\n• Yetib bordi: <b>{success_count} ta</b> userga\n• Yetib bormadi: <b>{fail_count} ta</b> userga")
            return

    if full_text.startswith("/admin") and chat_id in ADMIN_IDS:
        show_admin_menu_keyboard(chat_id)
        return
        
    if chat_id in ADMIN_IDS and ADMIN_STATES.get(chat_id):
        state = ADMIN_STATES[chat_id]
        
        if state == "waiting_for_search":
            ADMIN_STATES[chat_id] = None
            send_chat_action(chat_id, "typing")
            res = call_vps_api("admin-stats.php", {"telegram_id": chat_id, "search": full_text})
            if "error" in res:
                send_message(chat_id, f"Xatolik: {res['error']}")
                return
            users = res.get("users", [])
            if not users:
                send_message(chat_id, f"'{full_text}' bo'yicha hech qanday foydalanuvchi topilmadi.")
                return
            msg_lines = [f"<b>Qidiruv natijalari ('{full_text}'):</b>\n"]
            for u in users[:15]:
                username_str = f"@{u['username']}" if u['username'] else "Noma'lum"
                msg_lines.append(f"ID: <code>{u['telegram_id']}</code> | {username_str} | Balans: <b>{int(u['balance']):,} so'm</b>")
            
            reply_markup = {"inline_keyboard": [[{"text": "Admin Menyu", "callback_data": "admin_menu"}]]}
            send_message(chat_id, "\n".join(msg_lines), reply_markup)
            return

        if state == "waiting_for_balance_id":
            try:
                target_uid = int(full_text.strip())
            except ValueError:
                send_message(chat_id, "Telegram ID faqat son bo'lishi kerak! Qayta kiriting:")
                return
            
            ADMIN_DATA[chat_id] = {"target_uid": target_uid}
            ADMIN_STATES[chat_id] = "waiting_for_balance_amount"
            
            reply_markup = {"inline_keyboard": [[{"text": "Bekor qilish", "callback_data": "admin_menu"}]]}
            send_message(chat_id, f"Foydalanuvchi ID: <code>{target_uid}</code>\n\nSummani kiriting (masalan: 5000 yoki -2000):", reply_markup)
            return

        if state == "waiting_for_balance_amount":
            target_uid = ADMIN_DATA.get(chat_id, {}).get("target_uid")
            if not target_uid:
                ADMIN_STATES[chat_id] = None
                send_message(chat_id, "Ma'lumot yo'qoldi. Qayta urinib ko'ring.")
                return
            
            try:
                amount = int(full_text.strip())
            except ValueError:
                send_message(chat_id, "Miqdor faqat son bo'lishi kerak! Qayta kiriting:")
                return
            
            ADMIN_STATES[chat_id] = None
            send_chat_action(chat_id, "typing")
            
            payload = {
                "admin_id": chat_id,
                "action": "update_balance",
                "user_id": target_uid,
                "amount": amount
            }
            res = call_vps_api("admin-action.php", payload=payload, is_post=True)
            reply_markup = {"inline_keyboard": [[{"text": "Admin Menyu", "callback_data": "admin_menu"}]]}
            
            if "error" in res:
                send_message(chat_id, f"Xatolik: {res['error']}", reply_markup)
            else:
                send_message(chat_id, f"Balans muvaffaqiyatli yangilandi!\n\nID: <code>{target_uid}</code> balansi {amount:+,} so'mga o'zgartirildi.", reply_markup)
            return

        if state == "waiting_for_broadcast":
            ADMIN_STATES[chat_id] = None
            send_chat_action(chat_id, "typing")
            
            payload = {
                "admin_id": chat_id,
                "action": "broadcast",
                "message_text": full_text
            }
            res = call_vps_api("admin-action.php", payload=payload, is_post=True)
            reply_markup = {"inline_keyboard": [[{"text": "Admin Menyu", "callback_data": "admin_menu"}]]}
            
            if "error" in res:
                send_message(chat_id, f"Xatolik: {res['error']}", reply_markup)
            else:
                success_count = res.get("success_count", 0)
                fail_count = res.get("fail_count", 0)
                send_message(chat_id, f"E'lon muvaffaqiyatli tarqatildi!\n\nYetib bordi: <b>{success_count} ta</b> userga\nYetib bormadi: <b>{fail_count} ta</b> userga", reply_markup)
            return

    # Start Command
    if full_text.startswith("/start"):
        show_main_menu(chat_id, username, first_name)
        return

    # Standard chatbot text response with daily limit
    today_str = datetime.datetime.now().strftime("%Y-%m-%d")
    user_limit = DAILY_CHAT_LIMITS.get(chat_id, (today_str, 0))
    if user_limit[0] != today_str:
        user_limit = (today_str, 0)
        
    if user_limit[1] >= 10:
        limit_msg = (
            "<b>Kunlik bepul chat limitingiz (10 ta xabar) tugadi!</b>\n\n"
            "Muloqotni davom ettirish uchun Mini App ni oching:"
        )
        web_app_full_url = f"{WEB_APP_URL}/?telegram_id={chat_id}&username={urllib.parse.quote(username)}&first_name={urllib.parse.quote(first_name)}"
        reply_markup = {
            "inline_keyboard": [
                [
                    {"text": "Mini App-ni ochish", "web_app": {"url": web_app_full_url}}
                ],
                [
                    {"text": "Qo'llab-quvvatlash", "url": "https://t.me/mr_sobirjanov"}
                ]
            ]
        }
        send_message(chat_id, limit_msg, reply_markup)
        return
        
    DAILY_CHAT_LIMITS[chat_id] = (today_str, user_limit[1] + 1)
    
    send_chat_action(chat_id, "typing")
    reply = call_chatbot_api(full_text)
    send_message(chat_id, reply)

# Long Polling Loop
offset = 0
print("Neuroinson Telegram Bot starting (VPS mode)...")
while True:
    url = f"{API_URL}getUpdates?offset={offset}&timeout=15"
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    try:
        with urllib.request.urlopen(req, timeout=25) as response:
            data = json.loads(response.read().decode('utf-8'))
            if data.get("result"):
                for update in data["result"]:
                    offset = update["update_id"] + 1
                    try:
                        process_update(update)
                    except Exception as e:
                        print(f"Error processing update: {e}")
    except Exception as e:
        print(f"Connection error in polling loop: {e}")
        time.sleep(2)
