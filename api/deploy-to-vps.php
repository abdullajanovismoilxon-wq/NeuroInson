<?php
// api/deploy-to-vps.php — DEPRECATED: Secure SSH deploy requires server-side setup.
// Now requires admin authentication. Remove entirely if not needed.
require_once __DIR__ . "/db.php";

// Admin check
$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['admin_id']) ? (int)$data['admin_id'] : 0;
$config_file = __DIR__ . "/config.json";
$admins = [799317334];
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    if (isset($config['ADMIN_IDS'])) {
        $admins = is_array($config['ADMIN_IDS']) ? $config['ADMIN_IDS'] : [$config['ADMIN_IDS']];
    }
}
if (!in_array($telegram_id, $admins)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Ruxsat etilmagan!"]);
    exit;
}

$ip = isset($data['ip']) ? trim($data['ip']) : "";
$password = isset($data['password']) ? trim($data['password']) : "";
$code = isset($data['code']) ? $data['code'] : "";
$type = isset($data['type']) ? trim($data['type']) : "python-bot";

if (!$ip || !$password || !$code) {
    echo json_encode(["status" => "error", "message" => "IP, parol va kod to'ldirilishi shart!"]);
    exit;
}

$payload = json_encode([
    "ip" => $ip,
    "password" => $password,
    "code" => $code,
    "type" => $type
]);

$descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
);

$process = proc_open("python3 " . escapeshellarg(__DIR__ . "/deploy_runner.py"), $descriptorspec, $pipes);

if (is_resource($process)) {
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $return_value = proc_close($process);

    if ($output) {
        $res = json_decode($output, true);
        if ($res) {
            echo json_encode($res);
        } else {
            echo json_encode(["status" => "error", "message" => "Python output decode error: " . $output]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Subprocess failed with exit code $return_value. Errors: " . $errors]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Python runnerni ishga tushirib bo'lmadi."]);
}
