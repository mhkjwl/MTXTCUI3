<?php
/**
 * @file login.php
 * @description 管理员登录页面，处理登录认证、CSRF校验、防爆破锁定及登录会话管理
 * @author AI
 * @version 2.0.0-dev
 * @date 2026-08-08
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// 统一 JSON 响应并终止（登录/登出均返回 JSON，前端 AJAX 处理，禁止 header Location 整页刷新）
function login_json(int $code, string $msg, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 登录处理（POST + CSRF，禁止 GET 触发）==========
if(isset($_POST['action']) && $_POST['action'] === 'login') {
    if(!csrf_verify()) login_json(1, '安全校验失败，请刷新页面后重试！');
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    if($user === '' || $pass === '') login_json(1, '请填写完整的用户名和密码！');
    // 防爆破：基于服务端文件的失败计数（按 IP），清 Cookie/换会话无法绕过
    $f2bKey = 'admin_login_' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
    $lockRemain = f2b_locked_seconds($f2bKey);
    if($lockRemain > 0) {
        $wait = ceil($lockRemain / 60);
        login_json(1, "登录尝试过于频繁，请 {$wait} 分钟后再试！");
    }
    $stored = isset($conf['admin_pwd']) ? (string)$conf['admin_pwd'] : '';
    // M1 修复：禁止裸明文比对，分别检测 bcrypt/argon2/MD5/SHA1
    $isBcrypt = (strpos($stored, '$2y$') === 0);
    $isArgon2 = (strpos($stored, '$argon2') === 0);
    $isMd5    = !$isBcrypt && !$isArgon2 && (strlen($stored) === 32 && ctype_xdigit($stored));
    $isSha1   = !$isBcrypt && !$isArgon2 && !$isMd5 && (strlen($stored) === 40 && ctype_xdigit($stored));
    $isHashed = $isBcrypt || $isArgon2;
    $needMigrate = false; // 标记需要迁移到 bcrypt
    if($isBcrypt || $isArgon2) {
        $passOk = password_verify($pass, $stored);
    } elseif($isMd5) {
        // M1 修复：MD5 用对应算法比对后标记迁移
        $passOk = hash_equals($stored, md5($pass));
        $needMigrate = true;
    } elseif($isSha1) {
        // M1 修复：SHA1 用对应算法比对后标记迁移
        $passOk = hash_equals($stored, sha1($pass));
        $needMigrate = true;
    } else {
        // M1 修复：禁止裸明文比对，非已知格式一律拒绝（防止明文密码被探测）
        $passOk = false;
    }
    if($user === $conf['admin_user'] && $passOk) {
        f2b_reset($f2bKey);
        // 会话固定攻击防护：重新生成 session ID
        session_regenerate_id(true);
        // 密码哈希迁移：MD5/SHA1 → bcrypt（明文不再支持，已拒绝）
        if($needMigrate) {
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $DB->query_prepared("UPDATE eecms_config SET main=? WHERE name=?", 'ss', [$newHash, 'admin_pwd']);
            $stored = $newHash;
        }
        // M2 修复：会话令牌改用 hash_hmac('sha256')，与 inc/member.php 同步
        // 旧版用 md5($user.$stored.$password_hash) 违反"MD5/SHA1 禁止"硬约束
        $session = hash_hmac('sha256', $user . $stored, SYS_KEY);
        $token = authcode("{$user}\t{$session}", 'ENCODE', SYS_KEY);
        // M4 修复：Cookie 增加 SameSite=Lax（通过数组形式设置 options）
        $cookieOpts = [
            'expires'  => time() + 604800,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie("admin_token", $token, $cookieOpts);
        login_json(0, '登录成功');
    } else {
        f2b_hit($f2bKey, 5, 600, 600);
        login_json(1, '用户名或密码不正确！');
    }
}
// ========== 登出处理（POST + CSRF，禁止 GET 触发写操作，防止 CSRF 登出攻击）==========
elseif(isset($_POST['action']) && $_POST['action'] === 'logout') {
    if(!csrf_verify()) login_json(1, '安全校验失败，请刷新页面后重试！');
    // M4 修复：登出 cookie 同样设置 SameSite
    $cookieOpts = [
        'expires'  => time() - 604800,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie("admin_token", "", $cookieOpts);
    login_json(0, '已安全退出');
}
// ========== 已登录态访问登录页 → 前端 JS 跳转（禁止 header Location）==========
elseif(isset($islogin) && $islogin == 1) {
    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><title>跳转中</title></head><body><script>window.location.href="index.php";</script></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<script>
(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
<title><?php echo $lang->admin->login;?> - <?php echo $lang->admin->title;?></title>
<link rel="shortcut icon" href="../favicon.ico">
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <a href="index.php"><img alt="logo" src="style/images/logo-sidebar.png"></a>
    </div>
    <div class="login-title">欢迎回来</div>
    <div class="login-subtitle">请使用您的管理员账号登录系统</div>

    <form method="post" id="loginForm">
      <div class="login-input-wrap">
        <?php echo icon('account-outline'); ?>
        <input type="text" class="form-control" id="user" name="user" placeholder="请输入用户名" required autofocus>
      </div>
      <div class="login-input-wrap">
        <?php echo icon('lock-outline'); ?>
        <input type="password" class="form-control has-toggle" id="pass" name="pass" placeholder="请输入密码" required>
        <button type="button" class="login-pwd-toggle" id="pwdToggle" title="显示/隐藏密码" aria-label="显示/隐藏密码">
          <?php echo icon('eye-outline'); ?>
        </button>
      </div>
      <button class="btn-login" type="submit">
        <?php echo icon('login-variant', 'me-1'); ?> 登录系统
      </button>
    </form>
    <div class="login-footer"><?php echo $lang->admin->footer;?></div>
  </div>
</div>

<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script>
// 密码可见性切换（内联 SVG 整体替换）
document.getElementById('pwdToggle').addEventListener('click', function(){
    var btn = this;
    var pwd = document.getElementById('pass');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        btn.innerHTML = '<?php echo icon("eye-off-outline"); ?>';
    } else {
        pwd.type = 'password';
        btn.innerHTML = '<?php echo icon("eye-outline"); ?>';
    }
    pwd.focus();
});
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
document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault(); // 始终阻止默认提交，走 AJAX（禁止整页刷新）
    var u = document.getElementById('user').value.trim();
    var p = document.getElementById('pass').value.trim();
    if (!u || !p) {
        toast('warning', '请填写完整的用户名和密码！', 3000);
        return;
    }
    var btn = this.querySelector('.btn-login');
    var oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<?php echo icon("loading", "icon-spin"); ?>' + ' 登录中...';
    var fd = new FormData(this);
    fd.append('action', 'login');
    fetch('login.php', {method: 'POST', body: fd, credentials: 'same-origin', headers:{'X-CSRF-Token':'<?php echo csrf_token();?>'}})
      .then(function(r){ return r.json(); })
      .then(function(res){
          if(res.code === 0) {
              window.location.href = 'index.php';
          } else {
              toast('error', res.msg, 3000);
              btn.disabled = false;
              btn.innerHTML = oldHtml;
          }
      })
      .catch(function(){
          toast('error', '网络错误，请重试！', 3000);
          btn.disabled = false;
          btn.innerHTML = oldHtml;
      });
});
</script>
</body>
</html>