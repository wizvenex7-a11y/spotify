<?php
// Prevent timeouts
set_time_limit(0);
ignore_user_abort(true);

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; 

// Spotify Keys
$spotifyClientId = "e63d57e08bd7427ea40dc4a17de9b6e2";
$spotifyClientSecret = "48693228f719476697c29aebaf0765f7";

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
// 3. DOWNLOADER CLASS (SpotIMP3 API)
// ==========================================
class SpotImp3Downloader {
    
    // Step 1: Request the download link from the API
    private function getApiLink($spotifyUrl) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://spotimp3.net/api/download');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $spotifyUrl]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // Exact headers from your request
        $headers = [
            'sec-ch-ua-platform: "Windows"',
            'Referer: https://spotimp3.net/tl1/album',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'sec-ch-ua: "Not(A:Brand";v="8", "Chromium";v="144", "Google Chrome";v="144"',
            'Content-Type: application/json',
            'sec-ch-ua-mobile: ?0'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    // Step 2: Download the actual file from the link provided by API
    private function downloadFile($fileUrl) {
        $ch = curl_init($fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36');
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code == 200) ? $data : false;
    }

    public function getSong($spotifyUrl) {
        // 1. Call API
        $res = $this->getApiLink($spotifyUrl);
        
        if (!$res) return false;

        // 2. Extract Download URL (Adjust based on API response structure)
        // Common keys: 'download_url', 'link', 'url', 'file_url'
        $downloadUrl = $res['download_url'] ?? $res['link'] ?? $res['url'] ?? $res['file_url'] ?? null;

        if (!$downloadUrl) return false;

        // 3. Download the MP3
        return $this->downloadFile($downloadUrl);
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

    // 1. Start Command
    if ($text == "/start") {
        sendMessage($chatId, "👋 <b>Ready!</b> Send me a Spotify Track Link.", $msgId);
        exit;
    }

    // 2. Validate Link
    if (strpos($text, "spotify.com/track/") === false) {
        exit; // Ignore non-spotify messages
    }

    // 3. Extract Track ID
    if (preg_match('/track\/([a-zA-Z0-9]{22})/', $text, $m)) {
        $trackId = $m[1];
    } else {
        sendMessage($chatId, "❌ Invalid Link Format.", $msgId);
        exit;
    }
    
    $fullUrl = "https://open.spotify.com/track/" . $trackId;

    // Notify User
    sendMessage($chatId, "🔍 <b>Searching Metadata...</b>", $msgId);
    sendAction($chatId, "upload_voice"); 

    try {
        // --- A. GET METADATA ---
        $spotify = new SpotifyMetadata($spotifyClientId, $spotifyClientSecret);
        $meta = $spotify->getTrack($trackId);

        if (!$meta || isset($meta['error'])) {
            throw new Exception("Metadata failed. Invalid API Keys.");
        }

        $title = $meta['name'];
        $artist = $meta['artists'][0]['name'];
        $album = $meta['album']['name'];
        $coverUrl = $meta['album']['images'][0]['url'] ?? null;
        $duration = floor($meta['duration_ms'] / 1000);

        // --- B. DOWNLOAD AUDIO (3 RETRIES) ---
        // sendMessage($chatId, "⬇️ Downloading from SpotIMP3...", $msgId);
        
        $downloader = new SpotImp3Downloader();
        $mp3Data = false;
        $maxRetries = 3;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            $attempt++;
            $mp3Data = $downloader->getSong($fullUrl);
            
            if ($mp3Data) {
                break; // Success
            } else {
                if ($attempt < $maxRetries) sleep(3); // Wait 3s before retry
            }
        }

        if (!$mp3Data) {
            throw new Exception("Download failed after 3 attempts via SpotIMP3.");
        }

        // --- C. PREPARE UPLOAD ---
        sendAction($chatId, "upload_document");

        // Save files to temp directory
        $tempDir = sys_get_temp_dir();
        $tempMp3 = tempnam($tempDir, 'mp3');
        $tempThumb = tempnam($tempDir, 'jpg');

        file_put_contents($tempMp3, $mp3Data);
        
        $hasCover = false;
        if ($coverUrl) {
            $coverData = file_get_contents($coverUrl);
            if ($coverData) {
                file_put_contents($tempThumb, $coverData);
                $hasCover = true;
            }
        }

        // --- D. SEND TO TELEGRAM ---
        // We send the cover as 'thumb' so it shows in the player
        $postFields = [
            'chat_id' => $chatId,
            'audio' => new CURLFile($tempMp3, 'audio/mpeg', "$artist - $title.mp3"),
            'caption' => "🎧 <b>$title</b>\n👤 $artist\n💿 $album",
            'parse_mode' => 'HTML',
            'title' => $title,
            'performer' => $artist,
            'duration' => $duration,
            'reply_to_message_id' => $msgId
        ];

        if ($hasCover) {
            $postFields['thumb'] = new CURLFile($tempThumb, 'image/jpeg', 'thumb.jpg');
        }

        $ch = curl_init("https://api.telegram.org/bot$botToken/sendAudio");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

        // Cleanup Temp Files
        @unlink($tempMp3);
        @unlink($tempThumb);

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
    
    $ch = curl_init("https://api.telegram.org/bot$botToken/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function sendAction($chatId, $action) {
    global $botToken;
    file_get_contents("https://api.telegram.org/bot$botToken/sendChatAction?chat_id=$chatId&action=$action");
}
?>