<?php
header('Content-Type: application/json');

class TelegramViewBot {
    private $link;
    private $maxThreads = 400;
    private $proxyList = [];
    private $socksProxyList = [];

    public function __construct($link) {
        $this->link = $link;
    }

    private function sendSeen($channel, $msgid, $proxy) {
        $ch = curl_init();
        $proxyConfig = [
            'http' => $proxy,
            'https' => $proxy
        ];

        // First request to get cookie
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://t.me/$channel/$msgid",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxy,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true
        ]);

        $response = curl_exec($ch);
        if ($response === false) return false;

        preg_match('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookie = $matches[1] ?? '';
        if (empty($cookie)) return false;

        // Second request to send view
        $headers = [
            "Accept: */*",
            "Accept-Encoding: gzip, deflate, br",
            "Accept-Language: en-US,en;q=0.9",
            "Connection: keep-alive",
            "Content-type: application/x-www-form-urlencoded",
            "Cookie: $cookie",
            "Host: t.me",
            "Origin: https://t.me",
            "Referer: https://t.me/$channel/$msgid?embed=1",
            "User-Agent: Chrome"
        ];

        $postData = ["_rl" => "1"];

        curl_setopt_array($ch, [
            CURLOPT_URL => "https://t.me/$channel/$msgid?embed=1",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => $proxy
        ]);

        $response = curl_exec($ch);
        if ($response === false) return false;

        // Extract view key and current views
        preg_match('/data-view="([^"]+)"/', $response, $keyMatch);
        preg_match('/<span class="tgme_widget_message_views">([^<]+)<\/span>/', $response, $viewMatch);

        $key = $keyMatch[1] ?? '';
        $currentViews = $viewMatch[1] ?? '';

        if (empty($key)) return false;

        // Final request to confirm view
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://t.me/v/?views=$key",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => $proxy
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response === 'true' ? $currentViews : false;
    }

    private function scrapeProxies() {
        $types = [
            'https' => 'https://api.proxyscrape.com/?request=displayproxies&proxytype=https&timeout=0',
            'http' => 'https://api.proxyscrape.com/?request=displayproxies&proxytype=http&timeout=0',
            'socks5' => 'https://api.proxyscrape.com/?request=displayproxies&proxytype=socks5&timeout=0'
        ];

        foreach ($types as $type => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            
            $proxies = curl_exec($ch);
            curl_close($ch);

            if ($proxies) {
                $proxyList = array_filter(explode("\n", trim($proxies)));
                if ($type === 'socks5') {
                    $this->socksProxyList = $proxyList;
                } else {
                    $this->proxyList = array_merge($this->proxyList, $proxyList);
                }
            }
        }
    }

    public function start() {
        if (empty($this->link)) {
            return ['status' => false, 'message' => 'No Telegram link provided'];
        }

        $parts = explode('/', $this->link);
        if (count($parts) < 5) {
            return ['status' => false, 'message' => 'Invalid Telegram link format'];
        }

        $channel = $parts[3];
        $msgid = $parts[4];

        $this->scrapeProxies();
        
        $successfulViews = 0;
        $failedAttempts = 0;

        foreach ($this->proxyList as $proxy) {
            $result = $this->sendSeen($channel, $msgid, trim($proxy));
            if ($result !== false) {
                $successfulViews++;
            } else {
                $failedAttempts++;
            }
        }

        foreach ($this->socksProxyList as $proxy) {
            $result = $this->sendSeen($channel, $msgid, 'socks5://' . trim($proxy));
            if ($result !== false) {
                $successfulViews++;
            } else {
                $failedAttempts++;
            }
        }

        return [
            'status' => true,
            'successful_views' => $successfulViews,
            'failed_attempts' => $failedAttempts,
            'total_proxies_used' => count($this->proxyList) + count($this->socksProxyList)
        ];
    }
}

// API Endpoint handling
$link = $_GET['link'] ?? '';

if (empty($link)) {
    echo json_encode([
        'status' => false,
        'message' => 'Please provide a Telegram post link'
    ]);
    exit;
}

$viewBot = new TelegramViewBot($link);
$result = $viewBot->start();

echo json_encode($result);
?>Enter file contents here
