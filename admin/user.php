<?php
/**
 * @file user.php
 * @description 管理员账号信息修改页面，支持修改管理员用户名和密码，修改后同步到用户表并强制重新登录
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

$toast_icon = ''; $toast_title = '';
$need_logout = false; // 标记是否需要强制退出登录（账号或密码已修改）
if(isset($_POST['action'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if($isAjax){ header('Content-Type: application/json; charset=utf-8'); }
    if(!csrf_verify()) {
        if($isAjax){ echo json_encode(['code'=>1, 'msg'=>'安全校验失败，请刷新页面后重试！']); exit; }
        $toast_icon = 'error'; $toast_title = '安全校验失败，请刷新页面后重试！';
    } else {
        // favicon 上传（合并自原 upimg.php，独立 action，csrf 已在前置校验通过）
        if(isset($_POST['action']) && $_POST['action'] === 'favicon') {
            $tmpName = $_FILES['favicon']['tmp_name'] ?? '';
            $fileName = $_FILES['favicon']['name'] ?? '';
            $fileSize = $_FILES['favicon']['size'] ?? 0;
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            // 文件内容 MIME 校验 + ICO 文件头魔数校验
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime = $finfo ? finfo_file($finfo, $tmpName) : '';
            if($finfo) finfo_close($finfo);
            $allowedMimes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/bmp', 'image/vnd.wap.wbmp'];
            $headBytes = is_readable($tmpName) ? bin2hex((string)file_get_contents($tmpName, false, null, 0, 4)) : '';
            $validIcoMagic = ($headBytes === '00000100' || $headBytes === '00000200');
            if(!is_uploaded_file($tmpName)) {
                echo json_encode(['code'=>1, 'msg'=>'非法上传请求！']); exit;
            } elseif($ext !== 'ico') {
                echo json_encode(['code'=>1, 'msg'=>'仅允许上传 .ico 格式的图标文件！']); exit;
            } elseif($mime !== '' && !in_array($mime, $allowedMimes, true)) {
                echo json_encode(['code'=>1, 'msg'=>'文件内容不是有效的图标格式！']); exit;
            } elseif(!$validIcoMagic) {
                echo json_encode(['code'=>1, 'msg'=>'文件头魔数校验失败，不是有效的 ICO 文件！']); exit;
            } elseif($fileSize > 102400) {
                echo json_encode(['code'=>1, 'msg'=>'文件大小不能超过 100KB！']); exit;
            } elseif(move_uploaded_file($tmpName, "../favicon.ico")) {
                echo json_encode(['code'=>0, 'msg'=>'favicon图标上传成功！']); exit;
            } else {
                echo json_encode(['code'=>1, 'msg'=>'上传失败！请检查根目录写入权限。']); exit;
            }
        }
        // 保存修改前的管理员用户名，用于检测是否发生变更及同步到 eecms_users 表
        $oldAdminUser = isset($conf['admin_user']) ? $conf['admin_user'] : '';
        // 字段白名单：仅允许修改管理员用户名（防止 Mass Assignment 覆盖 admin_pwd/smtp_pass 等敏感配置）
        $newAdminUser = isset($_POST['admin_user']) ? trim($_POST['admin_user']) : '';
        if($newAdminUser !== '' && $newAdminUser !== $oldAdminUser) {
            if(mb_strlen($newAdminUser) > 64) {
                if($isAjax){ echo json_encode(['code'=>1, 'msg'=>'用户名长度不能超过64个字符']); exit; }
                $toast_icon = 'error'; $toast_title = '用户名长度不能超过64个字符';
            } else {
                $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`='admin_user', `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'ss', [$newAdminUser, $newAdminUser]);
                if($ok === false) {
                    if($isAjax){ echo json_encode(['code'=>1, 'msg'=>'用户名保存失败，请重试']); exit; }
                    $toast_icon = 'error'; $toast_title = '用户名保存失败，请重试';
                }
            }
        }
        $pwd = $_POST['pwd'] ?? '';
        $hashed = null;
        if(!empty($pwd)) {
            // M6 修复：修改密码必须验证旧密码，防止 XSS/会话窃取后接管账号
            $oldPwd = $_POST['old_pwd'] ?? '';
            $storedPwd = isset($conf['admin_pwd']) ? (string)$conf['admin_pwd'] : '';
            $storedIsBcrypt = (strpos($storedPwd, '$2y$') === 0 || strpos($storedPwd, '$argon2') === 0);
            $oldOk = false;
            if($storedIsBcrypt) {
                $oldOk = password_verify($oldPwd, $storedPwd);
            } elseif(strlen($storedPwd) === 32 && ctype_xdigit($storedPwd)) {
                // 兼容历史 MD5
                $oldOk = hash_equals($storedPwd, md5($oldPwd));
            } elseif(strlen($storedPwd) === 40 && ctype_xdigit($storedPwd)) {
                // 兼容历史 SHA1
                $oldOk = hash_equals($storedPwd, sha1($oldPwd));
            }
            if(!$oldOk) {
                if($isAjax){ echo json_encode(['code'=>1, 'msg'=>'旧密码验证失败，请重试']); exit; }
                $toast_icon = 'error'; $toast_title = '旧密码验证失败，请重试';
            } else {
                $hashed = password_hash($pwd, PASSWORD_DEFAULT);
                $ok = $DB->query_prepared("UPDATE eecms_config SET `main`=? WHERE `name`='admin_pwd'", 's', [$hashed]);
                if($ok === false) {
                    if($isAjax){ echo json_encode(['code'=>1, 'msg'=>'密码保存失败，请重试']); exit; }
                    $toast_icon = 'error'; $toast_title = '密码保存失败，请重试';
                }
            }
        }

        // 同步修改到 eecms_users 表（用户管理列表、用户中心均读取此表）
        if($newAdminUser !== '' && $newAdminUser !== $oldAdminUser) {
            // L6 修复：检查返回值，失败时回滚提示
            if($DB->query_prepared("UPDATE eecms_users SET username=? WHERE username=?", 'ss', [$newAdminUser, $oldAdminUser]) === false) {
                error_log('user.php: 同步用户名到 eecms_users 失败');
            }
        }
        if(!empty($pwd) && $hashed !== null) {
            // 密码同步：用新用户名匹配（如用户名已改则用新用户名），并递增 session_version 使旧登录态失效
            $syncUser = $newAdminUser !== '' ? $newAdminUser : $oldAdminUser;
            if($DB->query_prepared("UPDATE eecms_users SET password=?, session_version = session_version + 1 WHERE username=?", 'ss', [$hashed, $syncUser]) === false) {
                error_log('user.php: 同步密码到 eecms_users 失败');
            }
        }

        // 检测账号或密码是否实际发生变更 → 强制退出登录
        $usernameChanged = ($newAdminUser !== '' && $newAdminUser !== $oldAdminUser);
        $passwordChanged = !empty($pwd);

        // L2 修复：改用 pkg_safe_get_all 封装（无参数 SELECT 也用安全封装）
        $conf = array();
        foreach(pkg_safe_get_all($DB, "select * from eecms_config") as $row) {
            $conf[$row['name']] = $row['main'];
        }

        if($usernameChanged || $passwordChanged) {
            // 安全策略：修改账号或密码后立即清除登录态（含后台 admin_token 与前端 user_token），要求重新登录
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            setcookie("admin_token", "", time() - 604800, '/', '', $isHttps, true);
            setcookie("user_token", "", time() - 604800, '/', '', $isHttps, true);
            $need_logout = true;
            if($isAjax){ echo json_encode(['code'=>0, 'msg'=>'账号或密码已修改，为保障账户安全，请使用新账号密码重新登录。', 'need_logout'=>true]); exit; }
        } else {
            $toast_icon = 'info'; $toast_title = '账号信息未发生变更！';
            if($isAjax){ echo json_encode(['code'=>0, 'msg'=>'账号信息未发生变更！', 'need_logout'=>false]); exit; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>账号信息 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('account-outline'); ?> <?php echo $lang->admin->user;?>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">
          <div class="card-title"><?php echo icon('account-outline'); ?> <?php echo $lang->admin->user;?></div>
        </div>
        <div class="card-body">
          <div class="alert alert-info"><?php echo icon('information-outline'); ?> 如果不修改密码，请在密码输入框中留空</div>
          <form id="mainForm" method="post">
            <div class="mb-3">
              <label class="form-label">管理员账号</label>
              <input value="<?php echo htmlspecialchars($conf['admin_user'], ENT_QUOTES, 'UTF-8');?>" type="text" class="form-control" name="admin_user" required style="max-width:400px;">
            </div>
            <div class="mb-3">
              <label class="form-label">新密码</label>
              <input value="" type="password" class="form-control" name="pwd" placeholder="不修改请留空" autocomplete="off" style="max-width:400px;">
            </div>
            <div class="mb-3">
              <label class="form-label">旧密码（修改密码时必填）</label>
              <input value="" type="password" class="form-control" name="old_pwd" placeholder="修改密码时需验证旧密码" autocomplete="off" style="max-width:400px;">
            </div>
            <button type="button" class="btn btn-primary" onclick="doSave()"><?php echo icon('content-save'); ?> 保存修改</button>
            <input type="hidden" name="action" value="1">
          </form>
        </div>
      </div>
    </div>

    <!-- Favicon 图标（合并自原 LOGO修改页） -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">
          <div class="card-title"><?php echo icon('image-outline'); ?> Favicon 图标（浏览器小图标）</div>
        </div>
        <div class="card-body">
          <div class="alert alert-info"><?php echo icon('information-outline'); ?> 仅支持上传 .ico 图标文件，大小不超过 100KB。请确保根目录可写入。</div>
          <form id="faviconForm" method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">选择 ICO 文件</label>
              <input type="file" class="form-control" name="favicon" id="faviconFile" accept=".ico" style="max-width:400px;">
            </div>
            <div class="mb-4">
              <label class="form-label">当前图标</label>
              <div style="padding:12px;background:#f8fafc;border-radius:8px;display:inline-block;">
                <img id="faviconPreview" src="../favicon.ico?v=<?php echo time();?>" style="width:32px;height:32px;">
              </div>
            </div>
            <button type="button" class="btn btn-primary" onclick="uploadFavicon()"><?php echo icon('upload'); ?> 上传图标</button>
            <input type="hidden" name="action" value="favicon">
          </form>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
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

function doSave(){
    Swal.fire({
        title: '确认修改',
        text: '确定要保存账号信息吗？',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        // M6 修复：前端校验修改密码时必须填写旧密码
        var pwdVal = document.querySelector('input[name="pwd"]').value;
        var oldPwdVal = document.querySelector('input[name="old_pwd"]').value;
        if(pwdVal !== '' && oldPwdVal === ''){
            toast('warning', '修改密码时必须填写旧密码', 3000);
            return;
        }
        var formData = new FormData(document.getElementById('mainForm'));
        fetch('user.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?php echo csrf_token();?>' },
            body: formData
        })
        .then(function(response){ return response.json(); })
        .then(function(data){
            if(data.code !== 0){
                toast('error', data.msg, 3000);
                return;
            }
            if(data.need_logout){
                // 账号或密码已变更，登录态已失效，强制重新登录
                Swal.fire({
                    title: '修改成功',
                    html: '账号或密码已修改，为保障账户安全，请使用新账号密码重新登录。',
                    icon: 'success',
                    confirmButtonText: '重新登录',
                    confirmButtonColor: '#6366f1',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(function(){
                    top.location.href = 'login.php';
                });
            } else {
                toast('success', data.msg, 2000);
                // 清空密码框（已保存或不需保留）
                var pwdInput = document.querySelector('input[name="pwd"]');
                if(pwdInput){ pwdInput.value = ''; }
            }
        })
        .catch(function(){
            toast('error', '网络错误，请重试', 3000);
        });
    });
}

// Favicon 图标上传（合并自原 LOGO修改页）
function uploadFavicon(){
    var file = document.getElementById('faviconFile');
    if(!file.value){
        toast('warning', '请先选择ICO文件！', 3000);
        return;
    }
    var ext = file.value.split('.').pop().toLowerCase();
    if(ext !== 'ico'){
        toast('error', '仅允许上传 .ico 格式的文件！', 3000);
        return;
    }
    Swal.fire({
        title: '确认上传',
        text: '确定要更新favicon图标吗？将会覆盖当前图标。',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认上传',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        var formData = new FormData(document.getElementById('faviconForm'));
        fetch('user.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?php echo csrf_token();?>' },
            body: formData
        })
        .then(function(response){ return response.json(); })
        .then(function(data){
            toast(data.code === 0 ? 'success' : 'error', data.msg, 3000);
            if(data.code === 0){
                // 无刷新更新预览图（新时间戳破缓存）
                var preview = document.getElementById('faviconPreview');
                if(preview){ preview.src = '../favicon.ico?v=' + new Date().getTime(); }
                // 清空文件选择，便于再次上传
                document.getElementById('faviconFile').value = '';
            }
        })
        .catch(function(){
            toast('error', '网络错误，请重试', 3000);
        });
    });
}
</script>
<?php if($need_logout): ?>
<script>
Swal.fire({
    title: '修改成功',
    html: '账号或密码已修改，为保障账户安全，请使用新账号密码重新登录。',
    icon: 'success',
    confirmButtonText: '重新登录',
    confirmButtonColor: '#6366f1',
    allowOutsideClick: false,
    allowEscapeKey: false
}).then(function(){
    top.location.href = 'login.php';
});
</script>
<?php elseif($toast_title): ?>
<script>
toast(<?php echo json_encode($toast_icon);?>, <?php echo json_encode($toast_title, JSON_UNESCAPED_UNICODE);?>, 3000);
</script>
<?php endif; ?>
</body>
</html>