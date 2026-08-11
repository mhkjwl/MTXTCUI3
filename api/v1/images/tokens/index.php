<?php
/**
 * @file api/v1/images/tokens/index.php
 * @description picui适配器v1上传令牌生成接口（批量签发上传Token）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'GET', 'HEAD'], true)) {
    picui_failure('Method Not Allowed', 405, 405);
}

picui_require_auth(false);

$num = (int)($_POST['num'] ?? 1);
$seconds = (int)($_POST['seconds'] ?? 3600);
if ($num < 1) {
    $num = 1;
}
if ($num > 100) {
    picui_failure('生成数量最大为100', 400, 201);
}
if ($seconds < 1) {
    $seconds = 1;
}
if ($seconds > 2626560) {
    picui_failure('有效期最大为一个月', 400, 201);
}

// Pre-check: limit total active tokens to prevent store.json bloat
$preStore = picui_load_store();
picui_purge_expired($preStore);
if (count($preStore['tokens']) > 500) {
    picui_failure('活跃令牌数量已达上限(500)，请先等待部分令牌过期', 400, 201);
}

$tokens = [];
$store = picui_atomic_store(function(&$store) use ($num, $seconds, &$tokens) {
    picui_purge_expired($store);
    for ($i = 0; $i < $num; $i++) {
        $token = picui_random_token(24);
        $expiredAt = date('Y-m-d H:i:s', time() + $seconds);
        $tokens[] = [
            'token' => $token,
            'expired_at' => $expiredAt,
        ];
        $store['tokens'][] = [
            'token' => $token,
            'expired_at' => $expiredAt,
        ];
    }
    return true;
});

picui_json(true, 'ok', [
    'tokens' => $tokens,
]);