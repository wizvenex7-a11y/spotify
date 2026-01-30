<?php
// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; 

// Spotify Developer Keys (https://developer.spotify.com/dashboard)
$spotifyClientId = "e63d57e08bd7427ea40dc4a17de9b6e2";
$spotifyClientSecret = "48693228f719476697c29aebaf0765f7";

// Path to FFmpeg (leave empty "" if you don't have it, but metadata won't be embedded in file)
$ffmpeg_path = "C:/ffmpeg/bin/ffmpeg.exe"; // Linux example: "/usr/bin/ffmpeg"

// ==========================================
// 2. SPOTIFY METADATA CLASS
// ==========================================
class SpotifyMetadata {
    private $clientId;
    private $clientSecret;

    public function __construct($id, $secret) {
        $this->clientId = $id;
        $this->clientSecret = $secret;
    }

    private function getAccessToken() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($result, true);
        return $json['access_token'] ?? false;
    }

    public function getTrack($trackId) {
        $token = $this->getAccessToken();
        if (!$token) return false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/tracks/$trackId");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($result, true);
    }
}

// ==========================================
// 3. DOWNLOADER CLASS (SpotMate - No CSRF)
// ==========================================
class SpotMateDownloader {
    private $ch;
    private $cookieJar;
    public $lastError = '';

    public function __construct() {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'spot_cookie_');
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
        ]);
    }

    public function __destruct() {
        if ($this->ch) curl_close($this->ch);
        if (file_exists($this->cookieJar)) @unlink($this->cookieJar);
    }

    private function requestConversion($url) {
        $payload = json_encode(['urls' => $url]);
        curl_setopt($this->ch, CURLOPT_URL, 'https://spotmate.online/convert');
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Origin: https://spotmate.online',
            'Referer: https://spotmate.online/en1'
        ]);
        return json_decode(curl_exec($this->ch), true);
    }

    private function downloadFile($url) {
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, []); 
        $data = curl_exec($this->ch);
        return (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) == 200) ? $data : false;
    }

    public function getSong($spotifyUrl) {
        $res = $this->requestConversion($spotifyUrl);
        if (!$res) { $this->lastError = "API Error"; return false; }

        if (isset($res['url']) && !empty($res['url'])) return $this->downloadFile($res['url']);

        if (($res['status'] ?? '') !== 'queued') { $this->lastError = "Not Queued"; return false; }

        // Polling (3 Attempts)
        for ($i=0; $i<3; $i++) {
            sleep(5); // Wait 5s
            $res = $this->requestConversion($spotifyUrl);
            if (isset($res['url']) && !empty($res['url'])) return $this->downloadFile($res['url']);
        }
        $this->lastError = "Timeout";
        return false;
    }
}

// ==========================================
// 4. MAIN TELEGRAM HANDLER
// ==========================================

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update["message"]["text"])) {
    $chatId = $update["message"]["chat"]["id"];
    $text = trim($update["message"]["text"]);
    $msgId = $update["message"]["message_id"];

    // 1. Basic Commands
    if ($text == "/start") {
        sendMessage($chatId, "👋 <b>Ready!</b> Send me a Spotify Track Link.", $msgId);
        exit;
    }

    // 2. Validate Link
    if (strpos($text, "spotify.com/track/") === false) {
        sendMessage($chatId, "❌ Please send a valid Spotify Track link.", $msgId);
        exit;
    }

    // 3. Extract ID
    if (preg_match('/track\/([a-zA-Z0-9]{22})/', $text, $m)) {
        $trackId = $m[1];
    } else {
        sendMessage($chatId, "❌ Could not parse Track ID.", $msgId);
        exit;
    }
    
    $fullUrl = "https://open.spotify.com/track/" . $trackId;

    // Notify User
    sendMessage($chatId, "🔍 <b>Searching Metadata...</b>", $msgId);
    sendAction($chatId, "upload_voice"); // Show "recording audio..." status

    try {
        // --- A. GET METADATA ---
        $spotify = new SpotifyMetadata($spotifyClientId, $spotifyClientSecret);
        $meta = $spotify->getTrack($trackId);

        if (!$meta || isset($meta['error'])) {
            throw new Exception("Metadata failed. Check Client ID/Secret.");
        }

        $title = $meta['name'];
        $artist = $meta['artists'][0]['name'];
        $album = $meta['album']['name'];
        $coverUrl = $meta['album']['images'][0]['url'] ?? null;
        $duration = floor($meta['duration_ms'] / 1000);

        // Notify Downloading
        // editMessage($chatId, $sentMsgId, "⬇️ <b>Downloading... (Attempt 1/3)</b>"); // (Optional if you track msg IDs)

        // --- B. DOWNLOAD LOOP (3 RETRIES) ---
        $downloader = new SpotMateDownloader();
        $mp3Data = false;
        $attempt = 0;
        
        while ($attempt < 3 && !$mp3Data) {
            $attempt++;
            if ($attempt > 1) sleep(3); // Wait before retry
            $mp3Data = $downloader->getSong($fullUrl);
        }

        if (!$mp3Data) {
            throw new Exception("Download failed after 3 attempts.");
        }

        // --- C. SAVE TEMP FILES ---
        $baseName = sys_get_temp_dir() . "/spot_" . time();
        $mp3Path = $baseName . ".mp3";
        $finalPath = $baseName . "_final.mp3";
        $coverPath = $baseName . ".jpg";

        file_put_contents($mp3Path, $mp3Data);
        if ($coverUrl) file_put_contents($coverPath, file_get_contents($coverUrl));

        // --- D. FFMPEG TAGGING (Optional) ---
        if ($coverUrl && !empty($ffmpeg_path) && file_exists($ffmpeg_path)) {
            // Embed cover art and ID3 tags
            $cmd = sprintf(
                '%s -y -i %s -i %s -map 0:a -map 1:v -metadata title=%s -metadata artist=%s -metadata album=%s -c copy -disposition:v:0 attached_pic -id3v2_version 3 %s 2>&1',
                escapeshellarg($ffmpeg_path),
                escapeshellarg($mp3Path),
                escapeshellarg($coverPath),
                escapeshellarg($title),
                escapeshellarg($artist),
                escapeshellarg($album),
                escapeshellarg($finalPath)
            );
            exec($cmd, $out, $ret);
            
            if ($ret === 0 && file_exists($finalPath)) {
                $uploadPath = $finalPath; // Use the tagged file
            } else {
                $uploadPath = $mp3Path; // Fallback to untagged
            }
        } else {
            $uploadPath = $mp3Path;
        }

        // --- E. UPLOAD TO TELEGRAM ---
        sendAction($chatId, "upload_document");

        $postFields = [
            'chat_id' => $chatId,
            'audio' => new CURLFile($uploadPath),
            'caption' => "🎧 <b>$title</b>\n👤 $artist\n💿 $album",
            'parse_mode' => 'HTML',
            'title' => $title,
            'performer' => $artist,
            'duration' => $duration,
            'reply_to_message_id' => $msgId
        ];

        // Attach Thumbnail for Telegram Player if cover exists
        if (file_exists($coverPath)) {
            $postFields['thumb'] = new CURLFile($coverPath);
        }

        $ch = curl_init("https://api.telegram.org/bot$botToken/sendAudio");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

        // Cleanup
        @unlink($mp3Path);
        @unlink($finalPath);
        @unlink($coverPath);

        $jsonRes = json_decode($res, true);
        if (!$jsonRes['ok']) {
            sendMessage($chatId, "⚠️ Upload Failed: " . $jsonRes['description'], $msgId);
        }

    } catch (Exception $e) {
        sendMessage($chatId, "❌ <b>Error:</b> " . $e->getMessage(), $msgId);
    }
}

// --- HELPER FUNCTIONS ---
function sendMessage($chatId, $text, $replyId = null) {
    global $botToken;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    file_get_contents("https://api.telegram.org/bot$botToken/sendMessage?" . http_build_query($data));
}

function sendAction($chatId, $action) {
    global $botToken;
    file_get_contents("https://api.telegram.org/bot$botToken/sendChatAction?chat_id=$chatId&action=$action");
}
?>