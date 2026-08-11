<?php
/**
 * @file users.php
 * @description 用户管理页面，提供用户列表查看、编辑、封禁/启用、重置密码、删除、套餐切换及自定义存储配额设置等功能
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// 当前管理员用户名（用于禁止删除自己）
$currentAdminUser = isset($conf['admin_user']) ? $conf['admin_user'] : '';

function json_exit($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== AJAX 接口 ==========
if(isset($_POST['action'])) {
    if(!csrf_verify()) {
        json_exit(1, '安全校验失败，请刷新页面后重试！');
    }
    $action = $_POST['action'];

    if($action === 'list') {
        $page     = max(1, intval($_POST['page'] ?? 1));
        $perPage  = 20;
        $search   = trim($_POST['search'] ?? '');
        $role     = $_POST['role'] ?? 'all';
        $status   = $_POST['status'] ?? 'all';

        $where = ' WHERE 1=1';
        $types = '';
        $params = [];
        if($search !== '') {
            // Escape LIKE wildcards to prevent wildcard injection
            $escSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search);
            $where .= " AND u.username LIKE ? ESCAPE '\\\\'";
            $types .= 's';
            $params[] = '%'.$escSearch.'%';
        }
        if($role === 'super_admin' || $role === 'user') {
            $where .= " AND u.role=?";
            $types .= 's';
            $params[] = $role;
        }
        if($status === 'active') {
            $where .= " AND u.status=1";
        } elseif($status === 'disabled') {
            $where .= " AND u.status=0";
        }

        $total     = (int)$DB->count_prepared("SELECT COUNT(*) FROM eecms_users u{$where}", $types, $params);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if($page > $totalPages) $page = $totalPages;
        $start = ($page - 1) * $perPage;

        // 汇总统计
        $totalUsers   = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users");
        $totalAdmins  = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin'");
        $activeUsers  = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE status=1");

        // 默认套餐名（用于无订阅记录的用户显示）
        $defaultPkg = get_default_package($DB);
        $defaultPkgName = $defaultPkg ? $defaultPkg['name'] : '免费版';

        // 追加 LIMIT 占位符
        $listSql = "SELECT u.*, s.package_name AS sub_package_name, s.package_level AS sub_package_level, s.expire_time AS sub_expire_time FROM eecms_users u LEFT JOIN eecms_user_subs s ON u.id=s.user_id{$where} ORDER BY u.id ASC LIMIT ?, ?";
        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$start, $perPage]);
        $rs = $DB->fetch_all_prepared($listSql, $listTypes, $listParams);
        $userRows = [];
        if($rs) {
            while(($row = $DB->fetch($rs))) {
                $userRows[] = $row;
            }
        }
        $users = [];
        $nowDate = date('Y-m-d H:i:s');
        foreach($userRows as $row) {
            $subExpire = $row['sub_expire_time'];
            $subLevel  = (int)$row['sub_package_level'];
            $isExpired = ($subExpire && $subLevel > 0 && $subExpire < $nowDate);
            $users[] = [
                'id'            => (int)$row['id'],
                'username'      => $row['username'],
                'email'         => $row['email'],
                'role'          => $row['role'],
                'status'        => (int)$row['status'],
                'email_verified'=> (int)$row['email_verified'],
                'upload_count'  => (int)$row['upload_count'],
                'created_at'    => $row['created_at'],
                'last_login'    => $row['last_login'],
                'is_self'       => ($row['username'] === $currentAdminUser),
                'package_name'  => $row['sub_package_name'] ? $row['sub_package_name'] : $defaultPkgName,
                'package_level' => $subLevel,
                'expire_time'   => $subExpire,
                'is_expired'    => $isExpired,
                'is_permanent'  => ($subExpire !== null && substr((string)$subExpire, 0, 4) >= '2099'),
                'api_key_count' => api_key_count_by_user($DB, (int)$row['id']),
            ];
        }
        json_exit(0, 'ok', [
            'users'       => $users,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'perPage'     => $perPage,
            'summary'     => [
                'totalUsers'  => $totalUsers,
                'totalAdmins' => $totalAdmins,
                'activeUsers' => $activeUsers,
            ],
        ]);
    }

    if($action === 'update') {
        $id       = intval($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'user';
        $status   = intval($_POST['status'] ?? 1);

        if($id <= 0) json_exit(1, '参数错误');
        if($username === '') json_exit(1, '用户名不能为空');
        if(mb_strlen($username) > 64) json_exit(1, '用户名长度不能超过64个字符');
        // L14 修复：邮箱字段格式校验（非空时）
        if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_exit(1, '邮箱格式不正确');
        if(!in_array($role, ['super_admin', 'user'])) json_exit(1, '角色参数错误');
        if(!in_array($status, [0, 1])) json_exit(1, '状态参数错误');

        $current = pkg_safe_get_row_prepared($DB, "SELECT role, username, status FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$current) json_exit(1, '用户不存在');

        // H1 修复：禁止操作当前登录的管理员账号（封禁/降级自己会导致锁死）
        if($current['username'] === $currentAdminUser) json_exit(1, '无法操作当前登录的管理员账号');

        // 用户名唯一性检查
        $dup = $DB->get_row_prepared("SELECT id FROM eecms_users WHERE username=? AND id != ?", 'si', [$username, $id]);
        if($dup) json_exit(1, '该用户名已存在');

        // 降级超级管理员时，确保至少保留一个
        if($current['role'] === 'super_admin' && $role === 'user') {
            $superCount = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin'");
            if($superCount <= 1) json_exit(1, '无法降级，系统至少需要保留一个超级管理员');
        }
        // 禁用超级管理员时，确保至少保留一个启用的
        if($current['role'] === 'super_admin' && $current['status'] == 1 && $status === 0) {
            $activeSuper = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin' AND status=1");
            if($activeSuper <= 1) json_exit(1, '无法禁用，系统至少需要保留一个启用的超级管理员');
        }

        if($DB->query_prepared("UPDATE eecms_users SET username=?, email=?, role=?, status=? WHERE id=?", 'sssii', [$username, $email, $role, $status, $id]) === false) {
            json_exit(1, '用户信息更新失败，请重试！');
        }
        // L10: 禁用用户时使其所有会话令牌失效 + 同步禁用该用户名下所有 API 密钥
        if($status === 0) {
            invalidate_user_sessions($id);
            api_key_disable_by_user($DB, $id);
        }
        // H14 修复：补充审计日志
        log_admin_action($DB, 'user_update', 'user', $id, ['username' => $username, 'role' => $role, 'status' => $status]);
        json_exit(0, '用户信息更新成功');
    }

    if($action === 'reset_password') {
        $id       = intval($_POST['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if($id <= 0) json_exit(1, '参数错误');
        if(strlen($password) < 6) json_exit(1, '密码长度至少6位');
        if(strlen($password) > 64) json_exit(1, '密码长度不能超过64位');

        $exists = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$exists) json_exit(1, '用户不存在');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        // L10: 重置密码时使该用户的所有会话令牌失效
        invalidate_user_sessions($id);
        if($DB->query_prepared("UPDATE eecms_users SET password=? WHERE id=?", 'si', [$hash, $id]) === false) {
            json_exit(1, '密码重置失败，请重试！');
        }
        // H14 修复：补充审计日志
        log_admin_action($DB, 'user_reset_password', 'user', $id, []);
        json_exit(0, '密码重置成功');
    }

    if($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $user = pkg_safe_get_row_prepared($DB, "SELECT id, username, role FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$user) json_exit(1, '用户不存在');

        // 禁止删除自己
        if($user['username'] === $currentAdminUser) json_exit(1, '无法删除当前登录的管理员账号');

        // 禁止删除最后一个超级管理员
        if($user['role'] === 'super_admin') {
            $superCount = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin'");
            if($superCount <= 1) json_exit(1, '无法删除，系统至少需要保留一个超级管理员');
        }

        // L18: 删除用户时同步清理其名下所有 API 密钥与订阅记录，避免孤儿数据
        pkg_safe_query_prepared($DB, "DELETE FROM api_keys WHERE user_id=?", 'i', [$id]);
        pkg_safe_query_prepared($DB, "DELETE FROM eecms_user_subs WHERE user_id=?", 'i', [$id]);
        pkg_safe_query_prepared($DB, "DELETE FROM eecms_users WHERE id=?", 'i', [$id]);
        // H14 修复：补充审计日志
        log_admin_action($DB, 'user_delete', 'user', $id, ['username' => $user['username']]);
        json_exit(0, '用户已删除');
    }

    // ---- delete_selected: 批量删除选中的用户 ----
    if($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        if(!is_array($ids)) json_exit(1, '参数错误');

        $intIds = [];
        foreach($ids as $id) {
            $id = intval($id);
            if($id > 0) $intIds[] = $id;
        }
        $intIds = array_values(array_unique($intIds));
        if(count($intIds) === 0) json_exit(1, '请选择要删除的用户');
        if(count($intIds) > 500) json_exit(1, '单次最多删除 500 条');

        $phList = implode(',', array_fill(0, count($intIds), '?'));
        $phTypes = str_repeat('i', count($intIds));
        $rows = pkg_safe_get_all_prepared($DB, "SELECT id, username, role FROM eecms_users WHERE id IN ($phList)", $phTypes, $intIds);
        if(count($rows) === 0) json_exit(1, '用户不存在');

        $toDelete = [];
        $skippedCount = 0;
        $superAdminIds = [];
        foreach($rows as $row) {
            // 禁止删除自己
            if($row['username'] === $currentAdminUser) {
                $skippedCount++;
                continue;
            }
            if($row['role'] === 'super_admin') {
                $superAdminIds[] = (int)$row['id'];
            }
            $toDelete[] = (int)$row['id'];
        }

        // 检查超级管理员约束：不能删除所有超级管理员
        if(count($superAdminIds) > 0) {
            $totalSuper = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin'");
            $superInDelete = array_intersect($superAdminIds, $toDelete);
            if($totalSuper - count($superInDelete) < 1) {
                // 从删除列表中移除所有超级管理员
                $toDelete = array_values(array_diff($toDelete, $superAdminIds));
                $skippedCount += count($superInDelete);
            }
        }

        if(count($toDelete) === 0) {
            json_exit(1, '没有可删除的用户（当前账号和最后一个超级管理员不可删除）');
        }

        $delPhList = implode(',', array_fill(0, count($toDelete), '?'));
        $delPhTypes = str_repeat('i', count($toDelete));
        // L18: 批量删除时同步清理 API 密钥与订阅记录
        pkg_safe_query_prepared($DB, "DELETE FROM api_keys WHERE user_id IN ($delPhList)", $delPhTypes, $toDelete);
        pkg_safe_query_prepared($DB, "DELETE FROM eecms_user_subs WHERE user_id IN ($delPhList)", $delPhTypes, $toDelete);
        $ok = pkg_safe_query_prepared($DB, "DELETE FROM eecms_users WHERE id IN ($delPhList)", $delPhTypes, $toDelete);
        $affected = ($ok === false) ? 0 : (int)$ok;

        log_admin_action($DB, 'user_delete_selected', 'user', 0, ['count' => $affected, 'skipped' => $skippedCount]);
        $msg = "已删除 {$affected} 个用户";
        if($skippedCount > 0) $msg .= "，跳过 {$skippedCount} 个（当前账号或超级管理员）";
        json_exit(0, $msg);
    }

    // ---- set_status_selected: 批量启用/禁用用户 ----
    if($action === 'set_status_selected') {
        $ids = $_POST['ids'] ?? [];
        $status = intval($_POST['status'] ?? -1);
        if(!is_array($ids)) json_exit(1, '参数错误');
        if(!in_array($status, [0, 1])) json_exit(1, '状态参数错误');

        $intIds = [];
        foreach($ids as $id) {
            $id = intval($id);
            if($id > 0) $intIds[] = $id;
        }
        $intIds = array_values(array_unique($intIds));
        if(count($intIds) === 0) json_exit(1, '请选择用户');
        if(count($intIds) > 500) json_exit(1, '单次最多操作 500 条');

        $phList = implode(',', array_fill(0, count($intIds), '?'));
        $phTypes = str_repeat('i', count($intIds));
        $rows = pkg_safe_get_all_prepared($DB, "SELECT id, username, role, status FROM eecms_users WHERE id IN ($phList)", $phTypes, $intIds);
        if(count($rows) === 0) json_exit(1, '用户不存在');

        // M3 修复：批量操作中剔除当前登录管理员（禁止封禁自己）
        $filteredRows = [];
        foreach($rows as $row) {
            if($row['username'] === $currentAdminUser) continue;
            $filteredRows[] = $row;
        }
        if(count($filteredRows) === 0) json_exit(1, '无法操作当前登录的管理员账号');
        $rows = $filteredRows;
        // 重新构造 intIds（已剔除自己）
        $intIds = array_map(function($r){ return (int)$r['id']; }, $rows);

        // 禁用时检查超级管理员约束
        if($status === 0) {
            $superInList = [];
            foreach($rows as $row) {
                if($row['role'] === 'super_admin' && (int)$row['status'] === 1) {
                    $superInList[] = (int)$row['id'];
                }
            }
            if(count($superInList) > 0) {
                $activeSuper = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin' AND status=1");
                if($activeSuper - count($superInList) < 1) {
                    json_exit(1, '无法禁用，系统至少需要保留一个启用的超级管理员');
                }
            }
        }

        $updPhList = implode(',', array_fill(0, count($intIds), '?'));
        $updPhTypes = str_repeat('i', count($intIds));
        $updParams = array_merge([$status], $intIds);
        $ok = pkg_safe_query_prepared($DB, "UPDATE eecms_users SET status=? WHERE id IN ($updPhList)", 'i' . $updPhTypes, $updParams);
        $affected = ($ok === false) ? 0 : (int)$ok;

        // 禁用时使所有被禁用用户的会话失效 + 同步禁用其名下所有 API 密钥
        if($status === 0) {
            foreach($intIds as $uid) {
                invalidate_user_sessions($uid);
                api_key_disable_by_user($DB, $uid);
            }
        }

        log_admin_action($DB, 'user_set_status_selected', 'user', 0, ['status' => $status, 'count' => $affected]);
        $actionText = $status === 1 ? '启用' : '禁用';
        json_exit(0, "已批量{$actionText} {$affected} 个用户");
    }

    // ---- toggle_status: 切换单个用户状态 ----
    if($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $user = pkg_safe_get_row_prepared($DB, "SELECT id, username, role, status FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$user) json_exit(1, '用户不存在');

        // M3 修复：后端校验禁止封禁自己（前端已禁用按钮，但可构造 POST 绕过）
        if($user['username'] === $currentAdminUser) json_exit(1, '无法操作当前登录的管理员账号');

        $newStatus = (int)$user['status'] === 1 ? 0 : 1;

        // 禁用超级管理员时，确保至少保留一个启用的
        if($user['role'] === 'super_admin' && (int)$user['status'] === 1 && $newStatus === 0) {
            $activeSuper = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE role='super_admin' AND status=1");
            if($activeSuper <= 1) json_exit(1, '无法禁用，系统至少需要保留一个启用的超级管理员');
        }

        pkg_safe_query_prepared($DB, "UPDATE eecms_users SET status=? WHERE id=?", 'ii', [$newStatus, $id]);
        if($newStatus === 0) {
            invalidate_user_sessions($id);
            api_key_disable_by_user($DB, $id);
        }
        log_admin_action($DB, 'user_toggle_status', 'user', $id, ['new_status' => $newStatus]);
        json_exit(0, $newStatus === 1 ? '用户已启用' : '用户已禁用');
    }

    if($action === 'get_subscription') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');
        $exists = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$exists) json_exit(1, '用户不存在');

        $sub = get_user_subscription($DB, $id);
        $eff = get_user_effective_package($DB, $id);

        $subscription = [
            'package_id'    => $sub ? (int)$sub['package_id'] : (int)$eff['package_id'],
            'package_name'  => $sub ? $sub['package_name'] : $eff['package_name'],
            'package_level' => $sub ? (int)$sub['package_level'] : (int)$eff['level'],
            'expire_time'   => $sub ? $sub['expire_time'] : null,
            'is_expired'    => $eff['is_expired'],
            'is_permanent'  => !empty($eff['is_permanent']),
            'custom_storage'=> $eff['custom_storage'],
            'storage_limit' => (int)$eff['storage_limit'],
        ];

        $packages = [];
        foreach(get_all_packages($DB) as $pkg) {
            $packages[] = [
                'id'            => (int)$pkg['id'],
                'name'          => $pkg['name'],
                'level'         => (int)$pkg['level'],
                'storage_limit' => (int)$pkg['storage_limit'],
                'days'          => (int)$pkg['days'],
            ];
        }

        json_exit(0, 'ok', ['subscription' => $subscription, 'packages' => $packages]);
    }

    if($action === 'switch_package') {
        $id        = intval($_POST['id'] ?? 0);
        $packageId = intval($_POST['package_id'] ?? 0);
        $force     = intval($_POST['force'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');
        if($packageId <= 0) json_exit(1, '套餐参数错误');

        $result = switch_user_package($DB, $id, $packageId, 0, $force == 1);
        log_admin_action($DB, 'user_switch_package', 'user', $id, ['package_id' => $packageId, 'force' => $force]);
        if($result['ok']) {
            json_exit(0, $result['msg']);
        } else {
            json_exit(1, $result['msg']);
        }
    }

    if($action === 'set_custom_storage') {
        $id      = intval($_POST['id'] ?? 0);
        $enabled = intval($_POST['enabled'] ?? 0);
        $value   = intval($_POST['value'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');
        $exists = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_users WHERE id=?", 'i', [$id]);
        if(!$exists) json_exit(1, '用户不存在');

        if($enabled == 1) {
            if($value <= 0) json_exit(1, '请输入有效的存储数值');
            $bytes = $value * 1048576; // MB转字节
            $storageSql = $bytes;
        } else {
            $storageSql = 'NULL';
        }

        $sub = get_user_subscription($DB, $id);
        if(!$sub) {
            // 无订阅记录，先创建一条默认订阅
            $defaultPkg = get_default_package($DB);
            if(!$defaultPkg) json_exit(1, '系统未配置默认套餐');
            $now = date('Y-m-d H:i:s');
            if($enabled == 1) {
                $DB->query_prepared(
                    "INSERT INTO eecms_user_subs (user_id, package_id, package_level, package_name, expire_time, custom_storage) VALUES (?, ?, ?, ?, ?, ?)",
                    'iiissi',
                    [$id, (int)$defaultPkg['id'], (int)$defaultPkg['level'], $defaultPkg['name'], $now, $bytes]
                );
            } else {
                $DB->query_prepared(
                    "INSERT INTO eecms_user_subs (user_id, package_id, package_level, package_name, expire_time, custom_storage) VALUES (?, ?, ?, ?, ?, NULL)",
                    'iiiss',
                    [$id, (int)$defaultPkg['id'], (int)$defaultPkg['level'], $defaultPkg['name'], $now]
                );
            }
        } else {
            if($enabled == 1) {
                pkg_safe_query_prepared($DB, "UPDATE eecms_user_subs SET custom_storage=? WHERE user_id=?", 'ii', [$storageSql, $id]);
            } else {
                pkg_safe_query_prepared($DB, "UPDATE eecms_user_subs SET custom_storage=NULL WHERE user_id=?", 'i', [$id]);
            }
        }

        log_admin_action($DB, 'user_set_custom_storage', 'user', $id, ['enabled' => $enabled, 'value_mb' => $value]);
        json_exit(0, $enabled == 1 ? '自定义存储已开启' : '自定义存储已关闭，恢复跟随套餐');
    }

    json_exit(1, '未知操作');
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>用户管理 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('account-group-outline'); ?> 用户管理
  </div>

  <!-- 统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon blue"><?php echo icon('account-group'); ?></div>
        <div class="stat-content">
          <div class="stat-label">用户总数</div>
          <div class="stat-value" id="statTotal">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('check-circle-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">启用用户</div>
          <div class="stat-value" id="statActive">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon purple"><?php echo icon('shield-account-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">超级管理员</div>
          <div class="stat-value" id="statAdmins">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon cyan"><?php echo icon('image-multiple'); ?></div>
        <div class="stat-content">
          <div class="stat-label">当前筛选结果</div>
          <div class="stat-value" id="statFiltered">0</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 筛选栏 -->
  <div class="card">
    <div class="card-body">
      <div class="row g-2 align-items-center">
        <div class="col-md-4">
          <label class="form-label">搜索用户名</label>
          <input type="text" class="form-control" id="searchInput" placeholder="输入用户名搜索" onkeydown="if(event.key==='Enter')loadUsers(1)">
        </div>
        <div class="col-md-2">
          <label class="form-label">角色</label>
          <select class="form-select" id="roleFilter">
            <option value="all">全部角色</option>
            <option value="super_admin">超级管理员</option>
            <option value="user">普通用户</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">状态</label>
          <select class="form-select" id="statusFilter">
            <option value="all">全部状态</option>
            <option value="active">启用</option>
            <option value="disabled">禁用</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-primary w-100" onclick="loadUsers(1)"><?php echo icon('magnify'); ?> 搜索</button>
        </div>
        <div class="col-md-2">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilter()"><?php echo icon('refresh'); ?> 重置</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 用户列表 -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?php echo icon('account-group-outline'); ?> 用户列表</div>
      <div id="batchBar" class="batch-toolbar" style="display:none;">
        <span class="batch-info"><?php echo icon('checkbox-multiple-marked-outline'); ?> 已选择 <strong id="selectedCount">0</strong> 项</span>
        <button type="button" class="btn btn-success btn-sm" onclick="batchSetStatus(1)"><?php echo icon('account-check'); ?> 批量启用</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="batchSetStatus(0)"><?php echo icon('account-cancel'); ?> 批量封禁</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="batchDeleteSelected()"><?php echo icon('delete-sweep'); ?> 批量删除</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()"><?php echo icon('close'); ?> 取消选择</button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th style="width:40px;"><input type="checkbox" id="checkAll" onclick="toggleAll(this)" title="全选当前页"></th>
              <th style="width:60px;">ID</th>
              <th>用户名</th>
              <th>邮箱</th>
              <th style="width:110px;">角色</th>
              <th style="width:90px;">状态</th>
              <th style="width:90px;">上传数</th>
              <th style="width:90px;">API密钥</th>
              <th style="width:150px;">套餐</th>
              <th style="width:150px;">注册时间</th>
              <th style="width:150px;">最后登录</th>
              <th style="width:200px;">操作</th>
            </tr>
          </thead>
          <tbody id="usersTableBody">
            <tr><td colspan="11" class="text-center text-muted py-5"><?php echo icon('loading', 'icon-spin'); ?> 加载中...</td></tr>
          </tbody>
        </table>
      </div>
      <div id="usersPagination" class="d-flex justify-content-center py-3"></div>
    </div>
  </div>

</div>

<!-- 编辑用户 Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo icon('account-edit-outline'); ?> 编辑用户</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <form id="editForm">
          <div class="mb-3">
            <label class="form-label">用户名</label>
            <input type="text" class="form-control" id="editUsername" required>
          </div>
          <div class="mb-3">
            <label class="form-label">邮箱</label>
            <input type="email" class="form-control" id="editEmail" placeholder="选填">
          </div>
          <div class="row g-2">
            <div class="col-md-6 mb-3">
              <label class="form-label">角色</label>
              <select class="form-select" id="editRole">
                <option value="user">普通用户</option>
                <option value="super_admin">超级管理员</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">状态</label>
              <select class="form-select" id="editStatus">
                <option value="1">启用</option>
                <option value="0">禁用</option>
              </select>
            </div>
          </div>
          <input type="hidden" id="editId">

          <hr>
          <h6 class="text-primary mb-3"><?php echo icon('package-variant-closed'); ?> 套餐与存储</h6>
          <div class="mb-3">
            <div class="row g-2">
              <div class="col-6">
                <small class="text-muted d-block">当前套餐</small>
                <span id="subPackageName">-</span>
              </div>
              <div class="col-6">
                <small class="text-muted d-block">到期时间</small>
                <span id="subExpireTime">-</span>
              </div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-6">
                <small class="text-muted d-block">有效存储</small>
                <span id="subStorageLimit">-</span>
              </div>
              <div class="col-6">
                <small class="text-muted d-block">状态</small>
                <span id="subStatus">-</span>
              </div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">切换套餐</label>
            <select class="form-select" id="subPackageSelect">
              <option value="0">-- 请选择套餐 --</option>
            </select>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="subForceSwitch">
            <label class="form-check-label" for="subForceSwitch">强制切换（跳过降级拦截，用于管理员强制操作）</label>
          </div>
          <div class="mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="switchPackage()"><?php echo icon('swap-horizontal'); ?> 切换套餐</button>
          </div>
          <hr>
          <div class="mb-2">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="customStorageSwitch">
              <label class="form-check-label" for="customStorageSwitch">自定义存储配额（关闭则跟随套餐默认值）</label>
            </div>
          </div>
          <div class="mb-3" id="customStorageValueBox" style="display:none;">
            <label class="form-label">存储大小（MB）</label>
            <input type="number" class="form-control" id="customStorageValue" min="1" placeholder="如 1024 表示 1GB">
            <small class="text-muted">输入 MB 数值，如 1024 = 1GB，5120 = 5GB</small>
          </div>
          <div class="mb-3">
            <button type="button" class="btn btn-outline-success btn-sm" onclick="saveCustomStorage()"><?php echo icon('content-save'); ?> 保存存储设置</button>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="saveEdit()"><?php echo icon('content-save'); ?> 保存</button>
      </div>
    </div>
  </div>
</div>

<!-- 重置密码 Modal -->
<div class="modal fade" id="resetPwdModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo icon('lock-reset'); ?> 重置密码</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning"><?php echo icon('alert-outline'); ?> 将为用户 <strong id="resetPwdUsername"></strong> 设置新的密码，原密码将失效。</div>
        <div class="mb-3">
          <label class="form-label">新密码</label>
          <input type="password" class="form-control" id="resetPwdPassword" placeholder="至少6位" autocomplete="new-password">
        </div>
        <div class="mb-3">
          <label class="form-label">确认密码</label>
          <input type="password" class="form-control" id="resetPwdConfirm" placeholder="再次输入新密码" autocomplete="new-password">
        </div>
        <input type="hidden" id="resetPwdId">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-warning" onclick="saveResetPwd()"><?php echo icon('lock-reset'); ?> 确认重置</button>
      </div>
    </div>
  </div>
</div>

<script src="style/js/jquery.min.js"></script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
var CSRF_TOKEN = '<?php echo csrf_token();?>';
// M5 修复：CSRF Token 统一通过 Header 传递，不再放 POST Body
$.ajaxSetup({ beforeSend: function(xhr){ xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } });
var editModal, resetPwdModal;
var _usersCache = [];
var _currentPage = 1;

window.addEventListener('DOMContentLoaded', function(){
    editModal = new bootstrap.Modal(document.getElementById('editModal'));
    resetPwdModal = new bootstrap.Modal(document.getElementById('resetPwdModal'));
    $('#customStorageSwitch').on('change', function(){
        if($(this).prop('checked')){
            $('#customStorageValueBox').show();
        } else {
            $('#customStorageValueBox').hide();
        }
    });
    // 事件委托：避免内联 onclick 拼接字符串导致 XSS（H2 修复）
    $('#usersTableBody').on('click', '.act-toggle', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) toggleUserStatus(id);
    });
    $('#usersTableBody').on('click', '.act-edit', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) openEdit(id);
    });
    $('#usersTableBody').on('click', '.act-reset-pwd', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) openResetPwd(id);
    });
    $('#usersTableBody').on('click', '.act-delete', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) deleteUser(id);
    });
    $('#usersTableBody').on('click', '.act-view-keys', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) viewUserKeys(id);
    });
    loadUsers(1);
});

function escHtml(str){
    if(str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function toast(type, msg, timer){
    if(type === 'success'){
        Swal.fire({title:msg, icon:'success', timer:1200, showConfirmButton:false});
    } else if(type === 'error'){
        Swal.fire('错误', msg, 'error');
    } else if(type === 'warning'){
        Swal.fire('提示', msg, 'warning');
    } else {
        Swal.fire('提示', msg, 'info');
    }
}

function loadUsers(page){
    $.ajax({
        url: 'users.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'list',
            page: page,
            search: $('#searchInput').val(),
            role: $('#roleFilter').val(),
            status: $('#statusFilter').val()
        },
        success: function(res){
            if(res.code === 0){
                renderTable(res.data);
                renderPagination(res.data);
                renderSummary(res.data);
                _usersCache = res.data.users || [];
                _currentPage = res.data.page;
            } else {
                toast('error', res.msg);
            }
        },
        error: function(){
            toast('error', '网络错误，请重试');
        }
    });
}

function renderSummary(data){
    if(data.summary){
        $('#statTotal').text(data.summary.totalUsers);
        $('#statActive').text(data.summary.activeUsers);
        $('#statAdmins').text(data.summary.totalAdmins);
    }
    $('#statFiltered').text(data.total);
}

function renderTable(data){
    var tbody = $('#usersTableBody');
    tbody.empty();
    if(!data.users || data.users.length === 0){
        tbody.append('<tr><td colspan="12" class="text-center text-muted py-5"><span style="font-size:48px;display:block;margin-bottom:8px;">'+eeIcon('account-off-outline')+'</span>暂无用户数据</td></tr>');
        syncCheckAll();
        return;
    }
    $.each(data.users, function(i, u){
        var roleBadge = u.role === 'super_admin'
            ? '<span class="badge-status" style="background:#ede9fe;color:#5b21b6;">'+eeIcon('shield-account')+' 超级管理员</span>'
            : '<span class="badge-status" style="background:#e0f2fe;color:#075985;">'+eeIcon('account')+' 普通用户</span>';
        var statusBadge = u.status === 1
            ? '<span class="badge-status badge-status-on">'+eeIcon('check')+' 启用</span>'
            : '<span class="badge-status badge-status-off">'+eeIcon('close')+' 禁用</span>';
        var lastLogin = u.last_login ? escHtml(u.last_login) : '<span class="text-muted">从未登录</span>';
        var verifiedIcon = u.email_verified == 1 ? ' <span title="邮箱已验证">'+eeIcon('check-decagram', 'text-success')+'</span>' : '';
        var selfTag = u.is_self ? ' <span class="badge bg-info">当前账号</span>' : '';

        // 套餐信息渲染
        var pkgName = u.package_name ? escHtml(u.package_name) : '<span class="text-muted">免费版</span>';
        var pkgLevel = u.package_level > 0 ? ' <span class="badge bg-secondary">L'+u.package_level+'</span>' : '';
        var expireHtml = '';
        if(u.is_permanent){
            expireHtml = '<div class="text-success" style="font-size:12px;">'+eeIcon('infinity')+' 永久</div>';
        } else if(u.expire_time){
            if(u.is_expired){
                expireHtml = '<div style="font-size:12px;"><span class="badge bg-danger">已过期</span> '+escHtml(u.expire_time)+'</div>';
            } else {
                expireHtml = '<div class="text-muted" style="font-size:12px;">'+escHtml(u.expire_time)+'</div>';
            }
        }
        var pkgCell = '<div>'+pkgName+pkgLevel+'</div>'+expireHtml;

        // 选择框
        var checkedAttr = _selectedIds.has(u.id) ? ' checked' : '';
        var checkCell = '<input type="checkbox" class="row-check" value="'+u.id+'"'+checkedAttr+' onchange="toggleRow('+u.id+', this.checked)">';

        // 状态切换按钮（仅用 data-id，username 从 _usersCache 读取，避免内联 JS 字符串拼接 XSS）
        var toggleBtn = '';
        if(u.is_self){
            toggleBtn = '<button type="button" class="btn btn-outline-secondary" disabled title="无法操作当前账号">'+eeIcon('account-question-outline')+'</button>';
        } else if(u.status === 1){
            toggleBtn = '<button type="button" class="btn btn-outline-warning act-toggle" data-id="'+u.id+'" title="封禁用户">'+eeIcon('account-cancel-outline')+'</button>';
        } else {
            toggleBtn = '<button type="button" class="btn btn-outline-success act-toggle" data-id="'+u.id+'" title="启用用户">'+eeIcon('account-check-outline')+'</button>';
        }

        var actions = '<div class="btn-group btn-group-sm">';
        actions += '<button type="button" class="btn btn-outline-primary act-edit" data-id="'+u.id+'" title="编辑">'+eeIcon('pencil')+'</button>';
        actions += toggleBtn;
        actions += '<button type="button" class="btn btn-outline-warning act-reset-pwd" data-id="'+u.id+'" title="重置密码">'+eeIcon('lock-reset')+'</button>';
        if(u.is_self){
            actions += '<button type="button" class="btn btn-outline-secondary" disabled title="无法删除自己">'+eeIcon('delete')+'</button>';
        } else {
            actions += '<button type="button" class="btn btn-outline-danger act-delete" data-id="'+u.id+'" title="删除">'+eeIcon('delete')+'</button>';
        }
        actions += '</div>';

        var row = '<tr>' +
            '<td>'+checkCell+'</td>' +
            '<td>'+u.id+'</td>' +
            '<td><strong>'+escHtml(u.username)+'</strong>'+selfTag+verifiedIcon+'</td>' +
            '<td>'+escHtml(u.email || '-')+'</td>' +
            '<td>'+roleBadge+'</td>' +
            '<td>'+statusBadge+'</td>' +
            '<td>'+u.upload_count+'</td>' +
            '<td><a href="javascript:void(0)" class="act-view-keys" data-id="'+u.id+'" title="查看该用户API密钥" style="cursor:pointer;">'+u.api_key_count+'</a></td>' +
            '<td>'+pkgCell+'</td>' +
            '<td class="text-muted" style="font-size:13px;">'+escHtml(u.created_at)+'</td>' +
            '<td class="text-muted" style="font-size:13px;">'+lastLogin+'</td>' +
            '<td>'+actions+'</td>' +
            '</tr>';
        tbody.append(row);
    });
    syncCheckAll();
}

// ============ 批量选择 ============
var _selectedIds = new Set();

function toggleRow(id, checked){
    if(checked){
        _selectedIds.add(id);
    } else {
        _selectedIds.delete(id);
    }
    updateBatchBar();
}

function toggleAll(master){
    var checked = master.checked;
    $('#usersTableBody .row-check').each(function(){
        var id = parseInt($(this).val(), 10);
        if(isNaN(id)) return;
        if(checked){
            _selectedIds.add(id);
            this.checked = true;
        } else {
            _selectedIds.delete(id);
            this.checked = false;
        }
    });
    updateBatchBar();
}

function syncCheckAll(){
    var visibleChecks = $('#usersTableBody .row-check');
    if(visibleChecks.length === 0){
        $('#checkAll').prop('indeterminate', false).prop('checked', false);
        updateBatchBar();
        return;
    }
    var checkedCount = 0;
    visibleChecks.each(function(){
        if(_selectedIds.has(parseInt($(this).val(), 10))) checkedCount++;
    });
    $('#checkAll').prop('indeterminate', checkedCount > 0 && checkedCount < visibleChecks.length);
    $('#checkAll').prop('checked', checkedCount === visibleChecks.length);
    updateBatchBar();
}

function updateBatchBar(){
    var count = _selectedIds.size;
    $('#selectedCount').text(count);
    $('#batchBar').toggle(count > 0);
}

function clearSelection(){
    _selectedIds.clear();
    $('#usersTableBody .row-check').prop('checked', false);
    $('#checkAll').prop('checked', false).prop('indeterminate', false);
    updateBatchBar();
}

// ============ 单个状态切换 ============
function toggleUserStatus(id){
    var u = findUser(id);
    if(!u){ toast('error', '用户数据不存在'); return; }
    var currentStatus = u.status;
    var username = u.username;
    var action = currentStatus === 1 ? '封禁' : '启用';
    var html = currentStatus === 1
        ? '确定要<strong class="text-danger">封禁</strong>用户 <strong>'+escHtml(username)+'</strong> 吗？<br><span class="text-muted" style="font-size:13px;">封禁后该用户将立即被强制下线，无法登录。</span>'
        : '确定要<strong class="text-success">启用</strong>用户 <strong>'+escHtml(username)+'</strong> 吗？<br><span class="text-muted" style="font-size:13px;">启用后该用户可正常登录使用。</span>';
    Swal.fire({
        title:'确认'+action,
        html: html,
        icon: currentStatus === 1 ? 'warning' : 'question',
        showCancelButton:true,
        confirmButtonText:'确认'+action,
        cancelButtonText:'取消',
        confirmButtonColor: currentStatus === 1 ? '#f59e0b' : '#22c55e',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'users.php', type:'POST', dataType:'json',
                data:{ action:'toggle_status', id:id },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadUsers(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

// ============ 批量删除 ============
function batchDeleteSelected(){
    if(_selectedIds.size === 0){ toast('warning', '请先选择要删除的用户'); return; }
    var ids = Array.from(_selectedIds);
    Swal.fire({
        title:'确认批量删除',
        html:'确定要删除选中的 <strong style="color:#ef4444;">'+ids.length+'</strong> 个用户吗？<br><span class="text-muted" style="font-size:13px;">当前登录账号和最后一个超级管理员将被自动跳过。</span><br><span class="text-danger" style="font-size:13px;">该操作不可恢复！</span>',
        icon:'warning',
        showCancelButton:true,
        confirmButtonText:'确认删除',
        cancelButtonText:'取消',
        confirmButtonColor:'#ef4444',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'users.php', type:'POST', dataType:'json',
                data:{ action:'delete_selected', ids:ids },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        clearSelection();
                        loadUsers(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

// ============ 批量启用/封禁 ============
function batchSetStatus(status){
    if(_selectedIds.size === 0){ toast('warning', '请先选择用户'); return; }
    var ids = Array.from(_selectedIds);
    var actionText = status === 1 ? '启用' : '封禁';
    var html = status === 1
        ? '确定要<strong class="text-success">启用</strong>选中的 <strong>'+ids.length+'</strong> 个用户吗？'
        : '确定要<strong class="text-danger">封禁</strong>选中的 <strong>'+ids.length+'</strong> 个用户吗？<br><span class="text-muted" style="font-size:13px;">封禁后这些用户将立即被强制下线。</span>';
    Swal.fire({
        title:'确认批量'+actionText,
        html: html,
        icon: status === 1 ? 'question' : 'warning',
        showCancelButton:true,
        confirmButtonText:'确认'+actionText,
        cancelButtonText:'取消',
        confirmButtonColor: status === 1 ? '#22c55e' : '#f59e0b',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'users.php', type:'POST', dataType:'json',
                data:{ action:'set_status_selected', ids:ids, status:status },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        clearSelection();
                        loadUsers(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

function renderPagination(data){
    var box = $('#usersPagination');
    box.empty();
    if(data.totalPages <= 1) return;
    var nav = $('<nav><ul class="pagination mb-0"></ul></nav>');
    var ul = nav.find('ul');
    ul.append('<li class="page-item '+(data.page<=1?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" onclick="loadUsers('+(data.page-1)+')">上一页</a></li>');
    var start = Math.max(1, data.page - 2);
    var end = Math.min(data.totalPages, data.page + 2);
    if(start > 1){
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadUsers(1)">1</a></li>');
        if(start > 2) ul.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
    }
    for(var p = start; p <= end; p++){
        ul.append('<li class="page-item '+(p===data.page?'active':'')+'"><a class="page-link" href="javascript:void(0)" onclick="loadUsers('+p+')">'+p+'</a></li>');
    }
    if(end < data.totalPages){
        if(end < data.totalPages - 1) ul.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadUsers('+data.totalPages+')">'+data.totalPages+'</a></li>');
    }
    ul.append('<li class="page-item '+(data.page>=data.totalPages?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" onclick="loadUsers('+(data.page+1)+')">下一页</a></li>');
    box.append(nav);
}

function resetFilter(){
    $('#searchInput').val('');
    $('#roleFilter').val('all');
    $('#statusFilter').val('all');
    loadUsers(1);
}

function findUser(id){
    for(var i = 0; i < _usersCache.length; i++){
        if(_usersCache[i].id == id) return _usersCache[i];
    }
    return null;
}

function openEdit(id){
    var u = findUser(id);
    if(!u){ toast('error', '用户数据不存在'); return; }
    $('#editId').val(u.id);
    $('#editUsername').val(u.username);
    $('#editEmail').val(u.email);
    $('#editRole').val(u.role);
    $('#editStatus').val(u.status);
    $('#subForceSwitch').prop('checked', false);
    loadUserSubscription(id);
    editModal.show();
}

function saveEdit(){
    var data = {
        action: 'update',
        id: $('#editId').val(),
        username: $('#editUsername').val().trim(),
        email: $('#editEmail').val().trim(),
        role: $('#editRole').val(),
        status: $('#editStatus').val()
    };
    if(!data.username){ toast('warning', '用户名不能为空'); return; }

    var roleText = data.role === 'super_admin' ? '超级管理员' : '普通用户';
    var statusText = data.status == 1 ? '启用' : '禁用';
    Swal.fire({
        title: '确认修改',
        html: '确定要保存对用户 <strong>'+escHtml(data.username)+'</strong> 的修改吗？<br><span class="text-muted" style="font-size:13px;">角色：'+roleText+' ｜ 状态：'+statusText+'</span>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url: 'users.php', type: 'POST', dataType: 'json', data: data,
            success: function(res){
                if(res.code === 0){
                    editModal.hide();
                    toast('success', res.msg);
                    loadUsers(_currentPage);
                } else {
                    toast('error', res.msg);
                }
            },
            error: function(){ toast('error', '网络错误，请重试'); }
        });
    });
}

function openResetPwd(id){
    var u = findUser(id);
    if(!u){ toast('error', '用户数据不存在'); return; }
    $('#resetPwdId').val(id);
    $('#resetPwdUsername').text(u.username);
    $('#resetPwdPassword').val('');
    $('#resetPwdConfirm').val('');
    resetPwdModal.show();
}

function saveResetPwd(){
    var pwd = $('#resetPwdPassword').val();
    var confirm = $('#resetPwdConfirm').val();
    if(!pwd){ toast('warning', '请输入新密码'); return; }
    if(pwd.length < 6){ toast('warning', '密码长度至少6位'); return; }
    if(pwd !== confirm){ toast('warning', '两次输入的密码不一致'); return; }

    var username = $('#resetPwdUsername').text();
    Swal.fire({
        title: '确认重置密码',
        html: '确定要重置用户 <strong>'+escHtml(username)+'</strong> 的密码吗？<br><span class="text-danger" style="font-size:13px;">原密码将立即失效，该操作不可恢复！</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '确认重置',
        cancelButtonText: '取消',
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url: 'users.php', type: 'POST', dataType: 'json',
            data: { action: 'reset_password', id: $('#resetPwdId').val(), password: pwd },
            success: function(res){
                if(res.code === 0){
                    resetPwdModal.hide();
                    toast('success', res.msg);
                    loadUsers(_currentPage);
                } else {
                    toast('error', res.msg);
                }
            },
            error: function(){ toast('error', '网络错误，请重试'); }
        });
    });
}

function deleteUser(id){
    var u = findUser(id);
    if(!u){ toast('error', '用户数据不存在'); return; }
    var username = u.username;
    Swal.fire({
        title: '确认删除',
        html: '确定要删除用户 <strong>'+escHtml(username)+'</strong> 吗？<br>该操作不可恢复！',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '确认删除',
        cancelButtonText: '取消',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url: 'users.php', type: 'POST', dataType: 'json',
                data: { action: 'delete', id: id },
                success: function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadUsers(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error: function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

function formatBytes(bytes){
    if(bytes === null || bytes === undefined) return '-';
    bytes = parseInt(bytes, 10);
    if(bytes === -1) return '无限制';
    if(isNaN(bytes) || bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    if(i >= units.length) i = units.length - 1;
    if(i < 0) i = 0;
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

function loadUserSubscription(id){
    $('#subPackageName').text('-');
    $('#subExpireTime').text('-');
    $('#subStorageLimit').text('-');
    $('#subStatus').text('-');
    $('#subPackageSelect').html('<option value="0">-- 加载中 --</option>');
    $('#subForceSwitch').prop('checked', false);
    $('#customStorageSwitch').prop('checked', false);
    $('#customStorageValueBox').hide();
    $('#customStorageValue').val('');

    $.ajax({
        url: 'users.php', type: 'POST', dataType: 'json',
        data: { action: 'get_subscription', id: id },
        success: function(res){
            if(res.code === 0){
                var sub = res.data.subscription;
                var pkgs = res.data.packages || [];

                $('#subPackageName').text(sub.package_name || '免费版');
                if(sub.is_permanent){
                    $('#subExpireTime').html('<span class="text-success">'+eeIcon('infinity')+' 永久</span>');
                } else if(sub.expire_time){
                    $('#subExpireTime').text(sub.expire_time);
                } else {
                    $('#subExpireTime').html('<span class="text-muted">永久/无</span>');
                }
                $('#subStorageLimit').text(formatBytes(sub.storage_limit));
                if(sub.is_expired){
                    $('#subStatus').html('<span class="badge bg-danger">已过期</span>');
                } else {
                    $('#subStatus').html('<span class="badge bg-success">有效</span>');
                }

                var opts = '<option value="0">-- 请选择套餐 --</option>';
                $.each(pkgs, function(i, p){
                    var daysText = p.days == 0 ? '永久' : p.days+'天';
                    opts += '<option value="'+p.id+'">'+escHtml(p.name)+' (等级'+p.level+', '+formatBytes(p.storage_limit)+', '+daysText+')</option>';
                });
                $('#subPackageSelect').html(opts);

                // 自定义存储回填
                if(sub.custom_storage !== null && sub.custom_storage !== undefined){
                    $('#customStorageSwitch').prop('checked', true);
                    $('#customStorageValueBox').show();
                    $('#customStorageValue').val(Math.round(sub.custom_storage / 1048576));
                } else {
                    $('#customStorageSwitch').prop('checked', false);
                    $('#customStorageValueBox').hide();
                }
            } else {
                toast('error', res.msg);
            }
        },
        error: function(){ toast('error', '加载套餐信息失败'); }
    });
}

function switchPackage(){
    var id = $('#editId').val();
    var packageId = $('#subPackageSelect').val();
    var force = $('#subForceSwitch').prop('checked') ? 1 : 0;
    if(!id){ toast('warning', '请先选择用户'); return; }
    if(packageId == 0 || !packageId){ toast('warning', '请选择目标套餐'); return; }

    var pkgText = $('#subPackageSelect option:selected').text();
    Swal.fire({
        title: '确认切换套餐',
        html: '确定要将该用户的套餐切换为 <strong>'+escHtml(pkgText)+'</strong> 吗？'+(force?'<br><span class="text-danger">已勾选强制切换，将跳过降级拦截</span>':''),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认切换',
        cancelButtonText: '取消',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url: 'users.php', type: 'POST', dataType: 'json',
                data: { action: 'switch_package', id: id, package_id: packageId, force: force },
                success: function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadUserSubscription(id);
                        loadUsers(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error: function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

function saveCustomStorage(){
    var id = $('#editId').val();
    var enabled = $('#customStorageSwitch').prop('checked') ? 1 : 0;
    var value = $('#customStorageValue').val();
    if(!id){ toast('warning', '请先选择用户'); return; }
    if(enabled == 1){
        if(!value || parseInt(value) <= 0){ toast('warning', '请输入有效的存储数值（MB）'); return; }
    }

    var actionDesc = enabled == 1
        ? '开启自定义存储配额（'+parseInt(value)+' MB）'
        : '关闭自定义存储，恢复跟随套餐默认值';
    Swal.fire({
        title: '确认保存存储设置',
        text: '确定要'+actionDesc+'吗？',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url: 'users.php', type: 'POST', dataType: 'json',
            data: { action: 'set_custom_storage', id: id, enabled: enabled, value: value || 0 },
            success: function(res){
                if(res.code === 0){
                    toast('success', res.msg);
                    loadUserSubscription(id);
                    loadUsers(_currentPage);
                } else {
                    toast('error', res.msg);
                }
            },
            error: function(){ toast('error', '网络错误，请重试'); }
        });
    });
}
// ============ 用户 API 密钥查看（用户管理详情 Tab） ============
function viewUserKeys(userId){
    var u = findUser(userId);
    if(!u){ toast('error', '用户数据不存在'); return; }
    $('#ukUserName').text(u.username);
    $('#ukTableBody').html('<tr><td colspan="6" class="text-center text-muted py-4">'+eeIcon('loading','icon-spin')+' 加载中...</td></tr>');
    new bootstrap.Modal(document.getElementById('userKeysModal')).show();
    $.post('apikeys.php', {action:'user_keys', user_id:userId}, function(res){
        if(res.code !== 0){ $('#ukTableBody').html('<tr><td colspan="6" class="text-center text-danger py-4">'+res.msg+'</td></tr>'); return; }
        var list = res.data.list || [];
        $('#ukCount').text(res.data.count || 0);
        if(list.length === 0){
            $('#ukTableBody').html('<tr><td colspan="6" class="text-center text-muted py-4">'+eeIcon('key-remove')+' 该用户暂无API密钥</td></tr>');
            return;
        }
        var html = '';
        for(var i=0;i<list.length;i++){
            var r = list[i];
            var st = r.status === 1 ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-danger">禁用</span>';
            html += '<tr>'
                + '<td>'+r.id+'</td>'
                + '<td>'+escHtml(r.name)+'</td>'
                + '<td><code>'+escHtml(r.key_prefix)+'</code></td>'
                + '<td>'+st+'</td>'
                + '<td class="text-muted" style="font-size:12px;">'+(r.last_used_at||'未使用')+'</td>'
                + '<td class="text-muted" style="font-size:12px;">'+(r.created_at||'-')+'</td>'
                + '</tr>';
        }
        $('#ukTableBody').html(html);
    }, 'json').fail(function(){ $('#ukTableBody').html('<tr><td colspan="6" class="text-center text-danger py-4">网络错误</td></tr>'); });
}
</script>
<!-- 用户 API 密钥列表 Modal -->
<div class="modal fade" id="userKeysModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo icon('key-variant'); ?> <span id="ukUserName"></span> 的 API 密钥</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small text-muted">共 <b id="ukCount">0</b> 个密钥（明文不可查看，仅展示前缀与状态）</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead>
              <tr><th style="width:50px;">ID</th><th>名称</th><th>密钥前缀</th><th style="width:70px;">状态</th><th style="width:140px;">最后使用</th><th style="width:140px;">创建时间</th></tr>
            </thead>
            <tbody id="ukTableBody"></tbody>
          </table>
        </div>
        <div class="alert alert-info mt-2 mb-0 py-2 small"><?php echo icon('information-outline'); ?> 如需管理该用户的密钥（重置/删除/禁用），请前往「API密钥管理」页面。</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
