<?php
/**
 * @file router.php
 * @description 本地开发服务器路由（php -S 内置服务器），模拟 .htaccess 安全规则
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 本地开发服务器路由（仅用于 php -S 内置服务器，模拟 .htaccess 安全规则）
 * 生产环境（Apache/Nginx）请删除或忽略此文件
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$docroot = $_SERVER['DOCUMENT_ROOT'];

// 模拟 .htaccess：禁止直接访问敏感文件
$blocked = [
    '#^/config\.php$#i',                          // 数据库配置
    '#\.(bak|backup|old|orig|tmp|swp|save|~)$#i', // 备份/临时文件
    '#^/inc/#i',                                  // 核心类库目录
    '#^/docs/#i',                                 // 开发文档（版本日志/规范含备份路径等敏感信息，严禁暴露公网）
    '#^/logs/#i',                                 // 开发日志/调试文件
    '#^/backup/#i',                               // 全量备份 bf.zip（含数据库配置等敏感数据，严禁暴露公网）
    '#^/api/v1/data/#i',                          // v1 API 令牌库（store.json 含敏感 Token）
];
foreach ($blocked as $rule) {
    if (preg_match($rule, $uri)) {
        http_response_code(403);
        echo '403 Forbidden';
        return true;
    }
}

// 存在的静态文件/PHP 文件直接交给内置服务器处理
if ($uri !== '/' && file_exists($docroot . $uri) && !is_dir($docroot . $uri)) {
    return false;
}

// 目录请求：若无尾斜杠，301 重定向到带尾斜杠
// 原因：PHP 内置服务器在 router 模式下对 /admin（无尾斜杠）会直接执行 /admin/index.php，
// 但 URL 保持 /admin，导致页面内相对路径（如 login.php）被浏览器错误解析为 /login.php 而非 /admin/login.php。
// 加尾斜杠后浏览器以 /admin/ 为当前目录，相对路径才能正确解析。
if ($uri !== '/' && is_dir($docroot . $uri) && substr($uri, -1) !== '/') {
    header('Location: ' . $uri . '/', true, 301);
    return true;
}

// 目录请求（带尾斜杠）交给内置服务器（会自动找 index.php）
if (is_dir($docroot . $uri)) {
    return false;
}

// 其余不存在的路径 → 404 页面
http_response_code(404);
require $docroot . '/404.php';
return true;
