<?php
/**
 * @file api/v1/index.php
 * @description picui适配器v1 API根，返回服务信息与可用端点列表
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

picui_json(true, 'ok', [
    'service' => 'picui-adapter',
    'version' => 1,
    'endpoints' => [
        '/profile',
        '/strategies',
        '/images/tokens',
        '/upload',
        '/images',
        '/images/{key}',
        '/albums',
        '/albums/{id}',
    ],
]);