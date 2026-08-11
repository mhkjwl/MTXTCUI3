<?php
/**
 * @file api/v1/strategies/index.php
 * @description picui适配器v1上传策略查询接口（GET/HEAD，支持关键字搜索）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    picui_failure('Method Not Allowed', 405, 405);
}

picui_require_auth(false);

$store = picui_load_store();
$q = trim((string)($_GET['q'] ?? ''));
$strategies = array_values(array_filter($store['strategies'], function ($row) use ($q) {
    if ($q === '') {
        return true;
    }
    return mb_stripos((string)($row['name'] ?? ''), $q) !== false;
}));

picui_json(true, 'ok', [
    'strategies' => $strategies,
]);