<?php
/**
 * @file index.php
 * @description 后台主框架页面，采用 UI3 布局（sidebar + main + topbar + view 视图切换 + iframe 加载功能页）
 * @author AI
 * @version 3.1.0-dev
 * @date 2026-08-09
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit("<script>location.href='login.php';</script>");
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
<title>后台首页 - <?php echo htmlspecialchars($conf['name'] ?? 'EECMS', ENT_QUOTES, 'UTF-8');?></title>
<link rel="shortcut icon" type="image/x-icon" href="../favicon.ico">
<script>
// 渲染前应用已保存主题，避免闪烁（app.js 会接管后续主题逻辑）
(function(){
    try {
        var t = localStorage.getItem('admin_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    } catch(e) {}
})();
// 注入网站名称，供 app.js 设置浏览器标题使用（与 $conf['name'] 一致）
window.ADMIN_SITE_NAME = '<?php echo htmlspecialchars($conf['name'] ?? 'EECMS', ENT_QUOTES, 'UTF-8');?>';
</script>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
</head>
<body>
<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="brand">
    <div class="brand-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>
    </div>
    <div class="brand-text">
      <h1><?php echo htmlspecialchars($conf['name'] ?? 'EECMS', ENT_QUOTES, 'UTF-8');?></h1>
      <span>图床管理台</span>
    </div>
  </div>

  <div class="nav-search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input type="text" placeholder="搜索菜单 / 命令…" id="navSearch">
    <kbd>⌘K</kbd>
  </div>

  <nav class="nav">
    <p class="nav-group">主菜单</p>
    <a class="nav-item active" data-view="dashboard" data-url="dashboard.php" href="javascript:void(0)">
      <?php echo icon('view-dashboard-outline'); ?><span>后台首页</span>
    </a>
    <a class="nav-item" data-view="setting" data-url="setting.php" href="javascript:void(0)">
      <?php echo icon('cog-outline'); ?><span>网站设置</span>
    </a>
    <a class="nav-item" data-view="user" data-url="user.php" href="javascript:void(0)">
      <?php echo icon('account-outline'); ?><span>修改信息</span>
    </a>
    <a class="nav-item" data-view="api" data-url="api.php" href="javascript:void(0)">
      <?php echo icon('power-plug-outline'); ?><span>API接口设置</span>
    </a>
    <a class="nav-item" data-view="s3" data-url="s3.php" href="javascript:void(0)">
      <?php echo icon('cloud-outline'); ?><span>S3存储设置</span>
    </a>

    <p class="nav-group">用户与图片</p>
    <a class="nav-item" data-view="users" data-url="users.php" href="javascript:void(0)">
      <?php echo icon('account-group-outline'); ?><span>用户管理</span>
    </a>
    <a class="nav-item" data-view="images" data-url="images.php" href="javascript:void(0)">
      <?php echo icon('image-multiple-outline'); ?><span>图片管理</span>
    </a>
    <a class="nav-item" data-view="apikeys" data-url="apikeys.php" href="javascript:void(0)">
      <?php echo icon('key-variant'); ?><span>API密钥管理</span>
    </a>
    <a class="nav-item" data-view="regconfig" data-url="regconfig.php" href="javascript:void(0)">
      <?php echo icon('account-plus-outline'); ?><span>注册设置</span>
    </a>

    <p class="nav-group">套餐与权限</p>
    <a class="nav-item" data-view="packages" data-url="packages.php" href="javascript:void(0)">
      <?php echo icon('package-variant-closed'); ?><span>套餐管理</span>
    </a>
    <a class="nav-item" data-view="apigroups" data-url="apigroups.php" href="javascript:void(0)">
      <?php echo icon('group'); ?><span>接口分组</span>
    </a>
    <a class="nav-item" data-view="redeem" data-url="redeem.php" href="javascript:void(0)">
      <?php echo icon('ticket-outline'); ?><span>兑换码管理</span>
    </a>
    <a class="nav-item" data-view="access" data-url="access.php" href="javascript:void(0)">
      <?php echo icon('shield-lock-outline'); ?><span>访问控制</span>
    </a>
    <a class="nav-item" data-view="changelog" data-url="changelog.php" href="javascript:void(0)">
      <?php echo icon('history'); ?><span>更新日志</span>
    </a>
  </nav>

  <div class="side-card">
    <div class="side-card-halo"></div>
    <div class="side-card-body">
      <p class="tag"><?php echo htmlspecialchars($conf['name'] ?? 'EECMS', ENT_QUOTES, 'UTF-8');?></p>
      <h4>图床管理系统</h4>
      <p class="desc"><?php echo htmlspecialchars($conf['version'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
  </div>
</aside>

<!-- 移动端侧边栏抽屉遮罩 -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MAIN -->
<main class="main">
  <header class="topbar">
    <div class="topbar-left">
    <button class="icon-btn sidebar-toggle" id="sidebarToggle" type="button" aria-label="展开菜单" data-tip="菜单">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
    </button>
    <div class="crumbs">
      <span class="crumb-home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
      </span>
      <span class="crumb-sep">/</span>
      <span class="crumb">管理</span>
      <span class="crumb-sep">/</span>
      <span class="crumb crumb-active" id="crumbCurrent">后台首页</span>
    </div>
    </div>

    <div class="topbar-right">
      <a href="/" target="_blank" class="icon-btn tooltip" data-tip="访问前台">
        <?php echo icon('home-outline'); ?>
      </a>
      <button class="icon-btn tooltip" id="themeToggle" data-tip="切换主题">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>
      </button>
      <div class="divider-v"></div>
      <div class="dropdown">
        <div class="user-chip" data-bs-toggle="dropdown">
          <div class="avatar">
            <img src="style/images/avatar.jpg" alt="头像" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" />
            <i class="status-online"></i>
          </div>
          <div class="user-meta">
            <p><?php echo htmlspecialchars($conf['admin_user'], ENT_QUOTES, 'UTF-8');?></p>
            <span>超级管理员</span>
          </div>
          <?php echo icon('chevron-down'); ?>
        </div>
        <div class="dropdown-menu dropdown-menu-end">
          <a class="dropdown-item nav-item-link" data-url="user.php" href="javascript:void(0)">
            <?php echo icon('account-outline'); ?> 修改信息
          </a>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="javascript:void(0)" id="adminLogoutBtn">
            <?php echo icon('logout-variant'); ?> 退出登录
          </a>
        </div>
      </div>
    </div>
  </header>

  <!-- VIEWS -->
  <!-- 默认激活视图 dashboard 立即加载 src；其余使用 data-src 懒加载，由 app.js 在视图激活时赋值 -->
  <section class="view active" id="view-dashboard">
    <iframe src="dashboard.php" data-src="dashboard.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-setting">
    <iframe data-src="setting.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-user">
    <iframe data-src="user.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-api">
    <iframe data-src="api.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-s3">
    <iframe data-src="s3.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-users">
    <iframe data-src="users.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-images">
    <iframe data-src="images.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-apikeys">
    <iframe data-src="apikeys.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-regconfig">
    <iframe data-src="regconfig.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-packages">
    <iframe data-src="packages.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-apigroups">
    <iframe data-src="apigroups.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-redeem">
    <iframe data-src="redeem.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-access">
    <iframe data-src="access.php" frameborder="0"></iframe>
  </section>
  <section class="view" id="view-changelog">
    <iframe data-src="changelog.php" frameborder="0"></iframe>
  </section>
</main>

<!-- JS -->
<script src="style/js/jquery.min.js"></script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script src="style/js/cdropdown.js"></script>
<script src="style/js/app.js?v=20260809b"></script>
<script>
// 登出：POST + CSRF（禁止 GET 触发写操作，防止 CSRF 登出攻击）
// 内联脚本使用原生 JS（fetch + addEventListener），不依赖 $
// 退出登录增加二次确认（危险操作必须二次确认）
document.getElementById('adminLogoutBtn').addEventListener('click', function(){
    if(typeof Swal === 'undefined'){
        // Swal 未加载时的降级：直接执行登出
        doLogout();
        return;
    }
    Swal.fire({
        title: '确认退出登录？',
        text: '退出后需要重新输入账号密码登录',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认退出',
        cancelButtonText: '取消',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            doLogout();
        }
    });
});

function doLogout(){
    var fd = new FormData();
    fd.append('action', 'logout');
    // CSRF Token 通过 Header 传递
    fetch('login.php', {method: 'POST', body: fd, credentials: 'same-origin', headers:{'X-CSRF-Token':'<?php echo csrf_token();?>'}})
      .then(function(r){ return r.json(); })
      .then(function(res){
          if(res.code === 0){
              Swal.fire({title:'已安全退出', icon:'success', timer:1200, showConfirmButton:false})
                .then(function(){ location.href = 'login.php'; });
          } else {
              Swal.fire('错误', res.msg, 'error');
          }
      })
      .catch(function(){
          Swal.fire('错误', '网络错误，请重试！', 'error');
      });
}
</script>
</body>
</html>
