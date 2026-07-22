"""
local_server.py — Neuroinson AI HTTP Server (Development & Production Ready)
Serves the web frontend and all API endpoints locally without Apache/PHP.
For production, ensure PYTHONUNBUFFERED=1 and use a reverse proxy (nginx/caddy).
This server is fully synchronized with the PHP backend in api/ directory.
"""
import http.server
import socketserver
import json
import os
import sqlite3
import random
import hashlib
import smtplib
import email.utils
import time
import urllib.request
import urllib.parse
from datetime import datetime, timedelta
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

PORT = 8000
DB_FILE = "database.db"

# Initialize SQLite database
def get_db():
    conn = sqlite3.connect(DB_FILE, timeout=10)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    return conn

def init_db():
    conn = sqlite3.connect(DB_FILE, timeout=10)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    conn.execute('''
        CREATE TABLE IF NOT EXISTS users (
            telegram_id INTEGER PRIMARY KEY,
            username TEXT DEFAULT '',
            balance INTEGER DEFAULT 15000,
            daily_chat_count INTEGER DEFAULT 0,
            last_chat_date TEXT DEFAULT '',
            google_id TEXT DEFAULT '',
            google_email TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    conn.execute('''
        CREATE TABLE IF NOT EXISTS email_otp_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            code TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            is_used INTEGER DEFAULT 0,
            attempt_count INTEGER DEFAULT 0
        )
    ''')
    conn.execute('''
        CREATE TABLE IF NOT EXISTS pending_topups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER,
            unique_amount INTEGER,
            base_amount INTEGER,
            status TEXT DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    # Add columns if they don't exist (migration)
    for col_data in [('google_id', 'TEXT DEFAULT ""'), ('google_email', 'TEXT DEFAULT ""'), ('daily_chat_count', 'INTEGER DEFAULT 0'), ('last_chat_date', 'TEXT DEFAULT ""'), ('status', 'TEXT DEFAULT "active"')]:
        col_name = col_data[0]
        col_def = col_data[1]
        try:
            conn.execute("ALTER TABLE users ADD COLUMN " + col_name + " " + col_def)
        except Exception:
            pass
    conn.execute('''
        CREATE TABLE IF NOT EXISTS api_usage_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email TEXT DEFAULT '',
            api_name TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            model_name TEXT DEFAULT '',
            prompt_length INTEGER DEFAULT 0,
            response_time_ms INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    conn.execute('''
        CREATE TABLE IF NOT EXISTS processed_video_requests (
            request_id TEXT PRIMARY KEY,
            telegram_id INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    conn.execute('''
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER,
            amount INTEGER DEFAULT 0,
            type TEXT DEFAULT 'spent',
            description TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    # Clean expired OTP codes (older than 1 day)
    conn.execute("DELETE FROM email_otp_codes WHERE expires_at < datetime('now', '-1 day')")
    # Clean old usage logs (older than 90 days)
    conn.execute("DELETE FROM api_usage_log WHERE created_at < datetime('now', '-90 days')")
    conn.commit()
    conn.close()

init_db()

def load_config():
    config_path = os.path.join(os.path.dirname(__file__), "config.json")
    if os.path.exists(config_path):
        with open(config_path, "r", encoding="utf-8") as f:
            return json.load(f)
    return {}

def log_api_usage(user_email, api_name, endpoint, model_name='', prompt_length=0, response_time_ms=0):
    try:
        conn = get_db()
        conn.execute("INSERT INTO api_usage_log (user_email, api_name, endpoint, model_name, prompt_length, response_time_ms) VALUES (?, ?, ?, ?, ?, ?)",
                     (user_email, api_name, endpoint, model_name, prompt_length, response_time_ms))
        conn.commit()
        conn.close()
    except Exception:
        pass

def log_transaction(telegram_id, amount, type_str, description=''):
    try:
        conn = get_db()
        conn.execute("INSERT INTO transactions (telegram_id, amount, type, description) VALUES (?, ?, ?, ?)",
                     (telegram_id, amount, type_str, description))
        conn.commit()
        conn.close()
    except Exception:
        pass

class LocalAPIHandler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        # Allow CORS
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(200, "OK")
        self.end_headers()

    def do_GET(self):
        parsed_url = urllib.parse.urlparse(self.path)
        path = parsed_url.path
        query = urllib.parse.parse_qs(parsed_url.query)

        # Endpoint: Get or Create User
        if path == "/api/get-user.php":
            telegram_id = int(query.get('telegram_id', [0])[0])
            username = query.get('username', [""])[0]

            if not telegram_id:
                self.send_error_json("telegram_id is required", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user:
                # Register new user with 10000 UZS balance
                cursor.execute("INSERT INTO users (telegram_id, username, balance) VALUES (?, ?, 10000)", (telegram_id, username))
                conn.commit()
                balance = 10000
            else:
                balance = user[2]

            conn.close()

            self.send_json({
                "telegram_id": telegram_id,
                "username": username,
                "balance": balance
            })
            return

        # ── Admin API Usage Stats ──
        elif path == "/api/admin-usage.php":
            admin_id = int(query.get('telegram_id', [0])[0])
            days = int(query.get('days', [7])[0])

            config = load_config()
            admin_ids = config.get("ADMIN_IDS", [799317334])
            if admin_id not in admin_ids:
                conn_check = get_db()
                cur_check = conn_check.cursor()
                cur_check.execute("SELECT google_email FROM users WHERE telegram_id=?", (admin_id,))
                admin_user = cur_check.fetchone()
                conn_check.close()
                if not admin_user or admin_user[0] != 'abdullajanovismoilxon@gmail.com':
                    self.send_error_json("Ruxsat yo'q", 403)
                    return

            conn = get_db()
            cursor = conn.cursor()

            # Usage by API
            cursor.execute("SELECT api_name, COUNT(*) as cnt, COALESCE(SUM(response_time_ms), 0) as total_ms FROM api_usage_log WHERE created_at >= datetime('now', ? || ' days') GROUP BY api_name ORDER BY cnt DESC", (f'-{days}',))
            by_api = [{"name": r[0], "count": r[1], "total_ms": r[2]} for r in cursor.fetchall()]

            # Usage by endpoint
            cursor.execute("SELECT endpoint, COUNT(*) as cnt FROM api_usage_log WHERE created_at >= datetime('now', ? || ' days') GROUP BY endpoint ORDER BY cnt DESC", (f'-{days}',))
            by_endpoint = [{"name": r[0], "count": r[1]} for r in cursor.fetchall()]

            # Usage by day (last 7 days)
            cursor.execute("""
                SELECT DATE(created_at) as day, COUNT(*) as cnt
                FROM api_usage_log
                WHERE created_at >= datetime('now', '-7 days')
                GROUP BY DATE(created_at)
                ORDER BY day ASC
            """)
            by_day = [{"day": r[0], "count": r[1]} for r in cursor.fetchall()]

            # Total API calls
            cursor.execute("SELECT COUNT(*) FROM api_usage_log WHERE created_at >= datetime('now', ? || ' days')", (f'-{days}',))
            total_calls = cursor.fetchone()[0]

            # Top users by API usage
            cursor.execute("SELECT user_email, COUNT(*) as cnt FROM api_usage_log WHERE created_at >= datetime('now', ? || ' days') AND user_email != '' GROUP BY user_email ORDER BY cnt DESC LIMIT 10", (f'-{days}',))
            top_users = [{"email": r[0], "count": r[1]} for r in cursor.fetchall()]

            conn.close()

            self.send_json({
                "status": "success",
                "total_calls": total_calls,
                "by_api": by_api,
                "by_endpoint": by_endpoint,
                "by_day": by_day,
                "top_users": top_users
            })
            return

        # ── Admin Stats ──
        elif path == "/api/admin-stats.php":
            admin_id = int(query.get('telegram_id', [0])[0])
            period = query.get('period', ['all'])[0]
            search = query.get('search', [''])[0]

            # Check admin (allow abdullajanovismoilxon@gmail.com)
            config = load_config()
            admin_ids = config.get("ADMIN_IDS", [799317334])
            if admin_id not in admin_ids:
                # Also check by email in DB
                conn_check = get_db()
                cur_check = conn_check.cursor()
                cur_check.execute("SELECT google_email FROM users WHERE telegram_id=?", (admin_id,))
                admin_user = cur_check.fetchone()
                conn_check.close()
                if not admin_user or admin_user[0] != 'abdullajanovismoilxon@gmail.com':
                    self.send_error_json("Ruxsat yo'q", 403)
                    return

            conn = get_db()
            cursor = conn.cursor()

            # Period filter
            period_where = ""
            if period == "today":
                period_where = "AND created_at >= datetime('now', 'start of day')"
            elif period == "week":
                period_where = "AND created_at >= datetime('now', '-7 days')"
            elif period == "month":
                period_where = "AND created_at >= datetime('now', '-30 days')"
            elif period == "year":
                period_where = "AND created_at >= datetime('now', '-365 days')"

            # Total users
            cursor.execute("SELECT COUNT(*) FROM users")
            total_users = cursor.fetchone()[0]

            # New users in period
            if period_where:
                cursor.execute("SELECT COUNT(*) FROM users WHERE 1=1 " + period_where)
                new_users = cursor.fetchone()[0]
            else:
                new_users = 0

            # Total balance
            cursor.execute("SELECT COALESCE(SUM(balance), 0) FROM users")
            total_balance = cursor.fetchone()[0]

            # MRR (total balance as proxy)
            cursor.execute("SELECT COALESCE(SUM(balance), 0) FROM users WHERE balance > 0")
            mrr = cursor.fetchone()[0]

            # Period topups
            cursor.execute("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type='topup' " + period_where)
            period_topups = cursor.fetchone()[0]

            # Period spent
            cursor.execute("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type='spent' " + period_where)
            period_spent = cursor.fetchone()[0]

            # Period expenses
            cursor.execute("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type='expense' " + period_where)
            period_expenses = cursor.fetchone()[0]

            net_profit = period_topups - period_expenses

            # Users list
            if search:
                cursor.execute("SELECT telegram_id, username, google_email, balance, created_at, COALESCE(status, 'active') as status, google_id FROM users WHERE username LIKE ? OR google_email LIKE ? OR CAST(telegram_id AS TEXT) LIKE ? ORDER BY created_at DESC LIMIT 50",
                               (f'%{search}%', f'%{search}%', f'%{search}%'))
            else:
                cursor.execute("SELECT telegram_id, username, google_email, balance, created_at, COALESCE(status, 'active') as status, google_id FROM users ORDER BY created_at DESC LIMIT 50")
            users = []
            for row in cursor.fetchall():
                tid = row[0]
                uname = row[1] or ''
                g_email = row[2] or ''
                bal = row[3] or 0
                c_at = row[4] or 'N/A'
                st = row[5] or 'active'
                gid = row[6] or ''
                
                auth_method = 'google' if (g_email or gid) else 'telegram'
                
                users.append({
                    "telegram_id": tid,
                    "username": uname,
                    "email": g_email,
                    "balance": bal,
                    "created_at": c_at,
                    "status": st,
                    "auth_method": auth_method
                })

            # Recent topups
            cursor.execute("SELECT t.telegram_id, t.amount, t.description, t.created_at, COALESCE(u.username, '') FROM transactions t LEFT JOIN users u ON t.telegram_id=u.telegram_id WHERE t.type='topup' ORDER BY t.created_at DESC LIMIT 20")
            recent_topups = [{"telegram_id": r[0], "amount": r[1], "description": r[2], "created_at": r[3], "username": r[4]} for r in cursor.fetchall()]

            # Platform expenses
            cursor.execute("SELECT id, amount, description, created_at FROM transactions WHERE type='expense' ORDER BY created_at DESC LIMIT 50")
            expenses = [{"id": r[0], "amount": r[1], "description": r[2], "created_at": r[3]} for r in cursor.fetchall()]

            conn.close()

            self.send_json({
                "status": "success",
                "stats": {
                    "total_users": total_users,
                    "new_users": new_users,
                    "total_balance": total_balance,
                    "mrr": mrr,
                    "period_topups": period_topups,
                    "period_spent": period_spent,
                    "period_expenses": period_expenses,
                    "net_profit": net_profit
                },
                "users": users,
                "recent_topups": recent_topups,
                "expenses": expenses
            })
            return

        # Fallback to serving static files
        super().do_GET()

    def do_POST(self):
        parsed_url = urllib.parse.urlparse(self.path)
        path = parsed_url.path

        content_length = int(self.headers.get('Content-Length', 0))
        post_data = self.rfile.read(content_length).decode('utf-8') if content_length else ""

        try:
            data = json.loads(post_data) if post_data else {}
        except Exception:
            data = {}

        config = load_config()

        # Endpoint: Topup Generate Invoice
        if path == "/api/topup.php":
            telegram_id = int(data.get('telegram_id', 0))
            amount = int(data.get('amount', 0))

            if not telegram_id or not amount:
                self.send_error_json("telegram_id and amount are required", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            
            # Clean expired topups
            cursor.execute("DELETE FROM pending_topups WHERE status='pending' AND created_at < datetime('now', '-15 minutes')")
            
            unique_amount = amount
            attempts = 0
            while attempts < 100:
                offset = random.randint(1, 99)
                test_amount = amount + offset
                cursor.execute("SELECT id FROM pending_topups WHERE unique_amount = ? AND status='pending'", (test_amount,))
                if not cursor.fetchone():
                    unique_amount = test_amount
                    break
                attempts += 1

            cursor.execute("INSERT INTO pending_topups (telegram_id, unique_amount, base_amount) VALUES (?, ?, ?)", 
                           (telegram_id, unique_amount, amount))
            conn.commit()
            conn.close()

            self.send_json({
                "status": "success",
                "unique_amount": unique_amount,
                "card_number": "8600 1234 5678 9012",
                "card_owner": "AI Student Hub",
                "expires_in_minutes": 15,
                "message": f"Iltimos, kartaga aniq {unique_amount:,} so'm o'tkazing. Hisobingiz avtomatik to'ldiriladi!"
            })
            return

        # Endpoint: Image Generation (Pollinations AI - with Auto-Translation for Ultra-Quality)
        elif path == "/api/generate-image.php":
            telegram_id = int(data.get('telegram_id', 0))
            prompt = data.get('prompt', "")
            price = int(data.get('price', 300))

            if not telegram_id or not prompt:
                self.send_error_json("telegram_id and prompt are required", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            cursor.execute("SELECT balance FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user or user[0] < price:
                conn.close()
                self.send_error_json("Balansda mablag' yetarli emas!", 400)
                return

            or_key = config.get("OPENROUTER_KEY", "")
            
            # 1. Auto-Translate & Enhance Prompt using OpenRouter Gemini 2.5 Flash for Ultra-Quality
            enhanced_prompt = prompt
            if or_key:
                try:
                    url_or = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={or_key}"
                    payload_or = json.dumps({
                        "system_instruction": {
                            "parts": {"text": "Siz mukammal rasm promptlari tuzuvchi mutaxassissiz. Foydalanuvchi yuborgan o'zbekcha yoki inglizcha qisqa matnni rasm generatori (Flux/Midjourney) tushunadigan, ingliz tilidagi o'ta batafsil, fotorealistik, cinematic, 8k va professional promptga o'giring. Faqat inglizcha promptni qaytaring, boshqa hech qanday izoh qo'shmang."}
                        },
                        "contents": [{
                            "parts": [{"text": prompt}]
                        }]
                    }).encode('utf-8')
                    req_or = urllib.request.Request(url_or, data=payload_or, headers={
                        'Content-Type': 'application/json'
                    })
                    with urllib.request.urlopen(req_or, timeout=15) as response_or:
                        res_or = json.loads(response_or.read().decode('utf-8'))
                        enhanced_prompt = res_or['candidates'][0]['content']['parts'][0]['text'].strip().strip('"')
                except Exception:
                    pass
            
            # 2. Call Pollinations AI
            encoded_prompt = urllib.parse.quote(enhanced_prompt)
            url = f"https://image.pollinations.ai/p/{encoded_prompt}?width=1024&height=1024&model=flux"
            
            try:
                req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
                with urllib.request.urlopen(req, timeout=30) as response:
                    img_bytes = response.read()
                
                # Deduct balance
                new_balance = user[0] - price
                cursor.execute("UPDATE users SET balance = ? WHERE telegram_id = ?", (new_balance, telegram_id))
                # Get user email for logging
                cursor.execute("SELECT google_email FROM users WHERE telegram_id=?", (telegram_id,))
                u = cursor.fetchone()
                user_email = u[0] if u else ''
                conn.commit()
                conn.close()
                log_api_usage(user_email, 'pollinations', 'image', 'flux', len(prompt))
                log_transaction(telegram_id, price, 'spent', f'Rasm yaratish')

                # Save locally
                output_dir = os.path.join(os.path.dirname(__file__), "output")
                os.makedirs(output_dir, exist_ok=True)
                filename = f"img_{int(datetime.now().timestamp())}_{random.randint(1000, 9999)}.jpg"
                with open(os.path.join(output_dir, filename), "wb") as f:
                    f.write(img_bytes)

                self.send_json({
                    "status": "success",
                    "image_url": f"output/{filename}",
                    "new_balance": new_balance
                })
            except Exception as e:
                conn.close()
                self.send_error_json(f"Rasm yaratib bo'lmadi: {str(e)}", 500)
            return

        # ── Email OTP: Send 6-digit code ──
        elif path == "/api/send-otp.php":
            email_addr = data.get('email', '').strip().lower()

            import re
            if not re.match(r'^[^@\s]+@[^@\s]+\.[^@\s]+$', email_addr):
                self.send_error_json("Noto'g'ri email format", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            # Rate limit: check last sent for this email in last 20s
            cursor.execute("SELECT MAX(created_at) FROM email_otp_codes WHERE email=? AND created_at > datetime('now', '-20 seconds')", (email_addr,))
            recent = cursor.fetchone()[0]
            if recent:
                conn.close()
                self.send_error_json("Iltimos, biroz kuting. Kod yuborish oralig'i 20 soniya.", 429)
                return

            # Generate 6-digit code
            code = str(random.randint(100000, 999999))
            expires_at = (datetime.now() + timedelta(minutes=5)).strftime('%Y-%m-%d %H:%M:%S')

            # Store in DB
            cursor.execute("INSERT INTO email_otp_codes (email, code, expires_at) VALUES (?, ?, ?)",
                           (email_addr, code, expires_at))
            conn.commit()

            # Build HTML email body
            html_body = f"""\
<!DOCTYPE html><html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#1a1a1f;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#1a1a1f;padding:40px 20px;">
<tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="max-width:480px;">
<tr><td style="text-align:center;padding-bottom:8px;">
<span style="color:#7C6FF0;font-size:26px;font-weight:800;letter-spacing:1px;">NEUROINSON AI</span>
</td></tr>
<tr><td style="text-align:center;padding-bottom:30px;">
<span style="color:#A1A1AA;font-size:14px;letter-spacing:2px;text-transform:uppercase;">Sun'iy Intellekt Platformasi</span>
</td></tr>
<tr><td style="background:#0D0D10;border-radius:16px;padding:40px 32px;text-align:center;">
<h2 style="color:#F4F4F5;font-size:18px;margin:0 0 20px 0;font-weight:600;">Hisobingizga kirish uchun<br>tasdiqlash kodi</h2>
<div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#7C6FF0;background:rgba(124,111,240,0.08);border-radius:12px;padding:20px 16px;margin:0 0 24px 0;font-family:monospace;">{code}</div>
<p style="color:#71717A;font-size:13px;line-height:1.6;margin:0;">
Ushbu kod <strong style="color:#A78BFA;">5 daqiqa</strong> davomida amal qiladi.<br>
Kodni hech kimga bermang.
</p>
</td></tr>
<tr><td style="text-align:center;padding-top:24px;">
<span style="color:#52525B;font-size:11px;">&copy; 2026 Neuroinson AI. Barcha huquqlar himoyalangan.</span>
</td></tr>
</table></td></tr></table></body></html>"""

            # Send email via SMTP using config
            smtp_ok = False
            smtp_err_msg = ""
            try:
                smtp_host = load_config().get("SMTP_HOST", "smtp.gmail.com")
                smtp_port = int(load_config().get("SMTP_PORT", 587))
                smtp_user = load_config().get("SMTP_USER", "")
                smtp_pass = load_config().get("SMTP_PASS", "")

                if smtp_user and smtp_pass:
                    msg = MIMEMultipart('alternative')
                    msg['Subject'] = "Neuroinson AI — Tasdiqlash kodi"
                    msg['From'] = email.utils.formataddr(("Neuroinson AI", smtp_user))
                    msg['To'] = email_addr
                    msg['Date'] = email.utils.formatdate()
                    msg.attach(MIMEText(html_body, 'html', 'utf-8'))

                    server = smtplib.SMTP(smtp_host, smtp_port, timeout=12)
                    server.starttls()
                    server.login(smtp_user, smtp_pass)
                    server.sendmail(smtp_user, [email_addr], msg.as_string())
                    server.quit()
                    smtp_ok = True
                else:
                    smtp_err_msg = "SMTP foydalanuvchi ma'lumotlari sozlanmagan"
            except Exception as e:
                smtp_err_msg = str(e)
                print(f"[SMTP ERROR] Failed to send email to {email_addr}: {e}")

            conn.close()

            if not smtp_ok:
                self.send_error_json(f"Kod yuborishda SMTP xatolik yuz berdi: {smtp_err_msg}", 500)
                return

            self.send_json({
                "status": "success",
                "message": "Kod emailingizga yuborildi. (Spam papkasini ham tekshiring!)",
                "email": email_addr,
                "expires_in": 300,
                "smtp_configured": True
            })
            return

        # ── Email OTP: Verify code ──
        elif path == "/api/verify-otp.php":
            email_addr = data.get('email', '').strip().lower()
            code = data.get('code', '').strip()

            if not email_addr or not code:
                self.send_error_json("Email va kod talab qilinadi", 400)
                return

            try:
                conn = get_db()
                cursor = conn.cursor()

                cursor.execute("""SELECT id, code, expires_at, is_used, attempt_count FROM email_otp_codes
                                  WHERE email=? ORDER BY created_at DESC LIMIT 1""", (email_addr,))
                row = cursor.fetchone()

                if not row:
                    conn.close()
                    self.send_error_json("Kod topilmadi. Avval kod so'rang.", 404)
                    return

                otp_id, stored_code, expires_at, is_used, attempt_count = row
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

                if is_used:
                    conn.close()
                    self.send_error_json("Bu kod allaqachon ishlatilgan. Yangi kod so'rang.", 400)
                    return

                if expires_at < now:
                    conn.close()
                    self.send_error_json("Kod muddati tugagan. Yangi kod so'rang.", 400)
                    return

                if attempt_count >= 5:
                    cursor.execute("UPDATE email_otp_codes SET is_used=1 WHERE id=?", (otp_id,))
                    conn.commit()
                    conn.close()
                    self.send_error_json("5 marta noto'g'ri urinish. Kod bekor qilindi. Yangi kod so'rang.", 400)
                    return

                if code != stored_code:
                    cursor.execute("UPDATE email_otp_codes SET attempt_count=attempt_count+1 WHERE id=?", (otp_id,))
                    conn.commit()
                    conn.close()
                    self.send_error_json(f"Noto'g'ri kod. {4 - attempt_count} ta urinish qoldi.", 400)
                    return

                # Code correct — mark used
                cursor.execute("UPDATE email_otp_codes SET is_used=1 WHERE id=?", (otp_id,))

                # Find or create user by email
                safe_id = abs(int(hashlib.md5(email_addr.encode()).hexdigest(), 16)) % (10**12)
                cursor.execute("SELECT telegram_id FROM users WHERE google_email=? OR telegram_id=?", (email_addr, safe_id))
                existing = cursor.fetchone()

                if existing:
                    user_id = existing[0]
                else:
                    user_id = safe_id
                    username = email_addr.split('@')[0]
                    cursor.execute("INSERT INTO users (telegram_id, username, balance, google_email) VALUES (?, ?, 10000, ?)",
                                   (user_id, username, email_addr))

                cursor.execute("SELECT balance FROM users WHERE telegram_id=?", (user_id,))
                balance = cursor.fetchone()[0]
                conn.commit()
                conn.close()

                self.send_json({
                    "status": "success",
                    "telegram_id": user_id,
                    "username": email_addr.split('@')[0],
                    "balance": balance,
                    "email": email_addr
                })
                return

            except Exception as e:
                try:
                    conn.close()
                except Exception:
                    pass
                self.send_error_json(f"Server xatolik: {str(e)}", 500)
                return

        # ── Google OAuth Auth ──
        elif path == "/api/google-auth.php":
            id_token = data.get('id_token', '').strip()
            if not id_token:
                self.send_error_json("id_token talab qilinadi", 400)
                return

            # Verify token with Google
            google_ok = False
            google_info = None
            try:
                verify_url = f"https://oauth2.googleapis.com/tokeninfo?id_token={id_token}"
                req = urllib.request.Request(verify_url, headers={'User-Agent': 'Mozilla/5.0'})
                with urllib.request.urlopen(req, timeout=10) as resp:
                    google_info = json.loads(resp.read().decode('utf-8'))
                    if google_info.get('email_verified') == 'true' or google_info.get('email_verified') is True:
                        google_ok = True
            except Exception:
                pass

            if not google_ok or not google_info:
                self.send_error_json("Google tokenni tasdiqlab bo'lmadi.", 401)
                return

            google_id = google_info.get('sub', '')
            email_addr = google_info.get('email', '').lower()
            name = google_info.get('name', email_addr.split('@')[0] if email_addr else 'User')

            try:
                conn = get_db()
                cursor = conn.cursor()

                # Check existing user by google_id or email
                cursor.execute("SELECT * FROM users WHERE google_id=? OR google_email=?", (google_id, email_addr))
                user = cursor.fetchone()

                if user:
                    user_id = user[0]
                    balance = user[2]
                    # Link google_id if not set
                    if not user[9]:  # google_id column index
                        cursor.execute("UPDATE users SET google_id=?, google_email=? WHERE telegram_id=?", (google_id, email_addr, user_id))
                else:
                    user_id = abs(int(hashlib.md5((google_id + email_addr).encode()).hexdigest(), 16)) % (10**12)
                    cursor.execute("INSERT INTO users (telegram_id, username, balance, google_id, google_email) VALUES (?, ?, 10000, ?, ?)",
                                   (user_id, name, google_id, email_addr))
                    balance = 10000

                conn.commit()
                conn.close()

                self.send_json({
                    "status": "success",
                    "telegram_id": user_id,
                    "username": name,
                    "balance": balance,
                    "email": email_addr
                })
                return

            except Exception as e:
                try:
                    conn.close()
                except Exception:
                    pass
                self.send_error_json(f"Server xatolik: {str(e)}", 500)
                return

        # Endpoint: AI Chatbot (OpenRouter Gemini 2.5 Flash with Adaptive Tone)
        elif path == "/api/chat.php":
            telegram_id = int(data.get('telegram_id', 0))
            prompt = data.get('prompt', "")
            chat_type = data.get('chat_type', "antigravity")

            if not telegram_id or not prompt:
                self.send_error_json("telegram_id and prompt are required", 400)
                return

            conn = get_db()
            cursor = conn.cursor()

            today_str = datetime.now().strftime('%Y-%m-%d')
            
            try:
                cursor.execute("SELECT daily_prompt_count FROM users LIMIT 1")
            except:
                cursor.execute("ALTER TABLE users ADD COLUMN daily_prompt_count INTEGER DEFAULT 0")
                conn.commit()

            cursor.execute("SELECT balance, daily_chat_count, last_chat_date, daily_prompt_count FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user:
                conn.close()
                self.send_error_json("Foydalanuvchi topilmadi!", 400)
                return

            balance = user[0]
            daily_chat_count = user[1]
            last_chat_date = user[2]
            daily_prompt_count = user[3] if len(user) > 3 else 0

            # Check columns in users table
            try:
                cursor.execute("SELECT project_chat_remaining, active_project_type FROM users LIMIT 1")
            except:
                try: cursor.execute("ALTER TABLE users ADD COLUMN project_chat_remaining INTEGER DEFAULT 0")
                except: pass
                try: cursor.execute("ALTER TABLE users ADD COLUMN active_project_type TEXT DEFAULT ''")
                except: pass
                conn.commit()

            cursor.execute("SELECT balance, daily_chat_count, last_chat_date, daily_prompt_count, project_chat_remaining, active_project_type FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user:
                conn.close()
                self.send_error_json("Foydalanuvchi topilmadi!", 400)
                return

            balance = user[0]
            daily_chat_count = user[1]
            last_chat_date = user[2]
            daily_prompt_count = user[3] if len(user) > 3 else 0
            project_chat_remaining = user[4] if len(user) > 4 else 0
            active_project_type = user[5] if len(user) > 5 else ''

            if last_chat_date != today_str:
                daily_chat_count = 0
                daily_prompt_count = 0

            project_price = int(data.get('project_price', 0))

            if chat_type == 'antigravity':
                cost = 0 if daily_chat_count < 10 else 50
                new_daily_chat_count = daily_chat_count + 1
                new_daily_prompt_count = daily_prompt_count
            elif chat_type == 'project-chat':
                if project_price > 0:
                    cost = project_price
                else:
                    if project_chat_remaining <= 0:
                        conn.close()
                        self.send_error_json("Ushbu loyiha uchun suhbat limitlaringiz tugadi! Davom ettirish uchun loyihani qayta xarid qiling.", 400)
                        return
                    cost = 0
                new_daily_chat_count = daily_chat_count
                new_daily_prompt_count = daily_prompt_count
            else:
                cost = 0 if daily_prompt_count < 10 else 200
                new_daily_prompt_count = daily_prompt_count + 1
                new_daily_chat_count = daily_chat_count

            if balance < cost:
                conn.close()
                self.send_error_json(f"Balansda mablag' yetarli emas! Kerakli miqdor: {cost} so'm.", 400)
                return

            or_key = config.get("OPENROUTER_KEY", "")
            if not or_key:
                conn.close()
                self.send_error_json("Server API token not configured.", 500)
                return

            # Setup system prompt
            current_date_info = "Bugungi sana: 2026-yil 7-iyul. AQSH prezidenti — Donald Trump."

            if chat_type == 'antigravity':
                system_prompt = (f"Siz 'Google Antigravity Student Hub' loyihasining o'ta aqlli va do'stona AI yordamchisiz.\n"
                                 f"Foydalanuvchi — o'zbekistonlik talaba yoki yosh dasturchi.\n\n"
                                 f"Kontekst: {current_date_info}")
            elif chat_type == 'project-chat':
                project_title = data.get('project_name') or active_project_type or "Loyiha"
                project_restriction = (f"DIQQAT: Foydalanuvchi premium darajada faqatgina '{project_title}' loyihasini sotib olgan. "
                                       f"Siz faqatgina ushbu loyihaga tegishli buyruqlarni bajarishingiz shart! "
                                       f"Agar foydalanuvchi boshqa turdagi loyihani so'rasa, unga faqat o'zining '{project_title}' loyihasi bo'yicha yordam bera olishingizni muloyimlik bilan eslatib o'ting va boshqa aloqasiz loyihani bajarishni rad eting!\n")
                system_prompt = (f"Siz 'Neuroinson Project Editor AI' (Gemini Pro Premium) mutaxassisiz.\n"
                                 f"Siz foydalanuvchi sotib olgan premium loyiha ustida professional yordam berasiz.\n"
                                 f"{project_restriction}\n"
                                 f"Javoblaringizni doimo chiroyli o'zbek tilida yozing. Kodlarni `<code>` yoki tegishli dasturlash tili bloklarida (`python`, `html`, `css`) formatlang.\n\n"
                                 f"Kontekst: {current_date_info}")
            else:
                system_prompt = (f"Siz 'DeepInfra Prompt Assistant' mutaxassisiz.\n"
                                 f"Foydalanuvchi yuborgan o'zbekcha g'oyani Midjourney/FLUX uchun inglizcha mukammal promptga (`<code>` formatida) aylantirib bering. "
                                 f"\n\nKontekst: {current_date_info}")

            url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={or_key}"
            payload = json.dumps({
                "system_instruction": {
                    "parts": {"text": system_prompt}
                },
                "contents": [{
                    "parts": [{"text": prompt}]
                }]
            }).encode('utf-8')
            headers = {
                'Content-Type': 'application/json'
            }

            req = urllib.request.Request(url, data=payload, headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=40) as response:
                    res = json.loads(response.read().decode('utf-8'))
                
                reply = res['candidates'][0]['content']['parts'][0]['text'].strip()
                new_balance = balance - cost

                file_url = None
                file_label = None

                if chat_type == 'project-chat':
                    proj_name_saved = data.get('project_name') or active_project_type or "Loyiha"
                    p_title_lower = proj_name_saved.lower()
                    try:
                        import file_generator
                        if "taqdimot" in p_title_lower or "pptx" in p_title_lower:
                            file_url = file_generator.generate_pptx_file(reply, telegram_id)
                            file_label = "📥 Taqdimot Faylini Yuklab Olish (.pptx)"
                        elif "kurs" in p_title_lower or "mustaqil" in p_title_lower:
                            file_url = file_generator.generate_docx_file(reply, telegram_id)
                            file_label = "📥 Kurs Ishi Hujjatini Yuklab Olish (.docx)"
                        elif "web app" in p_title_lower or "sayt" in p_title_lower:
                            file_url = file_generator.generate_webapp_zip(reply, telegram_id)
                            file_label = "📥 Sayt Kodini Yuklab Olish (.zip)"
                        elif "bot" in p_title_lower:
                            file_url = file_generator.generate_bot_zip(reply, telegram_id)
                            file_label = "📥 Telegram Bot Loyihasini Yuklab Olish (.zip)"
                    except Exception as gen_err:
                        print("[FILE GEN ERR]", gen_err)

                    if cost > 0:
                        new_project_remaining = 50
                        cursor.execute("UPDATE users SET balance = ?, project_chat_remaining = ?, active_project_type = ? WHERE telegram_id = ?",
                                       (new_balance, new_project_remaining, proj_name_saved, telegram_id))
                    else:
                        new_project_remaining = max(0, project_chat_remaining - 1)
                        cursor.execute("UPDATE users SET balance = ?, project_chat_remaining = ? WHERE telegram_id = ?",
                                       (new_balance, new_project_remaining, telegram_id))
                else:
                    new_project_remaining = project_chat_remaining
                    cursor.execute("UPDATE users SET balance = ?, daily_chat_count = ?, daily_prompt_count = ?, last_chat_date = ? WHERE telegram_id = ?", 
                                   (new_balance, new_daily_chat_count, new_daily_prompt_count, today_str, telegram_id))

                cursor.execute("SELECT google_email FROM users WHERE telegram_id=?", (telegram_id,))
                u = cursor.fetchone()
                user_email = u[0] if u else ''
                conn.commit()
                conn.close()

                log_api_usage(user_email, 'openrouter', 'chat', 'google/gemini-1.5-pro', len(prompt))
                if cost > 0:
                    log_transaction(telegram_id, cost, 'spent', f'Loyiha ({chat_type})')

                res_obj = {
                    "status": "success",
                    "reply": reply,
                    "new_balance": new_balance,
                    "daily_remaining": max(0, 10 - new_daily_chat_count) if chat_type == 'antigravity' else None,
                    "project_chat_remaining": new_project_remaining if chat_type == 'project-chat' else (max(0, 10 - new_daily_prompt_count) if chat_type != 'antigravity' else None)
                }
                if file_url:
                    res_obj["file_url"] = file_url
                    res_obj["file_label"] = file_label

                self.send_json(res_obj)
            except Exception as e:
                conn.close()
                self.send_error_json(f"AI ulanishda xatolik: {str(e)}", 500)
            return

        # ── Video Generation: Submit job ──
        elif path == "/api/submit-video.php":
            telegram_id = int(data.get('telegram_id', 0))
            prompt = str(data.get('prompt', "")).strip()
            price = int(data.get('price', 3333))
            aspect_ratio = str(data.get('aspect_ratio', '16:9')).strip()

            VIDEO_TIERS = {3333: 'Standart', 7777: 'Premium', 14999: 'Biznes'}
            if price not in VIDEO_TIERS:
                self.send_error_json(f"Noto'g'ri narx. Mavjud narxlar: {', '.join(map(str, VIDEO_TIERS.keys()))}", 400)
                return

            if not telegram_id or not prompt:
                self.send_error_json("telegram_id va prompt kiritilishi shart", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            cursor.execute("SELECT balance FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user or user[0] < price:
                conn.close()
                self.send_error_json(f"Balansda mablag' yetarli emas! Kerakli: {price} so'm", 400)
                return

            fal_key = config.get("FAL_KEY", "")
            if not fal_key:
                conn.close()
                self.send_error_json("Fal.ai API kaliti topilmadi.", 500)
                return

            if price == 14999:
                model_path = "/fal-ai/kling-video/v1.6/pro/text-to-video"
                tier = "Biznes (Kling 1.6 Pro)"
            elif price == 7777:
                model_path = "/fal-ai/kling-video/v1.6/standard/text-to-video"
                tier = "Premium (Kling 1.6 Standard)"
            else:
                model_path = "/fal-ai/ltx-video"
                tier = "Standart (LTX Video)"

            payload_args = {"prompt": prompt}
            if "kling" in model_path:
                payload_args["aspect_ratio"] = "9:16" if aspect_ratio == "9:16" else ("1:1" if aspect_ratio == "1:1" else "16:9")
            else:
                payload_args["image_size"] = "portrait_16_9" if aspect_ratio == "9:16" else ("square" if aspect_ratio == "1:1" else "landscape_16_9")

            queue_url = f"https://queue.fal.run{model_path}"
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Key {fal_key}',
                'User-Agent': 'Mozilla/5.0'
            }

            req = urllib.request.Request(queue_url, data=json.dumps(payload_args).encode('utf-8'), headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=30) as response:
                    res = json.loads(response.read().decode('utf-8'))

                request_id = res.get('request_id', '')
                status_url = res.get('status_url', '')
                response_url = res.get('response_url', '')

                if request_id:
                    cursor.execute("SELECT google_email FROM users WHERE telegram_id=?", (telegram_id,))
                    u = cursor.fetchone()
                    u_email = u[0] if u else ''
                    conn.close()
                    log_api_usage(u_email, 'fal', 'video', model_path, len(prompt))

                    self.send_json({
                        "status": "submitted",
                        "request_id": request_id,
                        "status_url": status_url,
                        "response_url": response_url,
                        "model_path": model_path,
                        "new_balance": user[0],
                        "tier": tier,
                        "price": price,
                        "telegram_id": telegram_id
                    })
                else:
                    conn.close()
                    self.send_error_json("Navbatga qo'shib bo'lmadi. Fal.ai javobi mavjud emas.", 500)
            except Exception as e:
                conn.close()
                err_msg = str(e)
                if "403" in err_msg or "locked" in err_msg.lower() or "401" in err_msg:
                    self.send_error_json("Fal.ai API kaliti yoki balansida muammo bo'ldi. Kalitni to'ldiring.", 400)
                else:
                    self.send_error_json(f"Fal.ai xatolik: {err_msg}", 500)
            return

        # ── Video Generation: Check status ──
        elif path == "/api/check-video.php":
            request_id = str(data.get('request_id', '')).strip()
            status_url = str(data.get('status_url', '')).strip()
            response_url = str(data.get('response_url', '')).strip()
            model_path = str(data.get('model_path', '')).strip()
            telegram_id = int(data.get('telegram_id', 0))
            price = int(data.get('price', 0))
            tier = str(data.get('tier', 'Video')).strip()

            if not request_id:
                self.send_error_json("request_id required", 400)
                return

            fal_key = config.get("FAL_KEY", "")
            headers = {
                'Authorization': f'Key {fal_key}',
                'User-Agent': 'Mozilla/5.0'
            }

            if not response_url and status_url:
                response_url = status_url.replace('/status', '')
            elif not response_url and model_path:
                response_url = f"https://queue.fal.run{model_path}/requests/{request_id}"

            if not status_url and model_path:
                status_url = f"https://queue.fal.run{model_path}/requests/{request_id}/status"

            try:
                req = urllib.request.Request(status_url, headers=headers)
                with urllib.request.urlopen(req, timeout=15) as resp:
                    s_body = resp.read().decode('utf-8')
                    s_code = resp.status
                s = json.loads(s_body) if s_body else {}
            except Exception as e:
                self.send_json({"status": "pending", "message": f"Tekshirilmoqda... ({str(e)})"})
                return

            status = str(s.get('status', '')).lower()
            qpos = s.get('queue_position', None)

            if status in ['in_queue', 'queued', '']:
                msg = "Neuroinson navbatda kutilmoqda... ⏳"
                if qpos is not None and qpos > 0:
                    msg = f"Neuroinson: {qpos} ta oldinda ⏳"
                self.send_json({"status": "pending", "message": msg, "fal_status": status})
                return

            if status == 'completed':
                try:
                    req2 = urllib.request.Request(response_url, headers=headers)
                    with urllib.request.urlopen(req2, timeout=20) as resp2:
                        r_body = resp2.read().decode('utf-8')
                    r = json.loads(r_body) if r_body else {}
                    video_url = (
                        r.get('video', {}).get('url') or
                        (r.get('videos', [{}])[0].get('url') if r.get('videos') else None) or
                        r.get('output', {}).get('video', {}).get('url') or
                        r.get('output', {}).get('video') or
                        r.get('url') or ""
                    )
                    if video_url:
                        conn = get_db()
                        cursor = conn.cursor()
                        cursor.execute("SELECT balance FROM users WHERE telegram_id=?", (telegram_id,))
                        usr = cursor.fetchone()

                        cursor.execute("SELECT 1 FROM processed_video_requests WHERE request_id=?", (request_id,))
                        if cursor.fetchone():
                            conn.close()
                            self.send_json({"status": "done", "video_url": video_url, "new_balance": usr[0] if usr else 0})
                            return

                        if usr and usr[0] >= price:
                            new_bal = usr[0] - price
                            cursor.execute("UPDATE users SET balance = balance - ? WHERE telegram_id = ?", (price, telegram_id))
                            cursor.execute("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'video', ?, ?)", (telegram_id, -price, f"Video yaratish ({tier})"))
                            cursor.execute("INSERT OR IGNORE INTO processed_video_requests (request_id, telegram_id) VALUES (?, ?)", (request_id, telegram_id))
                            conn.commit()
                            conn.close()
                            self.send_json({"status": "done", "video_url": video_url, "new_balance": new_bal})
                        else:
                            conn.close()
                            self.send_json({"status": "done", "video_url": video_url, "new_balance": usr[0] if usr else 0})
                        return
                    else:
                        self.send_json({"status": "error", "message": "Video URL topilmadi."})
                        return
                except Exception as e:
                    self.send_json({"status": "error", "message": f"Natija olishda xatolik: {str(e)}"})
                    return
            elif status in ['failed', 'error']:
                err = s.get('error', {}).get('message') or s.get('error') or "Noma'lum xatolik"
                self.send_json({"status": "error", "message": f"Video yaratishda xatolik. Pul yechilmadi. ({err})"})
                return
            else:
                msg = "Neuroinson video render qilmoqda... 🎬" if status == 'in_progress' else "Neuroinson video yaratmoqda... ⏳"
                self.send_json({"status": "pending", "message": msg, "fal_status": status})
                return

        # ── Endpoint: Video Generation Fallback ──
        elif path == "/api/generate-video.php":
            telegram_id = int(data.get('telegram_id', 0))
            prompt = data.get('prompt', "")
            price = int(data.get('price', 1500))

            if not telegram_id or not prompt:
                self.send_error_json("telegram_id and prompt are required", 400)
                return

            conn = get_db()
            cursor = conn.cursor()
            cursor.execute("SELECT balance FROM users WHERE telegram_id = ?", (telegram_id,))
            user = cursor.fetchone()

            if not user or user[0] < price:
                conn.close()
                self.send_error_json("Balansda mablag' yetarli emas!", 400)
                return

            fal_key = config.get("FAL_KEY", "")
            if not fal_key:
                conn.close()
                self.send_error_json("Server API token not configured.", 500)
                return

            url = "https://queue.fal.run/fal-ai/ltx-video"
            payload = json.dumps({"prompt": prompt}).encode('utf-8')
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Key {fal_key}',
                'User-Agent': 'Mozilla/5.0'
            }

            req = urllib.request.Request(url, data=payload, headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=60) as response:
                    res = json.loads(response.read().decode('utf-8'))
                
                video_url = res.get('video', {}).get('url', "")
                if video_url:
                    new_balance = user[0] - price
                    cursor.execute("UPDATE users SET balance = ? WHERE telegram_id = ?", (new_balance, telegram_id))
                    cursor.execute("SELECT google_email FROM users WHERE telegram_id=?", (telegram_id,))
                    u = cursor.fetchone()
                    user_email = u[0] if u else ''
                    conn.commit()
                    conn.close()
                    log_api_usage(user_email, 'fal', 'video', 'fal-ai/ltx-video', len(prompt))
                    log_transaction(telegram_id, price, 'spent', f'Video yaratish')

                    self.send_json({
                        "status": "success",
                        "video_url": video_url,
                        "new_balance": new_balance
                    })
                else:
                    conn.close()
                    self.send_error_json("Video yaratishda xatolik yuz berdi.", 500)
            except Exception as e:
                conn.close()
                err_msg = str(e)
                if "403" in err_msg or "locked" in err_msg.lower():
                    self.send_error_json("Fal.ai API kalitida balans tugaganligi sababli video yaratib bo'lmadi. Kalitni to'ldiring.", 400)
                else:
                    self.send_error_json(f"Video xizmatida xatolik: {err_msg}", 500)
            return

        # ── Admin Action (CRUD + broadcast) ──
        elif path == "/api/admin-action.php":
            try:
                action = data.get('action', '')
                admin_id = int(data.get('admin_id', 0))

                cfg = load_config()
                admin_ids = cfg.get("ADMIN_IDS", [799317334])
                # Check admin
                if admin_id not in admin_ids:
                    conn_check = get_db()
                    cur_check = conn_check.cursor()
                    cur_check.execute("SELECT google_email FROM users WHERE telegram_id=?", (admin_id,))
                    admin_user = cur_check.fetchone()
                    conn_check.close()
                    if not admin_user or admin_user[0] != 'abdullajanovismoilxon@gmail.com':
                        self.send_error_json("Ruxsat yo'q", 403)
                        return

                conn = get_db()
                cursor = conn.cursor()

                if action == 'update_user':
                    target_id = int(data.get('target_id', 0))
                    username = str(data.get('username', '')).strip()
                    new_email = str(data.get('email', '')).strip()
                    balance = data.get('balance', 0)
                    status = str(data.get('status', 'active')).strip()

                    try:
                        balance = int(balance)
                    except ValueError:
                        self.send_error_json("Balans to'g'ri son bo'lishi kerak", 400)
                        conn.close()
                        return

                    if not username:
                        self.send_error_json("Foydalanuvchi nomi bo'sh bo'lishi mumkin emas!", 400)
                        conn.close()
                        return

                    if new_email and ('@' not in new_email or '.' not in new_email):
                        self.send_error_json("Email formati noto'g'ri!", 400)
                        conn.close()
                        return

                    if balance < 0:
                        self.send_error_json("Balans manfiy bo'lishi mumkin emas!", 400)
                        conn.close()
                        return

                    if status not in ['active', 'blocked', 'warned']:
                        status = 'active'

                    cursor.execute("UPDATE users SET username=?, google_email=?, balance=?, status=? WHERE telegram_id=?",
                                   (username, new_email, balance, status, target_id))
                    conn.commit()
                    conn.close()
                    self.send_json({"status": "success", "message": "Foydalanuvchi ma'lumotlari muvaffaqiyatli saqlandi!"})

                elif action == 'adjust_balance':
                    target_id = int(data.get('target_id', 0))
                    amount = int(data.get('amount', 0))
                    reason = data.get('reason', '')
                    cursor.execute("UPDATE users SET balance = balance + ? WHERE telegram_id=?", (amount, target_id))
                    conn.commit()
                    if cursor.rowcount > 0:
                        ttype = 'topup' if amount > 0 else 'spent'
                        action_label = "to'ldirildi" if amount > 0 else "yechildi"
                        log_transaction(target_id, abs(amount), ttype, reason or f"Admin tomonidan {action_label}")
                    conn.close()
                    self.send_json({"status": "success", "message": "Balans o'zgartirildi"})

                elif action == 'delete_user':
                    target_id = int(data.get('target_id', 0))
                    cursor.execute("DELETE FROM users WHERE telegram_id=?", (target_id,))
                    conn.commit()
                    conn.close()
                    self.send_json({"status": "success", "message": "Foydalanuvchi o'chirildi"})

                elif action == 'add_expense':
                    amount = int(data.get('amount', 0))
                    desc = data.get('description', 'Xarajat')
                    cursor.execute("INSERT INTO transactions (telegram_id, amount, type, description) VALUES (?, ?, 'expense', ?)", (admin_id, amount, desc))
                    conn.commit()
                    conn.close()
                    self.send_json({"status": "success", "message": "Xarajat qo'shildi"})

                elif action == 'delete_expense':
                    exp_id = int(data.get('expense_id', 0))
                    cursor.execute("DELETE FROM transactions WHERE id=? AND type='expense'", (exp_id,))
                    conn.commit()
                    conn.close()
                    self.send_json({"status": "success", "message": "Xarajat o'chirildi"})

                elif action == 'broadcast':
                    message = data.get('message', '')
                    cursor.execute("SELECT telegram_id FROM users")
                    all_users = cursor.fetchall()
                    conn.close()
                    self.send_json({"status": "success", "message": f"Xabar {len(all_users)} foydalanuvchiga yuborildi (local mode: faqat log)", "count": len(all_users)})

                else:
                    conn.close()
                    self.send_error_json("Noma'lum action", 400)
            except Exception as e:
                try:
                    conn.close()
                except Exception:
                    pass
                self.send_error_json(f"Server xatolik: {str(e)}", 500)
                return
            return

        self.send_error_json(f"Noma'lum POST so'rov: {path}", 404)

    def send_json(self, data):
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(data).encode('utf-8'))

    def send_error_json(self, message, status=400):
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"error": message}).encode('utf-8'))

if __name__ == '__main__':
    # Go to script directory to serve its files
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    
    # Create output folder locally
    os.makedirs("output", exist_ok=True)
    
    with socketserver.TCPServer(("", PORT), LocalAPIHandler) as httpd:
        print(f"[SERVER] Local Server running at http://localhost:{PORT}")
        print("Menga ulanish uchun brauzeringizda http://localhost:8000 sahifasini oching!")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nShutting down local server.")
