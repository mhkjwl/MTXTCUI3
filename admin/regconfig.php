<?php
/**
 * @file regconfig.php
 * @description 注册与上传设置页面，配置用户注册开关、邮箱验证、上传登录要求及SMTP邮件服务参数
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

// ========== 内联定义依赖（不依赖 user_auth.php / smtp_mailer.php）==========
if(!function_exists('is_smtp_configured')) {
    function is_smtp_configured() {
        global $conf;
        return !empty($conf['smtp_host']) && !empty($conf['smtp_user']);
    }
}

// ========== AJAX 保存 ==========
if(isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试！']);
        exit;
    }
    // 开关项白名单
    $allowed = ['reg_enable', 'reg_email_verify', 'upload_require_login'];
    foreach($allowed as $key) {
        $val = isset($_POST[$key]) ? '1' : '0';
        if($DB->query_prepared("INSERT INTO eecms_config SET `name`=?,`main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$key, $val, $val]) === false) {
            echo json_encode(['code' => 1, 'msg' => '配置保存失败，请重试！']); exit;
        }
    }
    // SMTP 配置项白名单
    $smtpFields = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'smtp_from_email', 'smtp_from_name'];
    foreach($smtpFields as $key) {
        $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
        // Encrypt SMTP password
        if($key === 'smtp_pass' && $val !== '' && $val !== '••••••••••••••••') {
            $val = ct_encrypt($val);
        }
        // If password is the placeholder, keep existing value
        if($key === 'smtp_pass' && $val === '••••••••••••••••') {
            // Keep existing encrypted value from database
            $val = isset($conf['smtp_pass']) ? $conf['smtp_pass'] : '';
        }
        if($DB->query_prepared("INSERT INTO eecms_config SET `name`=?,`main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$key, $val, $val]) === false) {
            echo json_encode(['code' => 1, 'msg' => '配置保存失败，请重试！']); exit;
        }
    }
    // 重新加载配置
    $conf = [];
    foreach(pkg_safe_get_all($DB, "SELECT * FROM eecms_config") as $row) {
        $conf[$row['name']] = $row['main'];
    }
    echo json_encode(['code' => 0, 'msg' => '设置保存成功！']);
    exit;
}

// ========== AJAX 测试邮件 ==========
if(isset($_POST['action']) && $_POST['action'] === 'test_email') {
    header('Content-Type: application/json; charset=utf-8');
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败']);
        exit;
    }
    $testTo = trim(isset($_POST['test_to']) ? $_POST['test_to'] : '');
    if(!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['code' => 1, 'msg' => '请输入有效的测试收件邮箱']);
        exit;
    }
    // 使用当前 POST 中的 SMTP 配置（而非数据库），方便管理员先测试再保存
    // 处理 SMTP 密码：若为占位符则使用数据库中已解密的值
    $smtpPassRaw = isset($_POST['smtp_pass']) ? $_POST['smtp_pass'] : '';
    if($smtpPassRaw === '••••••••••••••••') {
        $smtpPassRaw = isset($conf['smtp_pass']) ? ct_decrypt($conf['smtp_pass']) : '';
    }
    $config = [
        'smtp_host'   => trim(isset($_POST['smtp_host']) ? $_POST['smtp_host'] : ''),
        'smtp_port'   => trim(isset($_POST['smtp_port']) ? $_POST['smtp_port'] : '465'),
        'smtp_user'   => trim(isset($_POST['smtp_user']) ? $_POST['smtp_user'] : ''),
        'smtp_pass'   => $smtpPassRaw,
        'smtp_secure' => trim(isset($_POST['smtp_secure']) ? $_POST['smtp_secure'] : 'ssl'),
    ];
    $fromEmail = trim(isset($_POST['smtp_from_email']) ? $_POST['smtp_from_email'] : '');
    $fromName  = trim(isset($_POST['smtp_from_name']) ? $_POST['smtp_from_name'] : '');
    $siteName  = isset($conf['name']) ? $conf['name'] : '图床';

    if(empty($config['smtp_host']) || empty($config['smtp_user'])) {
        echo json_encode(['code' => 1, 'msg' => 'SMTP 主机和用户名不能为空']);
        exit;
    }

    // 安全加载 SmtpMailer（使用 include_once 替代 eval，避免任意代码执行风险）
    if(!class_exists('SmtpMailer')) {
        $smtpFile = SYSTEM_ROOT . 'smtp_mailer.php';
        if(!file_exists($smtpFile)) {
            echo json_encode(['code' => 1, 'msg' => 'SMTP 邮件组件文件不存在: inc/smtp_mailer.php']);
            exit;
        }
        // 使用 include_once 安全加载，解析错误由 common.php 的 error_reporting 控制
        include_once($smtpFile);
        if(!class_exists('SmtpMailer')) {
            echo json_encode(['code' => 1, 'msg' => 'SMTP 组件加载后类仍未定义，请检查 inc/smtp_mailer.php']);
            exit;
        }
    }

    // M16 修复：邮件标题过滤换行符，防止邮件头注入
    $safeSiteName = str_replace(["\r", "\n", "%0a", "%0d"], '', $siteName);
    $subject = "【{$safeSiteName}】SMTP 测试邮件";
    $body = '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px;">'
          . '<h2 style="color:#6d4aff;">SMTP 配置测试成功</h2>'
          . '<p>这是一封来自 ' . htmlspecialchars($siteName) . ' 的测试邮件。</p>'
          . '<p>如果您收到了这封邮件，说明 SMTP 邮箱配置正确。</p>'
          . '<p style="color:#999;font-size:12px;">发送时间：' . date('Y-m-d H:i:s') . '</p>'
          . '</body></html>';

    $mailer = new SmtpMailer($config);
    $result = $mailer->send($testTo, $subject, $body, $fromEmail ?: $config['smtp_user'], $fromName ?: $siteName);

    if($result['success']) {
        echo json_encode(['code' => 0, 'msg' => '测试邮件已发送至 ' . $testTo . '，请查收']);
    } else {
        echo json_encode(['code' => 1, 'msg' => '发送失败：' . $result['error']]);
    }
    exit;
}

// 当前配置值
$regEnable          = !isset($conf['reg_enable']) || $conf['reg_enable'] == '1';
$regEmailVerify     = isset($conf['reg_email_verify']) && $conf['reg_email_verify'] == '1';
$uploadRequireLogin = isset($conf['upload_require_login']) && $conf['upload_require_login'] == '1';
$smtpHost           = isset($conf['smtp_host']) ? $conf['smtp_host'] : '';
$smtpPort           = isset($conf['smtp_port']) ? $conf['smtp_port'] : '465';
$smtpUser           = isset($conf['smtp_user']) ? $conf['smtp_user'] : '';
$smtpPass           = (!isset($conf['smtp_pass']) || $conf['smtp_pass'] === '') ? '' : '••••••••••••••••';
$smtpSecure         = isset($conf['smtp_secure']) ? $conf['smtp_secure'] : 'ssl';
$smtpFromEmail      = isset($conf['smtp_from_email']) ? $conf['smtp_from_email'] : '';
$smtpFromName       = isset($conf['smtp_from_name']) ? $conf['smtp_from_name'] : '';
$smtpConfigured     = is_smtp_configured();
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>注册设置 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('account-plus-outline'); ?> 注册设置
  </div>

  <div class="row">
    <div class="col-lg-8">
      <!-- 注册与上传设置 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('account-plus-outline'); ?> 用户注册与上传设置</div>
        </div>
        <div class="card-body">
          <div class="alert alert-info"><?php echo icon('information-outline'); ?> 控制用户注册流程与上传权限，修改后点击保存即可生效。</div>

          <form id="regConfigForm">
            <!-- 开启注册 -->
            <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
              <div class="d-flex align-items-start" style="gap:14px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#3b82f6;"><?php echo icon('account-plus'); ?></span>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:600;color:#1e293b;">开启用户注册</div>
                  <div style="font-size:13px;color:#64748b;margin-top:2px;">允许新用户在前台注册账号。关闭后前台注册入口将隐藏。</div>
                </div>
              </div>
              <div class="form-check form-switch" style="margin-bottom:0;">
                <input class="form-check-input" type="checkbox" name="reg_enable" value="1" id="switchRegEnable" <?php echo $regEnable ? 'checked' : ''; ?>>
                <label class="form-check-label" for="switchRegEnable"></label>
              </div>
            </div>

            <!-- 邮箱验证 -->
            <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
              <div class="d-flex align-items-start" style="gap:14px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#10b981;"><?php echo icon('email-check-outline'); ?></span>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:600;color:#1e293b;">注册需要邮箱验证</div>
                  <div style="font-size:13px;color:#64748b;margin-top:2px;">开启后用户注册时需输入邮箱并接收验证码，验证通过后才能完成注册。需在下方配置 SMTP 邮件服务。</div>
                  <div id="smtpWarn" style="font-size:12px;color:#ef4444;margin-top:4px;<?php echo ($regEmailVerify && !$smtpConfigured) ? '' : 'display:none;'; ?>"><?php echo icon('alert-circle-outline'); ?> 当前已开启邮箱验证但 SMTP 未配置，请先完成下方邮箱设置</div>
                </div>
              </div>
              <div class="form-check form-switch" style="margin-bottom:0;">
                <input class="form-check-input" type="checkbox" name="reg_email_verify" value="1" id="switchRegEmailVerify" <?php echo $regEmailVerify ? 'checked' : ''; ?>>
                <label class="form-check-label" for="switchRegEmailVerify"></label>
              </div>
            </div>

            <!-- 上传需要登录 -->
            <div class="d-flex align-items-center justify-content-between py-3">
              <div class="d-flex align-items-start" style="gap:14px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#f59e0b;"><?php echo icon('cloud-upload-outline'); ?></span>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:600;color:#1e293b;">上传图片需要登录</div>
                  <div style="font-size:13px;color:#64748b;margin-top:2px;">开启后未登录用户无法上传图片，需先注册并登录账号。关闭则允许游客上传。</div>
                </div>
              </div>
              <div class="form-check form-switch" style="margin-bottom:0;">
                <input class="form-check-input" type="checkbox" name="upload_require_login" value="1" id="switchUploadRequireLogin" <?php echo $uploadRequireLogin ? 'checked' : ''; ?>>
                <label class="form-check-label" for="switchUploadRequireLogin"></label>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- SMTP 邮箱设置 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('email-edit-outline'); ?> SMTP 邮箱服务配置</div>
        </div>
        <div class="card-body">
          <div class="alert alert-warning"><?php echo icon('alert-circle-outline'); ?> 邮箱验证功能依赖此配置，请正确填写 SMTP 服务器信息。常见邮箱端口：QQ邮箱 465(SSL)，163邮箱 465(SSL)，Gmail 587(TLS)。</div>

          <form id="smtpConfigForm">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">SMTP 服务器地址</label>
                <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($smtpHost);?>" placeholder="如 smtp.qq.com" style="font-size:14px;">
              </div>
              <div class="col-md-3 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">端口</label>
                <input type="text" class="form-control" name="smtp_port" value="<?php echo htmlspecialchars($smtpPort);?>" placeholder="465" style="font-size:14px;">
              </div>
              <div class="col-md-3 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">加密方式</label>
                <select class="form-select" name="smtp_secure" style="font-size:14px;">
                  <option value="ssl" <?php echo $smtpSecure==='ssl'?'selected':'';?>>SSL</option>
                  <option value="tls" <?php echo $smtpSecure==='tls'?'selected':'';?>>TLS</option>
                  <option value="" <?php echo $smtpSecure===''?'selected':'';?>>无加密</option>
                </select>
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">SMTP 用户名（邮箱账号）</label>
                <input type="text" class="form-control" name="smtp_user" value="<?php echo htmlspecialchars($smtpUser);?>" placeholder="如 user@qq.com" style="font-size:14px;">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">SMTP 密码 / 授权码</label>
                <input type="password" class="form-control" name="smtp_pass" value="<?php echo htmlspecialchars($smtpPass);?>" placeholder="QQ邮箱需填授权码，非登录密码" style="font-size:14px;">
              </div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">发件人邮箱</label>
                <input type="email" class="form-control" name="smtp_from_email" value="<?php echo htmlspecialchars($smtpFromEmail);?>" placeholder="留空则使用 SMTP 用户名" style="font-size:14px;">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label" style="font-size:13px;font-weight:600;">发件人名称</label>
                <input type="text" class="form-control" name="smtp_from_name" value="<?php echo htmlspecialchars($smtpFromName);?>" placeholder="如 <?php echo htmlspecialchars(isset($conf['name'])?$conf['name']:'图床');?>" style="font-size:14px;">
              </div>
            </div>

            <!-- 测试邮件 -->
            <div class="border-top pt-3 mt-2">
              <div style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:10px;"><?php echo icon('send-outline'); ?> 发送测试邮件</div>
              <div class="d-flex gap-2 align-items-end">
                <div style="flex:1;">
                  <label class="form-label" style="font-size:12px;color:#64748b;">测试收件邮箱</label>
                  <input type="email" class="form-control" id="testEmailTo" placeholder="输入收件邮箱地址" style="font-size:14px;">
                </div>
                <button type="button" class="btn btn-outline-primary" id="btnTestEmail" onclick="sendTestEmail()"><?php echo icon('send'); ?> 发送测试</button>
              </div>
              <div style="font-size:12px;color:#94a3b8;margin-top:6px;">测试时使用当前表单中填写的 SMTP 配置（无需先保存），方便验证配置是否正确。</div>
            </div>

            <div class="mt-4 d-flex gap-2">
              <button type="button" class="btn btn-primary" onclick="saveConfig()"><?php echo icon('content-save'); ?> 保存全部设置</button>
              <?php if($smtpConfigured): ?>
              <span id="smtpStatusBadge" class="badge bg-success-subtle text-success d-flex align-items-center" style="font-size:13px;padding:6px 14px;border-radius:8px;">
                <?php echo icon('check-circle', 'me-1'); ?> SMTP 已配置
              </span>
              <?php else: ?>
              <span id="smtpStatusBadge" class="badge bg-warning-subtle text-warning d-flex align-items-center" style="font-size:13px;padding:6px 14px;border-radius:8px;">
                <?php echo icon('alert', 'me-1'); ?> SMTP 未配置
              </span>
              <?php endif; ?>
            </div>
            <input type="hidden" name="action" value="save">
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><?php echo icon('help-circle-outline'); ?> 使用说明</div>
        </div>
        <div class="card-body">
          <div style="font-size:13px;color:#64748b;line-height:1.8;">
            <p><?php echo icon('chevron-right'); ?> <strong>开启注册</strong>：控制前台是否显示注册入口。</p>
            <p><?php echo icon('chevron-right'); ?> <strong>邮箱验证</strong>：注册时需输入邮箱接收验证码，验证通过才能完成注册。</p>
            <p><?php echo icon('chevron-right'); ?> <strong>SMTP 配置</strong>：填写邮件服务器信息，验证码将通过此邮箱发送。</p>
            <p><?php echo icon('chevron-right'); ?> <strong>上传需登录</strong>：关闭后游客可直接上传，可能增加存储压力。</p>
            <hr>
            <p style="margin-bottom:6px;"><?php echo icon('lightbulb-outline'); ?> <strong>常见邮箱配置参考：</strong></p>
            <p style="margin-bottom:4px;font-size:12px;color:#94a3b8;">QQ邮箱：smtp.qq.com / 465 / SSL / 授权码</p>
            <p style="margin-bottom:4px;font-size:12px;color:#94a3b8;">163邮箱：smtp.163.com / 465 / SSL / 授权码</p>
            <p style="margin-bottom:4px;font-size:12px;color:#94a3b8;">Gmail：smtp.gmail.com / 587 / TLS / 应用密码</p>
            <p style="margin-bottom:0;font-size:12px;color:#94a3b8;">阿里邮箱：smtp.qiye.aliyun.com / 465 / SSL</p>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
// L8 修复：移除未使用的 csrfToken 死代码（M5：CSRF Token 已改走 Header 传递）
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

// 保存后同步 SMTP 状态徽章与警告显隐（依据表单当前值，无需刷新）
function updateSmtpStatusDisplay(){
    var host = document.querySelector('input[name="smtp_host"]').value.trim();
    var user = document.querySelector('input[name="smtp_user"]').value.trim();
    var configured = host !== '' && user !== '';
    var badge = document.getElementById('smtpStatusBadge');
    if(badge){
        if(configured){
            badge.className = 'badge bg-success-subtle text-success d-flex align-items-center';
            badge.style.cssText = 'font-size:13px;padding:6px 14px;border-radius:8px;';
            badge.innerHTML = eeIcon('check-circle', 'me-1')+' SMTP 已配置';
        } else {
            badge.className = 'badge bg-warning-subtle text-warning d-flex align-items-center';
            badge.style.cssText = 'font-size:13px;padding:6px 14px;border-radius:8px;';
            badge.innerHTML = eeIcon('alert', 'me-1')+' SMTP 未配置';
        }
    }
    // 邮箱验证开启且 SMTP 未配置时显示警告
    var emailVerify = document.getElementById('switchRegEmailVerify').checked;
    var warn = document.getElementById('smtpWarn');
    if(warn){
        warn.style.display = (emailVerify && !configured) ? '' : 'none';
    }
}

function saveConfig(){
    Swal.fire({
        title: '确认保存',
        text: '确定要保存当前设置吗？',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            // 合并两个表单的数据
            var regForm = document.getElementById('regConfigForm');
            var smtpForm = document.getElementById('smtpConfigForm');
            var formData = new FormData();

            // 注册设置表单（不含 action/csrf，从 smtpForm 取）
            var regData = new FormData(regForm);
            for(var pair of regData.entries()) {
                formData.append(pair[0], pair[1]);
            }
            // SMTP 表单（含 action/csrf）
            var smtpData = new FormData(smtpForm);
            for(var pair of smtpData.entries()) {
                formData.append(pair[0], pair[1]);
            }

            fetch('regconfig.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?php echo csrf_token();?>' },
                body: formData
            })
            .then(function(response){ return response.json(); })
            .then(function(data){
                toast(data.code === 0 ? 'success' : 'error', data.msg, 3000);
                if(data.code === 0){
                    updateSmtpStatusDisplay();
                }
            })
            .catch(function(){
                toast('error', '网络错误，请重试', 3000);
            });
        }
    });
}

function sendTestEmail(){
    var testTo = document.getElementById('testEmailTo').value.trim();
    if(!testTo){
        toast('warning', '请输入测试收件邮箱', 2500);
        return;
    }
    var btn = document.getElementById('btnTestEmail');
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = eeIcon('loading', 'icon-spin')+' 发送中...';

    var smtpForm = document.getElementById('smtpConfigForm');
    var formData = new FormData(smtpForm);
    formData.set('action', 'test_email');
    formData.append('test_to', testTo);

    fetch('regconfig.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?php echo csrf_token();?>' },
        body: formData
    })
    .then(function(response){ return response.json(); })
    .then(function(data){
        toast(data.code === 0 ? 'success' : 'error', data.msg, data.code === 0 ? 4000 : 6000);
    })
    .catch(function(){
        toast('error', '网络错误，请重试', 3000);
    })
    .finally(function(){
        btn.disabled = false;
        btn.innerHTML = orig;
    });
}
</script>
</body>
</html>
