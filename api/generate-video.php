<?php
// api/generate-video.php — DEPRECATED: Use submit-video.php + check-video.php (two-step) instead
// Kept for reference; frontend no longer calls this endpoint.
require_once __DIR__ . "/db.php";
ignore_user_abort(true);
set_time_limit(120);

$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['telegram_id']) ? (int)$data['telegram_id'] : 0;
$prompt = isset($data['prompt']) ? $data['prompt'] : "";
$price = isset($data['price']) ? (int)$data['price'] : 3333;

if (!$telegram_id || !$prompt) {
    echo json_encode(["error" => "telegram_id and prompt are required"]);
    exit;
}

try {
    // 1. Check user balance
    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['balance'] < $price) {
        echo json_encode(["error" => "Balansda mablag' yetarli emas!"]);
        exit;
    }
    
    // 2. Load API keys
    $config  = json_decode(file_get_contents(__DIR__ . "/config.json"), true);
    $fal_key = isset($config['FAL_KEY']) ? $config['FAL_KEY'] : "";
    
    if (!$fal_key) {
        echo json_encode(["error" => "Fal.ai API kaliti topilmadi."]);
        exit;
    }

    // 3. Tier mapping based on exact user pricing list:
    // Standart (3333): fal-ai/ltx-video (Flat $0.02 ~250 UZS, silent)
    // Premium  (7777): fal-ai/ltx-2.3/text-to-video/fast (Duration: 5s, Cost: $0.30 ~3840 UZS, with audio)
    // Biznes (14999): fal-ai/kling-video/v3/standard/text-to-video (Duration: 5s, Cost: $0.70 ~8960 UZS, with audio)
    if ($price === 7777) {
        $model_url      = "https://queue.fal.run/fal-ai/ltx-2.3/text-to-video/fast";
        $tier_label     = "Premium (LTX-2.3 Fast with Audio)";
        $duration       = 5;
        $generate_audio = true;
    } elseif ($price === 14999) {
        $model_url      = "https://queue.fal.run/fal-ai/kling-video/v3/standard/text-to-video";
        $tier_label     = "Biznes (Kling 3.0 Standard with Audio)";
        $duration       = 5;
        $generate_audio = true;
    } else {
        $model_url      = "https://queue.fal.run/fal-ai/ltx-video";
        $tier_label     = "Standart (LTX Video Flat)";
        $duration       = 5;
        $generate_audio = false; // Legacy LTX-video does not support native audio
    }

    // 4. Prompt enhancement via Gemini (prefer unblocked free keys)
    $enhanced_prompt = $prompt;
    $blocked_file = __DIR__ . "/blocked_keys.json";
    $blocked_keys = file_exists($blocked_file) ? (json_decode(file_get_contents($blocked_file), true) ?: []) : [];
    $now_ts = time();
    $all_gem_keys = array_merge($config['GEMINI_FREE_KEYS'] ?? [], $config['GEMINI_PAID_KEYS'] ?? []);
    $gemini_keys = array_filter($all_gem_keys, fn($k) => !isset($blocked_keys[$k]) || $now_ts > $blocked_keys[$k]);
    if (empty($gemini_keys)) $gemini_keys = $all_gem_keys;
    if (!empty($gemini_keys)) {
        $selected_key = $gemini_keys[array_rand($gemini_keys)];
        $url_gemini = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $selected_key;
        $payload_gemini = [
            "systemInstruction" => [
                "parts" => [["text" => "You are an expert video prompt engineer. Convert the user's short text (any language) into a highly detailed, professional cinematic English prompt for AI video generators (Veo/Kling/Minimax). Return ONLY the English prompt."]]
            ],
            "contents" => [["role" => "user", "parts" => [["text" => $prompt]]]],
            "generationConfig" => ["maxOutputTokens" => 400]
        ];
        $ch = curl_init($url_gemini);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload_gemini),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);
        $r = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $r) {
            $rj = json_decode($r, true);
            if (isset($rj['candidates'][0]['content']['parts'][0]['text'])) {
                $enhanced_prompt = trim($rj['candidates'][0]['content']['parts'][0]['text']);
            }
        }
        curl_close($ch);
    }

    // 5. Aspect ratio mapping
    $aspect_ratio = isset($data['aspect_ratio']) ? $data['aspect_ratio'] : "16:9";
    if ($aspect_ratio === "9:16") {
        $image_size = "portrait_16_9";
    } elseif ($aspect_ratio === "1:1") {
        $image_size = "square";
    } else {
        $image_size = "landscape_16_9";
    }

    // 6. Request to Fal.ai (Queue method)
    $payload_args = [
        "prompt"     => $enhanced_prompt,
        "image_size" => $image_size
    ];
    
    // Some models do not accept duration/generate_audio in the payload (like legacy ltx-video)
    if ($price === 7777 || $price === 14999) {
        $payload_args["duration"]       = $duration;
        $payload_args["generate_audio"] = $generate_audio;
    }

    $ch = curl_init($model_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload_args),
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Key ' . $fal_key,
            'User-Agent: Mozilla/5.0'
        ]
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300 || !$response) {
        echo json_encode(["error" => "Fal.ai API xatolik ($http_code): " . $response]);
        exit;
    }

    $res          = json_decode($response, true);
    $video_url    = $res['video']['url'] ?? ($res['videos'][0]['url'] ?? "");
    $response_url = $res['response_url'] ?? "";

    // Poll Fal queue using the generic response_url
    if (!$video_url && $response_url) {
        for ($i = 0; $i < 30; $i++) {
            sleep(4);
            $ch2 = curl_init($response_url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Key ' . $fal_key,
                    'User-Agent: Mozilla/5.0'
                ]
            ]);
            $sr       = curl_exec($ch2);
            curl_close($ch2);
            
            $sj = json_decode($sr, true);
            
            // Check for queue errors
            $status = $sj['status'] ?? "";
            if ($status === "ERROR" || $status === "FAILED") {
                echo json_encode(["error" => "Fal.ai chizishda xatolik yuz berdi: " . $sr]);
                exit;
            }
            
            $video_url = $sj['video']['url'] ?? ($sj['videos'][0]['url'] ?? "");
            if ($video_url) break;
        }
    }

    if (!$video_url) {
        echo json_encode(["error" => "Video chizib bo'lmadi. Server javobi: " . $response]);
        exit;
    }

    // === Deduct balance & log atomically ===
    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?");
    $stmt->execute([$price, $telegram_id]);
    $today = date("Y-m-d");
    $db->prepare("UPDATE users SET extra_prompt_limits = CASE WHEN last_prompt_date = ? THEN extra_prompt_limits + 2 ELSE 2 END, last_prompt_date = ? WHERE telegram_id = ?")
       ->execute([$today, $today, $telegram_id]);
    $stmt = $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'video', ?, ?)");
    $stmt->execute([$telegram_id, -$price, "Video yaratish ($tier_label)"]);
    $db->commit();

    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $new_balance = (int)$stmt->fetchColumn();

    // Log API usage
    try {
        $stmt_u = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
        $stmt_u->execute([$telegram_id]);
        $u_email = $stmt_u->fetchColumn() ?: '';
        $model_id = $model_url ?? 'fal-ai/ltx-video';
        $stmt_l = $db->prepare("INSERT INTO api_usage_log (user_email, api_name, endpoint, model_name, prompt_length) VALUES (?, 'fal', 'video', ?, ?)");
        $stmt_l->execute([$u_email, $model_id, strlen($prompt)]);
    } catch (Exception $e) {}

    echo json_encode([
        "status"      => "success",
        "video_url"   => $video_url,
        "new_balance" => $new_balance
    ]);

} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}
