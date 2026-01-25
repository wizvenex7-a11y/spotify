<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; // YOUR TOKEN
$apiUrl   = "https://api.telegram.org/bot{$botToken}";

// ==========================================
// 2. READ WEBHOOK UPDATE
// ==========================================
$update = json_decode(file_get_contents("php://input"), true);

if (!isset($update["message"]["text"])) {
    exit;
}

$chatId = $update["message"]["chat"]["id"];
$text   = trim($update["message"]["text"]);
$msgId  = $update["message"]["message_id"];

// ==========================================
// 3. /START COMMAND
// ==========================================
if ($text === "/start") {
    sendMessage(
        $chatId,
        "👋 <b>Send me a Spotify link</b>\n\n🎧 Track\n👤 Artist\n💿 Album\n📂 Playlist",
        $msgId
    );
    exit;
}

// ==========================================
// 4. VALIDATE SPOTIFY URL
// ==========================================
if (!preg_match('~https?://open\.spotify\.com/(track|album|artist|playlist)/[a-zA-Z0-9]+~', $text)) {
    sendMessage(
        $chatId,
        "❌ <b>Invalid Spotify link</b>\n\nExample:\n<code>https://open.spotify.com/track/...</code>",
        $msgId
    );
    exit;
}

// ==========================================
// 5. SHOW WORKING STATUS
// ==========================================
file_get_contents(
    "{$apiUrl}/sendChatAction?chat_id={$chatId}&action=upload_audio"
);

// ==========================================
// 6. PROCESS SPOTIFY LINK
// ==========================================
try {

    $requestUrl = buildSpotidownRequest($text);

    $context = stream_context_create([
        "http" => [
            "header"  => "User-Agent: Mozilla/5.0",
            "timeout" => 25
        ]
    ]);

    $response = @file_get_contents($requestUrl, false, $context);
    $data = json_decode($response, true);

    if (!$data) {
        throw new Exception("Spotidown API did not respond.");
    }

    // Direct track
    if (isset($data['audio']['url'])) {
        processTrack($chatId, $msgId, $data);
    }
    // Collection (album/artist/playlist)
    elseif (isset($data['tracks']) && is_array($data['tracks'])) {
        $count = count($data['tracks']);

        sendMessage(
            $chatId,
            "📂 <b>Collection detected</b>\nFound <b>{$count}</b> tracks.\nDownloading first track…",
            $msgId
        );

        if ($count > 0) {
            processTrack($chatId, $msgId, $data['tracks'][0]);
        }
    } else {
        throw new Exception("No downloadable audio found.");
    }

} catch (Exception $e) {
    sendMessage(
        $chatId,
        "⚠️ <b>Error:</b> " . htmlspecialchars($e->getMessage()),
        $msgId
    );
}

// ==========================================
// 7. FUNCTIONS
// ==========================================

function processTrack($chatId, $replyId, $trackData) {
    global $apiUrl;

    if (!isset($trackData['audio']['url'])) {
        if (isset($trackData['url'])) {
            $req = buildSpotidownRequest($trackData['url']);
            $json = file_get_contents($req);
            $trackData = json_decode($json, true);
        } else {
            sendMessage($chatId, "❌ Audio URL missing.", $replyId);
            return;
        }
    }

    $audio   = $trackData['audio']['url'];
    $title   = $trackData['name'] ?? 'Spotify Track';
    $artist  = is_array($trackData['artists'])
        ? implode(', ', $trackData['artists'])
        : ($trackData['artists'] ?? 'Unknown Artist');

    $album   = $trackData['album']['name'] ?? 'Spotify';
    $thumb   = $trackData['album']['coverUrl'] ?? null;
    $dur     = isset($trackData['duration']) ? floor($trackData['duration'] / 1000) : 0;

    $data = [
        'chat_id'  => $chatId,
        'audio'    => $audio,
        'caption'  => "🎧 <b>{$title}</b>\n👤 {$artist}\n💿 {$album}",
        'parse_mode' => 'HTML',
        'title'    => $title,
        'performer'=> $artist,
        'duration' => $dur,
        'reply_to_message_id' => $replyId
    ];

    if ($thumb) {
        $data['thumb'] = $thumb;
    }

    $ch = curl_init("{$apiUrl}/sendAudio");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function sendMessage($chatId, $text, $replyId = null) {
    global $apiUrl;

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($replyId) {
        $data['reply_to_message_id'] = $replyId;
    }

    file_get_contents("{$apiUrl}/sendMessage?" . http_build_query($data));
}

function buildSpotidownRequest($spotifyUrl) {
    $parsed = parse_url($spotifyUrl);
    $path = $parsed['host'] . $parsed['path'];
    $sigInput = "search/" . $path;
    $sig = generateSig($sigInput);

    return "https://api.spotidown.co/{$sigInput}?sig={$sig}";
}

function generateSig($input) {
    $b64 = base64_encode($input);
    $S = rand(0, strlen($b64) - 1);

    $part1 = dechex($S * 666111444);
    $rot   = substr($b64, $S) . substr($b64, 0, $S);
    $part2 = strrev($rot);
    $part3 = dechex($S * 666);

    $noise = [];
    foreach (str_split($b64) as $c) {
        if (rand(0, 1)) $noise[] = $c;
    }
    shuffle($noise);

    return $part1 . "_" . $part2 . "_" . $part3 . "_" . implode('', $noise);
}

?>
