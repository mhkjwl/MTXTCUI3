<?php
/**
 * @file user_auth.php
 * @description 用户中心认证核心（独立于管理员认证，含登录/登出/权限/验证码/自动建表）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined('IN_CRONLITE'))exit();

// 引入 SMTP 邮件发送类
include_once(SYSTEM_ROOT.'smtp_mailer.php');

// 兼容：确保 $password_hash 已定义
if(!isset($password_hash)) $password_hash = '';

$isUserLoggedIn = false;
$currentUserId  = 0;
$currentUser    = null;
$currentUserRole = 'guest';

if(isset($_COOKIE['user_token'])) {
    $token = authcode(daddslashes($_COOKIE['user_token']), 'DECODE', SYS_KEY);
    if($token) {
        $parts = explode("\t", $token);
        $uid = isset($parts[0]) ? intval($parts[0]) : 0;
        $session = isset($parts[1]) ? $parts[1] : '';
        if($uid > 0 && $session !== '') {
            $row = $DB->get_row_prepared("SELECT * FROM eecms_users WHERE id = ? AND status = 1", 'i', [$uid]);
            if($row) {
                $sv = isset($row['session_version']) ? (int)$row['session_version'] : 0;
                // H6 修复：会话令牌改用 hash_hmac('sha256')，与管理员端一致，禁止 MD5
                $expectedSession = hash_hmac('sha256', $row['id'].'|'.$row['password'].'|'.$sv, $password_hash);
                if(hash_equals($expectedSession, $session)) {
                    $isUserLoggedIn  = true;
                    $currentUserId   = (int)$row['id'];
                    $currentUser     = $row;
                    $currentUserRole = $row['role'];
                }
            }
        }
    }
}

/**
 * 用户登录：设置 cookie
 * 包含 session_regenerate_id 防止会话固定攻击
 */
function user_login($userId, $password) {
    global $conf, $password_hash, $DB;
    // 会话固定攻击防护：重新生成 session ID
    if(session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    // 从数据库获取用户的 session_version，纳入会话哈希以支持服务端令牌失效
    // 用 SELECT * 并 isset 检查，兼容旧表可能缺失 session_version 列的情况
    $userRow = $DB->get_row_prepared("SELECT * FROM eecms_users WHERE id = ?", 'i', [$userId]);
    $sessionVersion = ($userRow && isset($userRow['session_version'])) ? (int)$userRow['session_version'] : 0;
    // H6 修复：会话令牌改用 hash_hmac('sha256')，与验证端一致
    $session = hash_hmac('sha256', $userId.'|'.$password.'|'.$sessionVersion, $password_hash);
    $token = authcode($userId."\t".$session, 'ENCODE', SYS_KEY);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    // M24 修复：user_token Cookie 增加 SameSite 属性，与 admin_token 一致
    setcookie('user_token', $token, [
        'expires'  => time() + 604800,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * 用户登出：清除 cookie
 */
function user_logout() {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('user_token', '', [
        'expires'  => time() - 604800,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * 使指定用户的所有会话令牌失效（通过递增 session_version）
 * @param int $userId 用户 ID
 */
function invalidate_user_sessions($userId) {
    global $DB;
    $DB->query_prepared("UPDATE eecms_users SET session_version = session_version + 1 WHERE id = ?", 'i', [$userId]);
}

/**
 * 检查上传权限
 * @return array [allowed => bool, msg => string]
 */
function check_upload_permission() {
    global $conf, $isUserLoggedIn;
    $requireLogin = isset($conf['upload_require_login']) && $conf['upload_require_login'] == '1';
    if($requireLogin && !$isUserLoggedIn) {
        return ['allowed' => false, 'msg' => '当前设置需要登录后才能上传图片'];
    }
    return ['allowed' => true, 'msg' => ''];
}

/**
 * 检查注册是否开启
 */
function is_registration_enabled() {
    global $conf;
    return !isset($conf['reg_enable']) || $conf['reg_enable'] == '1';
}

/**
 * 检查是否需要邮箱验证
 */
function is_email_verify_required() {
    global $conf;
    return isset($conf['reg_email_verify']) && $conf['reg_email_verify'] == '1';
}

/**
 * 检查 SMTP 是否已配置
 */
function is_smtp_configured() {
    global $conf;
    return !empty($conf['smtp_host']) && !empty($conf['smtp_user']);
}

/**
 * 生成 6 位数字验证码
 */
function generate_email_code() {
    try {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * 存储验证码到数据库
 * @param string $email 邮箱
 * @param string $code  验证码
 * @param string $ip    IP 地址
 * @return bool 是否成功（false 表示发送过于频繁）
 */
function store_email_code($email, $code, $ip = '') {
    global $DB;

    // 创建表（幂等）
    ensure_email_codes_table($DB);

    // 频率限制：同一邮箱 60 秒内只能发一次（预处理语句）
    $recent = $DB->get_row_prepared("SELECT id FROM eecms_email_codes WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1", 's', [$email]);
    if($recent) {
        return false;
    }

    // IP 级别频率限制：同一 IP 1 小时内最多发送 10 次验证码（防止批量轰炸）
    if($ip !== '') {
        $ipCount = $DB->get_row_prepared("SELECT COUNT(*) AS cnt FROM eecms_email_codes WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", 's', [$ip]);
        if($ipCount && (int)$ipCount['cnt'] >= 10) {
            return false;
        }
    }

    // 清理同一邮箱的旧未使用验证码
    $DB->query_prepared("DELETE FROM eecms_email_codes WHERE email = ? AND used = 0", 's', [$email]);

    $DB->query_prepared("INSERT INTO eecms_email_codes (email, code, purpose, created_at, expires_at, used, ip) VALUES (?, ?, 'register', NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, ?)", 'sss', [$email, $code, $ip]);
    return true;
}

/**
 * 校验验证码（含尝试次数限制和 IP 频率限制）
 * - 单个验证码最多 5 次尝试机会
 * - 同一 IP 10 分钟内最多 10 次验证尝试，超限锁定 10 分钟
 * - 使用 hash_equals 防止时序攻击
 * @param string $email 邮箱
 * @param string $code  验证码
 * @return array ['valid' => bool, 'msg' => string]
 */
function verify_email_code($email, $code) {
    global $DB;

    ensure_email_codes_table($DB);

    $email    = trim($email);
    $code     = trim($code);

    // IP 级别频率限制：基于服务端文件计数（清 Cookie/换会话无法绕过）
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $f2bKey = 'evcode_' . $ip;
    $lockRemain = f2b_locked_seconds($f2bKey);
    if($lockRemain > 0) {
        $wait = ceil($lockRemain / 60);
        return ['valid' => false, 'msg' => "验证尝试过于频繁，请 {$wait} 分钟后再试"];
    }

    // 查找最新的有效验证码（未使用、未过期、尝试次数 < 5）
    $row = $DB->get_row_prepared("SELECT id, code, attempts FROM eecms_email_codes WHERE email = ? AND used = 0 AND expires_at > NOW() AND attempts < 5 ORDER BY id DESC LIMIT 1", 's', [$email]);

    if(!$row) {
        if(f2b_hit($f2bKey, 10, 600, 600)) {
            return ['valid' => false, 'msg' => '验证尝试过于频繁，请 10 分钟后再试'];
        }
        // 区分"尝试次数耗尽"和"验证码不存在/已过期"
        $locked = $DB->get_row_prepared("SELECT id FROM eecms_email_codes WHERE email = ? AND used = 0 AND attempts >= 5 ORDER BY id DESC LIMIT 1", 's', [$email]);
        if($locked) {
            return ['valid' => false, 'msg' => '验证码尝试次数过多（5次），请重新获取验证码'];
        }
        return ['valid' => false, 'msg' => '验证码不正确或已过期，请重新获取'];
    }

    // 使用 hash_equals 防止时序攻击
    if(hash_equals((string)$row['code'], $code)) {
        // 验证成功：标记为已使用
        $DB->query_prepared("UPDATE eecms_email_codes SET used = 1 WHERE id = ?", 'i', [$row['id']]);
        f2b_reset($f2bKey);
        return ['valid' => true, 'msg' => '验证成功'];
    }

    // 验证失败：增加尝试次数
    $newAttempts = (int)$row['attempts'] + 1;
    $DB->query_prepared("UPDATE eecms_email_codes SET attempts = ? WHERE id = ?", 'ii', [$newAttempts, $row['id']]);
    if(f2b_hit($f2bKey, 10, 600, 600)) {
        return ['valid' => false, 'msg' => '验证尝试过于频繁，请 10 分钟后再试'];
    }

    $remaining = 5 - $newAttempts;
    if($remaining <= 0) {
        return ['valid' => false, 'msg' => '验证码尝试次数过多（5次），请重新获取验证码'];
    }
    return ['valid' => false, 'msg' => "验证码不正确，剩余尝试次数：{$remaining} 次"];
}

/**
 * 确保验证码表存在
 * 所有数据库调用均用 try/catch 保护
 */
function ensure_email_codes_table($DB) {
    static $created = false;
    if($created) return;
    $created = true;

    try { $DB->query("CREATE TABLE IF NOT EXISTS `eecms_email_codes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `email` varchar(128) NOT NULL,
        `code` varchar(10) NOT NULL,
        `purpose` varchar(32) NOT NULL DEFAULT 'register',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` datetime NOT NULL,
        `used` tinyint(1) NOT NULL DEFAULT 0,
        `attempts` int(11) NOT NULL DEFAULT 0,
        `ip` varchar(45) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_email` (`email`),
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e) {}

    // 补全旧表可能缺失的 attempts 列（兼容 MySQL 8.0，不支持 ADD COLUMN IF NOT EXISTS）
    try {
        $hasAttempts = @$DB->get_row("SHOW COLUMNS FROM `eecms_email_codes` LIKE 'attempts'");
        if(!$hasAttempts) {
            @$DB->query("ALTER TABLE `eecms_email_codes` ADD COLUMN `attempts` int(11) NOT NULL DEFAULT 0");
        }
    } catch(Throwable $e) {}
}

/**
 * 确保用户表存在（自动建表，兼容首次安装）
 * 使用静态变量确保每次请求只执行一次
 * 所有数据库调用均用 try/catch 保护，防止 PHP 8 TypeError
 */
function ensure_user_tables($DB) {
    static $checked = false;
    if($checked) return;
    $checked = true;

    // 注册设置默认值 + SMTP 邮箱配置默认值（幂等插入）
    $defaults = [
        'reg_enable' => '1',
        'reg_email_verify' => '0',
        'upload_require_login' => '0',
        'smtp_host' => '',
        'smtp_port' => '465',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'ssl',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
    ];
    foreach($defaults as $key => $val) {
        try { $DB->query_prepared("INSERT IGNORE INTO eecms_config SET `name` = ?, `main` = ?", 'ss', [$key, $val]); } catch(Throwable $e) {}
    }

    // 建表（幂等，安装时已创建则跳过）
    $createStatements = [
        "CREATE TABLE IF NOT EXISTS `eecms_users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(64) NOT NULL,
            `password` varchar(255) NOT NULL,
            `email` varchar(128) NOT NULL DEFAULT '',
            `role` enum('super_admin','user') NOT NULL DEFAULT 'user',
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `email_verified` tinyint(1) NOT NULL DEFAULT 0,
            `avatar` varchar(255) DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_login` datetime DEFAULT NULL,
            `upload_count` int(11) NOT NULL DEFAULT 0,
            `session_version` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            KEY `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `eecms_images` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL DEFAULT 0,
            `username` varchar(64) NOT NULL DEFAULT '',
            `filename` varchar(255) NOT NULL,
            `url` text NOT NULL,
            `thumb_url` text,
            `size` bigint(20) NOT NULL DEFAULT 0,
            `api_type` varchar(32) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach($createStatements as $sql) {
        try { $DB->query($sql); } catch(Throwable $e) {}
    }

    // 补全可能缺失的列（旧版安装可能未包含这些字段）
    // 注意：SHOW COLUMNS / ALTER TABLE 属于 DDL 语句，表名和列名为内部常量（非用户输入），
    // 不支持预处理占位符，值来源于 $columnsToCheck 静态数组，安全无注入风险
    $columnsToCheck = [
        ['eecms_users', 'email_verified', "tinyint(1) NOT NULL DEFAULT 0"],
        ['eecms_users', 'avatar', "varchar(255) DEFAULT ''"],
        ['eecms_users', 'upload_count', "int(11) NOT NULL DEFAULT 0"],
        ['eecms_users', 'last_login', "datetime DEFAULT NULL"],
        ['eecms_users', 'session_version', "int(11) NOT NULL DEFAULT 0"],
    ];
    foreach($columnsToCheck as $col) {
        try {
            $exists = @$DB->get_row("SHOW COLUMNS FROM `{$col[0]}` LIKE '{$col[1]}'");
            if(!$exists) {
                @$DB->query("ALTER TABLE `{$col[0]}` ADD COLUMN `{$col[1]}` {$col[2]}");
            }
        } catch(Throwable $e) {}
    }

    // 创建验证码表
    ensure_email_codes_table($DB);

    // 如果用户表为空，创建默认超级管理员
    // H16 修复：独立管理员密码设置流程，避免直接复用 admin_pwd 配置值
    // 原问题：admin_pwd 可能是 MD5/SHA1 哈希，password_hash() 会再次哈希该哈希字符串，
    //         导致用户表中的密码无法被任何已知明文验证，超级管理员永久无法登录用户系统
    // 修复策略：
    //   1. 仅当 admin_pwd 已是 bcrypt 哈希时才直接复用（管理员用同一明文密码登录两套系统）
    //   2. 否则生成密码学安全的随机强密码，明文写入一次性密钥文件（dev/logs/，权限 0600），
    //      供管理员首次读取登录后立即删除；不写入 error_log（遵循 H11 约束）
    try {
        $cnt = (int)$DB->count("SELECT COUNT(*) FROM eecms_users");
    } catch(Throwable $e) {
        $cnt = 0;
    }
    if($cnt == 0) {
        global $conf;
        $adminUser = isset($conf['admin_user']) ? $conf['admin_user'] : 'admin';
        $storedPwd = isset($conf['admin_pwd']) ? (string)$conf['admin_pwd'] : '';
        $isBcrypt  = (strpos($storedPwd, '$2y$') === 0);
        $isArgon2  = (strpos($storedPwd, '$argon2') === 0);

        if($isBcrypt || $isArgon2) {
            // admin_pwd 已是现代哈希格式 → 直接复用（管理员用同一明文密码登录两套系统）
            $hash = $storedPwd;
        } else {
            // admin_pwd 为空或为 MD5/SHA1 等旧格式 → 无法反推明文，生成独立随机密码
            try { $plainPwd = bin2hex(random_bytes(10)); }
            catch (Throwable $e) { $plainPwd = bin2hex(random_bytes(10)); }
            $hash = password_hash($plainPwd, PASSWORD_DEFAULT);
            // 将明文写入一次性密钥文件（非日志，权限 0600），管理员读取后应立即删除
            $keyFile = ROOT . 'logs' . DIRECTORY_SEPARATOR . 'superadmin_initial_password.txt';
            $keyDir  = dirname($keyFile);
            if(!is_dir($keyDir)) { @mkdir($keyDir, 0700, true); }
            // 文件内容包含用户名、明文密码、生成时间、删除提示
            $content = "EECMS 超级管理员初始密码（一次性，登录后请立即删除本文件并修改密码）\n"
                     . "生成时间：" . date('Y-m-d H:i:s') . "\n"
                     . "用户名：" . $adminUser . "\n"
                     . "初始密码：" . $plainPwd . "\n"
                     . "安全提示：\n"
                     . "  1. 请尽快登录用户中心并修改密码\n"
                     . "  2. 修改密码后立即删除本文件\n"
                     . "  3. 本文件权限应为 0600，仅服务器管理员可读\n";
            @file_put_contents($keyFile, $content, LOCK_EX);
            @chmod($keyFile, 0600);
            // 仅记录事件提示，不包含明文密码（遵循 H11 约束）
            error_log('[eecms] 已为超级管理员生成随机初始密码，明文已写入 ' . $keyFile . '，请及时登录并修改密码后删除该文件');
            unset($plainPwd, $content);
        }
        // 预处理语句插入管理员（§ 8.3.4）
        try {
            $DB->query_prepared(
                "INSERT INTO eecms_users (username, password, email, role, status, email_verified, created_at) VALUES (?, ?, '', 'super_admin', 1, 1, NOW())",
                'ss',
                [$adminUser, $hash]
            );
        } catch(Throwable $e) {}
    }
}
