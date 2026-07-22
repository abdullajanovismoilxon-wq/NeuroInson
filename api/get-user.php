<?php
// api/get-user.php
require_once __DIR__ . "/db.php";

$telegram_id   = isset($_GET['telegram_id'])   ? (int)$_GET['telegram_id']   : 0;
$username      = isset($_GET['username'])      ? trim($_GET['username'])     : "";
$google_id     = isset($_GET['google_id'])     ? trim($_GET['google_id'])     : "";
$google_email  = isset($_GET['google_email'])  ? trim($_GET['google_email'])  : "";

if (!$telegram_id && !$google_id) {
    echo json_encode(["error" => "telegram_id or google_id is required"]);
    exit;
}

try {
    $user = null;

    if ($telegram_id) {
        $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($google_id) {
        $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ? OR telegram_id = ?");
        $stmt->execute([$google_id, (int)$google_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        // Register new user with 10000 UZS balance
        $user_id = $telegram_id ?: (int)crc32($google_id);
        $user_name = $username ?: ($google_email ? explode('@', $google_email)[0] : "Foydalanuvchi");

        $stmt = $db->prepare("INSERT INTO users (telegram_id, username, balance, google_id, google_email) VALUES (?, ?, 10000, ?, ?)");
        $stmt->execute([$user_id, $user_name, $google_id, $google_email]);

        $user = [
            "telegram_id"  => $user_id,
            "username"     => $user_name,
            "balance"      => 10000,
            "google_id"    => $google_id,
            "google_email" => $google_email
        ];
    } else {
        // Update username or link google account if passed
        $updates = [];
        $params = [];
        if ($username && $user['username'] !== $username) {
            $updates[] = "username = ?";
            $params[] = $username;
            $user['username'] = $username;
        }
        if ($google_id && empty($user['google_id'])) {
            $updates[] = "google_id = ?";
            $params[] = $google_id;
            $user['google_id'] = $google_id;
        }
        if ($google_email && empty($user['google_email'])) {
            $updates[] = "google_email = ?";
            $params[] = $google_email;
            $user['google_email'] = $google_email;
        }
        if (!empty($updates)) {
            $params[] = $user['telegram_id'];
            $stmt = $db->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE telegram_id = ?");
            $stmt->execute($params);
        }
    }

    echo json_encode($user);
} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}
