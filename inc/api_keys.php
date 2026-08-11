<?php
declare(strict_types=1);
/**
 * API 密钥管理核心库
 *
 * @file        api_keys.php
 * @description 提供 API 密钥的建表、生成、CRUD、Bearer 鉴权等核心能力
 *              所有数据库操作均使用 mysqli 预处理（mysqli_prepare + bind_param）
 *              密钥明文仅在创建/重生成时返回一次，数据库只存 SHA-256 哈希
 * @author      eecms
 * @version     1.1.0-dev
 * @date        2026-08-04
 * @see         docs/AI开发规范.md § 8.3.4（预处理）、§ 5.4（API 数据传输）
 */

if(!defined('IN_CRONLITE'))exit();

/**
 * 确保 api_keys 表存在（幂等创建，含索引）
 * @param object $DB 数据库实例（inc/db.class.php）
 */
function ensure_api_keys_table($DB): void {
    static $created = false;
    if($created) return;
    $created = true;

    $sql = "CREATE TABLE IF NOT EXISTS `api_keys` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(100) NOT NULL COMMENT '密钥名称',
        `key_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 哈希，不存明文',
        `key_prefix` VARCHAR(20) NOT NULL COMMENT '展示用前缀 sk-xxxxxxxx',
        `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
        `last_used_at` DATETIME DEFAULT NULL COMMENT '最后使用时间',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        UNIQUE KEY `uniq_key_hash` (`key_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try { $DB->query($sql); } catch(Throwable $e) {}
}

/**
 * 生成明文 API 密钥
 * 规则：sk- + 32 位十六进制随机字符串（bin2hex(random_bytes(16))），共 35 字符
 * @return string 明文密钥（仅返回一次给客户端，DB 不存储明文）
 */
function api_key_generate(): string {
    try {
        return 'sk-' . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        // 极端情况回退（仍保证长度与字符集）
        return 'sk-' . bin2hex(openssl_random_pseudo_bytes(16));
    }
}

/**
 * 计算密钥的 SHA-256 哈希
 * @param string $key 明文密钥
 * @return string 64 位十六进制哈希
 */
function api_key_hash(string $key): string {
    return hash('sha256', $key);
}

/**
 * 从明文密钥提取展示前缀（sk- + 前 8 位）
 * @param string $key 明文密钥
 * @return string 如 sk-xxxxxxxx
 */
function api_key_prefix_from_key(string $key): string {
    return 'sk-' . substr($key, 3, 8);
}

/**
 * 创建新密钥
 * @param object $DB
 * @param int $userId 用户 ID
 * @param string $name 密钥名称（≤100 字符）
 * @return array 成功返回 ['api_key'=>明文, 'id'=>id, 'key_prefix'=>前缀]；失败返回 []
 */
function api_key_create($DB, int $userId, string $name): array {
    $name = trim($name);
    if($name === '' || strlen($name) > 100 || $userId <= 0) return [];

    $key = api_key_generate();
    $hash = api_key_hash($key);
    $prefix = api_key_prefix_from_key($key);

    $stmt = mysqli_prepare($DB->link, 'INSERT INTO api_keys (user_id, name, key_hash, key_prefix, status, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
    if($stmt === false) return [];
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $name, $hash, $prefix);
    $ok = mysqli_stmt_execute($stmt);
    $id = $ok ? (int)mysqli_insert_id($DB->link) : 0;
    mysqli_stmt_close($stmt);

    if(!$ok) return [];
    return ['api_key' => $key, 'id' => $id, 'key_prefix' => $prefix];
}

/**
 * 获取用户密钥列表（不返回明文/哈希，仅展示字段）
 * @param object $DB
 * @param int $userId
 * @return array 列表，每项含 id/name/key_prefix/status/last_used_at/created_at
 */
function api_key_list_by_user($DB, int $userId): array {
    if($userId <= 0) return [];
    $stmt = mysqli_prepare($DB->link, 'SELECT id, name, key_prefix, status, last_used_at, created_at FROM api_keys WHERE user_id = ? ORDER BY id DESC');
    if($stmt === false) return [];
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * 取单条密钥（含所有权校验，防越权）
 * @param object $DB
 * @param int $id 密钥 ID
 * @param int $userId 当前用户 ID（所有权校验）
 * @return array|false
 */
function api_key_get_owned($DB, int $id, int $userId) {
    if($id <= 0 || $userId <= 0) return false;
    $stmt = mysqli_prepare($DB->link, 'SELECT id, user_id, name, key_prefix, status, last_used_at, created_at FROM api_keys WHERE id = ? AND user_id = ? LIMIT 1');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row ?: false;
}

/**
 * 重新生成密钥（旧明文立即失效，返回新明文）
 * @param object $DB
 * @param int $id 密钥 ID
 * @param int $userId 当前用户 ID（所有权校验）
 * @return array 成功返回 ['api_key'=>新明文, 'key_prefix'=>新前缀]；失败返回 []
 */
function api_key_regen($DB, int $id, int $userId): array {
    if($id <= 0 || $userId <= 0) return [];

    // 先校验所有权
    $existing = api_key_get_owned($DB, $id, $userId);
    if(!$existing) return [];

    $key = api_key_generate();
    $hash = api_key_hash($key);
    $prefix = api_key_prefix_from_key($key);

    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET key_hash = ?, key_prefix = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
    if($stmt === false) return [];
    mysqli_stmt_bind_param($stmt, 'ssii', $hash, $prefix, $id, $userId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if(!$ok || $affected <= 0) return [];
    return ['api_key' => $key, 'key_prefix' => $prefix];
}

/**
 * 删除密钥
 * @param object $DB
 * @param int $id
 * @param int $userId 所有权校验
 * @return bool
 */
function api_key_delete($DB, int $id, int $userId): bool {
    if($id <= 0 || $userId <= 0) return false;
    $stmt = mysqli_prepare($DB->link, 'DELETE FROM api_keys WHERE id = ? AND user_id = ?');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

/**
 * 启用/禁用密钥
 * @param object $DB
 * @param int $id
 * @param int $userId 所有权校验
 * @param int $status 1启用 0禁用
 * @return bool
 */
function api_key_set_status($DB, int $id, int $userId, int $status): bool {
    if($id <= 0 || $userId <= 0) return false;
    $status = $status ? 1 : 0;
    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'iii', $status, $id, $userId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

/**
 * 统计用户密钥数量（后台用户管理用）
 * @param object $DB
 * @param int $userId
 * @return int
 */
function api_key_count_by_user($DB, int $userId): int {
    if($userId <= 0) return 0;
    $stmt = mysqli_prepare($DB->link, 'SELECT COUNT(*) AS cnt FROM api_keys WHERE user_id = ?');
    if($stmt === false) return 0;
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * Bearer 鉴权：校验明文密钥，返回密钥归属信息
 * @param object $DB
 * @param string $key 明文密钥
 * @return array|false 成功返回 ['id'=>密钥ID, 'user_id'=>用户ID]；不存在/禁用/格式错返回 false
 */
function api_key_verify($DB, string $key) {
    // 格式校验：sk- + 32 hex = 35 字符
    if(strlen($key) !== 35 || strpos($key, 'sk-') !== 0) return false;
    if(!preg_match('/^sk-[0-9a-f]{32}$/', $key)) return false;

    $hash = api_key_hash($key);
    $stmt = mysqli_prepare($DB->link, 'SELECT id, user_id, status FROM api_keys WHERE key_hash = ? LIMIT 1');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if(!$row) return false;
    if((int)$row['status'] !== 1) return false; // 已禁用
    return ['id' => (int)$row['id'], 'user_id' => (int)$row['user_id']];
}

/**
 * 更新密钥最后使用时间（鉴权成功后调用）
 * @param object $DB
 * @param int $id
 * @return void
 */
function api_key_touch($DB, int $id): void {
    if($id <= 0) return;
    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET last_used_at = NOW() WHERE id = ?');
    if($stmt === false) return;
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * 禁用指定用户名下的所有 API 密钥（用户被封禁时调用）
 * @param object $DB
 * @param int $userId 用户 ID
 * @return int 受影响行数
 */
function api_key_disable_by_user($DB, int $userId): int {
    if($userId <= 0) return 0;
    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET status = 0, updated_at = NOW() WHERE user_id = ? AND status = 1');
    if($stmt === false) return 0;
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

/**
 * 后台：获取全站密钥列表（关联用户名，支持过滤）
 * @param object $DB
 * @param array $filters ['username'=>string, 'status'=>int, 'limit'=>int, 'offset'=>int]
 * @return array
 */
function api_key_list_all($DB, array $filters = []): array {
    $sql = 'SELECT k.id, k.user_id, k.name, k.key_prefix, k.status, k.last_used_at, k.created_at, u.username 
            FROM api_keys k 
            LEFT JOIN eecms_users u ON k.user_id = u.id 
            WHERE 1=1';
    $types = '';
    $params = [];

    if(!empty($filters['username'])) {
        $sql .= ' AND u.username LIKE ?';
        $types .= 's';
        $params[] = '%' . $filters['username'] . '%';
    }
    if(isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
        $sql .= ' AND k.status = ?';
        $types .= 'i';
        $params[] = (int)$filters['status'];
    }
    $sql .= ' ORDER BY k.id DESC';
    if(!empty($filters['limit']) && $filters['limit'] > 0) {
        $sql .= ' LIMIT ?';
        $types .= 'i';
        $params[] = (int)$filters['limit'];
        if(!empty($filters['offset']) && $filters['offset'] > 0) {
            $sql .= ' OFFSET ?';
            $types .= 'i';
            $params[] = (int)$filters['offset'];
        }
    }

    $stmt = mysqli_prepare($DB->link, $sql);
    if($stmt === false) return [];
    if(!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * 后台：统计全站密钥总数（用于分页）
 * @param object $DB
 * @param array $filters 与 api_key_list_all 一致
 * @return int
 */
function api_key_count_all($DB, array $filters = []): int {
    $sql = 'SELECT COUNT(*) AS cnt FROM api_keys k LEFT JOIN eecms_users u ON k.user_id = u.id WHERE 1=1';
    $types = '';
    $params = [];
    if(!empty($filters['username'])) {
        $sql .= ' AND u.username LIKE ?';
        $types .= 's';
        $params[] = '%' . $filters['username'] . '%';
    }
    if(isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
        $sql .= ' AND k.status = ?';
        $types .= 'i';
        $params[] = (int)$filters['status'];
    }
    $stmt = mysqli_prepare($DB->link, $sql);
    if($stmt === false) return 0;
    if(!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * 后台：按 ID 取密钥（管理员操作，含用户名）
 * @param object $DB
 * @param int $id
 * @return array|false
 */
function api_key_get_by_id_admin($DB, int $id) {
    if($id <= 0) return false;
    $stmt = mysqli_prepare($DB->link, 'SELECT k.id, k.user_id, k.name, k.key_prefix, k.status, k.last_used_at, k.created_at, u.username FROM api_keys k LEFT JOIN eecms_users u ON k.user_id = u.id WHERE k.id = ? LIMIT 1');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row ?: false;
}

/**
 * 后台：管理员重生成密钥（不需要所有权校验，按 id）
 * @param object $DB
 * @param int $id
 * @return array 成功返回 ['api_key'=>明文, 'key_prefix'=>前缀]；失败返回 []
 */
function api_key_regen_admin($DB, int $id): array {
    if($id <= 0) return [];
    $key = api_key_generate();
    $hash = api_key_hash($key);
    $prefix = api_key_prefix_from_key($key);
    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET key_hash = ?, key_prefix = ?, updated_at = NOW() WHERE id = ?');
    if($stmt === false) return [];
    mysqli_stmt_bind_param($stmt, 'ssi', $hash, $prefix, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if(!$ok || $affected <= 0) return [];
    return ['api_key' => $key, 'key_prefix' => $prefix];
}

/**
 * 后台：管理员删除/启停密钥（按 id，无所有权校验）
 */
function api_key_delete_admin($DB, int $id): bool {
    if($id <= 0) return false;
    $stmt = mysqli_prepare($DB->link, 'DELETE FROM api_keys WHERE id = ?');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}

function api_key_set_status_admin($DB, int $id, int $status): bool {
    if($id <= 0) return false;
    $status = $status ? 1 : 0;
    $stmt = mysqli_prepare($DB->link, 'UPDATE api_keys SET status = ?, updated_at = NOW() WHERE id = ?');
    if($stmt === false) return false;
    mysqli_stmt_bind_param($stmt, 'ii', $status, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $ok && $affected > 0;
}
