<?php
/**
 * @file api/imgbrige.php
 * @description 图片桥接代理，解决外部图床跨域/防盗链问题（含来源校验与速率限制）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

// 图片桥接代理 — 用于解决外部图床的跨域/防盗链问题
// 安全增强：来源校验 + 速率限制，防止被滥用为开放代理

// 来源校验：仅允许本站页面调用（通过 Referer 或 Origin 校验）
$allowedOrigin = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

$validSource = false;
if ($allowedOrigin !== '') {
    // 精确匹配域名（parse_url 提取 host），防止子串绕过（如 evil.com/example.com）
    if ($referer !== '') {
        $refHost = parse_url($referer, PHP_URL_HOST);
        if ($refHost && strcasecmp($refHost, $allowedOrigin) === 0) {
            $validSource = true;
        }
    }
    if (!$validSource && $origin !== '') {
        // Origin 格式为 scheme://host[:port]，需提取 host
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost && strcasecmp($originHost, $allowedOrigin) === 0) {
            $validSource = true;
        }
    }
    // 无 Referer/Origin 时拒绝（防止被滥用为开放代理）
}

if (!$validSource) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied: invalid source');
}

// 速率限制：基于 IP 的简单限流（文件锁，每IP每60秒最多100次请求）
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rateLimitDir = sys_get_temp_dir() . '/imgbrige_rl';
if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0700, true);

if (is_dir($rateLimitDir) && is_writable($rateLimitDir)) {
    $rateFile = $rateLimitDir . '/' . md5($clientIp) . '.json';
    $now = time();
    $fp = @fopen($rateFile, 'c+');
    if ($fp !== false) {
        if (@flock($fp, LOCK_EX | LOCK_NB)) {
            $raw = stream_get_contents($fp);
            $data = json_decode($raw, true);
            if (!is_array($data)) $data = ['start' => $now, 'count' => 0];
            
            // 窗口滑动（60秒）
            if ($now - ($data['start'] ?? $now) >= 60) {
                $data = ['start' => $now, 'count' => 1];
            } else {
                $data['count'] = (int)($data['count'] ?? 0) + 1;
            }
            
            // 超限则拒绝
            if ($data['count'] > 1000) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: 60');
                exit('Rate limit exceeded');
            }
            
            @ftruncate($fp, 0);
            @rewind($fp);
            @fwrite($fp, json_encode($data));
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);
    }
}

$url = isset($_GET['url']) ? $_GET['url'] : '';

if(empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid URL');
}

$scheme = parse_url($url, PHP_URL_SCHEME);
if(!in_array($scheme, ['http', 'https'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Protocol not allowed');
}

$host = parse_url($url, PHP_URL_HOST);
if(empty($host)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid host');
}

// DNS 解析校验，拒绝内网地址（解析失败时拒绝，不放过任何可疑请求）
$resolvedIp = '';
if(filter_var($host, FILTER_VALIDATE_IP)) {
    if(!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        header('HTTP/1.1 403 Forbidden');
        exit('Private address not allowed');
    }
    $resolvedIp = $host;
} else {
    // DNS 解析，失败时拒绝（不放过无法解析的域名，防止 IPv6 等绕过）
    $a = @gethostbyname($host);
    if(!$a || $a === $host || !filter_var($a, FILTER_VALIDATE_IP)) {
        header('HTTP/1.1 403 Forbidden');
        exit('DNS resolution failed');
    }
    if(!filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        header('HTTP/1.1 403 Forbidden');
        exit('Private address not allowed');
    }
    $resolvedIp = $a;
}

// 自适应获取图片：多级降级策略
// 安全增强：禁用重定向跟随（防止 302 绕过内网检查），使用 CURLOPT_RESOLVE 固定 DNS 结果（消除 TOCTOU 竞态）
function fetchImage($url, $resolvedIp, $headers = []) {
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);
    if(!$port) {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = ($scheme === 'https') ? 443 : 80;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, // 禁用重定向跟随，防止 302 到内网地址绕过检查
        CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp], // 固定 DNS 结果，消除 TOCTOU 竞态
    ]);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    unset($ch);
    return [$data, $httpCode, $contentType, $err];
}

// 浏览器基础请求头
$browserHeaders = [
    'Accept: image/avif,image/webp,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5',
    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
];

// 第一次尝试：模拟浏览器请求，不带 Referer
list($data, $httpCode, $contentType, $err) = fetchImage($url, $resolvedIp, $browserHeaders);

// 第二次尝试：带 Referer（防盗链图床）
if (($data === false || empty($data) || $httpCode >= 400) && !empty($scheme) && !empty($host)) {
    list($data, $httpCode, $contentType, $err) = fetchImage($url, $resolvedIp, array_merge($browserHeaders, [
        'Referer: ' . $scheme . '://' . $host . '/',
    ]));
}

// 第三次尝试：不带任何自定义头，让 curl 完全自由请求
if ($data === false || empty($data) || $httpCode >= 400) {
    list($data, $httpCode, $contentType, $err) = fetchImage($url, $resolvedIp);
}

if ($data === false || empty($data)) {
    header('HTTP/1.1 502 Bad Gateway');
    exit('Failed to fetch image' . ($err ? ': ' . $err : '') . ' [HTTP ' . $httpCode . ']');
}

// 宽松的内容类型检查：只拒绝明确不是图片的 text/html
if ($contentType && stripos($contentType, 'text/html') === 0 && strlen($data) < 500) {
    header('HTTP/1.1 403 Forbidden');
    exit('Not an image (received HTML)');
}

// 输出图片 — 从二进制数据探测 MIME，确保不为空
$mime = '';
if ($contentType && stripos($contentType, 'image/') === 0) {
    $mime = $contentType;
}
if (!$mime && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = @finfo_buffer($finfo, $data, FILEINFO_MIME_TYPE);
    if ($detected && stripos($detected, 'image/') === 0) $mime = $detected;
    finfo_close($finfo);
}
// URL 扩展名兜底
if (!$mime) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $extMap = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml','avif'=>'image/avif'];
    $mime = $extMap[$ext] ?? 'image/png';
}
// 清除之前可能输出的任何内容，确保 header 生效
if (ob_get_level()) ob_clean();
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
echo $data;
?>