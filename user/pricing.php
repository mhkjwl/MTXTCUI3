<?php
/**
 * @file pricing.php
 * @description 套餐购买独立页面，展示所有可用套餐及其详细介绍，支持兑换码直接兑换
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-05
 */
declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require('../inc/common.php');

if(!$isUserLoggedIn) {
    header('Location: login.php');
    exit;
}

$siteName    = isset($conf['name']) ? $conf['name'] : '图床';
$siteEmail   = isset($conf['email']) && $conf['email'] !== '' ? $conf['email']
             : (isset($conf['picui_email']) ? $conf['picui_email'] : '');
$csrfToken   = csrf_token();
$username    = isset($currentUser['username']) ? $currentUser['username'] : '用户';
$userAvatar  = !empty($currentUser['avatar']) ? $currentUser['avatar'] : '';
$firstChar   = mb_substr($username, 0, 1, 'UTF-8');
$isSuperAdmin = ($currentUserRole === 'super_admin');
$themeAttr   = isset($_COOKIE['theme']) ? htmlspecialchars($_COOKIE['theme']) : 'light';

// SubTask 1.1: 检测 embed 模式（?embed=1 时返回 partial 内容片段）
$isEmbedMode = (isset($_GET['embed']) && (string)$_GET['embed'] === '1');

// SubTask 1.2 & 1.3: embed 模式只输出核心内容片段 + 内联样式 + 必要脚本
// 不输出 DOCTYPE/html/head/body 外壳、notifContainer、confirmModal、topbar、footer
if($isEmbedMode) {
?>
<link rel="stylesheet" href="../admin/style/css/sweetalert2.min.css">
<style>
    /* ============ 暗色主题（pricing 自有样式，父页 index.php 无这些类） ============ */
    [data-theme="dark"]{
      --bg:#0d0b16;
      --panel:rgba(30,26,46,0.72);
      --panel-strong:rgba(30,26,46,0.92);
      --line:rgba(123,140,255,0.16);
      --text:#f0edf8;
      --muted:#8a85a0;
      --shadow:0 20px 60px rgba(0,0,0,0.3);
    }
    [data-theme="dark"] .pricing-card,
    [data-theme="dark"] .feature-item,
    [data-theme="dark"] .redeem-box,
    [data-theme="dark"] .faq-box{background:rgba(30,26,46,0.72);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .feature-item i{background:rgba(123,140,255,0.14)}
    [data-theme="dark"] .pricing-card .feat-li{border-color:rgba(123,140,255,0.08)}
    [data-theme="dark"] input[type="text"]{background-color:rgba(30,26,46,0.78);color:var(--text);border-color:rgba(123,140,255,0.16)}

    /* ============ 布局 ============ */
    .pricing-embed{width:100%}
    .pricing-shell{max-width:1200px;margin:0 auto;padding:24px}

    /* ============ Hero ============ */
    .hero{text-align:center;margin-bottom:32px}
    .hero-badge{display:inline-block;padding:6px 18px;border-radius:999px;background:var(--gradient-soft);border:1px solid var(--line);font-weight:700;font-size:0.8rem;letter-spacing:0.08em;color:#8f78ff;margin-bottom:12px}
    .hero h1{font-size:2.8rem;font-weight:800;letter-spacing:-0.02em;background:var(--gradient-main);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1.15;margin:0 0 10px}
    .hero p{font-size:1.05rem;color:var(--muted);max-width:520px;margin:0 auto 18px}
    .hero .trust-row{display:inline-flex;flex-wrap:wrap;justify-content:center;gap:10px 20px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:10px 24px;backdrop-filter:blur(18px)}
    .hero .trust-row span{display:flex;align-items:center;gap:5px;font-weight:600;font-size:0.86rem;color:var(--text)}
    .hero .trust-row i{color:#8f78ff;font-size:18px}

    /* ============ 卖点 ============ */
    .features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:36px}
    .feature-item{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px 16px;text-align:center;backdrop-filter:blur(18px);transition:transform .25s ease,box-shadow .25s ease}
    .feature-item:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
    .feature-item i{font-size:2.2rem;color:#8f78ff;background:var(--gradient-soft);width:60px;height:60px;border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px}
    .feature-item h3{font-size:1.05rem;font-weight:700;margin:0 0 4px}
    .feature-item p{font-size:0.82rem;color:var(--muted);margin:0}

    /* ============ 套餐卡片 ============ */
    .section-head{text-align:center;margin-bottom:24px}
    .section-head .eyebrow{margin:0 0 6px}
    .section-head h2{font-size:1.8rem;font-weight:800;margin:0;display:flex;align-items:center;justify-content:center;gap:8px}
    .section-head h2 i{color:#8f78ff;font-size:1.8rem}

    .pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:32px}
    .pricing-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px 22px;backdrop-filter:blur(18px);box-shadow:var(--shadow);transition:transform .3s ease,box-shadow .3s ease,border-color .3s;display:flex;flex-direction:column;position:relative;overflow:hidden}
    .pricing-card:hover{transform:translateY(-6px);box-shadow:0 28px 60px rgba(123,140,255,0.16)}
    .pricing-card.current{border-color:#8f78ff;box-shadow:0 0 0 3px rgba(143,120,255,0.12),var(--shadow)}
    .pricing-card.current::before{content:'当前套餐';position:absolute;top:0;right:18px;background:var(--gradient-main);color:#fff;font-size:0.72rem;padding:4px 14px;border-radius:0 0 10px 10px;font-weight:700;z-index:2}
    .pricing-card .recommend-tag{position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#8b5cf6,#67b7ff);color:#fff;font-weight:700;font-size:0.7rem;letter-spacing:0.06em;padding:4px 18px;border-radius:0 0 12px 12px;z-index:2}
    .pricing-card .default-tag{position:absolute;top:14px;right:14px;background:var(--gradient-soft);border:1px solid var(--line);color:#8f78ff;font-size:0.66rem;font-weight:700;padding:3px 10px;border-radius:999px;z-index:2}

    .pkg-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
    .pkg-name{font-size:1.4rem;font-weight:800;margin:0}
    .pkg-icon{width:44px;height:44px;border-radius:14px;background:var(--gradient-soft);display:flex;align-items:center;justify-content:center;color:#8f78ff;font-size:22px;flex-shrink:0}
    .level-badge{display:inline-block;font-size:0.72rem;padding:2px 10px;border-radius:6px;font-weight:700;margin-left:8px;vertical-align:middle}
    .level-badge.free{background:rgba(128,139,194,0.14);color:var(--muted)}
    .level-badge.vip{background:var(--gradient-main);color:#fff}

    .pkg-desc{color:var(--muted);font-size:0.88rem;padding:8px 0 14px;border-bottom:1px solid var(--line);margin-bottom:14px}
    .pkg-desc .hi{color:#8f78ff;font-weight:600}

    .feat-list{list-style:none;padding:0;margin:0 0 20px;flex:1}
    .feat-li{display:flex;align-items:center;gap:8px;padding:7px 0;font-size:0.9rem;color:var(--text);border-bottom:1px solid rgba(125,142,196,0.06)}
    .feat-li:last-child{border-bottom:none}
    .feat-li i{color:#52d8a2;font-size:18px;flex-shrink:0}

    .pkg-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:12px 18px;border-radius:14px;font-weight:700;font-size:0.92rem;border:0;cursor:pointer;transition:transform .2s,box-shadow .2s;text-decoration:none;margin-top:auto}
    .pkg-btn.upgrade{background:var(--gradient-main);color:#fff;box-shadow:0 6px 18px rgba(123,140,255,0.22)}
    .pkg-btn.upgrade:hover{transform:translateY(-1px);color:#fff}
    .pkg-btn.locked{background:rgba(128,139,194,0.12);color:var(--muted);cursor:not-allowed}
    .pkg-btn.current-btn{background:rgba(82,216,162,0.12);color:#22a36d;border:1px solid rgba(82,216,162,0.3);cursor:default}

    /* ============ 兑换区 ============ */
    .redeem-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px;text-align:center;backdrop-filter:blur(18px);box-shadow:var(--shadow);margin-bottom:28px}
    .redeem-box h2{font-size:1.4rem;font-weight:800;margin:0 0 6px;display:flex;align-items:center;justify-content:center;gap:8px}
    .redeem-box h2 i{color:#8f78ff}
    .redeem-box p{color:var(--muted);font-size:0.9rem;margin:0 0 18px}
    .redeem-row{display:flex;gap:10px;max-width:480px;margin:0 auto}
    .redeem-row input{flex:1;padding:12px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.92rem;color:var(--text);transition:border .2s}
    .redeem-row input:focus{outline:none;border-color:#8f78ff}
    .redeem-row button{padding:12px 22px;background:var(--gradient-main);color:#fff;border:0;border-radius:14px;font-weight:700;font-size:0.9rem;cursor:pointer;transition:transform .2s;white-space:nowrap;display:flex;align-items:center;gap:6px;box-shadow:0 6px 18px rgba(123,140,255,0.22)}
    .redeem-row button:hover{transform:translateY(-1px)}
    .redeem-row button:disabled{opacity:0.6;cursor:allowed;transform:none}

    /* ============ FAQ ============ */
    .faq-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px;backdrop-filter:blur(18px);box-shadow:var(--shadow);margin-bottom:28px}
    .faq-box h2{text-align:center;font-size:1.5rem;font-weight:800;margin:0 0 20px}
    .faq-item{border-bottom:1px solid var(--line);padding:14px 0;cursor:pointer}
    .faq-item:last-child{border-bottom:none}
    .faq-q{display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:0.98rem;color:var(--text)}
    .faq-q i{transition:transform .3s;color:#8f78ff;font-size:22px}
    .faq-item.active .faq-q i{transform:rotate(180deg)}
    .faq-a{max-height:0;overflow:hidden;transition:max-height .3s ease,padding-top .3s;color:var(--muted);font-size:0.9rem;line-height:1.6}
    .faq-item.active .faq-a{max-height:200px;padding-top:10px}

    /* ============ 响应式 ============ */
    @media(max-width:768px){
      .pricing-shell{padding:16px}
      .hero h1{font-size:2rem}
      .hero .trust-row{gap:6px 12px;padding:8px 16px}
      .pricing-grid{gap:16px}
      .redeem-row{flex-direction:column}
      .redeem-row button{justify-content:center}
    }
</style>
<div class="pricing-embed">
  <div class="pricing-shell">

    <!-- Hero -->
    <section class="hero">
      <div class="hero-badge">🚀 极速 · 稳定 · 安全</div>
      <h1>选择适合你的套餐</h1>
      <p>专为不同需求打造，从个人轻量到企业级存储，总有一款适合你。</p>
      <div class="trust-row">
        <span><i class="mdi mdi-database"></i> 多接口存储</span>
        <span><i class="mdi mdi-lightning-bolt"></i> 毫秒级响应</span>
        <span><i class="mdi mdi-shield-check-outline"></i> 安全防盗链</span>
        <span><i class="mdi mdi-image-multiple"></i> CDN 加速</span>
      </div>
    </section>

    <!-- 核心卖点 -->
    <div class="features-grid">
      <div class="feature-item">
        <i class="mdi mdi-flash-outline"></i>
        <h3>毫秒级加载</h3>
        <p>智能路由 + 边缘缓存</p>
      </div>
      <div class="feature-item">
        <i class="mdi mdi-shield-lock-outline"></i>
        <h3>企业级安全</h3>
        <p>防盗链 · SSL · 权限</p>
      </div>
      <div class="feature-item">
        <i class="mdi mdi-image-multiple-outline"></i>
        <h3>智能处理</h3>
        <p>多格式支持 · API 上传</p>
      </div>
      <div class="feature-item">
        <i class="mdi mdi-headphones"></i>
        <h3>专属支持</h3>
        <p>7×24 快速响应</p>
      </div>
    </div>

    <!-- 定价区标题 -->
    <div class="section-head">
      <p class="eyebrow">Pricing Plans</p>
      <h2><i class="mdi mdi-tag-outline"></i> 套餐方案</h2>
    </div>

    <!-- 套餐卡片（JS 动态渲染） -->
    <div class="pricing-grid" id="pricingGrid">
      <div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;">
        <i class="mdi mdi-loading mdi-spin" style="font-size:36px;display:block;margin-bottom:12px;"></i>
        加载中...
      </div>
    </div>

    <!-- 兑换码输入区 -->
    <div class="redeem-box">
      <h2><i class="mdi mdi-ticket-confirmation-outline"></i> 输入兑换码</h2>
      <p>已有兑换码？直接输入即可升级或续期套餐</p>
      <div class="redeem-row">
        <input type="text" id="pricingRedeemInput" placeholder="请输入兑换码" autocomplete="off">
        <button type="button" id="pricingRedeemBtn">
          <i class="mdi mdi-ticket-confirmation-outline"></i> 立即兑换
        </button>
      </div>
    </div>

    <!-- FAQ -->
    <section class="faq-box">
      <h2>🤔 常见问题</h2>
      <div class="faq-item active">
        <div class="faq-q">
          <span>如何获取套餐兑换码？</span>
          <i class="mdi mdi-chevron-down"></i>
        </div>
        <div class="faq-a">兑换码由管理员发放，可通过活动赠送、合作渠道或直接联系管理员获取。获取后在上方输入框中输入即可激活对应套餐。</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">
          <span>套餐可以降级吗？</span>
          <i class="mdi mdi-chevron-down"></i>
        </div>
        <div class="faq-a">不支持手动降级。套餐到期后会自动回退到默认套餐。如需更换更低等级套餐，请等待当前套餐到期后再兑换。</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">
          <span>永久套餐可以重复兑换吗？</span>
          <i class="mdi mdi-chevron-down"></i>
        </div>
        <div class="faq-a">永久套餐（有效期为永久）不支持同级重复兑换，因为再兑换也是无意义的。您可以兑换更高等级的套餐进行升级。</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">
          <span>套餐升级后有效期怎么算？</span>
          <i class="mdi mdi-chevron-down"></i>
        </div>
        <div class="faq-a">升级到更高等级套餐时，有效期从当前时间重新计算（覆盖模式）。同级续期时，有效期在原到期时间基础上顺延。已过期套餐兑换后按新开通处理。</div>
      </div>
    </section>

  </div>
</div>
<script src="../admin/style/js/sweetalert2.min.js"></script>
<script>
// SubTask 1.3: embed 模式 JS 初始化（guard 防重复执行）
if(typeof window.__pricingEmbedInit === 'undefined'){
  window.__pricingEmbedInit = true;

  var CSRF_TOKEN = '<?php echo $csrfToken;?>';
  var _currentPkgLevel = -1;
  var _pkgIcons = ['mdi-seed-outline','mdi-rocket-launch-outline','mdi-apartment','mdi-domain','mdi-crown-outline','mdi-diamond-outline','mdi-star-shooting-outline','mdi-trophy-outline'];

  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

  // ===== Toast 通知（复用父页 notifContainer，禁止原生 alert/confirm/prompt）=====
  function toast(type, msg, timer){
    var container = document.getElementById('notifContainer');
    if(!container) return;
    var icons = {success:'mdi-check-circle',error:'mdi-alert-circle',warning:'mdi-alert',info:'mdi-information'};
    var n = document.createElement('div');
    n.className = 'notif ' + type;
    n.innerHTML = '<i class="mdi ' + (icons[type]||icons.info) + '"></i><span>' + esc(msg) + '</span>';
    container.appendChild(n);
    setTimeout(function(){
      n.classList.add('notif-out');
      setTimeout(function(){ if(n.parentNode) n.parentNode.removeChild(n); }, 300);
    }, timer || 3000);
  }

  // ===== 主题同步：从父页 document.documentElement.dataset.theme 读取 =====
  // pricing 样式使用 [data-theme="dark"] 选择器，自动响应父页 <html data-theme="...">
  // 不显示自己的 themeToggle 按钮，主题切换由父页 index.php 统一管理

  // ===== 加载当前套餐信息 =====
  function loadCurrentPackage(){
    fetch('../api/user_api.php?action=package_info&_t=' + Date.now())
      .then(function(r){return r.json();})
      .then(function(d){
        if(d.code === 0 && d.data && !d.data.is_expired){
          _currentPkgLevel = d.data.level;
        }
        loadPricingPlans();
      })
      .catch(function(){ loadPricingPlans(); });
  }

  // ===== 加载套餐列表 =====
  function loadPricingPlans(){
    fetch('../api/user_api.php?action=packages_list&_t=' + Date.now())
      .then(function(r){return r.json();})
      .then(function(d){
        var grid = document.getElementById('pricingGrid');
        if(!grid) return;
        if(d.code !== 0 || !d.data || !d.data.packages){
          grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-alert-circle-outline" style="font-size:36px;display:block;margin-bottom:12px;"></i>' + esc(d.msg || '加载失败') + '</div>';
          return;
        }
        var list = d.data.packages;
        if(!list.length){
          grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-package-variant-closed" style="font-size:48px;display:block;margin-bottom:12px;"></i>暂无可用套餐</div>';
          return;
        }
        var html = '';
        list.forEach(function(pkg, idx){
          var isCurrent = pkg.level === _currentPkgLevel;
          var icon = _pkgIcons[idx % _pkgIcons.length];
          var badgeHtml = pkg.level > 0
            ? '<span class="level-badge vip">VIP' + pkg.level + '</span>'
            : '<span class="level-badge free">免费</span>';
          var defaultHtml = pkg.is_default ? '<span class="default-tag">⭐ 默认</span>' : '';
          var recommendHtml = (pkg.level > 0 && idx === 1) ? '<span class="recommend-tag">最受欢迎</span>' : '';
          var featuresHtml = '';
          pkg.features.forEach(function(f){
            featuresHtml += '<li class="feat-li"><i class="mdi mdi-check-circle"></i>' + esc(f) + '</li>';
          });
          var btnHtml = '';
          if(isCurrent){
            btnHtml = '<button class="pkg-btn current-btn" disabled><i class="mdi mdi-check-circle"></i> 当前套餐</button>';
          } else if(pkg.level <= _currentPkgLevel && _currentPkgLevel >= 0){
            btnHtml = '<button class="pkg-btn locked" disabled><i class="mdi mdi-lock"></i> 已拥有更高等级</button>';
          } else {
            btnHtml = '<button class="pkg-btn upgrade" onclick="scrollToRedeem()"><i class="mdi mdi-ticket-confirmation-outline"></i> 输入兑换码升级</button>';
          }
          html += '<div class="pricing-card' + (isCurrent ? ' current' : '') + '">' +
            recommendHtml +
            defaultHtml +
            '<div class="pkg-head">' +
              '<div><span class="pkg-name">' + esc(pkg.name) + '</span>' + badgeHtml + '</div>' +
              '<div class="pkg-icon"><i class="mdi ' + icon + '"></i></div>' +
            '</div>' +
            '<div class="pkg-desc">' + (pkg.level > 0 ? '<span class="hi">VIP' + pkg.level + ' 等级</span> · ' : '') + esc(pkg.storage_text) + ' · ' + esc(pkg.days_text) + '</div>' +
            '<ul class="feat-list">' + featuresHtml + '</ul>' +
            btnHtml +
          '</div>';
        });
        grid.innerHTML = html;
      })
      .catch(function(){
        var grid = document.getElementById('pricingGrid');
        if(grid) grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-alert-circle-outline" style="font-size:36px;display:block;margin-bottom:12px;"></i>网络错误，请刷新重试</div>';
      });
  }

  // ===== 滚动到兑换码输入区（暴露到全局供动态生成的 onclick 调用）=====
  function scrollToRedeem(){
    var el = document.querySelector('.pricing-embed .redeem-box');
    if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
    var input = document.getElementById('pricingRedeemInput');
    if(input) setTimeout(function(){ input.focus(); }, 400);
  }
  window.scrollToRedeem = scrollToRedeem;

  // ===== 兑换码（SweetAlert2 二次确认 reverseButtons + POST + X-CSRF-Token Header）=====
  function doRedeem(){
    var input = document.getElementById('pricingRedeemInput');
    if(!input) return;
    var code = input.value.trim();
    if(!code){ toast('warning', '请输入兑换码'); return; }
    Swal.fire({
      title: '确认兑换',
      text: '确定要使用此兑换码兑换套餐吗？兑换后套餐等级将变更。',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: '确认兑换',
      cancelButtonText: '取消',
      reverseButtons: true
    }).then(function(result){
      if(!result.isConfirmed) return;
      var btn = document.getElementById('pricingRedeemBtn');
      var origHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 兑换中...';
      // POST + Body + CSRF Token（Header X-CSRF-Token），符合规范 § 5.4
      fetch('../api/user_api.php?action=redeem', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': CSRF_TOKEN
        },
        body: 'code=' + encodeURIComponent(code)
      })
      .then(function(r){return r.json();})
      .then(function(d){
        btn.disabled = false;
        btn.innerHTML = origHtml;
        if(d.code === 0){
          // 局部更新：刷新套餐卡片高亮 + Toast 成功提示，禁止 location.reload()
          toast('success', d.msg || '兑换成功', 2000);
          loadCurrentPackage();
          input.value = '';
        } else {
          toast('error', d.msg || '兑换失败');
        }
      })
      .catch(function(){
        btn.disabled = false;
        btn.innerHTML = origHtml;
        toast('error', '网络错误，请重试');
      });
    });
  }

  // ===== FAQ 折叠（限定 embed 容器范围，避免影响父页元素）=====
  document.querySelectorAll('.pricing-embed .faq-item').forEach(function(item){
    var q = item.querySelector('.faq-q');
    q.addEventListener('click', function(){
      var isActive = item.classList.contains('active');
      document.querySelectorAll('.pricing-embed .faq-item').forEach(function(i){ i.classList.remove('active'); });
      if(!isActive) item.classList.add('active');
    });
  });

  // ===== 回车提交兑换码 =====
  document.getElementById('pricingRedeemInput').addEventListener('keydown', function(e){
    if(e.key === 'Enter'){ e.preventDefault(); doRedeem(); }
  });

  // ===== 兑换按钮点击 =====
  document.getElementById('pricingRedeemBtn').addEventListener('click', doRedeem);

  // ===== 初始化 =====
  loadCurrentPackage();
}
</script>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?php echo $themeAttr;?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>套餐购买 - <?php echo htmlspecialchars($siteName);?></title>
  <link href="../admin/style/css/materialdesignicons.min.css" rel="stylesheet">
  <link href="../bd/qd.css" rel="stylesheet">
  <style>
    /* ============ 暗色主题 ============ */
    [data-theme="dark"]{
      --bg:#0d0b16;
      --panel:rgba(30,26,46,0.72);
      --panel-strong:rgba(30,26,46,0.92);
      --line:rgba(123,140,255,0.16);
      --text:#f0edf8;
      --muted:#8a85a0;
      --shadow:0 20px 60px rgba(0,0,0,0.3);
    }
    [data-theme="dark"] body{
      background:
        radial-gradient(circle at top left, rgba(155,125,255,0.18), transparent 28%),
        radial-gradient(circle at top right, rgba(123,211,252,0.14), transparent 24%),
        radial-gradient(circle at bottom, rgba(255,127,168,0.1), transparent 30%),
        var(--bg);
    }
    [data-theme="dark"] .pricing-card,
    [data-theme="dark"] .feature-item,
    [data-theme="dark"] .redeem-box,
    [data-theme="dark"] .faq-box,
    [data-theme="dark"] .topbar{background:rgba(30,26,46,0.72);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .feature-item i{background:rgba(123,140,255,0.14)}
    [data-theme="dark"] .pricing-card .feat-li{border-color:rgba(123,140,255,0.08)}
    [data-theme="dark"] input[type="text"]{background-color:rgba(30,26,46,0.78);color:var(--text);border-color:rgba(123,140,255,0.16)}

    /* ============ 布局 ============ */
    .pricing-shell{max-width:1200px;margin:0 auto;padding:24px}
    .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:14px 22px;backdrop-filter:blur(18px);box-shadow:var(--shadow)}
    .topbar .brand{display:flex;align-items:center;gap:12px;font-weight:700;font-size:1.1rem}
    .topbar .brand i{font-size:1.5rem;color:#8f78ff}
    .topbar .actions{display:flex;gap:10px;align-items:center}
    .topbar .back-btn{display:inline-flex;align-items:center;gap:6px;background:var(--gradient-main);color:#fff;padding:10px 18px;border-radius:14px;text-decoration:none;font-weight:600;font-size:0.88rem;transition:transform .2s ease;box-shadow:0 6px 18px rgba(123,140,255,0.22)}
    .topbar .back-btn:hover{transform:translateY(-1px);color:#fff}
    #themeToggle{width:40px;height:40px;border-radius:12px;border:1px solid var(--line);background:var(--panel);color:var(--text);cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;transition:background .2s}
    #themeToggle:hover{background:rgba(123,140,255,0.12)}

    /* ============ Hero ============ */
    .hero{text-align:center;margin-bottom:32px}
    .hero-badge{display:inline-block;padding:6px 18px;border-radius:999px;background:var(--gradient-soft);border:1px solid var(--line);font-weight:700;font-size:0.8rem;letter-spacing:0.08em;color:#8f78ff;margin-bottom:12px}
    .hero h1{font-size:2.8rem;font-weight:800;letter-spacing:-0.02em;background:var(--gradient-main);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1.15;margin:0 0 10px}
    .hero p{font-size:1.05rem;color:var(--muted);max-width:520px;margin:0 auto 18px}
    .hero .trust-row{display:inline-flex;flex-wrap:wrap;justify-content:center;gap:10px 20px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:10px 24px;backdrop-filter:blur(18px)}
    .hero .trust-row span{display:flex;align-items:center;gap:5px;font-weight:600;font-size:0.86rem;color:var(--text)}
    .hero .trust-row i{color:#8f78ff;font-size:18px}

    /* ============ 卖点 ============ */
    .features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:36px}
    .feature-item{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px 16px;text-align:center;backdrop-filter:blur(18px);transition:transform .25s ease,box-shadow .25s ease}
    .feature-item:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
    .feature-item i{font-size:2.2rem;color:#8f78ff;background:var(--gradient-soft);width:60px;height:60px;border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px}
    .feature-item h3{font-size:1.05rem;font-weight:700;margin:0 0 4px}
    .feature-item p{font-size:0.82rem;color:var(--muted);margin:0}

    /* ============ 套餐卡片 ============ */
    .section-head{text-align:center;margin-bottom:24px}
    .section-head .eyebrow{margin:0 0 6px}
    .section-head h2{font-size:1.8rem;font-weight:800;margin:0;display:flex;align-items:center;justify-content:center;gap:8px}
    .section-head h2 i{color:#8f78ff;font-size:1.8rem}

    .pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:32px}
    .pricing-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px 22px;backdrop-filter:blur(18px);box-shadow:var(--shadow);transition:transform .3s ease,box-shadow .3s ease,border-color .3s;display:flex;flex-direction:column;position:relative;overflow:hidden}
    .pricing-card:hover{transform:translateY(-6px);box-shadow:0 28px 60px rgba(123,140,255,0.16)}
    .pricing-card.current{border-color:#8f78ff;box-shadow:0 0 0 3px rgba(143,120,255,0.12),var(--shadow)}
    .pricing-card.current::before{content:'当前套餐';position:absolute;top:0;right:18px;background:var(--gradient-main);color:#fff;font-size:0.72rem;padding:4px 14px;border-radius:0 0 10px 10px;font-weight:700;z-index:2}
    .pricing-card .recommend-tag{position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#8b5cf6,#67b7ff);color:#fff;font-weight:700;font-size:0.7rem;letter-spacing:0.06em;padding:4px 18px;border-radius:0 0 12px 12px;z-index:2}
    .pricing-card .default-tag{position:absolute;top:14px;right:14px;background:var(--gradient-soft);border:1px solid var(--line);color:#8f78ff;font-size:0.66rem;font-weight:700;padding:3px 10px;border-radius:999px;z-index:2}

    .pkg-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
    .pkg-name{font-size:1.4rem;font-weight:800;margin:0}
    .pkg-icon{width:44px;height:44px;border-radius:14px;background:var(--gradient-soft);display:flex;align-items:center;justify-content:center;color:#8f78ff;font-size:22px;flex-shrink:0}
    .level-badge{display:inline-block;font-size:0.72rem;padding:2px 10px;border-radius:6px;font-weight:700;margin-left:8px;vertical-align:middle}
    .level-badge.free{background:rgba(128,139,194,0.14);color:var(--muted)}
    .level-badge.vip{background:var(--gradient-main);color:#fff}

    .pkg-desc{color:var(--muted);font-size:0.88rem;padding:8px 0 14px;border-bottom:1px solid var(--line);margin-bottom:14px}
    .pkg-desc .hi{color:#8f78ff;font-weight:600}

    .feat-list{list-style:none;padding:0;margin:0 0 20px;flex:1}
    .feat-li{display:flex;align-items:center;gap:8px;padding:7px 0;font-size:0.9rem;color:var(--text);border-bottom:1px solid rgba(125,142,196,0.06)}
    .feat-li:last-child{border-bottom:none}
    .feat-li i{color:#52d8a2;font-size:18px;flex-shrink:0}

    .pkg-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:12px 18px;border-radius:14px;font-weight:700;font-size:0.92rem;border:0;cursor:pointer;transition:transform .2s,box-shadow .2s;text-decoration:none;margin-top:auto}
    .pkg-btn.upgrade{background:var(--gradient-main);color:#fff;box-shadow:0 6px 18px rgba(123,140,255,0.22)}
    .pkg-btn.upgrade:hover{transform:translateY(-1px);color:#fff}
    .pkg-btn.locked{background:rgba(128,139,194,0.12);color:var(--muted);cursor:not-allowed}
    .pkg-btn.current-btn{background:rgba(82,216,162,0.12);color:#22a36d;border:1px solid rgba(82,216,162,0.3);cursor:default}

    /* ============ 兑换区 ============ */
    .redeem-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px;text-align:center;backdrop-filter:blur(18px);box-shadow:var(--shadow);margin-bottom:28px}
    .redeem-box h2{font-size:1.4rem;font-weight:800;margin:0 0 6px;display:flex;align-items:center;justify-content:center;gap:8px}
    .redeem-box h2 i{color:#8f78ff}
    .redeem-box p{color:var(--muted);font-size:0.9rem;margin:0 0 18px}
    .redeem-row{display:flex;gap:10px;max-width:480px;margin:0 auto}
    .redeem-row input{flex:1;padding:12px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.92rem;color:var(--text);transition:border .2s}
    .redeem-row input:focus{outline:none;border-color:#8f78ff}
    .redeem-row button{padding:12px 22px;background:var(--gradient-main);color:#fff;border:0;border-radius:14px;font-weight:700;font-size:0.9rem;cursor:pointer;transition:transform .2s;white-space:nowrap;display:flex;align-items:center;gap:6px;box-shadow:0 6px 18px rgba(123,140,255,0.22)}
    .redeem-row button:hover{transform:translateY(-1px)}
    .redeem-row button:disabled{opacity:0.6;cursor:not-allowed;transform:none}

    /* ============ FAQ ============ */
    .faq-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-xl);padding:28px;backdrop-filter:blur(18px);box-shadow:var(--shadow);margin-bottom:28px}
    .faq-box h2{text-align:center;font-size:1.5rem;font-weight:800;margin:0 0 20px}
    .faq-item{border-bottom:1px solid var(--line);padding:14px 0;cursor:pointer}
    .faq-item:last-child{border-bottom:none}
    .faq-q{display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:0.98rem;color:var(--text)}
    .faq-q i{transition:transform .3s;color:#8f78ff;font-size:22px}
    .faq-item.active .faq-q i{transform:rotate(180deg)}
    .faq-a{max-height:0;overflow:hidden;transition:max-height .3s ease,padding-top .3s;color:var(--muted);font-size:0.9rem;line-height:1.6}
    .faq-item.active .faq-a{max-height:200px;padding-top:10px}

    /* ============ 页脚 ============ */
    .footer{text-align:center;padding:20px;color:var(--muted);font-size:0.84rem;border-top:1px solid var(--line)}
    .footer a{color:#8f78ff;text-decoration:none;font-weight:600;cursor:pointer}
    .footer a:hover{text-decoration:underline}

    /* ============ Toast 通知 ============ */
    .notif-container{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
    .notif{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:16px;background:var(--panel-strong);backdrop-filter:blur(18px);box-shadow:var(--shadow);border:1px solid var(--line);font-size:0.92rem;color:var(--text);min-width:240px;max-width:400px;animation:notif-in .3s ease;pointer-events:auto}
    .notif i{font-size:20px;flex-shrink:0}
    .notif.success{border-color:rgba(82,216,162,0.4)}.notif.success i{color:#22a36d}
    .notif.error{border-color:rgba(255,127,168,0.4)}.notif.error i{color:#e53e6b}
    .notif.warning{border-color:rgba(255,193,87,0.4)}.notif.warning i{color:#d9a441}
    .notif.info{border-color:rgba(123,140,255,0.4)}.notif.info i{color:#7b8cff}
    .notif.notif-out{animation:notif-out .3s ease forwards}
    @keyframes notif-in{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
    @keyframes notif-out{to{opacity:0;transform:translateX(40px)}}

    /* ============ 确认弹窗 ============ */
    .modal-overlay{display:none;position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
    .modal-overlay.show{display:flex}
    .modal-box{background:var(--panel-strong);backdrop-filter:blur(24px);border-radius:24px;box-shadow:var(--shadow);border:1px solid var(--line);padding:28px;max-width:460px;width:90%;animation:modal-in .3s ease}
    @keyframes modal-in{from{opacity:0;transform:scale(0.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    .modal-title{margin:0 0 16px;font-size:1.2rem;font-weight:700;display:flex;align-items:center;gap:8px}
    .modal-body{margin:0 0 24px;color:var(--muted);line-height:1.6}
    .modal-actions{display:flex;gap:12px;justify-content:flex-end}
    .modal-btn{padding:12px 20px;border-radius:14px;border:0;cursor:pointer;font:inherit;font-weight:600;transition:transform .2s ease;display:inline-flex;align-items:center;gap:6px}
    .modal-btn:hover{transform:translateY(-1px)}
    .modal-btn.cancel{background:rgba(128,139,194,0.12);color:var(--muted)}
    .modal-btn.confirm{background:var(--gradient-main);color:#fff;box-shadow:0 8px 20px rgba(133,102,255,0.22)}

    /* ============ 响应式 ============ */
    @media(max-width:768px){
      .pricing-shell{padding:16px}
      .hero h1{font-size:2rem}
      .hero .trust-row{gap:6px 12px;padding:8px 16px}
      .pricing-grid{gap:16px}
      .redeem-row{flex-direction:column}
      .redeem-row button{justify-content:center}
    }
  </style>
</head>
<body>
  <div class="notif-container" id="notifContainer"></div>
  <!-- 确认弹窗 -->
  <div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
      <div class="modal-title" id="confirmTitle"><i class="mdi mdi-help-circle-outline"></i> 确认兑换</div>
      <div class="modal-body" id="confirmBody">确定要使用此兑换码兑换套餐吗？兑换后套餐等级将变更。</div>
      <div class="modal-actions">
        <button class="modal-btn cancel" onclick="closeConfirm()"><i class="mdi mdi-close"></i> 取消</button>
        <button class="modal-btn confirm" id="confirmOkBtn"><i class="mdi mdi-check"></i> 确认兑换</button>
      </div>
    </div>
  </div>

<div class="pricing-shell">

  <!-- 顶部导航条 -->
  <div class="topbar">
    <div class="brand">
      <i class="mdi mdi-package-variant"></i>
      <span><?php echo htmlspecialchars($siteName);?> · 套餐中心</span>
    </div>
    <div class="actions">
      <button id="themeToggle" title="切换主题"><i class="mdi mdi-weather-night"></i></button>
      <a href="index.php" class="back-btn">
        <i class="mdi mdi-arrow-left"></i> 返回用户中心
      </a>
    </div>
  </div>

  <!-- Hero -->
  <section class="hero">
    <div class="hero-badge">🚀 极速 · 稳定 · 安全</div>
    <h1>选择适合你的套餐</h1>
    <p>专为不同需求打造，从个人轻量到企业级存储，总有一款适合你。</p>
    <div class="trust-row">
      <span><i class="mdi mdi-database"></i> 多接口存储</span>
      <span><i class="mdi mdi-lightning-bolt"></i> 毫秒级响应</span>
      <span><i class="mdi mdi-shield-check-outline"></i> 安全防盗链</span>
      <span><i class="mdi mdi-image-multiple"></i> CDN 加速</span>
    </div>
  </section>

  <!-- 核心卖点 -->
  <div class="features-grid">
    <div class="feature-item">
      <i class="mdi mdi-flash-outline"></i>
      <h3>毫秒级加载</h3>
      <p>智能路由 + 边缘缓存</p>
    </div>
    <div class="feature-item">
      <i class="mdi mdi-shield-lock-outline"></i>
      <h3>企业级安全</h3>
      <p>防盗链 · SSL · 权限</p>
    </div>
    <div class="feature-item">
      <i class="mdi mdi-image-multiple-outline"></i>
      <h3>智能处理</h3>
      <p>多格式支持 · API 上传</p>
    </div>
    <div class="feature-item">
      <i class="mdi mdi-headphones"></i>
      <h3>专属支持</h3>
      <p>7×24 快速响应</p>
    </div>
  </div>

  <!-- 定价区标题 -->
  <div class="section-head">
    <p class="eyebrow">Pricing Plans</p>
    <h2><i class="mdi mdi-tag-outline"></i> 套餐方案</h2>
  </div>

  <!-- 套餐卡片（JS 动态渲染） -->
  <div class="pricing-grid" id="pricingGrid">
    <div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;">
      <i class="mdi mdi-loading mdi-spin" style="font-size:36px;display:block;margin-bottom:12px;"></i>
      加载中...
    </div>
  </div>

  <!-- 兑换码输入区 -->
  <div class="redeem-box">
    <h2><i class="mdi mdi-ticket-confirmation-outline"></i> 输入兑换码</h2>
    <p>已有兑换码？直接输入即可升级或续期套餐</p>
    <div class="redeem-row">
      <input type="text" id="redeemInput" placeholder="请输入兑换码" autocomplete="off">
      <button type="button" id="redeemBtn" onclick="doRedeem()">
        <i class="mdi mdi-ticket-confirmation-outline"></i> 立即兑换
      </button>
    </div>
  </div>

  <!-- FAQ -->
  <section class="faq-box">
    <h2>🤔 常见问题</h2>
    <div class="faq-item active">
      <div class="faq-q">
        <span>如何获取套餐兑换码？</span>
        <i class="mdi mdi-chevron-down"></i>
      </div>
      <div class="faq-a">兑换码由管理员发放，可通过活动赠送、合作渠道或直接联系管理员获取。获取后在上方输入框中输入即可激活对应套餐。</div>
    </div>
    <div class="faq-item">
      <div class="faq-q">
        <span>套餐可以降级吗？</span>
        <i class="mdi mdi-chevron-down"></i>
      </div>
      <div class="faq-a">不支持手动降级。套餐到期后会自动回退到默认套餐。如需更换更低等级套餐，请等待当前套餐到期后再兑换。</div>
    </div>
    <div class="faq-item">
      <div class="faq-q">
        <span>永久套餐可以重复兑换吗？</span>
        <i class="mdi mdi-chevron-down"></i>
      </div>
      <div class="faq-a">永久套餐（有效期为永久）不支持同级重复兑换，因为再兑换也是无意义的。您可以兑换更高等级的套餐进行升级。</div>
    </div>
    <div class="faq-item">
      <div class="faq-q">
        <span>套餐升级后有效期怎么算？</span>
        <i class="mdi mdi-chevron-down"></i>
      </div>
      <div class="faq-a">升级到更高等级套餐时，有效期从当前时间重新计算（覆盖模式）。同级续期时，有效期在原到期时间基础上顺延。已过期套餐兑换后按新开通处理。</div>
    </div>
  </section>

  <!-- 页脚 -->
  <div class="footer">
    🖼️ 所有套餐均享受多接口存储 &nbsp;·&nbsp; 支持兑换码升级
    <?php if(!empty($siteEmail)): ?>
    &nbsp;·&nbsp; <a onclick="copyEmail('<?php echo htmlspecialchars($siteEmail, ENT_QUOTES);?>')"><?php echo htmlspecialchars($siteEmail);?></a>
    <?php endif; ?>
  </div>

</div>

<script>
var CSRF_TOKEN = '<?php echo $csrfToken;?>';
var _currentPkgLevel = -1;
var _pkgIcons = ['mdi-seed-outline','mdi-rocket-launch-outline','mdi-apartment','mdi-domain','mdi-crown-outline','mdi-diamond-outline','mdi-star-shooting-outline','mdi-trophy-outline'];

function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

// ===== Toast 通知（自定义组件，禁止原生 alert/confirm/prompt）=====
function toast(type, msg, timer){
  var container = document.getElementById('notifContainer');
  if(!container) return;
  var icons = {success:'mdi-check-circle',error:'mdi-alert-circle',warning:'mdi-alert',info:'mdi-information'};
  var n = document.createElement('div');
  n.className = 'notif ' + type;
  n.innerHTML = '<i class="mdi ' + (icons[type]||icons.info) + '"></i><span>' + esc(msg) + '</span>';
  container.appendChild(n);
  setTimeout(function(){
    n.classList.add('notif-out');
    setTimeout(function(){ if(n.parentNode) n.parentNode.removeChild(n); }, 300);
  }, timer || 3000);
}

// ===== 确认弹窗（自定义 Modal）=====
var _confirmCallback = null;
function showConfirm(title, body, callback){
  document.getElementById('confirmTitle').innerHTML = '<i class="mdi mdi-help-circle-outline"></i> ' + esc(title);
  document.getElementById('confirmBody').textContent = body;
  _confirmCallback = callback;
  document.getElementById('confirmModal').classList.add('show');
}
function closeConfirm(){
  document.getElementById('confirmModal').classList.remove('show');
  _confirmCallback = null;
}
document.getElementById('confirmOkBtn').addEventListener('click', function(){
  closeConfirm();
  if(typeof _confirmCallback === 'function') _confirmCallback();
});
document.getElementById('confirmModal').addEventListener('click', function(e){
  if(e.target === this) closeConfirm();
});

// ===== 复制邮箱 =====
function copyEmail(email){
  if(navigator.clipboard){
    navigator.clipboard.writeText(email).then(function(){
      toast('success', '邮箱已复制：' + email);
    }).catch(function(){ toast('error', '复制失败，请手动复制'); });
  } else {
    var ta=document.createElement('textarea');ta.value=email;document.body.appendChild(ta);ta.select();
    try{document.execCommand('copy');toast('success', '邮箱已复制');}catch(e){ toast('error', '复制失败，请手动复制'); }
    document.body.removeChild(ta);
  }
}

// ===== 主题切换 =====
var themeToggle = document.getElementById('themeToggle');
var htmlEl = document.documentElement;
function updateThemeIcon(){
  var isDark = htmlEl.getAttribute('data-theme') === 'dark';
  themeToggle.innerHTML = '<i class="mdi ' + (isDark ? 'mdi-white-balance-sunny' : 'mdi-weather-night') + '"></i>';
}
updateThemeIcon();
themeToggle.addEventListener('click', function(){
  var isDark = htmlEl.getAttribute('data-theme') === 'dark';
  var newTheme = isDark ? 'light' : 'dark';
  htmlEl.setAttribute('data-theme', newTheme);
  document.cookie = 'theme=' + newTheme + ';path=/;max-age=31536000';
  updateThemeIcon();
});

// ===== 加载当前套餐信息 =====
function loadCurrentPackage(){
  fetch('../api/user_api.php?action=package_info&_t=' + Date.now())
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.code === 0 && d.data && !d.data.is_expired){
        _currentPkgLevel = d.data.level;
      }
      loadPricingPlans();
    })
    .catch(function(){ loadPricingPlans(); });
}

// ===== 加载套餐列表 =====
function loadPricingPlans(){
  fetch('../api/user_api.php?action=packages_list&_t=' + Date.now())
    .then(function(r){return r.json();})
    .then(function(d){
      var grid = document.getElementById('pricingGrid');
      if(!grid) return;
      if(d.code !== 0 || !d.data || !d.data.packages){
        grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-alert-circle-outline" style="font-size:36px;display:block;margin-bottom:12px;"></i>' + esc(d.msg || '加载失败') + '</div>';
        return;
      }
      var list = d.data.packages;
      if(!list.length){
        grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-package-variant-closed" style="font-size:48px;display:block;margin-bottom:12px;"></i>暂无可用套餐</div>';
        return;
      }
      var html = '';
      list.forEach(function(pkg, idx){
        var isCurrent = pkg.level === _currentPkgLevel;
        var icon = _pkgIcons[idx % _pkgIcons.length];
        var badgeHtml = pkg.level > 0
          ? '<span class="level-badge vip">VIP' + pkg.level + '</span>'
          : '<span class="level-badge free">免费</span>';
        var defaultHtml = pkg.is_default ? '<span class="default-tag">⭐ 默认</span>' : '';
        var recommendHtml = (pkg.level > 0 && idx === 1) ? '<span class="recommend-tag">最受欢迎</span>' : '';
        var featuresHtml = '';
        pkg.features.forEach(function(f){
          featuresHtml += '<li class="feat-li"><i class="mdi mdi-check-circle"></i>' + esc(f) + '</li>';
        });
        // 按钮逻辑
        var btnHtml = '';
        if(isCurrent){
          btnHtml = '<button class="pkg-btn current-btn" disabled><i class="mdi mdi-check-circle"></i> 当前套餐</button>';
        } else if(pkg.level <= _currentPkgLevel && _currentPkgLevel >= 0){
          btnHtml = '<button class="pkg-btn locked" disabled><i class="mdi mdi-lock"></i> 已拥有更高等级</button>';
        } else {
          btnHtml = '<button class="pkg-btn upgrade" onclick="scrollToRedeem()"><i class="mdi mdi-ticket-confirmation-outline"></i> 输入兑换码升级</button>';
        }
        html += '<div class="pricing-card' + (isCurrent ? ' current' : '') + '">' +
          recommendHtml +
          defaultHtml +
          '<div class="pkg-head">' +
            '<div><span class="pkg-name">' + esc(pkg.name) + '</span>' + badgeHtml + '</div>' +
            '<div class="pkg-icon"><i class="mdi ' + icon + '"></i></div>' +
          '</div>' +
          '<div class="pkg-desc">' + (pkg.level > 0 ? '<span class="hi">VIP' + pkg.level + ' 等级</span> · ' : '') + esc(pkg.storage_text) + ' · ' + esc(pkg.days_text) + '</div>' +
          '<ul class="feat-list">' + featuresHtml + '</ul>' +
          btnHtml +
        '</div>';
      });
      grid.innerHTML = html;
    })
    .catch(function(){
      var grid = document.getElementById('pricingGrid');
      if(grid) grid.innerHTML = '<div style="text-align:center;padding:48px;color:var(--muted);grid-column:1/-1;"><i class="mdi mdi-alert-circle-outline" style="font-size:36px;display:block;margin-bottom:12px;"></i>网络错误，请刷新重试</div>';
    });
}

// ===== 滚动到兑换码输入区 =====
function scrollToRedeem(){
  var el = document.querySelector('.redeem-box');
  if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
  var input = document.getElementById('redeemInput');
  if(input) setTimeout(function(){ input.focus(); }, 400);
}

// ===== 兑换码（含二次确认弹窗）=====
function doRedeem(){
  var code = document.getElementById('redeemInput').value.trim();
  if(!code){ toast('warning', '请输入兑换码'); return; }
  showConfirm('确认兑换', '确定要使用此兑换码兑换套餐吗？兑换后套餐等级将变更。', function(){
    var btn = document.getElementById('redeemBtn');
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 兑换中...';
    fetch('../api/user_api.php?action=redeem', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'code=' + encodeURIComponent(code) + '&csrf_token=' + CSRF_TOKEN
    })
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false;
      btn.innerHTML = origHtml;
      if(d.code === 0){
        toast('success', d.msg || '兑换成功', 2000);
        loadCurrentPackage();
        document.getElementById('redeemInput').value = '';
      } else {
        toast('error', d.msg || '兑换失败');
      }
    })
    .catch(function(){
      btn.disabled = false;
      btn.innerHTML = origHtml;
      toast('error', '网络错误，请重试');
    });
  });
}

// ===== FAQ 折叠 =====
document.querySelectorAll('.faq-item').forEach(function(item){
  var q = item.querySelector('.faq-q');
  q.addEventListener('click', function(){
    var isActive = item.classList.contains('active');
    document.querySelectorAll('.faq-item').forEach(function(i){ i.classList.remove('active'); });
    if(!isActive) item.classList.add('active');
  });
});

// ===== 回车提交兑换码 =====
document.getElementById('redeemInput').addEventListener('keydown', function(e){
  if(e.key === 'Enter'){ e.preventDefault(); doRedeem(); }
});

// ===== 初始化 =====
loadCurrentPackage();
</script>
</body>
</html>
