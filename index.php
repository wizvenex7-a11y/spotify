<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; // YOUR TOKEN
$apiUrl   = "https://api.telegram.org/bot$botToken";

// ==========================================
// 2. SPOTDL API CLASS
// ==========================================
class SpotDL {
    private $cookieFile;
    private $csrfToken = null;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'spotdl_session');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) @unlink($this->cookieFile);
    }

    // Step 1: Initialize Session & Get CSRF Token
    public function initSession() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://spotdl.io/v2');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . $this->userAgent,
            'Accept: text/html,application/xhtml+xml',
            'Referer: https://spotdl.io/'
        ]);
        
        $html = curl_exec($ch);
        curl_close($ch);

        if (preg_match('/<meta\s+name="csrf-token"\s+content="(.*?)"/', $html, $matches)) {
            $this->csrfToken = $matches[1];
            return true;
        }
        return false;
    }

    // Step 2: Get Metadata (Image, Title, Artist)
    public function getMetadata($spotifyUrl) {
        if (!$this->csrfToken) $this->initSession();
        return $this->postRequest('https://spotdl.io/getTrackData', ['spotify_url' => $spotifyUrl]);
    }

    // Step 3: Get Download Link (master.dlapi.app)
    public function getDownloadLink($spotifyUrl) {
        if (!$this->csrfToken) $this->initSession();
        return $this->postRequest('https://spotdl.io/convert', ['urls' => $spotifyUrl]);
    }

    private function postRequest($url, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
            'x-csrf-token: ' . $this->csrfToken,
            'Origin: https://spotdl.io',
            'Referer: https://spotdl.io/v2'
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}

// ==========================================
// 3. TELEGRAM HANDLER
// ==========================================

$content = file_get_contents("php://input");
$update  = json_decode($content, true);

if (isset($update["message"]["text"])) {
    $chatId = $update["message"]["chat"]["id"];
    $text   = trim($update["message"]["text"]);
    $msgId  = $update["message"]["message_id"];

    // Basic Commands
    if ($text === "/start") {
        sendMessage($chatId, "👋 Send me a Spotify Track URL.", $msgId);
        exit;
    }

    // Validate URL
    if (strpos($text, "spotify.com/track/") === false) {
        sendMessage($chatId, "❌ Invalid Link.", $msgId);
        exit;
    }

    // Send Status
    file_get_contents("$apiUrl/sendChatAction?chat_id=$chatId&action=upload_voice");

    try {
        $api = new SpotDL();

        // 1. Fetch Metadata
        $metaJson = $api->getMetadata($text);
        
        // Retry session if expired
        if (empty($metaJson) || !isset($metaJson['data'])) {
            $api->initSession();
            $metaJson = $api->getMetadata($text);
        }

        if (!isset($metaJson['data'])) {
            throw new Exception("Could not fetch track info.");
        }

        // Extract Info
        $trackData = $metaJson['data'];
        $title     = $trackData['name'];
        $artist    = $trackData['artists'][0]['name'] ?? 'Unknown';
        $coverUrl  = $trackData['album']['images'][0]['url'] ?? '';
        $duration  = floor(($trackData['duration_ms'] ?? 0) / 1000);

        // 2. Fetch Download Link
        $dlJson = $api->getDownloadLink($text);

        // The response format you provided: { "error": false, "url": "..." }
        if (!isset($dlJson['url']) || $dlJson['error'] === true) {
            throw new Exception("Conversion failed or queue is full.");
        }

        $mp3Url = $dlJson['url'];

        // 3. Send to Telegram
        sendAudioToTelegram($chatId, $mp3Url, $title, $artist, $coverUrl, $duration, $msgId);

    } catch (Exception $e) {
        sendMessage($chatId, "⚠️ Error: " . $e->getMessage(), $msgId);
    }
}

// ==========================================
// 4. HELPER FUNCTIONS
// ==========================================

function sendMessage($chatId, $text, $replyId) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $replyId
    ];
    file_get_contents("$apiUrl/sendMessage?" . http_build_query($data));
}

function sendAudioToTelegram($chatId, $fileUrl, $title, $artist, $thumbUrl, $duration, $replyId) {
    global $apiUrl;

    $postFields = [
        'chat_id'   => $chatId,
        'audio'     => $fileUrl, // Telegram downloads from here
        'caption'   => "🎧 <b>$title</b>\n👤 $artist",
        'parse_mode'=> 'HTML',
        'title'     => $title,
        'performer' => $artist,
        'duration'  => $duration,
        'thumb'     => $thumbUrl, // Sets the Cover Art
        'reply_to_message_id' => $replyId
    ];

    $ch = curl_init("$apiUrl/sendAudio");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Error checking
    if ($curlError) {
        sendMessage($chatId, "⚠️ Network Error: $curlError", $replyId);
    } else {
        $resJson = json_decode($result, true);
        if (!$resJson['ok']) {
            sendMessage($chatId, "⚠️ API Error: " . ($resJson['description'] ?? 'Unknown'), $replyId);
        }
    }
}
?>
