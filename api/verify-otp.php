<?php
require_once __DIR__ . "/db.php";

$input = json_decode(file_get_contents("php://input"), true);
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$code  = isset($input['code']) ? trim($input['code']) : '';

if (!$email || !$code) {
    http_response_code(400);
    echo json_encode(["error" => "Email va kod talab qilinadi"]);
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, code, expires_at, is_used, attempt_count FROM email_otp_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(["error" => "Kod topilmadi. Avval kod so'rang."]);
        exit;
    }

    if ($row['is_used']) {
        echo json_encode(["error" => "Bu kod allaqachon ishlatilgan. Yangi kod so'rang."]);
        exit;
    }

    if ($row['expires_at'] < date('Y-m-d H:i:s')) {
        echo json_encode(["error" => "Kod muddati tugagan. Yangi kod so'rang."]);
        exit;
    }

    if ($row['attempt_count'] >= 5) {
        $stmt = $db->prepare("UPDATE email_otp_codes SET is_used = 1 WHERE id = ?");
        $stmt->execute([$row['id']]);
        echo json_encode(["error" => "5 marta noto'g'ri urinish. Kod bekor qilindi."]);
        exit;
    }

    if ($code !== $row['code']) {
        $stmt = $db->prepare("UPDATE email_otp_codes SET attempt_count = attempt_count + 1 WHERE id = ?");
        $stmt->execute([$row['id']]);
        $remaining = 4 - $row['attempt_count'];
        echo json_encode(["error" => "Noto'g'ri kod. $remaining ta urinish qoldi."]);
        exit;
    }

    // Code correct
    $stmt = $db->prepare("UPDATE email_otp_codes SET is_used = 1 WHERE id = ?");
    $stmt->execute([$row['id']]);

    // Find or create user
    $safe_id = abs(crc32($email)) % 1000000000000;
    $stmt = $db->prepare("SELECT telegram_id FROM users WHERE google_email = ? OR telegram_id = ?");
    $stmt->execute([$email, $safe_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = $user['telegram_id'];
    } else {
        $user_id = $safe_id;
        $username = explode('@', $email)[0];
        $stmt = $db->prepare("INSERT INTO users (telegram_id, username, balance, google_email) VALUES (?, ?, 10000, ?)");
        $stmt->execute([$user_id, $username, $email]);
    }

    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$user_id]);
    $balance = $stmt->fetchColumn();

    echo json_encode([
        "status" => "success",
        "telegram_id" => $user_id,
        "username" => explode('@', $email)[0],
        "balance" => (int)$balance,
        "email" => $email
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server xatolik"]);
}
