<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db_file = __DIR__ . "/../database.db";

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        telegram_id INTEGER PRIMARY KEY,
        username TEXT DEFAULT '',
        balance INTEGER DEFAULT 10000,
        daily_chat_count INTEGER DEFAULT 0,
        last_chat_date TEXT DEFAULT '',
        daily_prompt_count INTEGER DEFAULT 0,
        extra_prompt_limits INTEGER DEFAULT 0,
        last_prompt_date TEXT DEFAULT '',
        project_chat_remaining INTEGER DEFAULT 0,
        active_project_type TEXT DEFAULT '',
        google_id TEXT DEFAULT '',
        google_email TEXT DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS pending_topups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id INTEGER NOT NULL,
        unique_amount INTEGER NOT NULL,
        base_amount INTEGER NOT NULL,
        status TEXT DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS paid_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pay_id TEXT UNIQUE NOT NULL,
        telegram_id INTEGER NOT NULL,
        amount INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        amount INTEGER NOT NULL,
        description TEXT DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (telegram_id) REFERENCES users(telegram_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS processed_video_requests (
        request_id TEXT PRIMARY KEY,
        telegram_id INTEGER NOT NULL,
        status TEXT DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        ip TEXT,
        endpoint TEXT,
        hits INTEGER DEFAULT 1,
        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ip, endpoint)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS email_otp_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        code TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        is_used INTEGER DEFAULT 0,
        attempt_count INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS platform_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        description TEXT NOT NULL,
        amount INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS api_usage_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_email TEXT DEFAULT '',
        api_name TEXT NOT NULL,
        endpoint TEXT NOT NULL,
        model_name TEXT DEFAULT '',
        prompt_length INTEGER DEFAULT 0,
        response_time_ms INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_user ON transactions(telegram_id, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topups_status ON pending_topups(status, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topups_user ON pending_topups(telegram_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_paid_pay_id ON paid_transactions(pay_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_created ON api_usage_log(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_api ON api_usage_log(api_name)");
} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}
