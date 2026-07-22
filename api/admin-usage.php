<?php
require_once __DIR__ . "/db.php";

$admin_id = isset($_GET['telegram_id']) ? (int)$_GET['telegram_id'] : 0;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;

// Admin check
$config_file = __DIR__ . "/config.json";
$admins = [799317334];
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    if (isset($config['ADMIN_IDS'])) {
        $admins = $config['ADMIN_IDS'];
    }
}

if (!in_array($admin_id, $admins)) {
    $stmt = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
    $stmt->execute([$admin_id]);
    $admin_user = $stmt->fetchColumn();
    if ($admin_user !== 'abdullajanovismoilxon@gmail.com') {
        http_response_code(403);
        echo json_encode(["error" => "Ruxsat yo'q"]);
        exit;
    }
}

$days_clause = "created_at >= datetime('now', '-' || ? || ' days')";

// Usage by API
$stmt = $db->prepare("SELECT api_name, COUNT(*) as cnt, COALESCE(SUM(response_time_ms), 0) as total_ms FROM api_usage_log WHERE $days_clause GROUP BY api_name ORDER BY cnt DESC");
$stmt->execute([$days]);
$raw_by_api = $stmt->fetchAll(PDO::FETCH_ASSOC);
$by_api = array_map(fn($r) => ['name' => $r['api_name'], 'count' => (int)$r['cnt'], 'total_ms' => (int)$r['total_ms']], $raw_by_api);

// Usage by endpoint
$stmt = $db->prepare("SELECT endpoint, COUNT(*) as cnt FROM api_usage_log WHERE $days_clause GROUP BY endpoint ORDER BY cnt DESC");
$stmt->execute([$days]);
$raw_endpoint = $stmt->fetchAll(PDO::FETCH_ASSOC);
$by_endpoint = array_map(fn($r) => ['name' => $r['endpoint'], 'count' => (int)$r['cnt']], $raw_endpoint);

// Usage by day (last 7 days)
$stmt = $db->prepare("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM api_usage_log WHERE created_at >= datetime('now', '-7 days') GROUP BY DATE(created_at) ORDER BY day ASC");
$stmt->execute();
$raw_day = $stmt->fetchAll(PDO::FETCH_ASSOC);
$by_day = array_map(fn($r) => ['day' => $r['day'], 'count' => (int)$r['cnt']], $raw_day);

// Total calls
$stmt = $db->prepare("SELECT COUNT(*) FROM api_usage_log WHERE $days_clause");
$stmt->execute([$days]);
$total_calls = (int)$stmt->fetchColumn();

// Top users
$stmt = $db->prepare("SELECT user_email, COUNT(*) as cnt FROM api_usage_log WHERE $days_clause AND user_email != '' GROUP BY user_email ORDER BY cnt DESC LIMIT 10");
$stmt->execute([$days]);
$raw_top = $stmt->fetchAll(PDO::FETCH_ASSOC);
$top_users = array_map(fn($r) => ['email' => $r['user_email'], 'count' => (int)$r['cnt']], $raw_top);

echo json_encode([
    "status" => "success",
    "total_calls" => $total_calls,
    "by_api" => $by_api,
    "by_endpoint" => $by_endpoint,
    "by_day" => $by_day,
    "top_users" => $top_users
]);
