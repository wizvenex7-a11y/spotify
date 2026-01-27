<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; // YOUR TOKEN
$apiUrl   = "https://api.telegram.org/bot$botToken";

// ==========================================
// 2. SPOTDL API CLASS (Cloudflare Bypass)
// ==========================================
class SpotDL {
    private $cookieFile;
    private $csrfToken = null;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'spotdl_sess');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) @unlink($this->cookieFile);
    }

    // Step 1: Init Session & Get CSRF
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

    // Step 2: Get Metadata
    public function getMetadata($spotifyUrl) {
        if (!$this->csrfToken) $this->initSession();
        return $this->postRequest('https://spotdl.io/getTrackData', ['spotify_url' => $spotifyUrl]);
    }

    // Step 3: Get Download URL
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
// 3. TELEGRAM WEBHOOK HANDLER
// ==========================================

$content = file_get_contents("php://input");
$update  = json_decode($content, true);

if (isset($update["message"]["text"])) {
    $chatId = $update["message"]["chat"]["id"];
    $text   = trim($update["message"]["text"]);
    $msgId  = $update["message"]["message_id"];

    if ($text === "/start") {
        sendMessage($chatId, "👋 Send me a Spotify Track link.", $msgId);
        exit;
    }

    // Validation
    if (strpos($text, "spotify.com/track/") === false) {
        sendMessage($chatId, "❌ Invalid Link. Please send a Spotify Track link.", $msgId);
        exit;
    }

    // Changed to 'upload_document' so user knows it's a file, not a voice note
    file_get_contents("$apiUrl/sendChatAction?chat_id=$chatId&action=upload_document");

    try {
        $api = new SpotDL();

        // 1. Get Metadata
        $meta = $api->getMetadata($text);
        
        // Retry session if needed
        if (!isset($meta['data'])) {
            $api->initSession();
            $meta = $api->getMetadata($text);
        }

        if (!isset($meta['data'])) throw new Exception("Could not fetch metadata.");

        $track   = $meta['data'];
        $title   = $track['name'];
        $artist  = $track['artists'][0]['name'] ?? 'Unknown Artist';
        $album   = $track['album']['name'] ?? 'Single';
        $duration = floor(($track['duration_ms'] ?? 0) / 1000);

        // 2. Get Download URL
        $dlJson = $api->getDownloadLink($text);

        if (!isset($dlJson['url']) || $dlJson['error'] === true) {
            throw new Exception("Download link generation failed.");
        }

        $mp3Url = $dlJson['url'];

        // 3. Send as Audio File (MP3)
        // We do not pass 'thumb' URL because Telegram requires a file upload for thumbs.
        // We rely on the ID3 tags inside the MP3 for the cover art.
        $postFields = [
            'chat_id'   => $chatId,
            'audio'     => $mp3Url, 
            'caption'   => "🎧 <b>$title</b>\n👤 $artist\n💿 $album",
            'parse_mode'=> 'HTML',
            'title'     => $title,     // Shows in music player
            'performer' => $artist,    // Shows in music player
            'duration'  => $duration,
            'reply_to_message_id' => $msgId
        ];

        $ch = curl_init("$apiUrl/sendAudio");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        $resJson = json_decode($result, true);
        if (!$resJson['ok']) {
            sendMessage($chatId, "⚠️ Telegram Error: " . $resJson['description'], $msgId);
        }

    } catch (Exception $e) {
        sendMessage($chatId, "⚠️ Error: " . $e->getMessage(), $msgId);
    }
}

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
?>
