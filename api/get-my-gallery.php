<?php
// api/get-my-gallery.php — Returns only the authenticated user's generated media files
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$telegram_id = 0;
if (isset($_GET['telegram_id'])) {
    $telegram_id = (int)$_GET['telegram_id'];
} elseif (isset($_POST['telegram_id'])) {
    $telegram_id = (int)$_POST['telegram_id'];
} else {
    $input = json_decode(file_get_contents("php://input"), true);
    if (isset($input['telegram_id'])) {
        $telegram_id = (int)$input['telegram_id'];
    }
}

if ($telegram_id <= 0) {
    echo json_encode(["status" => "error", "error" => "Telegram ID talab qilinadi.", "media" => []]);
    exit;
}

$db_file = __DIR__ . "/../database.db";

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT id, media_type, file_path, tariff_label, price, created_at FROM generated_media WHERE telegram_id = ? ORDER BY created_at DESC LIMIT 30");
    $stmt->execute([$telegram_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $media_list = [];
    foreach ($rows as $r) {
        $media_list[] = [
            "id" => (int)$r['id'],
            "media_type" => $r['media_type'],
            "file_url" => "api/media.php?id=" . $r['id'] . "&telegram_id=" . $telegram_id,
            "tariff_label" => $r['tariff_label'],
            "price" => (int)$r['price'],
            "created_at" => $r['created_at']
        ];
    }

    echo json_encode([
        "status" => "success",
        "media" => $media_list
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "error" => $e->getMessage(),
        "media" => []
    ]);
}
