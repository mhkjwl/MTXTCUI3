<?php
/**
 * @file package.php
 * @description 套餐系统核心逻辑：套餐、用户订阅、兑换码、接口分组与权限继承
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 套餐系统核心逻辑
 *
 * 数据表：
 *   eecms_packages        - 套餐表
 *   eecms_user_subs       - 用户订阅表
 *   eecms_redeem_codes    - 兑换码表
 *   eecms_api_groups      - 接口分组表
 *   eecms_api_group_items - 分组接口关联表（多对多）
 *   eecms_admin_logs      - 管理员操作日志表
 *
 * 核心规则：
 *   规则A（覆盖）：新套餐等级 > 当前 → 到期时间 = 当前时间 + 新套餐天数
 *   规则B（顺延）：新套餐等级 == 当前 → 到期时间 = 原到期 + 新套餐天数
 *   规则B'（永久拦截）：当前套餐为永久且同级兑换 → 拦截（永久套餐无需续期）
 *   规则C（禁止降级）：新套餐等级 < 当前 → 拦截（仅到期后自动降级或管理员强制）
 *   规则D（过期兜底）：当前套餐已过期 → 按规则A处理
 *   权限继承（并集）：VIP(N) 可用分组 = Level 1~N 所有绑定的接口并集
 */

if(!defined('IN_CRONLITE')) exit('Access Denied');

// 永久套餐到期时间哨兵值：days=0 表示永久，用远期日期表示，
// 避免 process_expired_packages 把 expire_time=now 误判为已过期而立即回退到默认套餐
if(!defined('PKG_PERMANENT_EXPIRE')) define('PKG_PERMANENT_EXPIRE', '2099-12-31 23:59:59');

/**
 * 安全执行查询并获取单行（PHP 8 兼容，表不存在时返回 null 而非 TypeError）
 */
function pkg_safe_get_row($DB, $sql) {
    try {
        $rs = @$DB->query($sql);
        if(!$rs) return null;
        $row = @$DB->fetch($rs);
        return $row ?: null;
    } catch(Throwable $e) {
        return null;
    }
}

/**
 * 安全执行查询并获取多行（PHP 8 兼容）
 */
function pkg_safe_get_all($DB, $sql) {
    try {
        $rs = @$DB->query($sql);
        if(!$rs) return [];
        $list = [];
        while(($row = @$DB->fetch($rs))) {
            $list[] = $row;
        }
        return $list;
    } catch(Throwable $e) {
        return [];
    }
}

/**
 * 安全执行 COUNT 查询（PHP 8 兼容）
 */
function pkg_safe_count($DB, $sql) {
    try {
        $val = @$DB->count($sql);
        return (int)$val;
    } catch(Throwable $e) {
        return 0;
    }
}

/**
 * 安全执行写操作（PHP 8 兼容）
 */
function pkg_safe_query($DB, $sql) {
    try {
        return @$DB->query($sql);
    } catch(Throwable $e) {
        return false;
    }
}

/**
 * 安全执行预处理查询并获取单行（PHP 8 兼容）
 */
function pkg_safe_get_row_prepared($DB, $sql, $types, $params) {
    try {
        $row = @$DB->get_row_prepared($sql, $types, $params);
        return $row ?: null;
    } catch(Throwable $e) {
        return null;
    }
}

/**
 * 安全执行预处理写操作（PHP 8 兼容）
 * 返回影响行数（int），失败返回 false
 */
function pkg_safe_query_prepared($DB, $sql, $types, $params) {
    try {
        return @$DB->query_prepared($sql, $types, $params);
    } catch(Throwable $e) {
        return false;
    }
}

/**
 * 安全执行预处理 COUNT 查询（PHP 8 兼容）
 */
function pkg_safe_count_prepared($DB, $sql, $types, $params) {
    try {
        return (int)@$DB->count_prepared($sql, $types, $params);
    } catch(Throwable $e) {
        return 0;
    }
}

/**
 * 安全执行预处理查询并获取多行（PHP 8 兼容）
 */
function pkg_safe_get_all_prepared($DB, $sql, $types, $params) {
    try {
        $rs = @$DB->fetch_all_prepared($sql, $types, $params);
        if($rs === false) return [];
        $list = [];
        while(($row = @$DB->fetch($rs))) {
            $list[] = $row;
        }
        return $list;
    } catch(Throwable $e) {
        return [];
    }
}

/**
 * 建表（幂等，首次调用时创建）
 * 所有 CREATE TABLE 和后续查询均使用安全方式，避免 PHP 8 TypeError
 */
function ensure_package_tables($DB) {
    static $checked = false;
    if($checked) return;

    // 所有建表语句均用 try/catch + @ 抑制错误，确保不会因单条失败而中断
    $statements = [
        // 套餐表
        "CREATE TABLE IF NOT EXISTS `eecms_packages` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL COMMENT '套餐名称',
            `level` int(11) NOT NULL DEFAULT 0 COMMENT '等级权重，越大越高',
            `storage_limit` bigint(20) NOT NULL DEFAULT 0 COMMENT '存储上限（字节）',
            `days` int(11) NOT NULL DEFAULT 0 COMMENT '有效天数',
            `group_id` int(11) NOT NULL DEFAULT 0 COMMENT '绑定接口分组ID',
            `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认套餐',
            `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用(软删除)',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_level` (`level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // 用户订阅表
        "CREATE TABLE IF NOT EXISTS `eecms_user_subs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `package_id` int(11) NOT NULL DEFAULT 0,
            `package_level` int(11) NOT NULL DEFAULT 0 COMMENT '冗余：开通时套餐等级',
            `package_name` varchar(64) NOT NULL DEFAULT '' COMMENT '冗余：开通时套餐名',
            `expire_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '到期时间',
            `custom_storage` bigint(20) DEFAULT NULL COMMENT '自定义存储覆盖（NULL=跟随套餐）',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // 兑换码表
        "CREATE TABLE IF NOT EXISTS `eecms_redeem_codes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `code` varchar(64) NOT NULL COMMENT '兑换码',
            `target_package_id` int(11) NOT NULL COMMENT '目标套餐ID',
            `custom_days` int(11) DEFAULT NULL COMMENT '自定义天数（NULL=取套餐天数）',
            `used_user_id` int(11) NOT NULL DEFAULT 0 COMMENT '使用者ID（0=未使用）',
            `used_at` datetime DEFAULT NULL COMMENT '使用时间',
            `expires_at` datetime DEFAULT NULL COMMENT '兑换码过期时间',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `batch_no` varchar(32) NOT NULL DEFAULT '' COMMENT '批次号',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_batch` (`batch_no`),
            KEY `idx_target` (`target_package_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // 接口分组表
        "CREATE TABLE IF NOT EXISTS `eecms_api_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL COMMENT '分组名称',
            `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分组描述',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // 分组-接口关联表
        "CREATE TABLE IF NOT EXISTS `eecms_api_group_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_id` int(11) NOT NULL,
            `api_type` varchar(32) NOT NULL COMMENT '图床标识（如 local, imgbb, s3:0）',
            `is_s3` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否S3存储',
            `s3_id` int(11) NOT NULL DEFAULT 0 COMMENT 'S3配置ID',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_group_api` (`group_id`, `api_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // 管理员操作日志表
        "CREATE TABLE IF NOT EXISTS `eecms_admin_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `admin_user` varchar(64) NOT NULL DEFAULT '',
            `action` varchar(64) NOT NULL DEFAULT '' COMMENT '操作类型',
            `target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '目标类型',
            `target_id` int(11) NOT NULL DEFAULT 0 COMMENT '目标ID',
            `detail` text COMMENT '操作详情JSON',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin` (`admin_user`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach($statements as $sql) {
        pkg_safe_query($DB, $sql);
    }

    // 为过期扫描补充索引（process_expired_packages 按 expire_time 过滤）
    try {
        $idxExists = @$DB->get_row("SHOW INDEX FROM `eecms_user_subs` WHERE Key_name='idx_expire'");
        if(!$idxExists) {
            @$DB->query("ALTER TABLE `eecms_user_subs` ADD INDEX `idx_expire` (`expire_time`)");
        }
    } catch(Throwable $e) {}

    // 初始化默认套餐（如果表为空）
    $cnt = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_packages");
    if($cnt === 0) {
        pkg_safe_query($DB, "INSERT INTO eecms_packages (name, level, storage_limit, days, group_id, is_default, status) VALUES ('免费版', 0, 104857600, 0, 0, 1, 1)");
    }

    // 初始化默认配置项
    $defaults = [
        'guest_group_id' => '0',
        'guest_hide_local' => '0',
    ];
    foreach($defaults as $k => $v) {
        $exists = pkg_safe_get_row_prepared($DB, "SELECT name FROM eecms_config WHERE name=?", 's', [$k]);
        if(!$exists) {
            pkg_safe_query_prepared($DB, "INSERT INTO eecms_config SET name=?, main=?", 'ss', [$k, $v]);
        }
    }

    $checked = true;
}

/**
 * 记录管理员操作日志
 */
function log_admin_action($DB, $action, $targetType='', $targetId=0, $detail=[]) {
    global $conf;
    $adminUser = isset($conf['admin_user']) ? $conf['admin_user'] : 'unknown';
    $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE);
    $ip = real_ip();
    $sql = "INSERT INTO eecms_admin_logs (admin_user, action, target_type, target_id, detail, ip) VALUES (?, ?, ?, ?, ?, ?)";
    pkg_safe_query_prepared($DB, $sql, 'sssiss', [$adminUser, $action, $targetType, (int)$targetId, $detailJson, $ip]);
}

/**
 * 获取所有套餐（按level升序）
 */
function get_all_packages($DB) {
    return pkg_safe_get_all($DB, "SELECT p.*, g.name AS group_name FROM eecms_packages p LEFT JOIN eecms_api_groups g ON p.group_id=g.id WHERE p.status=1 ORDER BY p.level ASC");
}

/**
 * 获取默认套餐
 */
function get_default_package($DB) {
    return pkg_safe_get_row($DB, "SELECT * FROM eecms_packages WHERE is_default=1 AND status=1 LIMIT 1");
}

/**
 * 获取指定套餐
 */
function get_package($DB, $id) {
    $id = intval($id);
    return pkg_safe_get_row_prepared($DB, "SELECT * FROM eecms_packages WHERE id=? AND status=1", 'i', [$id]);
}

/**
 * 获取用户当前订阅
 * @return array|null 包含 package_id, expire_time, custom_storage, package_name, package_level
 */
function get_user_subscription($DB, $userId) {
    $userId = intval($userId);
    if($userId <= 0) return null;
    return pkg_safe_get_row_prepared($DB, "SELECT * FROM eecms_user_subs WHERE user_id=?", 'i', [$userId]);
}

/**
 * 获取用户当前有效套餐等级
 * @return array ['level'=>int, 'package_id'=>int, 'expire_time'=>string, 'is_expired'=>bool, 'storage_limit'=>int(-1=无限制), 'custom_storage'=>int|null]
 */
function get_user_effective_package($DB, $userId) {
    $userId = intval($userId);
    $result = [
        'level' => 0,
        'package_id' => 0,
        'package_name' => '',
        'expire_time' => null,
        'is_expired' => false,
        'is_permanent' => false,
        'storage_limit' => 0,
        'custom_storage' => null,
        'days' => 0,
    ];

    if($userId <= 0) {
        // 未登录用户：默认等级0
        $default = get_default_package($DB);
        if($default) {
            $result['package_id'] = (int)$default['id'];
            $result['package_name'] = $default['name'];
            $result['storage_limit'] = (int)$default['storage_limit'];
            $result['days'] = (int)$default['days'];
        }
        return $result;
    }

    $sub = get_user_subscription($DB, $userId);
    if(!$sub) {
        // 无订阅记录 → 分配默认套餐
        $default = get_default_package($DB);
        if($default) {
            $result['package_id'] = (int)$default['id'];
            $result['package_name'] = $default['name'];
            $result['level'] = 0;
            $result['storage_limit'] = (int)$default['storage_limit'];
            $result['days'] = (int)$default['days'];
        }
        return $result;
    }

    $result['package_id'] = (int)$sub['package_id'];
    $result['package_name'] = $sub['package_name'];
    $result['level'] = (int)$sub['package_level'];
    $result['expire_time'] = $sub['expire_time'];
    $result['is_permanent'] = (!empty($sub['expire_time']) && substr($sub['expire_time'], 0, 4) >= '2099');
    $result['custom_storage'] = $sub['custom_storage'] !== null ? (int)$sub['custom_storage'] : null;

    // 检查是否过期
    $now = date('Y-m-d H:i:s');
    if($sub['expire_time'] < $now && (int)$sub['package_level'] > 0) {
        $result['is_expired'] = true;
        // 过期 → 回退到默认套餐
        $default = get_default_package($DB);
        if($default) {
            $result['level'] = 0;
            $result['package_id'] = (int)$default['id'];
            $result['package_name'] = $default['name'];
            $result['storage_limit'] = (int)$default['storage_limit'];
        }
    } else {
        // 未过期 → 取套餐存储限制（-1 表示无限制）
        $pkg = get_package($DB, $sub['package_id']);
        if($pkg) {
            $result['storage_limit'] = (int)$pkg['storage_limit'];
            $result['days'] = (int)$pkg['days'];
        }
    }

    // 自定义存储覆盖（custom_storage 也支持 -1 表示无限制）
    if($result['custom_storage'] !== null) {
        $result['storage_limit'] = $result['custom_storage'];
    }

    return $result;
}

/**
 * 获取用户可用存储配额（字节）
 * 返回 -1 表示无限制
 */
function get_user_storage_limit($DB, $userId) {
    $pkg = get_user_effective_package($DB, $userId);
    // storage_limit == -1 表示无限制，直接返回 -1
    if((int)$pkg['storage_limit'] === -1) {
        return -1;
    }
    return (int)$pkg['storage_limit'];
}

/**
 * 获取用户已用存储（字节）
 */
function get_user_storage_used($DB, $userId) {
    $userId = intval($userId);
    if($userId <= 0) return 0;
    return pkg_safe_count_prepared($DB, "SELECT COALESCE(SUM(size),0) FROM eecms_images WHERE user_id=?", 'i', [$userId]);
}

/**
 * 切换用户套餐（核心逻辑：规则A/B/C/D）
 *
 * @param $DB
 * @param $userId int 用户ID
 * @param $newPackageId int 新套餐ID
 * @param $extraDays int 额外天数（0=取套餐自身天数）
 * @param $force bool 是否强制切换（跳过降级拦截，管理员操作用）
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function switch_user_package($DB, $userId, $newPackageId, $extraDays=0, $force=false) {
    $userId = intval($userId);
    $newPackageId = intval($newPackageId);

    if($userId <= 0) return ['ok'=>false, 'msg'=>'用户ID无效'];
    $newPkg = get_package($DB, $newPackageId);
    if(!$newPkg) return ['ok'=>false, 'msg'=>'目标套餐不存在'];

    $newLevel = (int)$newPkg['level'];
    $newDays = $extraDays > 0 ? intval($extraDays) : (int)$newPkg['days'];

    // 获取当前订阅
    $sub = get_user_subscription($DB, $userId);
    $now = date('Y-m-d H:i:s');

    if(!$sub) {
        // 无订阅 → 首次开通（规则A）
        $expireTime = $newDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$newDays} days")) : PKG_PERMANENT_EXPIRE;
        $sql = "INSERT INTO eecms_user_subs (user_id, package_id, package_level, package_name, expire_time) VALUES (?, ?, ?, ?, ?)";
        $ok = pkg_safe_query_prepared($DB, $sql, 'iiiss', [$userId, $newPackageId, $newLevel, $newPkg['name'], $expireTime]);
        if(!$ok) return ['ok'=>false, 'msg'=>'开通失败：' . $DB->error()];
        return ['ok'=>true, 'msg'=>'套餐开通成功，有效期至 ' . $expireTime];
    }

    $currentLevel = (int)$sub['package_level'];
    $currentExpire = $sub['expire_time'];
    $isExpired = ($currentExpire < $now) && $currentLevel > 0;

    // 规则D：已过期 → 按规则A处理（覆盖）
    if($isExpired) {
        $expireTime = $newDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$newDays} days")) : PKG_PERMANENT_EXPIRE;
        $sql = "UPDATE eecms_user_subs SET package_id=?, package_level=?, package_name=?, expire_time=? WHERE user_id=?";
        pkg_safe_query_prepared($DB, $sql, 'iissi', [$newPackageId, $newLevel, $newPkg['name'], $expireTime, $userId]);
        return ['ok'=>true, 'msg'=>'套餐已过期，已重新开通，有效期至 ' . $expireTime];
    }

    // 规则C：禁止降级
    if($newLevel < $currentLevel && !$force) {
        return ['ok'=>false, 'msg'=>'无法降级，您已拥有更高等级套餐（当前等级：' . $currentLevel . '）'];
    }

    // 规则A：新等级 > 当前 → 覆盖
    if($newLevel > $currentLevel) {
        $expireTime = $newDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$newDays} days")) : PKG_PERMANENT_EXPIRE;
        $sql = "UPDATE eecms_user_subs SET package_id=?, package_level=?, package_name=?, expire_time=? WHERE user_id=?";
        pkg_safe_query_prepared($DB, $sql, 'iissi', [$newPackageId, $newLevel, $newPkg['name'], $expireTime, $userId]);
        return ['ok'=>true, 'msg'=>'套餐已升级至 ' . $newPkg['name'] . '，有效期至 ' . $expireTime];
    }

    // 规则B：同级 → 顺延
    if($newLevel == $currentLevel) {
        // 永久套餐不允许同级兑换（再兑换也是无意义的，永久有效期无法顺延）
        $isCurrentPermanent = !empty($currentExpire) && substr($currentExpire, 0, 4) >= '2099';
        if($isCurrentPermanent) {
            return ['ok'=>false, 'msg'=>'您已拥有该永久套餐，无需再次兑换，如需升级请兑换更高等级套餐'];
        }
        $expireTime = $newDays > 0 ? date('Y-m-d H:i:s', strtotime($currentExpire . " +{$newDays} days")) : PKG_PERMANENT_EXPIRE;
        $sql = "UPDATE eecms_user_subs SET package_id=?, package_level=?, package_name=?, expire_time=? WHERE user_id=?";
        pkg_safe_query_prepared($DB, $sql, 'iissi', [$newPackageId, $newLevel, $newPkg['name'], $expireTime, $userId]);
        return ['ok'=>true, 'msg'=>'套餐已续期，有效期至 ' . $expireTime];
    }

    // 强制降级（管理员操作）
    if($newLevel < $currentLevel && $force) {
        $expireTime = $newDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$newDays} days")) : PKG_PERMANENT_EXPIRE;
        $sql = "UPDATE eecms_user_subs SET package_id=?, package_level=?, package_name=?, expire_time=? WHERE user_id=?";
        pkg_safe_query_prepared($DB, $sql, 'iissi', [$newPackageId, $newLevel, $newPkg['name'], $expireTime, $userId]);
        return ['ok'=>true, 'msg'=>'套餐已强制切换至 ' . $newPkg['name'] . '，有效期至 ' . $expireTime];
    }

    return ['ok'=>false, 'msg'=>'未知操作'];
}

/**
 * 核销兑换码
 *
 * @param $DB
 * @param $userId int 用户ID
 * @param $code string 兑换码
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function redeem_code($DB, $userId, $code) {
    $userId = intval($userId);
    $code = trim($code);
    if($userId <= 0) return ['ok'=>false, 'msg'=>'请先登录'];
    if($code === '') return ['ok'=>false, 'msg'=>'请输入兑换码'];

    $row = pkg_safe_get_row_prepared($DB, "SELECT * FROM eecms_redeem_codes WHERE code=?", 's', [$code]);
    if(!$row) return ['ok'=>false, 'msg'=>'兑换码不存在'];

    // H8 修复：原子更新防止 TOCTOU 竞态，确保兑换码只能被使用一次
    $now = date('Y-m-d H:i:s');
    $affected = pkg_safe_query_prepared($DB, "UPDATE eecms_redeem_codes SET used_user_id=?, used_at=? WHERE id=? AND used_user_id=0", 'isi', [$userId, $now, (int)$row['id']]);
    if(!$affected || $affected == 0) {
        return ['ok'=>false, 'msg'=>'该兑换码已被使用或兑换失败'];
    }

    // 检查是否过期
    if($row['expires_at'] !== null && $row['expires_at'] < $now) {
        return ['ok'=>false, 'msg'=>'该兑换码已过期'];
    }

    // 获取目标套餐
    $targetPkg = get_package($DB, $row['target_package_id']);
    if(!$targetPkg) return ['ok'=>false, 'msg'=>'兑换码关联的套餐不存在'];

    // 自定义天数
    $extraDays = $row['custom_days'] !== null ? (int)$row['custom_days'] : 0;

    // 执行套餐切换（走核心规则）
    $result = switch_user_package($DB, $userId, $targetPkg['id'], $extraDays);
    if(!$result['ok']) return $result;

    return ['ok'=>true, 'msg'=>'兑换成功！' . $result['msg']];
}

/**
 * 批量生成兑换码
 *
 * @param $DB
 * @param $targetPackageId int 目标套餐ID
 * @param $count int 生成数量
 * @param $customDays int|null 自定义天数
 * @param $expiresAt string|null 兑换码过期时间
 * @return array ['ok'=>bool, 'msg'=>string, 'codes'=>array]
 */
function generate_redeem_codes($DB, $targetPackageId, $count, $customDays=null, $expiresAt=null) {
    $targetPackageId = intval($targetPackageId);
    $count = max(1, min(500, intval($count))); // 限制1-500

    $pkg = get_package($DB, $targetPackageId);
    if(!$pkg) return ['ok'=>false, 'msg'=>'目标套餐不存在', 'codes'=>[]];

    $batchNo = date('YmdHis') . sprintf('%03d', random_int(0, 999));
    $codes = [];
    $customDaysVal = $customDays !== null ? (int)$customDays : null;
    $expiresVal = $expiresAt ?: null;
    $insertSql = "INSERT INTO eecms_redeem_codes (code, target_package_id, custom_days, expires_at, batch_no) VALUES (?, ?, ?, ?, ?)";

    for($i = 0; $i < $count; $i++) {
        // H7 修复：使用 random_bytes 生成密码学安全的兑换码，禁止 md5/uniqid/mt_rand
        // 格式：XXXX-XXXX-XXXX-XXXX（16位大写十六进制，64位熵）
        $code = strtoupper(
            bin2hex(random_bytes(2)) . '-' .
            bin2hex(random_bytes(2)) . '-' .
            bin2hex(random_bytes(2)) . '-' .
            bin2hex(random_bytes(2))
        );

        $affected = pkg_safe_query_prepared($DB, $insertSql, 'siiss', [$code, $targetPackageId, $customDaysVal, $expiresVal, $batchNo]);
        if($affected > 0) {
            $codes[] = $code;
        } else {
            // 碰撞时重试一次（M20 修复）
            $code = strtoupper(
                bin2hex(random_bytes(2)) . '-' .
                bin2hex(random_bytes(2)) . '-' .
                bin2hex(random_bytes(2)) . '-' .
                bin2hex(random_bytes(2))
            );
            $affected = pkg_safe_query_prepared($DB, $insertSql, 'siiss', [$code, $targetPackageId, $customDaysVal, $expiresVal, $batchNo]);
            if($affected > 0) {
                $codes[] = $code;
            }
        }
    }

    return ['ok'=>true, 'msg'=>'成功生成 ' . count($codes) . ' 个兑换码', 'codes'=>$codes, 'batch_no'=>$batchNo];
}

/**
 * 获取用户可用接口列表（基于套餐等级的权限继承）
 *
 * @param $DB
 * @param $userId int 0=未登录用户
 * @return array ['api_keys'=>array, 's3_ids'=>array, 'group_name'=>string]
 */
function get_user_allowed_apis($DB, $userId) {
    global $conf;
    $userId = intval($userId);

    if($userId <= 0) {
        // 未登录用户：使用配置的访客分组
        $guestGroupId = isset($conf['guest_group_id']) ? (int)$conf['guest_group_id'] : 0;
        if($guestGroupId > 0) {
            return get_group_apis($DB, $guestGroupId);
        }
        // 未配置分组 → 返回空（由调用方决定是否放行全部已启用接口）
        return ['api_keys'=>[], 's3_ids'=>[], 'group_name'=>'', 'has_group'=>false];
    }

    // 已登录用户：获取有效套餐
    $pkgInfo = get_user_effective_package($DB, $userId);
    $userLevel = $pkgInfo['level'];

    if($userLevel <= 0) {
        // 等级0：默认套餐
        $defaultPkg = get_default_package($DB);
        if($defaultPkg && (int)$defaultPkg['group_id'] > 0) {
            return get_group_apis($DB, (int)$defaultPkg['group_id']);
        }
        // 默认套餐无绑定分组 → 返回空（放行全部已启用）
        return ['api_keys'=>[], 's3_ids'=>[], 'group_name'=>'', 'has_group'=>false];
    }

    // VIP用户：并集获取 Level 1~N 所有绑定的分组接口
    $groupRows = pkg_safe_get_all_prepared($DB, "SELECT DISTINCT group_id FROM eecms_packages WHERE level <= ? AND level > 0 AND group_id > 0 AND status=1", 'i', [$userLevel]);
    $groupIds = [];
    foreach($groupRows as $row) {
        $groupIds[] = (int)$row['group_id'];
    }

    if(empty($groupIds)) {
        return ['api_keys'=>[], 's3_ids'=>[], 'group_name'=>'', 'has_group'=>false];
    }

    // 并集查询所有分组的接口（动态 IN 占位符）
    $gidPhList = implode(',', array_fill(0, count($groupIds), '?'));
    $gidPhTypes = str_repeat('i', count($groupIds));
    $itemRows = pkg_safe_get_all_prepared($DB, "SELECT DISTINCT api_type, is_s3, s3_id FROM eecms_api_group_items WHERE group_id IN ($gidPhList)", $gidPhTypes, $groupIds);
    $apiKeys = [];
    $s3Ids = [];
    foreach($itemRows as $row) {
        if((int)$row['is_s3'] === 1) {
            $s3Id = (int)$row['s3_id'];
            // 仅保留仍处于启用状态的 S3 配置（接口关闭后自动从可用列表中排除）
            if(is_s3_config_enabled($conf, $s3Id)) {
                $s3Ids[] = $s3Id;
            }
        } else {
            $apiKey = $row['api_type'];
            // 仅保留仍处于启用状态的图床接口（接口关闭后自动从可用列表中排除）
            if(is_api_enabled($conf, $apiKey)) {
                $apiKeys[] = $apiKey;
            }
        }
    }

    // has_group=true：已绑定分组，即使所有接口都已关闭，也不放行全部接口
    return ['api_keys'=>$apiKeys, 's3_ids'=>$s3Ids, 'group_name'=>'VIP'.$userLevel, 'has_group'=>true];
}

/**
 * 检查指定索引的 S3 存储配置是否已启用
 * @param array $conf 全局配置
 * @param int $s3Id S3 配置在 JSON 数组中的索引
 * @return bool
 */
function is_s3_config_enabled($conf, $s3Id) {
    $s3Id = (int)$s3Id;
    if($s3Id < 0) return false;
    if(!isset($conf['s3_storage_configs']) || $conf['s3_storage_configs'] === '') return false;
    $decoded = json_decode($conf['s3_storage_configs'], true);
    if(!is_array($decoded) || !isset($decoded[$s3Id])) return false;
    return isset($decoded[$s3Id]['enabled']) && $decoded[$s3Id]['enabled'] === '1';
}

/**
 * 检查图床接口是否已启用（读取 eecms_config 中 api_<key>_enable）
 * @param array $conf 全局配置
 * @param string $apiKey 接口标识
 * @return bool
 */
function is_api_enabled($conf, $apiKey) {
    $enableKey = 'api_' . $apiKey . '_enable';
    return isset($conf[$enableKey]) && $conf[$enableKey] == '1';
}

/**
 * 获取分组的接口列表（仅返回当前仍处于启用状态的接口）
 * 当接口在「接口管理」中被关闭后，虽然仍存在于 eecms_api_group_items，
 * 但不会出现在此返回值中，确保分组有效接口数与实际可用接口同步
 */
function get_group_apis($DB, $groupId) {
    global $conf;
    $groupId = intval($groupId);
    $itemRows = pkg_safe_get_all_prepared($DB, "SELECT * FROM eecms_api_group_items WHERE group_id=?", 'i', [$groupId]);
    $apiKeys = [];
    $s3Ids = [];
    $groupName = '';
    $grp = pkg_safe_get_row_prepared($DB, "SELECT name FROM eecms_api_groups WHERE id=?", 'i', [$groupId]);
    if($grp) $groupName = $grp['name'];

    foreach($itemRows as $row) {
        if((int)$row['is_s3'] === 1) {
            $s3Id = (int)$row['s3_id'];
            // 仅保留仍处于启用状态的 S3 配置
            if(is_s3_config_enabled($conf, $s3Id)) {
                $s3Ids[] = $s3Id;
            }
        } else {
            $apiKey = $row['api_type'];
            // 仅保留仍处于启用状态的图床接口
            if(is_api_enabled($conf, $apiKey)) {
                $apiKeys[] = $apiKey;
            }
        }
    }
    return ['api_keys'=>$apiKeys, 's3_ids'=>$s3Ids, 'group_name'=>$groupName, 'has_group'=>true];
}

/**
 * 检查用户是否可以使用指定接口
 *
 * @param $DB
 * @param $userId int
 * @param $apiType string 接口标识
 * @param $isS3 bool 是否S3
 * @param $s3Id int S3配置ID
 * @return bool
 */
function can_user_use_api($DB, $userId, $apiType, $isS3=false, $s3Id=0) {
    global $conf;
    $allowed = get_user_allowed_apis($DB, $userId);

    // 如果用户可用列表为空
    if(empty($allowed['api_keys']) && empty($allowed['s3_ids'])) {
        // 已绑定分组但所有接口均已关闭 → 拒绝访问（不放行全部接口，避免越权）
        if(!empty($allowed['has_group'])) {
            return false;
        }
        // 未配置分组 → 放行全部已启用接口（兼容未配置分组的场景）
        if($userId <= 0) {
            $hideLocal = isset($conf['guest_hide_local']) ? $conf['guest_hide_local'] : '0';
            if($hideLocal === '1' && !$isS3 && $apiType === 'local') {
                return false;
            }
        }
        return true;
    }

    if($isS3) {
        return in_array((int)$s3Id, $allowed['s3_ids']);
    } else {
        // 未登录用户强制隐藏本地上传
        if($userId <= 0) {
            $hideLocal = isset($conf['guest_hide_local']) ? $conf['guest_hide_local'] : '0';
            if($hideLocal === '1' && $apiType === 'local') {
                return false;
            }
        }
        return in_array($apiType, $allowed['api_keys']);
    }
}

/**
 * 处理过期套餐回退（可在定时任务或页面访问时调用）
 * 节流：最多每小时执行一次扫描，避免每个请求都全表查询拖慢页面
 */
function process_expired_packages($DB) {
    global $conf;
    // 节流检查：距上次执行不足 1 小时则跳过
    $lastRun = isset($conf['pkg_expire_last_run']) ? (int)$conf['pkg_expire_last_run'] : 0;
    if($lastRun > 0 && (time() - $lastRun) < 3600) {
        return 0;
    }
    // H10 修复：改用预处理语句，禁止 SQL 拼接
    $nowTs = (string)time();
    $DB->query_prepared("INSERT INTO eecms_config (`name`, `main`) VALUES ('pkg_expire_last_run', ?) ON DUPLICATE KEY UPDATE `main` = ?", 'ss', [$nowTs, $nowTs]);

    $now = date('Y-m-d H:i:s');
    $defaultPkg = get_default_package($DB);
    if(!$defaultPkg) return 0;

    $defaultId = (int)$defaultPkg['id'];
    $defaultLevel = (int)$defaultPkg['level'];

    // 找出所有已过期且等级>0的订阅
    $expiredRows = pkg_safe_get_all_prepared($DB, "SELECT user_id FROM eecms_user_subs WHERE expire_time < ? AND package_level > 0", 's', [$now]);
    $count = 0;
    $updateSql = "UPDATE eecms_user_subs SET package_id=?, package_level=?, package_name=? WHERE user_id=?";
    foreach($expiredRows as $row) {
        $uid = (int)$row['user_id'];
        pkg_safe_query_prepared($DB, $updateSql, 'iisi', [$defaultId, $defaultLevel, $defaultPkg['name'], $uid]);
        $count++;
    }
    return $count;
}
