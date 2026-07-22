<?php
// api/media.php — Secure media file server with owner verification
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$req_telegram_id = isset($_GET['telegram_id']) ? (int)$_GET['telegram_id'] : 0;

if ($id <= 0) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(["error" => "Media ID talab qilinadi."]);
    exit;
}

$db_file = __DIR__ . "/../database.db";

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM generated_media WHERE id = ?");
    $stmt->execute([$id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        header("HTTP/1.1 404 Not Found");
        echo json_encode(["error" => "Fayl topilmadi."]);
        exit;
    }

    // Owner authorization check
    $owner_id = (int)$media['telegram_id'];

    // Load admin list from config
    $admin_ids = [799317334, 759065470570];
    $config_path = __DIR__ . "/config.json";
    if (file_exists($config_path)) {
        $cfg = json_decode(file_get_contents($config_path), true);
        if (isset($cfg['ADMIN_IDS']) && is_array($cfg['ADMIN_IDS'])) {
            $admin_ids = array_map('intval', $cfg['ADMIN_IDS']);
        }
    }

    if ($req_telegram_id !== $owner_id && !in_array($req_telegram_id, $admin_ids)) {
        header("HTTP/1.1 403 Forbidden");
        echo json_encode(["error" => "Ruxsat etilmadi. Ushbu fayl faqat egasiga ko'rinadi."]);
        exit;
    }

    // Resolve physical file path
    $raw_path = $media['file_path'];
    $clean_path = ltrim($raw_path, '/');
    $full_path = __DIR__ . "/../" . $clean_path;

    if (!file_exists($full_path) || !is_file($full_path)) {
        header("HTTP/1.1 404 Not Found");
        echo json_encode(["error" => "Serverda fayl mavjud emas."]);
        exit;
    }

    // Determine mime type
    $mime_type = "application/octet-stream";
    $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
        $mime_type = "image/" . ($ext === 'jpg' ? 'jpeg' : $ext);
    } elseif (in_array($ext, ['mp4', 'webm', 'mov'])) {
        $mime_type = "video/" . $ext;
    }

    header("Content-Type: " . $mime_type);
    header("Content-Length: " . filesize($full_path));
    header("Cache-Control: private, max-age=86400");
    readfile($full_path);
    exit;

} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Media server xatosi: " . $e->getMessage()]);
    exit;
}
