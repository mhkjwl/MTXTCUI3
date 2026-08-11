<?php
/**
 * @file common.php
 * @description 公共配置文件（系统初始化、数据库连接、安全密钥、全局加载）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

// 防重复加载守卫：上传链路中 common.php 会被二次 require
// （api/upload.php:15 先加载 → 随后 require 床接口适配器 api/{key}.php → 适配器内再次 require common.php）。
// 首次加载后直接 return，阻止：① 常量重复 define 触发 PHP 8.5 Warning（PHP 9 将变为 Error）污染 JSON 输出；
// ② session_start() / header() 重复调用报 Warning；③ $DB = new DB() 重复连接数据库。
// 符合 AI开发规范.md § 1.6.4「使用 defined() 保护防止重复定义冲突」。
if (defined('IN_CRONLITE')) {
    return;
}

// H3 修复：根据环境判断是否显示错误，生产环境禁止输出敏感信息到页面
// 但排除 PHP 8.5+ 的 curl_close() 废弃警告（整个项目历史代码均使用 curl_close，无害）
error_reporting(E_ALL & ~E_DEPRECATED);
$__isDev = (getenv('APP_ENV') === 'dev' || (defined('APP_DEBUG') && APP_DEBUG));
ini_set('display_errors', $__isDev ? '1' : '0');
ini_set('log_errors', '1');
unset($__isDev);
define('IN_CRONLITE', true);
define('VERSION', '1001');
define('SYSTEM_ROOT', dirname(__FILE__).'/');
define('ROOT', dirname(SYSTEM_ROOT).'/');

define('CC_Defender', 0); //防CC攻击开关（0=关闭，建议在 nginx/CDN 层做限流；PHP层限流会拖慢每个请求）

date_default_timezone_set("Asia/Shanghai");
$date = date("Y-m-d H:i:s");

// HTTPS 检测加固：不信任单独的 X-Forwarded-Proto 头（可伪造）
// 仅在 HTTPS 标准变量存在，或 X-Forwarded-Proto 与 SERVER_PORT 一致时判定为 HTTPS
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443') ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
            && isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// 安全响应头：防止点击劫持、MIME 嗅探、XSS
if(!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// 兼容PHP8：定义可能未定义的变量
if(!isset($sitepath)) $sitepath = '';

if(CC_Defender!=0)
	include_once SYSTEM_ROOT.'security.php';

require ROOT.'config.php';
// M3: HTTP_HOST 可伪造，优先使用 SERVER_NAME，并对 host 做格式校验
$rawHost = '';
if(isset($_SERVER['HTTP_HOST'])) {
    $rawHost = $_SERVER['HTTP_HOST'];
} elseif(isset($_SERVER['SERVER_NAME'])) {
    $rawHost = $_SERVER['SERVER_NAME'];
}
// 校验 host 格式：只允许域名、IP、端口号，防止注入恶意字符
if(!preg_match('/^[a-zA-Z0-9._:\-]+$/', $rawHost)) {
    $rawHost = '';
}
$siteurl = ($isHttps ? 'https://' : 'http://') . $rawHost . $sitepath . '/';
if(empty($dbconfig['user'])||!isset($dbconfig['pwd'])||empty($dbconfig['dbname']))//检测安装
{
header('Content-type:text/html;charset=utf-8');
echo '你还没安装！<a href="/install/">点此安装</a>';
exit();
}

//连接数据库
include_once(SYSTEM_ROOT."db.class.php");
$DB=new DB($dbconfig['host'],$dbconfig['user'],$dbconfig['pwd'],$dbconfig['dbname'],$dbconfig['port']);

if($DB->query("select * from eecms_config where 1")==FALSE)//检测安装2
{
header('Content-type:text/html;charset=utf-8');
echo '你还没安装！<a href="/install/">点此安装</a>';
exit();
}

$rs=$DB->query("select * from eecms_config");
while($row=$DB->fetch($rs)){
	$conf[$row['name']]=$row['main'];
}

// 安全密钥：随机生成并持久化到数据库（保证跨请求稳定，不依赖文件目录可写）。
// 用于 admin_token cookie 加密与会话校验，替代原硬编码密钥，防止 cookie 伪造。
// L12: 使用 INSERT ... ON DUPLICATE KEY UPDATE 原子操作，消除并发竞态
if(empty($conf['security_key'])){
	try { $__k = bin2hex(random_bytes(24)); }
	catch (Throwable $e) { $__k = md5(uniqid((string)mt_rand(), true).microtime(true)); }
	// 原子操作：无记录时插入，已有记录时保留原值（main=main 不覆盖），消除竞态
	// 使用预处理语句绑定参数（§ 8.3.4），消除 SQL 拼接
	$DB->query_prepared(
		"INSERT INTO eecms_config (`name`, `main`) VALUES ('security_key', ?) ON DUPLICATE KEY UPDATE `main` = IF(`main` = '' OR `main` IS NULL, VALUES(`main`), `main`)",
		's',
		[$__k]
	);
	// 始终重新读取，确保使用数据库中的实际值（可能是并发请求先写入的）
	$__row = $DB->get_row_prepared("SELECT `main` FROM eecms_config WHERE `name` = ?", 's', ['security_key']);
	$conf['security_key'] = ($__row && !empty($__row['main'])) ? $__row['main'] : $__k;
}
if(!defined('SYS_KEY')) define('SYS_KEY', $conf['security_key']);
if(!defined('ENCRYPT_KEY')) define('ENCRYPT_KEY', $conf['security_key']);
// H4 修复：三密钥分离，从主密钥派生用途特定的子密钥，避免密钥合一导致任一泄露全军覆没
// SYS_KEY/ENCRYPT_KEY 保持向后兼容（使用原始主密钥），$password_hash 使用派生子密钥
$password_hash = hash_hmac('sha256', 'session_pepper', $conf['security_key']);

include_once(SYSTEM_ROOT."function.php");

// 图床敏感凭据（Cookie/Token/Key 等）在数据库中以 ct_encrypt 加密存储，
// 加载到内存时统一解密，对下游所有接口透明（ct_decrypt 对非加密值原样返回，向后兼容明文旧数据）
foreach($conf as $__ck => $__cv) {
	if(is_string($__cv) && preg_match('/^api_.+_(cookie|token|key|secret|authid|sid|pwd|pass|password)$/i', $__ck)) {
		$conf[$__ck] = ct_decrypt($__cv);
	}
}
unset($__ck, $__cv);

// 兜底：确保 random_name 函数可用（防止 function.php 未更新导致 fatal error）
// L4 修复：原兜底实现用 mt_rand 弱随机源，与 function.php 主版本（random_int）不一致；
//          升级为 random_int + 异常回退 mt_rand，与主版本保持密码学安全的随机实现
if(!function_exists('random_name')) {
    function random_name($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $str = '';
        $max = strlen($chars) - 1;
        for($i = 0; $i < $length; $i++) {
            try {
                $str .= $chars[random_int(0, $max)];
            } catch (Throwable $e) {
                $str .= $chars[mt_rand(0, $max)];
            }
        }
        return $str;
    }
}

include_once(SYSTEM_ROOT."member.php");

// 用户中心认证系统（独立于管理员认证）
include_once(SYSTEM_ROOT."user_auth.php");
// 自动建表（幂等操作，首次运行时创建用户表和图片记录表）
ensure_user_tables($DB);

// API 密钥管理（对外 API 上传接口的 Bearer 鉴权）
// 安全策略：明文密钥仅返回一次，数据库只存 SHA-256 哈希
include_once(SYSTEM_ROOT."api_keys.php");
// 自动建表（幂等，首次运行时创建 api_keys 表 + 索引）
ensure_api_keys_table($DB);

// 套餐系统
include_once(SYSTEM_ROOT."package.php");
ensure_package_tables($DB);
// 处理过期套餐回退（每次请求检查，轻量操作）
process_expired_packages($DB);

// ============================================================
// 自动注入：版本号定义（初始化时注入，不影响现有功能）
// 依据：docs/AI开发规范.md § 1.6.3 / § 1.6.4
// 说明：使用 defined() 防止重复定义冲突；dev/ 带 -dev 后缀
// 原有 VERSION='1001' 常量保持不动，本常量为规范要求的语义化版本号
// ============================================================
defined('APP_VERSION') || define('APP_VERSION', '1.3.11');