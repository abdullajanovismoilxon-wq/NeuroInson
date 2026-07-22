<?php
// api/submit-video.php — Step 1: Submit video job, return job_id immediately
require_once __DIR__ . "/db.php";

$data        = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['telegram_id']) ? (int)$data['telegram_id'] : 0;
$prompt      = isset($data['prompt'])      ? trim($data['prompt'])      : "";
$price       = isset($data['price'])       ? (int)$data['price']        : 3333;
$aspect_ratio = isset($data['aspect_ratio']) ? $data['aspect_ratio']    : "16:9";

$VIDEO_TIERS = [3333 => 'Standart', 7777 => 'Premium', 14999 => 'Biznes'];
if (!isset($VIDEO_TIERS[$price])) {
    echo json_encode(["error" => "Noto'g'ri narx. Mavjud narxlar: " . implode(', ', array_keys($VIDEO_TIERS))]);
    exit;
}

if (!$telegram_id || !$prompt) {
    echo json_encode(["error" => "telegram_id and prompt are required"]);
    exit;
}

try {
    // 1. Check balance
    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['balance'] < $price) {
        echo json_encode(["error" => "Balansda mablag' yetarli emas! Kerakli: {$price} so'm"]);
        exit;
    }

    // 2. Load config
    $config  = json_decode(file_get_contents(__DIR__ . "/config.json"), true);

    // 3. Enhance prompt via Gemini (prefer unblocked free keys)
    $enhanced_prompt = $prompt;
    $blocked_file = __DIR__ . "/blocked_keys.json";
    $blocked_keys = file_exists($blocked_file) ? (json_decode(file_get_contents($blocked_file), true) ?: []) : [];
    $now_ts = time();
    $all_gem_keys = array_merge($config['GEMINI_FREE_KEYS'] ?? [], $config['GEMINI_PAID_KEYS'] ?? []);
    $gemini_keys = array_filter($all_gem_keys, fn($k) => !isset($blocked_keys[$k]) || $now_ts > $blocked_keys[$k]);
    if (empty($gemini_keys)) $gemini_keys = $all_gem_keys;
    if (!empty($gemini_keys)) {
        $gkey = $gemini_keys[array_rand($gemini_keys)];
        $gurl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $gkey;

        $gpay = json_encode([
            "systemInstruction" => ["parts" => [["text" => "You are a professional video prompt engineer. Convert ANY language input into a concise, cinematic English video prompt for AI video generators (max 150 words). Focus on: camera movement, lighting, subject action, scene details. Return ONLY the prompt."]]],
            "contents"          => [["role" => "user", "parts" => [["text" => $prompt]]]],
            "generationConfig"  => ["maxOutputTokens" => 200]
        ]);
        $ch = curl_init($gurl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $gpay, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $gr = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $gr) {
            $gj = json_decode($gr, true);
            $txt = $gj['candidates'][0]['content']['parts'][0]['text'] ?? "";
            if ($txt) $enhanced_prompt = trim($txt);
        }
        curl_close($ch);
    }

    // 4. Model selection & routing based on selected tariff
    if ($price === 14999) {
        $model_path = "/fal-ai/kling-video/v1.6/pro/text-to-video";
        $tier = "Biznes (Kling 1.6 Pro)";
    } elseif ($price === 7777) {
        $model_path = "/fal-ai/kling-video/v1.6/standard/text-to-video";
        $tier = "Premium (Kling 1.6 Standard)";
    } else {
        $model_path = "/fal-ai/ltx-video";
        $tier = "Standart (LTX Video)";
    }

    $fal_key = $config['FAL_KEY'] ?? "";
    if (!$fal_key) {
        echo json_encode(["error" => "Fal.ai API kaliti topilmadi."]);
        exit;
    }

    // Build payload arguments
    $payload_args = [
        "prompt" => $enhanced_prompt
    ];

    if ($model_path === "/fal-ai/kling-video/v3/standard/text-to-video") {
        $payload_args["aspect_ratio"] = $aspect_ratio === "9:16" ? "9:16" : ($aspect_ratio === "1:1" ? "1:1" : "16:9");
        $payload_args["duration"] = 5;
        $payload_args["generate_audio"] = true;
    } elseif ($model_path === "/fal-ai/ltx-2.3/text-to-video/fast") {
        if ($aspect_ratio === "9:16")     $payload_args["image_size"] = "portrait_16_9";
        elseif ($aspect_ratio === "1:1")  $payload_args["image_size"] = "square";
        else                               $payload_args["image_size"] = "landscape_16_9";
        $payload_args["duration"] = 6; // LTX-2.3 fast minimum duration is 6
        $payload_args["generate_audio"] = true;
    } else {
        // LTX-Video Flat
        if ($aspect_ratio === "9:16")     $payload_args["image_size"] = "portrait_16_9";
        elseif ($aspect_ratio === "1:1")  $payload_args["image_size"] = "square";
        else                               $payload_args["image_size"] = "landscape_16_9";
    }

    $queue_url = "https://queue.fal.run" . $model_path;

    $ch = curl_init($queue_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload_args),
        CURLOPT_TIMEOUT        => 30,
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

    if ($http_code >= 200 && $http_code < 300 && $response) {
        $res = json_decode($response, true);
        $request_id   = $res['request_id']  ?? "";
        $status_url   = $res['status_url']   ?? "";
        $response_url = $res['response_url'] ?? "";

        if ($request_id) {
            // Log API usage
            try {
                $stmt_u = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
                $stmt_u->execute([$telegram_id]);
                $u_email = $stmt_u->fetchColumn() ?: '';
                $stmt_l = $db->prepare("INSERT INTO api_usage_log (user_email, api_name, endpoint, model_name, prompt_length) VALUES (?, 'fal', 'video', ?, ?)");
                $stmt_l->execute([$u_email, $model_path, strlen($prompt)]);
            } catch (Exception $e) {}

            echo json_encode([
                "status"            => "submitted",
                "request_id"        => $request_id,
                "status_url"        => $status_url,
                "response_url"      => $response_url,
                "model_path"        => $model_path,
                "new_balance"       => $user['balance'],
                "tier"              => $tier,
                "price"             => $price,
                "telegram_id"       => $telegram_id
            ]);
        } else {
            echo json_encode(["error" => "Navbatga qo'shib bo'lmadi. Fal.ai javobi: " . substr($response, 0, 200)]);
        }
    } else {
        echo json_encode(["error" => "Fal.ai xatolik ($http_code): " . substr($response, 0, 200)]);
    }
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}

