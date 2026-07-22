<?php
require_once __DIR__ . "/db.php";

$input = json_decode(file_get_contents("php://input"), true);
$id_token = isset($input['id_token']) ? trim($input['id_token']) : '';

if (!$id_token) {
    http_response_code(400);
    echo json_encode(["error" => "id_token talab qilinadi"]);
    exit;
}

// Verify token with Google
$verify_url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $verify_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || !$response) {
    http_response_code(401);
    echo json_encode(["error" => "Google tokenni tasdiqlab bo'lmadi."]);
    exit;
}

$google_info = json_decode($response, true);
if (!$google_info || empty($google_info['email_verified']) || $google_info['email_verified'] !== 'true') {
    http_response_code(401);
    echo json_encode(["error" => "Email tasdiqlanmagan."]);
    exit;
}

$google_id = $google_info['sub'] ?? '';
$email_addr = strtolower($google_info['email'] ?? '');
$name = $google_info['name'] ?? (explode('@', $email_addr)[0] ?? 'User');

if (!$google_id || !$email_addr) {
    http_response_code(401);
    echo json_encode(["error" => "Google ma'lumotlari yetarli emas."]);
    exit;
}

try {
    // Check existing user by google_id or email
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ? OR google_email = ?");
    $stmt->execute([$google_id, $email_addr]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = $user['telegram_id'];
        $balance = $user['balance'];
        // Link google_id if not set
        if (empty($user['google_id'])) {
            $stmt = $db->prepare("UPDATE users SET google_id = ?, google_email = ? WHERE telegram_id = ?");
            $stmt->execute([$google_id, $email_addr, $user_id]);
        }
    } else {
        $user_id = abs(crc32($google_id . $email_addr)) % 1000000000000;
        $stmt = $db->prepare("INSERT INTO users (telegram_id, username, balance, google_id, google_email) VALUES (?, ?, 10000, ?, ?)");
        $stmt->execute([$user_id, $name, $google_id, $email_addr]);
        $balance = 10000;
    }

    echo json_encode([
        "status" => "success",
        "telegram_id" => $user_id,
        "username" => $name,
        "balance" => (int)$balance,
        "email" => $email_addr
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server xatolik"]);
}
