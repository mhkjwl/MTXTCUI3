<?php
/**
 * @file login.php
 * @description 用户登录页，玻璃拟态设计，含登录态校验与跳转
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 用户登录页
 * 玻璃拟态设计，与主站 index.php 保持一致
 */
require('../inc/common.php');

// 已登录则跳转到用户中心
if($isUserLoggedIn) {
    header('Location: index.php');
    exit;
}

$siteName    = isset($conf['name']) ? $conf['name'] : '图床';
$siteNotice  = !empty($conf['jieshao']) ? $conf['jieshao'] : '欢迎使用图床服务';
$regEnabled  = is_registration_enabled();
$csrfToken   = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>用户登录 - <?php echo htmlspecialchars($siteName);?></title>
<link href="../admin/style/css/materialdesignicons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{-webkit-font-smoothing:antialiased}
:root{
  --primary:#7b8cff;--primary-soft:#9b7dff;--primary-deep:#6366f1;
  --secondary:#ff7ac6;--secondary-soft:#ff9ad4;
  --accent:#7dd3fc;--success:#00c896;--warning:#ffa600;--danger:#ff4757;
  --surface:rgba(255,255,255,0.65);
  --surface-2:rgba(255,255,255,0.45);
  --surface-solid:#ffffff;
  --surface-hover:rgba(255,255,255,0.82);
  --border:rgba(123,140,255,0.10);
  --border-strong:rgba(123,140,255,0.20);
  --text:#15121f;--text-2:#4a4458;--text-muted:#7d7890;--text-light:#aaa6b8;
  --shadow-sm:0 2px 10px rgba(123,140,255,0.06);
  --shadow-lg:0 16px 48px rgba(123,140,255,0.12);
  --shadow-xl:0 24px 64px rgba(123,140,255,0.16);
  --radius-sm:12px;--radius-md:16px;--radius-lg:20px;--radius-xl:26px;--radius-full:999px;
  --font-display:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei","Helvetica Neue",Arial,sans-serif;
  --font-body:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei","Helvetica Neue",Arial,sans-serif;
  --ease-out:cubic-bezier(0.16,1,0.3,1);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);
}
[data-theme="dark"]{
  --primary:#9b7dff;--primary-soft:#b4a3ff;--primary-deep:#7b8cff;
  --secondary:#ff7fa8;
  --accent:#7dd3fc;--success:#10d99c;--warning:#ffb84d;--danger:#ff5a6a;
  --surface:rgba(30,26,46,0.65);
  --surface-2:rgba(30,26,46,0.42);
  --surface-solid:#1e1a2e;
  --surface-hover:rgba(40,35,60,0.75);
  --border:rgba(123,140,255,0.12);
  --border-strong:rgba(123,140,255,0.25);
  --text:#f0edf8;--text-2:#c8c4d6;--text-muted:#8a85a0;--text-light:#605c70;
  --shadow-sm:0 2px 10px rgba(0,0,0,0.2);
  --shadow-lg:0 16px 48px rgba(0,0,0,0.3);
  --shadow-xl:0 24px 64px rgba(0,0,0,0.35);
}
body{
  font-family:var(--font-body);
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  position:relative;overflow-x:hidden;
  transition:background .4s ease,color .4s ease;
}
[data-theme="light"] body{background:linear-gradient(135deg,#e8ecff 0%,#fce8f3 50%,#e0f4ff 100%)}
[data-theme="dark"] body{background:linear-gradient(135deg,#0d0b16 0%,#1a1530 50%,#101828 100%)}
/* 浮动光球 */
.orb{position:fixed;border-radius:50%;filter:blur(60px);opacity:.5;pointer-events:none;z-index:0;animation:orbFloat 20s ease-in-out infinite}
.orb.o1{width:420px;height:420px;background:radial-gradient(circle,rgba(123,140,255,.45),transparent 70%);top:-120px;right:-100px;animation-delay:0s}
.orb.o2{width:380px;height:380px;background:radial-gradient(circle,rgba(255,122,198,.35),transparent 70%);bottom:-120px;left:-80px;animation-delay:-7s}
.orb.o3{width:300px;height:300px;background:radial-gradient(circle,rgba(125,211,252,.3),transparent 70%);top:40%;left:50%;animation-delay:-14s}
@keyframes orbFloat{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-40px) scale(1.08)}66%{transform:translate(-25px,30px) scale(0.95)}}
[data-theme="dark"] .orb{opacity:.35}
::-webkit-scrollbar{width:6px}
::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:999px}

/* 登录卡片 */
.login-wrap{position:relative;z-index:1;width:420px;max-width:92%;padding:0 10px}
.login-card{
  background:var(--surface);
  backdrop-filter:blur(28px) saturate(1.5);-webkit-backdrop-filter:blur(28px) saturate(1.5);
  border:1px solid var(--border);
  border-radius:var(--radius-xl);
  padding:40px 38px;
  box-shadow:var(--shadow-xl);
  animation:cardIn .5s var(--ease-spring);
}
@keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

/* 顶部品牌 */
.brand-area{display:flex;align-items:center;gap:12px;margin-bottom:8px;justify-content:center}
.brand-logo{
  width:48px;height:48px;border-radius:14px;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:grid;place-items:center;color:#fff;font-size:24px;font-weight:800;
  box-shadow:0 6px 20px rgba(123,140,255,.35);position:relative;overflow:hidden;
  font-family:var(--font-display);
}
.brand-logo::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent 35%,rgba(255,255,255,.4) 50%,transparent 65%);animation:shine 4s ease-in-out infinite}
@keyframes shine{0%,100%{transform:translateX(-120%)}50%{transform:translateX(120%)}}
.brand-name{font-family:var(--font-display);font-weight:800;font-size:22px;letter-spacing:-.5px}
.login-title{text-align:center;font-family:var(--font-display);font-size:24px;font-weight:800;margin:22px 0 4px;letter-spacing:-.5px}
.login-subtitle{text-align:center;font-size:13px;color:var(--text-muted);margin-bottom:28px}

/* 表单 */
.form-group{margin-bottom:18px;position:relative}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:7px;letter-spacing:.2px}
.input-wrap{position:relative}
.input-wrap .mdi{position:absolute;left:15px;top:50%;transform:translateY(-50%);font-size:20px;color:var(--text-light);z-index:2;pointer-events:none;transition:color .2s}
.form-input{
  width:100%;padding:13px 16px 13px 46px;border-radius:var(--radius-sm);
  background:var(--surface-solid);border:1.5px solid var(--border-strong);
  color:var(--text);font-size:14px;font-family:var(--font-body);
  transition:all .2s var(--ease-out);outline:none;
}
.form-input:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(123,140,255,.1)}
.form-input:focus + .mdi,.input-wrap:focus-within .mdi{color:var(--primary)}
.form-input::placeholder{color:var(--text-light)}

/* 按钮 */
.btn-submit{
  width:100%;padding:14px;border-radius:var(--radius-sm);border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;
  font-size:15px;font-weight:700;font-family:var(--font-body);
  box-shadow:0 6px 20px rgba(123,140,255,.3);transition:all .25s var(--ease-out);
  display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;
}
.btn-submit:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 10px 28px rgba(123,140,255,.4)}
.btn-submit:active{transform:translateY(0)}
.btn-submit:disabled{opacity:.6;cursor:not-allowed}
.btn-submit .mdi-spin{animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* 底部链接 */
.login-footer{margin-top:24px;text-align:center;font-size:13px;color:var(--text-muted)}
.login-footer a{color:var(--primary);font-weight:600;text-decoration:none;transition:opacity .2s}
.login-footer a:hover{opacity:.8;text-decoration:underline}
.divider{display:flex;align-items:center;gap:12px;margin:22px 0;color:var(--text-light);font-size:11px;font-weight:600}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border-strong)}
.back-home{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;padding:10px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface-2);color:var(--text-2);font-size:13px;font-weight:600;text-decoration:none;transition:all .2s}
.back-home:hover{background:var(--surface-hover);color:var(--primary);border-color:var(--primary)}

/* 主题切换 */
.theme-toggle{position:fixed;top:20px;right:20px;z-index:10;width:44px;height:44px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);backdrop-filter:blur(12px);display:grid;place-items:center;cursor:pointer;color:var(--text-2);font-size:20px;transition:all .2s}
.theme-toggle:hover{background:var(--surface-hover);color:var(--primary);transform:translateY(-2px)}

/* 通知 */
.notif-container{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;gap:10px;pointer-events:none;width:90%;max-width:400px}
.notif{pointer-events:auto;display:flex;align-items:center;gap:10px;padding:13px 20px;border-radius:var(--radius-sm);background:var(--surface-solid);border:1px solid var(--border-strong);box-shadow:var(--shadow-lg);font-size:13.5px;font-weight:600;color:var(--text);animation:notifIn .35s var(--ease-spring)}
@keyframes notifIn{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
.notif.notif-out{animation:notifOut .3s var(--ease-out) forwards}
@keyframes notifOut{to{opacity:0;transform:translateY(-20px)}}
.notif i{font-size:20px;flex-shrink:0}
.notif.success i{color:var(--success)}
.notif.error i{color:var(--danger)}
.notif.warning i{color:var(--warning)}
.notif.info i{color:var(--primary)}

@media(max-width:480px){
  .login-card{padding:30px 24px}
  .login-title{font-size:21px}
}
</style>
</head>
<body>
<button class="theme-toggle" id="themeToggle" title="切换主题">
  <i class="mdi mdi-weather-night"></i>
</button>

<div class="orb o1"></div>
<div class="orb o2"></div>
<div class="orb o3"></div>

<div class="login-wrap">
  <div class="login-card">
    <div class="brand-area">
      <div class="brand-logo"><?php echo htmlspecialchars(mb_substr($siteName, 0, 1, 'UTF-8'));?></div>
      <div class="brand-name"><?php echo htmlspecialchars($siteName);?></div>
    </div>
    <h1 class="login-title">欢迎回来</h1>
    <p class="login-subtitle"><?php echo htmlspecialchars($siteNotice);?></p>

    <form id="loginForm" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');?>">
      <div class="form-group">
        <label class="form-label" for="username">用户名</label>
        <div class="input-wrap">
          <input type="text" class="form-input" id="username" name="username" placeholder="请输入用户名" required autofocus>
          <i class="mdi mdi-account-outline"></i>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">密码</label>
        <div class="input-wrap">
          <input type="password" class="form-input" id="password" name="password" placeholder="请输入密码" required>
          <i class="mdi mdi-lock-outline"></i>
        </div>
      </div>
      <button type="submit" class="btn-submit" id="submitBtn">
        <i class="mdi mdi-login"></i> <span>登 录</span>
      </button>
    </form>

    <div class="divider">或</div>

    <?php if($regEnabled): ?>
    <div class="login-footer">
      还没有账号？ <a href="register.php">立即注册 &rarr;</a>
    </div>
    <?php endif; ?>

    <a href="../index.php" class="back-home">
      <i class="mdi mdi-home-outline"></i> 返回首页
    </a>
  </div>
</div>

<div class="notif-container" id="notifContainer"></div>

<script>
var csrfToken = <?php echo json_encode($csrfToken);?>;
var regEnabled = <?php echo $regEnabled ? 'true' : 'false';?>;

// ===== 主题切换 =====
(function initTheme(){
  var saved = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  var icon = document.querySelector('#themeToggle i');
  if(icon) icon.className = saved === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
})();
document.getElementById('themeToggle').addEventListener('click', function(){
  var cur = document.documentElement.getAttribute('data-theme');
  var next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
  this.querySelector('i').className = next === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
});

// ===== 通知 =====
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function notify(msg, type){
  type = type || 'info';
  var icons = {success:'mdi-check-circle',error:'mdi-alert-circle',warning:'mdi-alert',info:'mdi-information'};
  var el = document.createElement('div');
  el.className = 'notif ' + type;
  el.innerHTML = '<i class="mdi '+(icons[type]||icons.info)+'"></i><span>'+esc(msg)+'</span>';
  document.getElementById('notifContainer').appendChild(el);
  setTimeout(function(){
    el.classList.add('notif-out');
    setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 300);
  }, 3000);
}

// ===== 回车提交 =====
document.getElementById('password').addEventListener('keypress', function(e){
  if(e.key === 'Enter') document.getElementById('loginForm').requestSubmit();
});

// ===== 登录提交 =====
document.getElementById('loginForm').addEventListener('submit', function(e){
  e.preventDefault();
  var username = document.getElementById('username').value.trim();
  var password = document.getElementById('password').value;
  if(!username || !password){
    notify('请填写用户名和密码', 'warning');
    return;
  }

  var btn = document.getElementById('submitBtn');
  var origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> <span>登录中...</span>';

  var fd = new FormData();
  fd.append('username', username);
  fd.append('password', password);
  fd.append('csrf_token', csrfToken);

  fetch('../api/user_api.php?action=login', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(data){
      if(data.code === 0){
        notify(data.msg || '登录成功，即将跳转...', 'success');
        setTimeout(function(){ window.location.href = data.redirect || 'index.php'; }, 1200);
      } else {
        notify(data.msg || '登录失败', 'error');
        btn.disabled = false;
        btn.innerHTML = origHTML;
      }
    })
    .catch(function(err){
      notify('网络错误，请稍后重试', 'error');
      btn.disabled = false;
      btn.innerHTML = origHTML;
    });
});
</script>
</body>
</html>
