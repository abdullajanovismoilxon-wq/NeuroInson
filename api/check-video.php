<?php
// api/check-video.php
// Polls fal.ai queue or Google Veo operations. When done → deducts balance & logs transaction.
require_once __DIR__ . "/db.php";

$data         = json_decode(file_get_contents("php://input"), true);
$request_id   = trim($data['request_id']   ?? "");
$status_url   = trim($data['status_url']   ?? "");
$response_url = trim($data['response_url'] ?? "");
$model_path   = trim($data['model_path']   ?? "");
$telegram_id  = (int)($data['telegram_id'] ?? 0);
$price        = (int)($data['price']       ?? 0);
$tier         = trim($data['tier']         ?? "Video");

if (!$request_id) {
    echo json_encode(["error" => "request_id required"]);
    exit;
}

$config  = json_decode(file_get_contents(__DIR__ . "/config.json"), true);

// ── All video requests use Fal.ai ──

$fal_key = $config['FAL_KEY'] ?? "";
$hdrs = [
    'Authorization: Key ' . $fal_key,
    'User-Agent: Mozilla/5.0'
];

if (!$status_url && $model_path) {
    $status_url = "https://queue.fal.run{$model_path}/requests/{$request_id}/status";
}

if ($status_url) {
    $response_url = preg_replace('/\/status$/', '', $status_url);
} elseif ($model_path) {
    $response_url = "https://queue.fal.run{$model_path}/requests/{$request_id}";
}

$ch = curl_init($status_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET        => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER     => $hdrs
]);
$s_body = curl_exec($ch);
$s_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$s_body) {
    echo json_encode(["status" => "pending", "message" => "Tekshirilmoqda..."]);
    exit;
}

$s      = json_decode($s_body, true);
$status = strtolower($s['status'] ?? "");
$qpos   = $s['queue_position'] ?? null;

if ($s_code === 202 || in_array($status, ['in_queue', 'queued', ''])) {
    $msg = "Neuroinson navbatda kutilmoqda... ⏳";
    if ($qpos !== null && $qpos > 0) $msg = "Neuroinson: {$qpos} ta oldinda ⏳";
    echo json_encode(["status" => "pending", "message" => $msg, "fal_status" => $status]);

    exit;
}

if ($s_code !== 200 && $s_code !== 201) {
    echo json_encode(["status" => "pending", "message" => "Tekshirilmoqda... ($s_code)"]);
    exit;
}

if ($status === 'completed') {
    $ch2 = curl_init($response_url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $hdrs
    ]);
    $r_body = curl_exec($ch2);
    $r_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($r_code === 200 && $r_body) {
        $r = json_decode($r_body, true);
        $video_url =
            $r['video']['url']          ??
            $r['videos'][0]['url']       ??
            $r['output']['video']['url'] ??
            $r['output']['video']        ??
            $r['url']                    ??
            "";

        if ($video_url) {
            if ($telegram_id && $price) {
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
                    $stmt->execute([$telegram_id]);
                    $usr = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $db->prepare("SELECT 1 FROM processed_video_requests WHERE request_id = ?");
                    $stmt->execute([$request_id]);
                    if ($stmt->fetch()) {
                        $db->rollBack();
                        echo json_encode(["status" => "done", "video_url" => $video_url, "new_balance" => $usr['balance']]);
                        exit;
                    }

                    if ($usr && $usr['balance'] >= $price) {
                        $db->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?")
                           ->execute([$price, $telegram_id]);

                        $today = date("Y-m-d");
                        $db->prepare("UPDATE users SET extra_prompt_limits = CASE WHEN last_prompt_date = ? THEN extra_prompt_limits + 2 ELSE 2 END, last_prompt_date = ? WHERE telegram_id = ?")
                           ->execute([$today, $today, $telegram_id]);

                        $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'video', ?, ?)")
                           ->execute([$telegram_id, -$price, "Video yaratish ($tier)"]);

                        $db->prepare("INSERT OR IGNORE INTO processed_video_requests (request_id, telegram_id) VALUES (?, ?)")
                           ->execute([$request_id, $telegram_id]);

                        // Download video locally for secure streaming
                        $output_dir = __DIR__ . "/../output";
                        if (!is_dir($output_dir)) mkdir($output_dir, 0755, true);
                        $v_filename = "video_" . time() . "_" . rand(1000, 9999) . ".mp4";
                        $v_local = $output_dir . "/" . $v_filename;
                        
                        $ch_dl = curl_init($video_url);
                        curl_setopt_array($ch_dl, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 60,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_FOLLOWLOCATION => true
                        ]);
                        $v_content = curl_exec($ch_dl);
                        curl_close($ch_dl);

                        if ($v_content) {
                            file_put_contents($v_local, $v_content);
                            $saved_rel_path = "output/" . $v_filename;
                        } else {
                            $saved_rel_path = $video_url;
                        }

                        $stmt_m = $db->prepare("INSERT INTO generated_media (telegram_id, media_type, file_path, tariff_label, price) VALUES (?, 'video', ?, ?, ?)");
                        $stmt_m->execute([$telegram_id, $saved_rel_path, $tier, $price]);
                        $media_id = (int)$db->lastInsertId();

                        $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
                        $stmt->execute([$telegram_id]);
                        $new_balance = (int)$stmt->fetchColumn();
                    } else {
                        $new_balance = $usr['balance'] ?? 0;
                        $media_id = 0;
                    }
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $new_balance = $usr['balance'] ?? 0;
                    $media_id = 0;
                }
            } else {
                $new_balance = null;
                $media_id = 0;
            }

            $final_url = ($media_id > 0) ? ("api/media.php?id=" . $media_id . "&telegram_id=" . $telegram_id) : $video_url;

            echo json_encode([
                "status"      => "done",
                "video_url"   => $final_url,
                "new_balance" => $new_balance
            ]);
        } else {
            echo json_encode([
                "status"  => "error",
                "message" => "Video URL topilmadi. Raw: " . substr($r_body, 0, 300)
            ]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Natija olishda xatolik ($r_code)"]);
    }
} elseif (in_array($status, ['failed', 'error'])) {
    $err = $s['error']['message'] ?? ($s['error'] ?? "Noma'lum xatolik");
    echo json_encode([
        "status"  => "error",
        "message" => "Video yaratishda xatolik. Pul yechilmadi. ($err)"
    ]);
} else {
    if ($status === 'in_progress') {
        $msg = "Neuroinson video render qilmoqda... 🎬";
    } elseif ($qpos !== null && $qpos > 0) {
        $msg = "Neuroinson navbatda: $qpos ta oldinda ⏳";
    } else {
        $msg = "Neuroinson video yaratmoqda... ⏳";
    }
    echo json_encode(["status" => "pending", "message" => $msg, "fal_status" => $status]);

}
