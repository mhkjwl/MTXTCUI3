<?php
/**
 * @file api/user_api.php
 * @description 用户中心API，支持注册/登录/登出/用户信息/改密/图片列表/删除图片
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 用户中心 API
 * 支持：注册、登录、登出、获取用户信息、修改密码、获取图片列表、删除图片
 */
require ('../inc/common.php');
header('Content-Type: application/json; charset=utf-8');
// 防止浏览器缓存 AJAX 响应（套餐信息、接口列表等需实时反映最新状态）
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch($action) {

// ========== 注册 ==========
case 'register':
    if(!is_registration_enabled()) {
        echo json_encode(['code' => 1, 'msg' => '当前已关闭注册功能']);
        exit;
    }
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试']);
        exit;
    }
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $email    = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $verifyCode = trim(isset($_POST['verify_code']) ? $_POST['verify_code'] : '');

    // 校验
    if(strlen($username) < 3 || strlen($username) > 32) {
        echo json_encode(['code' => 1, 'msg' => '用户名长度需要 3-32 个字符']);
        exit;
    }
    if(!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        echo json_encode(['code' => 1, 'msg' => '用户名只能包含字母、数字、下划线、中文']);
        exit;
    }
    if(strlen($password) < 6 || strlen($password) > 64) {
        echo json_encode(['code' => 1, 'msg' => '密码长度需要 6-64 个字符']);
        exit;
    }
    // M7: 密码复杂度要求 - 至少包含字母和数字
    if(!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        echo json_encode(['code' => 1, 'msg' => '密码必须包含字母和数字']);
        exit;
    }
    if(is_email_verify_required()) {
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['code' => 1, 'msg' => '请输入有效的邮箱地址']);
            exit;
        }
        if(empty($verifyCode)) {
            echo json_encode(['code' => 1, 'msg' => '请输入邮箱验证码']);
            exit;
        }
        // 校验验证码（含尝试次数限制，返回具体错误信息）
        $verifyResult = verify_email_code($email, $verifyCode);
        if(!$verifyResult['valid']) {
            echo json_encode(['code' => 1, 'msg' => $verifyResult['msg']]);
            exit;
        }
    }

    // M6: 统一错误消息防止用户名/邮箱枚举
    // 检查用户名是否已存在
    $existing = $DB->get_row_prepared("SELECT id FROM eecms_users WHERE username = ?", 's', [$username]);
    $usernameTaken = $existing ? true : false;

    // 邮箱验证开启时检查邮箱是否已被使用
    $emailTaken = false;
    if(is_email_verify_required()) {
        $emailExists = $DB->get_row_prepared("SELECT id FROM eecms_users WHERE email = ? AND email != ''", 's', [$email]);
        $emailTaken = $emailExists ? true : false;
    }

    if($usernameTaken || $emailTaken) {
        // 统一错误消息，不区分是用户名还是邮箱已被占用
        echo json_encode(['code' => 1, 'msg' => '注册失败，请更换信息后重试']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    // 邮箱验证码已验证通过，直接标记为已验证
    $emailVerified = 1;

    // § 8.3.4：使用预处理语句插入新用户
    $newId = $DB->insert_prepared(
        "INSERT INTO eecms_users (username, password, email, role, status, email_verified, created_at) VALUES (?, ?, ?, 'user', 1, ?, NOW())",
        'sssi',
        [$username, $hash, $email, $emailVerified]
    );

    if($newId && $newId > 0) {
        // 为新用户分配默认套餐
        $defaultPkg = get_default_package($DB);
        if($defaultPkg) {
            $defId = (int)$defaultPkg['id'];
            $defLevel = (int)$defaultPkg['level'];
            $defName = $defaultPkg['name'];
            $defExpire = date('Y-m-d H:i:s', strtotime('+10 years')); // 默认套餐长期有效
            $subOk = pkg_safe_query_prepared($DB,
                "INSERT INTO eecms_user_subs (user_id, package_id, package_level, package_name, expire_time) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE package_id=VALUES(package_id), package_level=VALUES(package_level), package_name=VALUES(package_name), expire_time=VALUES(expire_time)",
                'iiiss',
                [$newId, $defId, $defLevel, $defName, $defExpire]
            );
            if($subOk === false) {
                // 套餐分配失败，记录日志以便管理员后续补发（用户仍可凭兜底逻辑使用默认套餐）
                error_log('[register] user_subs assignment failed: user_id=' . $newId . ' pkg_id=' . $defId);
            }
        } else {
            // 无默认套餐配置，记录日志
            error_log('[register] no default package found for new user: user_id=' . $newId);
        }
        // 不自动登录，让用户手动登录
        echo json_encode(['code' => 0, 'msg' => '注册成功，请登录']);
    } else {
        // M9: 不暴露数据库错误信息，仅记录到日志
        error_log('Registration failed for user: ' . $username . ' DB Error: ' . $DB->error());
        echo json_encode(['code' => 1, 'msg' => '注册失败，请稍后重试或联系管理员']);
    }
    exit;

// ========== 发送验证码 ==========
case 'send_code':
    if(!is_registration_enabled()) {
        echo json_encode(['code' => 1, 'msg' => '当前已关闭注册功能']);
        exit;
    }
    if(!is_email_verify_required()) {
        echo json_encode(['code' => 1, 'msg' => '当前未开启邮箱验证功能']);
        exit;
    }
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试']);
        exit;
    }
    if(!is_smtp_configured()) {
        echo json_encode(['code' => 1, 'msg' => '管理员尚未配置 SMTP 邮箱服务，无法发送验证码']);
        exit;
    }

    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['code' => 1, 'msg' => '请输入有效的邮箱地址']);
        exit;
    }

    // 检查邮箱是否已被注册
    $emailUsed = $DB->get_row_prepared("SELECT id FROM eecms_users WHERE email = ? AND email != ''", 's', [$email]);
    if($emailUsed) {
        echo json_encode(['code' => 1, 'msg' => '该邮箱已被注册，请使用其他邮箱']);
        exit;
    }

    $ip = real_ip();
    $code = generate_email_code();

    // 存储验证码（含 60 秒频率限制）
    if(!store_email_code($email, $code, $ip)) {
        echo json_encode(['code' => 1, 'msg' => '发送过于频繁，请 60 秒后再试']);
        exit;
    }

    // 发送邮件
    $siteName = isset($conf['name']) ? $conf['name'] : '图床';
    $result = send_verification_email($email, $code, $siteName);

    if($result['success']) {
        echo json_encode(['code' => 0, 'msg' => '验证码已发送至 ' . $email . '，请查收邮件（10 分钟内有效）']);
    } else {
        echo json_encode(['code' => 1, 'msg' => '验证码发送失败：' . $result['error']]);
    }
    exit;

// ========== 登录 ==========
case 'login':
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试']);
        exit;
    }
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if($username === '' || $password === '') {
        echo json_encode(['code' => 1, 'msg' => '请填写用户名和密码']);
        exit;
    }

    // 暴力破解防护：基于服务端文件的失败计数（账号级 + IP 级），清 Cookie/换会话无法绕过
    $clientIp = real_ip();
    $f2bUserKey = 'ulogin_user_' . md5(strtolower($username));
    $f2bIpKey   = 'ulogin_ip_' . $clientIp;

    // 账号级别锁定检查
    $lockUser = f2b_locked_seconds($f2bUserKey);
    if($lockUser > 0) {
        $wait = ceil($lockUser / 60);
        echo json_encode(['code' => 1, 'msg' => "登录尝试过于频繁，请 {$wait} 分钟后再试"]);
        exit;
    }
    // IP 级别锁定检查（防止切换用户名绕过账号锁定）
    $lockIp = f2b_locked_seconds($f2bIpKey);
    if($lockIp > 0) {
        $wait = ceil($lockIp / 60);
        echo json_encode(['code' => 1, 'msg' => "此 IP 登录尝试过多，请 {$wait} 分钟后再试"]);
        exit;
    }

    $row = $DB->get_row_prepared("SELECT * FROM eecms_users WHERE username = ?", 's', [$username]);
    if(!$row) {
        // 统一错误消息，防止用户枚举
        f2b_hit($f2bUserKey, 5, 600, 600);
        f2b_hit($f2bIpKey, 10, 600, 600);
        echo json_encode(['code' => 1, 'msg' => '用户名或密码不正确']);
        exit;
    }
    if($row['status'] != 1) {
        echo json_encode(['code' => 1, 'msg' => '您的账户已被封禁，请联系客服']);
        exit;
    }
    // 超级管理员只能通过后台管理入口登录，不能通过用户中心登录
    if($row['role'] === 'super_admin') {
        echo json_encode(['code' => 1, 'msg' => '用户名或密码不正确']);
        exit;
    }
    if(!password_verify($password, $row['password'])) {
        // 统一错误消息，防止用户枚举
        f2b_hit($f2bUserKey, 5, 600, 600);
        f2b_hit($f2bIpKey, 10, 600, 600);
        echo json_encode(['code' => 1, 'msg' => '用户名或密码不正确']);
        exit;
    }
    if(is_email_verify_required() && $row['email_verified'] != 1) {
        echo json_encode(['code' => 1, 'msg' => '用户名或密码不正确']);
        exit;
    }

    // 登录成功：重置失败计数
    f2b_reset($f2bUserKey);
    // 会话固定攻击防护：重新生成 session ID
    session_regenerate_id(true);
    // 更新最后登录时间
    $DB->query("UPDATE eecms_users SET last_login = NOW() WHERE id = ".intval($row['id']));
    user_login($row['id'], $row['password']);
    echo json_encode(['code' => 0, 'msg' => '登录成功！', 'redirect' => 'index.php', 'role' => $row['role']]);
    exit;

// ========== 登出 ==========
case 'logout':
    user_logout();
    echo json_encode(['code' => 0, 'msg' => '已退出登录', 'redirect' => 'login.php']);
    exit;

// ========== 获取当前用户信息 ==========
case 'profile':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '未登录']);
        exit;
    }
    $pkgInfo = get_user_effective_package($DB, $currentUserId);
    echo json_encode([
        'code' => 0,
        'user' => [
            'id'       => (int)$currentUser['id'],
            'username' => $currentUser['username'],
            'email'    => $currentUser['email'],
            'role'     => $currentUser['role'],
            'avatar'   => $currentUser['avatar'],
            'upload_count' => (int)$currentUser['upload_count'],
            'created_at'   => $currentUser['created_at'],
            'last_login'   => $currentUser['last_login'],
            'package_name'  => $pkgInfo['package_name'],
            'package_level' => (int)$pkgInfo['level'],
            'expire_time'   => $pkgInfo['expire_time'],
        ]
    ]);
    exit;

// ========== 修改密码 ==========
case 'change_password':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败']);
        exit;
    }
    $oldPwd = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $newPwd = isset($_POST['new_password']) ? $_POST['new_password'] : '';

    if(!password_verify($oldPwd, $currentUser['password'])) {
        echo json_encode(['code' => 1, 'msg' => '原密码不正确']);
        exit;
    }
    if(strlen($newPwd) < 6 || strlen($newPwd) > 64) {
        echo json_encode(['code' => 1, 'msg' => '新密码长度需要 6-64 个字符']);
        exit;
    }
    // M7: 密码复杂度要求
    if(!preg_match('/[a-zA-Z]/', $newPwd) || !preg_match('/[0-9]/', $newPwd)) {
        echo json_encode(['code' => 1, 'msg' => '新密码必须包含字母和数字']);
        exit;
    }

    $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
    // 递增 session_version 使所有设备（含当前设备）的登录态失效
    invalidate_user_sessions($currentUserId);
    // § 8.3.4：使用预处理语句更新密码
    $ok = $DB->query_prepared("UPDATE eecms_users SET password = ? WHERE id = ?", 'si', [$newHash, $currentUserId]);
    if($ok === false) {
        echo json_encode(['code' => 1, 'msg' => '密码修改失败，请稍后重试']);
        exit;
    }
    // 安全策略：修改密码后立即退出登录，要求用户使用新密码重新登录
    user_logout();
    echo json_encode(['code' => 0, 'msg' => '密码修改成功，请使用新密码重新登录', 'logout' => true]);
    exit;

// ========== 获取图片列表 ==========
case 'images':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? min(100, max(10, intval($_GET['per_page']))) : 20;
    $offset = ($page - 1) * $perPage;
    $filterUser = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // 权限：普通用户只能看自己的，超级管理员可以看所有或指定用户的
    $where = "";
    if($currentUserRole !== 'super_admin') {
        $where = " WHERE user_id = ".intval($currentUserId);
    } elseif($filterUser > 0) {
        $where = " WHERE user_id = ".intval($filterUser);
    }

    $total = $DB->count("SELECT COUNT(*) FROM eecms_images".$where);
    $rs = $DB->query("SELECT * FROM eecms_images".$where." ORDER BY created_at DESC LIMIT {$offset}, {$perPage}");
    $images = [];
    while($rs && ($row = $DB->fetch($rs))) {
        $images[] = [
            'id'         => (int)$row['id'],
            'user_id'    => (int)$row['user_id'],
            'username'   => $row['username'],
            'filename'   => $row['filename'],
            'url'        => $row['url'],
            'thumb_url'  => $row['thumb_url'],
            'size'       => (int)$row['size'],
            'api_type'   => $row['api_type'],
            'created_at' => $row['created_at'],
        ];
    }

    // 如果是超级管理员，返回用户列表用于筛选
    $users = [];
    if($currentUserRole === 'super_admin') {
        $rs2 = $DB->query("SELECT id, username, role FROM eecms_users ORDER BY id ASC");
        while($u = $DB->fetch($rs2)) {
            $users[] = ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']];
        }
    }

    echo json_encode([
        'code'  => 0,
        'data'  => $images,
        'total' => (int)$total,
        'page'  => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage),
        'users' => $users,
        'can_manage_all' => ($currentUserRole === 'super_admin'),
    ]);
    exit;

// ========== 删除图片记录 ==========
case 'delete_image':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败']);
        exit;
    }
    $imgId = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if($imgId <= 0) {
        echo json_encode(['code' => 1, 'msg' => '参数无效']);
        exit;
    }

    // 权限检查：普通用户只能删自己的
    $img = $DB->get_row("SELECT * FROM eecms_images WHERE id = ".intval($imgId));
    if(!$img) {
        echo json_encode(['code' => 1, 'msg' => '图片记录不存在']);
        exit;
    }
    if($currentUserRole !== 'super_admin' && $img['user_id'] != $currentUserId) {
        echo json_encode(['code' => 1, 'msg' => '无权操作此图片']);
        exit;
    }

    $DB->query("DELETE FROM eecms_images WHERE id = ".intval($imgId));
    // 联动删除本地存储的文件（本地上传的图片）
    try_delete_local_image($img['url']);
    // 减少用户上传计数
    if($img['user_id'] > 0) {
        $DB->query("UPDATE eecms_users SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ".intval($img['user_id']));
    }
    echo json_encode(['code' => 0, 'msg' => '已删除图片记录']);
    exit;

// ========== 获取统计数据 ==========
case 'stats':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    $myCount = $DB->count("SELECT COUNT(*) FROM eecms_images WHERE user_id = ".intval($currentUserId));
    $mySize  = $DB->count("SELECT COALESCE(SUM(size),0) FROM eecms_images WHERE user_id = ".intval($currentUserId));

    // 套餐信息
    $pkgInfo = get_user_effective_package($DB, $currentUserId);

    $result = [
        'code' => 0,
        'my_images'  => (int)$myCount,
        'upload_count' => (int)$myCount, // 兼容旧版前端缓存，值与 my_images 一致
        'my_size'    => (int)$mySize,
        'my_size_formatted' => format_size((int)$mySize),
        'is_super_admin' => ($currentUserRole === 'super_admin'),
        'package_name'  => $pkgInfo['package_name'],
        'package_level' => (int)$pkgInfo['level'],
        'expire_time'   => $pkgInfo['expire_time'],
        'is_expired'    => $pkgInfo['is_expired'],
        'storage_limit' => (int)$pkgInfo['storage_limit'],
        'storage_limit_formatted' => format_size((int)$pkgInfo['storage_limit']),
    ];

    if($currentUserRole === 'super_admin') {
        $totalUsers = $DB->count("SELECT COUNT(*) FROM eecms_users");
        $totalImages = $DB->count("SELECT COUNT(*) FROM eecms_images");
        $totalSize = $DB->count("SELECT COALESCE(SUM(size),0) FROM eecms_images");
        $todayImages = $DB->count("SELECT COUNT(*) FROM eecms_images WHERE DATE(created_at) = CURDATE()");
        $result['total_users'] = (int)$totalUsers;
        $result['total_images'] = (int)$totalImages;
        $result['total_size'] = (int)$totalSize;
        $result['total_size_formatted'] = format_size((int)$totalSize);
        $result['today_images'] = (int)$todayImages;
    }

    echo json_encode($result);
    exit;

// ========== 检查上传权限 ==========
case 'check_upload':
    $perm = check_upload_permission();
    $regEnabled = is_registration_enabled();
    $guestHideLocal = isset($conf['guest_hide_local']) ? $conf['guest_hide_local'] : '0';
    echo json_encode([
        'code' => 0,
        'upload_allowed' => $perm['allowed'],
        'msg' => $perm['msg'],
        'require_login' => (isset($conf['upload_require_login']) && $conf['upload_require_login'] == '1'),
        'reg_enabled' => $regEnabled,
        'is_logged_in' => $isUserLoggedIn,
        'role' => $currentUserRole,
        'guest_hide_local' => $guestHideLocal,
    ]);
    exit;

// ========== 获取套餐信息 ==========
case 'package_info':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    $pkgInfo = get_user_effective_package($DB, $currentUserId);
    $storageUsed = get_user_storage_used($DB, $currentUserId);
    echo json_encode([
        'code' => 0,
        'data' => [
            'package_name'  => $pkgInfo['package_name'],
            'level'         => (int)$pkgInfo['level'],
            'expire_time'   => $pkgInfo['expire_time'],
            'is_expired'    => $pkgInfo['is_expired'],
            'is_permanent'  => !empty($pkgInfo['is_permanent']),
            'storage_limit' => (int)$pkgInfo['storage_limit'],
            'storage_used'  => (int)$storageUsed,
            'storage_used_formatted'  => format_size((int)$storageUsed),
            'storage_limit_formatted' => format_size((int)$pkgInfo['storage_limit']),
            'days'          => (int)$pkgInfo['days'],
        ]
    ]);
    exit;

// ========== 获取所有套餐列表（套餐购买页面用） ==========
case 'packages_list':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    $allPackages = get_all_packages($DB);
    // 预查各分组接口数量，避免 N+1 查询
    $groupApiCounts = [];
    $countRows = pkg_safe_get_all($DB, "SELECT group_id, COUNT(*) AS cnt FROM eecms_api_group_items GROUP BY group_id");
    foreach($countRows as $cr) {
        $groupApiCounts[(int)$cr['group_id']] = (int)$cr['cnt'];
    }
    $list = [];
    foreach($allPackages as $pkg) {
        $storageBytes = (int)$pkg['storage_limit'];
        $days = (int)$pkg['days'];
        $groupId = (int)$pkg['group_id'];
        // 格式化存储大小
        if($storageBytes == -1) {
            $storageText = '无限制';
        } elseif($storageBytes >= 1073741824) {
            $storageText = round($storageBytes / 1073741824, 2) . ' GB';
        } else {
            $storageText = round($storageBytes / 1048576) . ' MB';
        }
        // 格式化有效天数
        $daysText = $days == 0 ? '永久' : $days . ' 天';
        // 接口数量
        $apiCount = isset($groupApiCounts[$groupId]) ? $groupApiCounts[$groupId] : 0;
        // 构造介绍文案
        $features = [];
        $features[] = $storageBytes == -1 ? '无限存储空间' : $storageText . ' 存储空间';
        $features[] = $days == 0 ? '永久有效' : $daysText . ' 有效期';
        if(!empty($pkg['group_name'])) {
            $features[] = '接口分组：' . $pkg['group_name'] . '（' . $apiCount . ' 个图床接口）';
        } else {
            $features[] = '接口分组：全站接口';
        }
        $list[] = [
            'id'            => (int)$pkg['id'],
            'name'          => $pkg['name'],
            'level'         => (int)$pkg['level'],
            'storage_limit' => $storageBytes,
            'storage_text'  => $storageText,
            'days'          => $days,
            'days_text'     => $daysText,
            'group_id'      => $groupId,
            'group_name'    => $pkg['group_name'] ? $pkg['group_name'] : '',
            'api_count'     => $apiCount,
            'is_default'    => (int)$pkg['is_default'],
            'features'      => $features,
        ];
    }
    echo json_encode([
        'code' => 0,
        'data' => ['packages' => $list],
    ]);
    exit;

// ========== 兑换码核销 ==========
case 'redeem':
    if(!$isUserLoggedIn) {
        echo json_encode(['code' => 1, 'msg' => '请先登录']);
        exit;
    }
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试']);
        exit;
    }
    $code = trim(isset($_POST['code']) ? $_POST['code'] : '');
    if($code === '') {
        echo json_encode(['code' => 1, 'msg' => '请输入兑换码']);
        exit;
    }
    // 兑换频率限制：每用户 10 分钟内最多 10 次尝试，防爆破（服务端文件计数）
    $f2bKey = 'redeem_' . intval($currentUserId);
    $lockRemain = f2b_locked_seconds($f2bKey);
    if($lockRemain > 0) {
        $wait = ceil($lockRemain / 60);
        echo json_encode(['code' => 1, 'msg' => "兑换尝试过于频繁，请 {$wait} 分钟后再试"]);
        exit;
    }
    $result = redeem_code($DB, $currentUserId, $code);
    if(!empty($result['ok'])) {
        f2b_reset($f2bKey);
    } else {
        f2b_hit($f2bKey, 10, 600, 600);
    }
    echo json_encode([
        'code' => $result['ok'] ? 0 : 1,
        'msg'  => $result['msg'],
    ]);
    exit;

// ========== 获取用户可用接口列表 ==========
case 'allowed_apis':
    $checkUserId = $isUserLoggedIn ? (int)$currentUserId : 0;
    if($isUserLoggedIn && $currentUserRole === 'super_admin') {
        // 超级管理员不限
        echo json_encode(['code'=>0, 'data'=>['api_keys'=>[], 's3_ids'=>[], 'restricted'=>false]]);
        exit;
    }
    $allowed = get_user_allowed_apis($DB, $checkUserId);
    // has_group=true 表示用户已绑定分组，即使分组内接口全部已关闭，
    // 也应标记为受限（restricted=true），防止前端误放行全部已启用接口
    $restricted = !empty($allowed['api_keys']) || !empty($allowed['s3_ids']) || !empty($allowed['has_group']);
    echo json_encode([
        'code' => 0,
        'data' => [
            'api_keys' => $allowed['api_keys'],
            's3_ids' => $allowed['s3_ids'],
            'restricted' => $restricted,
        ]
    ]);
    exit;

default:
    echo json_encode(['code' => 1, 'msg' => '未知操作']);
    exit;
}

function format_size($bytes) {
    if($bytes == -1) return '无限制';
    if($bytes < 1024) return $bytes.' B';
    if($bytes < 1048576) return round($bytes/1024, 1).' KB';
    if($bytes < 1073741824) return round($bytes/1048576, 1).' MB';
    return round($bytes/1073741824, 2).' GB';
}
