<?php
/**
 * @file index.php
 * @description 官网首页（静态设计适配真实系统），用户登录后进入用户中心
 */
require __DIR__ . '/inc/common.php';

$siteName = trim((string)($conf['name'] ?? ''));
if($siteName === '') $siteName = '图巢 PicNest';

$apiConfig  = get_api_config();
$apiCount   = count($apiConfig);
$regEnabled = is_registration_enabled();
$isAdmin    = isset($islogin) && $islogin == 1;
$isUser     = !empty($isUserLoggedIn);

$loginUrl      = 'user/login.php';
$registerUrl   = 'user/register.php';
$userCenterUrl = 'user/';
$apiDocUrl     = 'api_doc.php';
$pricingUrl    = 'user/pricing.php';

$apiBase = (isset($conf['site_url']) && $conf['site_url'] !== '')
    ? rtrim((string)$conf['site_url'], '/')
    : rtrim((string)$siteurl, '/');
$apiEndpoint = $apiBase . '/api/api_upload.php';

$fmtBytes = function($bytes) {
    $bytes = (int)$bytes;
    if($bytes < 0) return '不限';
    if($bytes >= 1073741824) return rtrim(rtrim(number_format($bytes / 1073741824, 2, '.', ''), '0'), '.') . ' GB';
    if($bytes >= 1048576) return round($bytes / 1048576) . ' MB';
    if($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
};

$defaultPkg = get_default_package($DB);
$defaultStorageText = $defaultPkg ? $fmtBytes((int)$defaultPkg['storage_limit']) : '以套餐为准';

$packages = [];
$pkgRs = $DB->query("SELECT name, level, storage_limit, days, is_default FROM eecms_packages WHERE status=1 ORDER BY level ASC, id ASC");
if($pkgRs) {
    while($row = $DB->fetch($pkgRs)) {
        $packages[] = $row;
    }
}
if(empty($packages)) {
    $packages[] = [
        'name' => '默认套餐',
        'level' => 0,
        'storage_limit' => $defaultPkg ? (int)$defaultPkg['storage_limit'] : 0,
        'days' => 0,
        'is_default' => 1,
    ];
}

$primaryCtaUrl  = $isUser ? $userCenterUrl : ($isAdmin ? $loginUrl : ($regEnabled ? $registerUrl : $loginUrl));
$primaryCtaText = $isUser ? '进入个人中心' : ($isAdmin ? '用户登录' : ($regEnabled ? '免费开始使用' : '登录后使用'));

$contactEmail = trim((string)($conf['email'] ?? ''));
$icp          = trim((string)($conf['icp'] ?? ''));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?> — 多图床聚合上传与图片管理</title>
<meta name="description" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?> 内置 <?php echo (int)$apiCount;?> 个图床接口，支持本地上传与自定义 S3 存储，提供网页上传、用户中心与 API 密钥上传。">
<link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars(site_favicon_href(), ENT_QUOTES, 'UTF-8');?>">
<style>
  /* ============================================================
     1. Design Tokens
     ============================================================ */
  :root {
    /* 中性色 */
    --ink: #0C1017;
    --ink-soft: #1B2230;
    --body: #4B5565;
    --muted: #6F7889;
    --line: #E7E9EE;
    --line-strong: #D9DDE6;
    --bg: #FFFFFF;
    --bg-soft: #F6F7FA;
    --bg-tint: #F1F2FC;

    /* 品牌色 */
    --brand: #4F46E5;
    --brand-deep: #4338CA;
    --brand-soft: #EEF0FF;
    --violet: #7C3AED;
    --cyan: #06B6D4;
    --green: #0E9F6E;
    --amber: #F59E0B;
    --grad: linear-gradient(112deg, #4F46E5 0%, #7C3AED 55%, #06B6D4 100%);
    --grad-soft: linear-gradient(140deg, #F4F4FF 0%, #F8F5FF 45%, #EFFBFF 100%);

    /* 字体 */
    --font: -apple-system, BlinkMacSystemFont, "PingFang SC", "HarmonyOS Sans SC",
      "Hiragino Sans GB", "Microsoft YaHei", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    --mono: "SF Mono", "JetBrains Mono", "Fira Code", ui-monospace, Menlo, Consolas, monospace;

    /* 圆角与阴影 */
    --r-sm: 8px;
    --r-md: 12px;
    --r-lg: 16px;
    --r-xl: 22px;
    --r-2xl: 28px;
    --r-full: 999px;
    --sh-sm: 0 1px 2px rgba(16, 24, 40, .05);
    --sh-card: 0 1px 2px rgba(16, 24, 40, .04), 0 10px 28px -16px rgba(16, 24, 40, .16);
    --sh-lift: 0 2px 4px rgba(16, 24, 40, .04), 0 28px 56px -28px rgba(30, 34, 80, .35);

    /* 动效 */
    --ease: cubic-bezier(.22, .61, .36, 1);
    --ease-out: cubic-bezier(0, 0, .2, 1);
  }

  /* ============================================================
     2. Reset & Base
     ============================================================ */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
  body {
    font-family: var(--font);
    font-size: 16px;
    line-height: 1.7;
    color: var(--ink);
    background: var(--bg);
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
    overflow-x: clip;
  }
  h1, h2, h3, h4 { font-weight: 800; line-height: 1.24; letter-spacing: -.02em; }
  p { color: var(--body); }
  a { color: inherit; text-decoration: none; }
  img, svg { display: block; }
  button { font: inherit; color: inherit; background: none; border: none; cursor: pointer; }
  ul { list-style: none; }
  kbd {
    font-family: var(--mono); font-size: .78em; padding: 2px 6px;
    border: 1px solid var(--line-strong); border-bottom-width: 2px;
    border-radius: 5px; background: #fff; color: var(--ink-soft); white-space: nowrap;
  }
  :focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; border-radius: 4px; }
  ::selection { background: rgba(79, 70, 229, .16); }

  .container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 24px; }
  .section { padding: 108px 0; position: relative; }
  .section--soft { background: var(--bg-soft); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
  .section[id], section[id] { scroll-margin-top: 88px; }

  /* 区块标题 */
  .sec-head { max-width: 660px; margin: 0 auto 60px; text-align: center; }
  .sec-head--left { margin-left: 0; text-align: left; }
  .eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: var(--brand); margin-bottom: 18px;
  }
  .eyebrow::before {
    content: ""; width: 18px; height: 2px; border-radius: 2px; background: var(--grad);
  }
  .sec-head h2 { font-size: clamp(1.85rem, 3.4vw, 2.6rem); margin-bottom: 16px; }
  .sec-head p { font-size: 1.0625rem; line-height: 1.8; }

  /* 按钮 */
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    padding: 13px 24px; border-radius: var(--r-md);
    font-size: .95rem; font-weight: 650; letter-spacing: -.01em;
    transition: transform .18s var(--ease), box-shadow .24s var(--ease),
                background-color .18s var(--ease), border-color .18s var(--ease), color .18s var(--ease);
    white-space: nowrap;
  }
  .btn svg { width: 17px; height: 17px; flex: none; }
  .btn--primary {
    color: #fff; background: var(--brand);
    box-shadow: 0 1px 2px rgba(16, 24, 40, .12), 0 10px 24px -10px rgba(79, 70, 229, .6);
  }
  .btn--primary:hover {
    background: var(--brand-deep); transform: translateY(-2px);
    box-shadow: 0 2px 4px rgba(16, 24, 40, .1), 0 18px 34px -14px rgba(79, 70, 229, .7);
  }
  .btn--ghost {
    color: var(--ink); background: #fff; border: 1px solid var(--line-strong);
    box-shadow: var(--sh-sm);
  }
  .btn--ghost:hover { border-color: #BFC5D2; transform: translateY(-2px); box-shadow: var(--sh-card); }
  .btn--lg { padding: 15px 28px; font-size: 1rem; border-radius: 14px; }
  .btn--white { background: #fff; color: var(--ink); box-shadow: 0 10px 30px -12px rgba(0, 0, 0, .45); }
  .btn--white:hover { transform: translateY(-2px); box-shadow: 0 18px 40px -14px rgba(0, 0, 0, .5); }
  .btn--onDark {
    color: #fff; border: 1px solid rgba(255, 255, 255, .28); background: rgba(255, 255, 255, .08);
    backdrop-filter: blur(8px);
  }
  .btn--onDark:hover { background: rgba(255, 255, 255, .16); border-color: rgba(255, 255, 255, .45); transform: translateY(-2px); }

  /* 滚动进场 */
  .reveal { opacity: 0; transform: translateY(22px); transition: opacity .7s var(--ease-out), transform .7s var(--ease-out); }
  .reveal.is-in { opacity: 1; transform: none; }

  /* ============================================================
     3. 顶部公告条
     ============================================================ */
  .announce {
    background: #0B0F18;
    color: #C9D0DE;
    font-size: 13px;
    letter-spacing: .01em;
  }
  .announce-inner {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 9px 24px; flex-wrap: wrap; text-align: center;
  }
  .pill-new {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 2px 9px; border-radius: var(--r-full);
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    color: #C7C9FF; background: rgba(99, 102, 241, .18);
    border: 1px solid rgba(129, 140, 248, .3);
  }
  .announce a { color: #fff; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
  .announce a:hover { color: #C7C9FF; }
  .announce a svg { width: 13px; height: 13px; }

  /* ============================================================
     4. 导航
     ============================================================ */
  .nav {
    position: sticky; top: 0; z-index: 90;
    background: rgba(255, 255, 255, .82);
    backdrop-filter: saturate(180%) blur(16px);
    -webkit-backdrop-filter: saturate(180%) blur(16px);
    border-bottom: 1px solid transparent;
    transition: border-color .25s var(--ease), box-shadow .25s var(--ease), background-color .25s var(--ease);
  }
  .nav.is-stuck { border-bottom-color: var(--line); box-shadow: 0 8px 24px -18px rgba(16, 24, 40, .5); }
  .nav-inner {
    max-width: 1200px; margin: 0 auto; padding: 0 24px;
    height: 68px; display: flex; align-items: center; justify-content: space-between; gap: 24px;
  }
  .logo { display: inline-flex; align-items: center; gap: 10px; flex: none; }
  .logo-mark {
    width: 32px; height: 32px; border-radius: 9px;
    background: var(--grad); display: grid; place-items: center;
    box-shadow: 0 6px 14px -6px rgba(79, 70, 229, .8);
  }
  .logo-mark svg { width: 19px; height: 19px; }
  .logo-text { font-size: 17px; font-weight: 800; letter-spacing: -.03em; }
  .logo-text em {
    font-style: normal; font-size: 12px; font-weight: 600; color: var(--muted);
    letter-spacing: .06em; margin-left: 6px; text-transform: uppercase;
  }
  .nav-links { display: flex; align-items: center; gap: 4px; margin-left: 12px; margin-right: auto; }
  .nav-links a {
    padding: 8px 12px; border-radius: var(--r-sm);
    font-size: 14px; font-weight: 550; color: var(--body);
    transition: color .16s, background-color .16s;
  }
  .nav-links a:hover { color: var(--ink); background: var(--bg-soft); }
  .nav-actions { display: flex; align-items: center; gap: 8px; flex: none; }
  .nav-login { padding: 9px 14px; font-size: 14px; font-weight: 600; color: var(--body); border-radius: var(--r-sm); }
  .nav-login:hover { color: var(--ink); background: var(--bg-soft); }
  .nav-actions .btn { padding: 9px 16px; font-size: 13.5px; border-radius: 10px; }
  .nav-toggle { display: none; width: 40px; height: 40px; border-radius: 10px; place-items: center; }
  .nav-toggle:hover { background: var(--bg-soft); }
  .nav-toggle span { display: block; width: 18px; height: 1.8px; background: var(--ink); border-radius: 2px; transition: transform .3s var(--ease), opacity .2s; }
  .nav-toggle span + span { margin-top: 4px; }
  .nav-toggle[aria-expanded="true"] span:nth-child(1) { transform: translateY(5.8px) rotate(45deg); }
  .nav-toggle[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
  .nav-toggle[aria-expanded="true"] span:nth-child(3) { transform: translateY(-5.8px) rotate(-45deg); }
  .nav-mobile {
    display: none; padding: 8px 24px 22px;
    border-top: 1px solid var(--line); background: #fff;
  }
  .nav-mobile.is-open { display: block; }
  .nav-mobile a {
    display: block; padding: 13px 4px; font-size: 15px; font-weight: 550;
    color: var(--ink-soft); border-bottom: 1px solid var(--line);
  }
  .nav-mobile .btn { width: 100%; margin-top: 16px; }

  /* ============================================================
     5. Hero
     ============================================================ */
  .hero {
    position: relative; overflow: hidden;
    padding: 84px 0 92px;
    background:
      radial-gradient(900px 480px at 82% -8%, rgba(124, 58, 237, .13), transparent 62%),
      radial-gradient(760px 420px at 6% 4%, rgba(6, 182, 212, .10), transparent 60%),
      linear-gradient(180deg, #FBFBFF 0%, #FFFFFF 72%);
  }
  .hero::before {
    content: ""; position: absolute; inset: 0;
    background-image: linear-gradient(to right, rgba(15, 23, 42, .045) 1px, transparent 1px),
                      linear-gradient(to bottom, rgba(15, 23, 42, .045) 1px, transparent 1px);
    background-size: 68px 68px;
    mask-image: radial-gradient(620px 340px at 50% 0%, #000 0%, transparent 78%);
    -webkit-mask-image: radial-gradient(620px 340px at 50% 0%, #000 0%, transparent 78%);
    pointer-events: none;
  }
  .hero-inner {
    position: relative; z-index: 1;
    display: grid; grid-template-columns: minmax(0, 1.02fr) minmax(0, .98fr);
    gap: 56px; align-items: center;
  }
  .hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 6px 6px 12px; border-radius: var(--r-full);
    background: #fff; border: 1px solid var(--line);
    box-shadow: 0 2px 8px -4px rgba(16, 24, 40, .16);
    font-size: 12.5px; font-weight: 600; color: var(--ink-soft);
    margin-bottom: 26px;
  }
  .hero-badge .tag {
    padding: 2px 8px; border-radius: var(--r-full);
    background: var(--grad); color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .04em;
  }
  .hero h1 {
    font-size: clamp(2.35rem, 4.5vw, 3.55rem);
    line-height: 1.14; letter-spacing: -.035em; margin-bottom: 22px;
  }
  .grad-text {
    background: var(--grad);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent; color: var(--brand);
  }
  .hero-sub {
    font-size: 1.0625rem; line-height: 1.85; color: var(--body);
    max-width: 520px; margin-bottom: 32px;
  }
  .hero-sub strong { color: var(--ink-soft); font-weight: 650; }
  .hero-cta { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 26px; }
  .hero-note { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 13px; color: var(--muted); }
  .hero-note span { display: inline-flex; align-items: center; gap: 6px; }
  .hero-note svg { width: 14px; height: 14px; color: var(--green); flex: none; }

  /* Hero 右侧控制台 */
  .hero-visual { position: relative; }
  .hero-visual::before {
    content: ""; position: absolute; inset: 10% -5% -9% 6%; z-index: 0;
    background: linear-gradient(135deg, rgba(79, 70, 229, .4), rgba(6, 182, 212, .34));
    filter: blur(42px); opacity: .55; border-radius: 44px;
  }
  .console {
    position: relative; z-index: 2;
    background: #fff; border: 1px solid var(--line);
    border-radius: var(--r-xl); overflow: hidden;
    box-shadow: 0 2px 6px rgba(16, 24, 40, .05), 0 40px 80px -40px rgba(40, 44, 90, .45);
  }
  .console-head {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px; border-bottom: 1px solid var(--line);
    background: linear-gradient(180deg, #FCFCFE, #F7F8FC);
  }
  .dots { display: flex; gap: 5px; }
  .dots i { width: 8px; height: 8px; border-radius: 50%; background: #E1E4EC; }
  .dots i:first-child { background: #FCA5A5; }
  .dots i:nth-child(2) { background: #FCD34D; }
  .dots i:nth-child(3) { background: #86EFAC; }
  .console-url {
    flex: 1; text-align: center; font-family: var(--mono); font-size: 11px;
    color: var(--muted); background: #fff; border: 1px solid var(--line);
    border-radius: 6px; padding: 3px 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .console-secure { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: var(--green); letter-spacing: .04em; }
  .console-secure svg { width: 11px; height: 11px; }

  .dropzone {
    margin: 18px 18px 0; padding: 26px 18px;
    border: 1.5px dashed #CFD4E4; border-radius: var(--r-lg);
    background: linear-gradient(180deg, #FAFAFF, #F8F9FE 100%);
    text-align: center; transition: border-color .2s, background-color .2s;
  }
  .dropzone:hover { border-color: #A5AAFF; background: #F7F7FF; }
  .dz-icon {
    width: 42px; height: 42px; margin: 0 auto 12px; border-radius: 12px;
    display: grid; place-items: center; color: var(--brand);
    background: #fff; border: 1px solid #E4E4FB;
    box-shadow: 0 8px 18px -12px rgba(79, 70, 229, .7);
  }
  .dz-icon svg { width: 21px; height: 21px; }
  .dz-title { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
  .dz-sub { font-size: 11.5px; color: var(--muted); }

  .filelist { padding: 14px 18px 4px; display: grid; gap: 10px; }
  .file {
    display: grid; grid-template-columns: 32px 1fr auto; align-items: center; gap: 10px;
    padding: 8px; border-radius: 10px; border: 1px solid var(--line);
    background: #fff; transition: border-color .2s, box-shadow .2s;
  }
  .file:hover { border-color: #D5D8E8; box-shadow: var(--sh-sm); }
  .file-thumb { width: 32px; height: 32px; border-radius: 7px; background-image: var(--img); background-size: cover; }
  .file-name {
    font-size: 11.5px; font-weight: 650; color: var(--ink-soft);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .file-meta { font-family: var(--mono); font-size: 10px; color: var(--muted); }
  .bar { height: 3px; border-radius: 3px; background: #EEF0F6; overflow: hidden; margin-top: 5px; }
  .bar span {
    display: block; height: 100%; width: var(--w); border-radius: 3px;
    background: var(--grad); transform-origin: left; animation: fill 1.5s var(--ease-out) both;
  }
  @keyframes fill { from { transform: scaleX(0); } to { transform: scaleX(1); } }
  .file-state {
    font-size: 10px; font-weight: 700; color: var(--green);
    display: inline-flex; align-items: center; gap: 3px;
  }
  .file-state svg { width: 11px; height: 11px; }
  .file-state.is-syncing { color: var(--brand); }
  .file-state .spin { width: 10px; height: 10px; border: 1.5px solid #C7C9FF; border-top-color: var(--brand); border-radius: 50%; animation: spin .9s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }

  .console-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 12px 18px 15px; margin-top: 10px; border-top: 1px solid var(--line);
    background: #FCFCFE;
  }
  .chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: var(--r-full);
    font-size: 10.5px; font-weight: 600; color: var(--body);
    background: #fff; border: 1px solid var(--line); white-space: nowrap;
  }
  .chip svg { width: 11px; height: 11px; color: var(--brand); }
  .chip--ok { color: #067A55; background: #ECFDF5; border-color: #C7F0DF; }
  .chip--ok svg { color: var(--green); }

  /* 浮动小卡 */
  .float-card {
    position: absolute; z-index: 3;
    display: flex; align-items: center; gap: 8px;
    padding: 9px 13px; border-radius: 12px;
    background: rgba(255, 255, 255, .94);
    border: 1px solid var(--line);
    box-shadow: 0 14px 34px -18px rgba(16, 24, 40, .4);
    backdrop-filter: blur(10px);
    font-size: 11.5px; font-weight: 650; color: var(--ink-soft);
    animation: bob 6s ease-in-out infinite;
  }
  .float-card svg { width: 14px; height: 14px; }
  .float-a { top: -18px; left: -26px; }
  .float-a .live { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 0 3px rgba(14, 159, 110, .16); }
  .float-b { bottom: -20px; right: -18px; animation-delay: -3s; color: var(--ink); }
  .float-b svg { color: var(--brand); }
  @keyframes bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

  /* ============================================================
     6. 存储源跑马灯
     ============================================================ */
  .providers { padding: 40px 0 44px; border-bottom: 1px solid var(--line); background: #fff; }
  .providers-label {
    text-align: center; font-size: 12.5px; font-weight: 600; color: var(--muted);
    letter-spacing: .04em; margin-bottom: 26px;
  }
  .marquee { overflow: hidden; mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent); }
  .marquee-track { display: flex; width: max-content; animation: slide 34s linear infinite; }
  .marquee:hover .marquee-track { animation-play-state: paused; }
  @keyframes slide { to { transform: translateX(-50%); } }
  .prov-set { display: flex; align-items: center; gap: 46px; padding-right: 46px; }
  .prov {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 15px; font-weight: 700; color: #97A0AF;
    letter-spacing: -.01em; white-space: nowrap;
    transition: color .2s;
  }
  .prov:hover { color: var(--ink); }
  .prov i { width: 6px; height: 6px; border-radius: 2px; background: currentColor; opacity: .55; }

  /* ============================================================
     7. 数据条
     ============================================================ */
  .stats { padding: 54px 0; background: #fff; }
  .stats-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    border: 1px solid var(--line); border-radius: var(--r-xl);
    background: linear-gradient(180deg, #FDFDFF, #FAFAFD);
    overflow: hidden;
  }
  .stat { padding: 30px 26px; border-left: 1px solid var(--line); }
  .stat:first-child { border-left: none; }
  .stat-num {
    font-size: clamp(1.7rem, 2.7vw, 2.15rem); font-weight: 800; letter-spacing: -.04em;
    line-height: 1.1; color: var(--ink); font-variant-numeric: tabular-nums;
    display: flex; align-items: baseline; gap: 2px;
  }
  .stat-num em { font-style: normal; font-size: .55em; font-weight: 700; color: var(--brand); }
  .stat-label { margin-top: 8px; font-size: 13px; color: var(--body); }

  /* ============================================================
     8. Bento 功能区
     ============================================================ */
  .bento {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 18px;
  }
  .bcard {
    grid-column: span 2;
    position: relative; overflow: hidden;
    padding: 28px; border-radius: var(--r-xl);
    background: #fff; border: 1px solid var(--line);
    box-shadow: var(--sh-sm);
    transition: transform .3s var(--ease), box-shadow .3s var(--ease), border-color .3s;
    display: flex; flex-direction: column;
  }
  .bcard:hover { transform: translateY(-3px); box-shadow: var(--sh-lift); border-color: #DFE2EE; }
  .bcard--4 { grid-column: span 4; }
  .bcard--3 { grid-column: span 3; }
  .bcard--accent { background: var(--grad-soft); border-color: #E4E3FA; }
  .bcard-icon {
    width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center;
    background: var(--brand-soft); color: var(--brand); margin-bottom: 18px;
    border: 1px solid #E3E3FC;
  }
  .bcard-icon svg { width: 18px; height: 18px; }
  .bcard h3 { font-size: 1.0625rem; font-weight: 750; margin-bottom: 8px; letter-spacing: -.015em; }
  .bcard p { font-size: 13.5px; line-height: 1.75; color: var(--body); }

  /* 卡片内可视化：存储节点 */
  .nodes {
    margin-top: 20px; display: grid; gap: 7px; padding: 12px;
    border-radius: 12px; background: #fff; border: 1px solid var(--line);
    box-shadow: var(--sh-sm);
  }
  .node {
    display: flex; align-items: center; gap: 9px;
    font-size: 11.5px; font-weight: 600; color: var(--body);
    padding: 6px 8px; border-radius: 7px;
  }
  .node.is-active { background: #F6F6FF; color: var(--ink); box-shadow: inset 0 0 0 1px #E6E5FC; }
  .node-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); flex: none; }
  .node-dot.warn { background: var(--amber); }
  .node-dot.off { background: #CBD2DE; }
  .node-name { flex: 1; }
  .node-latency { font-family: var(--mono); font-size: 10px; color: var(--muted); }
  .node-flag {
    font-size: 9px; font-weight: 800; letter-spacing: .06em; color: var(--brand);
    background: #EEEEFF; padding: 1px 6px; border-radius: 4px;
  }

  /* 卡片内可视化：压缩对比 */
  .compress { margin-top: 20px; display: grid; gap: 9px; }
  .cbar-row { display: flex; align-items: center; gap: 8px; font-size: 10.5px; color: var(--muted); font-family: var(--mono); }
  .cbar { height: 7px; border-radius: 4px; background: #EDEFF5; flex: 1; overflow: hidden; }
  .cbar i { display: block; height: 100%; border-radius: 4px; width: var(--w); }
  .cbar i.raw { background: #D8DCE6; }
  .cbar i.out { background: var(--grad); }
  .save-badge {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 4px; align-self: flex-start;
    font-size: 11px; font-weight: 700; color: #067A55;
    background: #ECFDF5; border: 1px solid #C7F0DF; padding: 3px 9px; border-radius: var(--r-full);
  }

  /* 卡片内可视化：代码 */
  .mini-code {
    margin-top: 20px; border-radius: 12px; overflow: hidden;
    background: #0D1117; border: 1px solid #1E2532;
    font-family: var(--mono); font-size: 10.5px; line-height: 1.9;
  }
  .mini-code-bar { display: flex; gap: 4px; padding: 7px 10px; border-bottom: 1px solid #1B2130; }
  .mini-code-bar i { width: 6px; height: 6px; border-radius: 50%; background: #2C3444; }
  .mini-code pre { padding: 10px 12px; overflow: hidden; color: #C9D1D9; }
  .mini-code .k { color: #C792EA; }
  .mini-code .s { color: #9AE6B4; }
  .mini-code .c { color: #5B6474; }
  .mini-code .f { color: #82AAFF; }
  .mini-code .p { color: #7D8799; }

  /* 卡片内可视化：域名标签 */
  .domain-stack { margin-top: 20px; display: grid; gap: 8px; }
  .domain-chip {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 12px; border-radius: 10px; background: #fff;
    border: 1px solid var(--line); font-family: var(--mono); font-size: 11px; color: var(--ink-soft);
    box-shadow: var(--sh-sm);
  }
  .domain-chip svg { width: 13px; height: 13px; color: var(--green); flex: none; }
  .domain-chip span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .domain-chip b { font-family: var(--font); font-size: 9px; font-weight: 800; letter-spacing: .05em; color: var(--muted); }

  /* 卡片内可视化：防护标签 */
  .guard-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 18px; }
  .guard-tags span {
    font-size: 10.5px; font-weight: 600; color: var(--body);
    padding: 4px 9px; border-radius: 6px; background: var(--bg-soft);
    border: 1px solid var(--line);
  }

  /* ============================================================
     9. 三步接入
     ============================================================ */
  .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; counter-reset: step; }
  .step {
    position: relative; padding: 30px 26px 28px;
    border-radius: var(--r-xl); background: #fff;
    border: 1px solid var(--line); box-shadow: var(--sh-sm);
  }
  .step::after {
    content: ""; position: absolute; top: 46px; right: -14px; width: 8px; height: 8px;
    border-top: 1.5px solid var(--line-strong); border-right: 1.5px solid var(--line-strong);
    transform: rotate(45deg);
  }
  .step:last-child::after { display: none; }
  .step-num {
    font-family: var(--mono); font-size: 11px; font-weight: 700; letter-spacing: .1em;
    color: var(--brand); background: var(--brand-soft);
    display: inline-block; padding: 3px 9px; border-radius: 6px; margin-bottom: 16px;
  }
  .step h3 { font-size: 1.0625rem; margin-bottom: 8px; }
  .step p { font-size: 13.5px; line-height: 1.75; }
  .step-code {
    margin-top: 16px; display: inline-flex; align-items: center; gap: 7px;
    font-family: var(--mono); font-size: 11px; color: var(--ink-soft);
    background: var(--bg-soft); border: 1px solid var(--line);
    padding: 6px 10px; border-radius: 7px;
  }
  .step-code i { width: 5px; height: 5px; border-radius: 50%; background: var(--green); }

  /* ============================================================
     10. API 区
     ============================================================ */
  .api-wrap { display: grid; grid-template-columns: .9fr 1.1fr; gap: 56px; align-items: center; }
  .api-copy h2 { font-size: clamp(1.8rem, 3.2vw, 2.4rem); margin-bottom: 16px; }
  .api-copy > p { font-size: 1rem; line-height: 1.85; margin-bottom: 26px; }
  .api-list { display: grid; gap: 14px; margin-bottom: 30px; }
  .api-list li { display: flex; gap: 12px; align-items: flex-start; }
  .api-list .tick {
    width: 20px; height: 20px; border-radius: 50%; flex: none; margin-top: 2px;
    display: grid; place-items: center; background: #ECFDF5; color: #067A55;
    border: 1px solid #C7F0DF;
  }
  .api-list .tick svg { width: 11px; height: 11px; }
  .api-list strong { display: block; font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 1px; }
  .api-list span { font-size: 13px; color: var(--body); line-height: 1.65; }
  .api-clients { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--muted); }
  .api-clients b {
    font-weight: 650; font-size: 11.5px; color: var(--ink-soft);
    padding: 3px 9px; border-radius: 6px; background: #fff; border: 1px solid var(--line);
  }

  .codebox {
    border-radius: var(--r-lg); overflow: hidden;
    background: #0B0F18; border: 1px solid #1B2230;
    box-shadow: 0 30px 60px -34px rgba(11, 15, 24, .7);
  }
  .codebox-head {
    display: flex; align-items: center; gap: 6px;
    padding: 9px 12px; border-bottom: 1px solid #1B2230; background: #0E131D;
  }
  .tabs { display: flex; gap: 2px; }
  .tab {
    padding: 6px 12px; border-radius: 7px;
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    color: #77839A; transition: color .16s, background-color .16s;
  }
  .tab:hover { color: #C9D1D9; background: rgba(255, 255, 255, .05); }
  .tab[aria-selected="true"] { color: #fff; background: rgba(255, 255, 255, .1); }
  .copy-btn {
    margin-left: auto; display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 9px; border-radius: 6px; font-size: 10.5px; font-weight: 600;
    color: #8B97AC; border: 1px solid #232B3A; transition: all .16s;
  }
  .copy-btn:hover { color: #fff; border-color: #3A465C; background: rgba(255, 255, 255, .05); }
  .copy-btn svg { width: 11px; height: 11px; }
  .code-body { position: relative; }
  .code-panel { display: none; padding: 18px 20px 22px; overflow-x: auto; }
  .code-panel.is-active { display: block; }
  .code-panel pre { font-family: var(--mono); font-size: 12px; line-height: 1.95; color: #C9D1D9; }
  .code-panel .k { color: #C792EA; }
  .code-panel .s { color: #9AE6B4; }
  .code-panel .c { color: #5B6474; font-style: italic; }
  .code-panel .f { color: #82AAFF; }
  .code-panel .n { color: #F78C6C; }
  .code-panel .p { color: #7D8799; }
  .code-panel .v { color: #E2E8F0; }

  /* ============================================================
     11. 图片流
     ============================================================ */
  .flow { overflow: hidden; }
  .flow-rows { display: grid; gap: 16px; margin-top: 12px; }
  .flow-row {
    overflow: hidden;
    mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
  }
  .flow-track { display: flex; width: max-content; gap: 16px; animation: slideFlow 48s linear infinite; }
  .flow-row--rev .flow-track { animation-direction: reverse; animation-duration: 58s; }
  .flow-row:hover .flow-track { animation-play-state: paused; }
  /* 位移 = 半组宽度 + 半个 gap，保证无缝循环 */
  @keyframes slideFlow { to { transform: translateX(calc(-50% - 8px)); } }
  .shot {
    position: relative; width: 236px; height: 158px; flex: none;
    border-radius: 14px; overflow: hidden;
    border: 1px solid rgba(16, 24, 40, .08);
    transition: transform .35s var(--ease), box-shadow .35s var(--ease);
  }
  .shot:hover { transform: scale(1.03); box-shadow: 0 22px 40px -20px rgba(16, 24, 40, .5); }
  .shot::before { content: ""; position: absolute; inset: 0; background-image: var(--img); }
  .shot::after {
    content: ""; position: absolute; inset: 0;
    background: linear-gradient(180deg, transparent 40%, rgba(8, 12, 20, .72) 100%);
    opacity: .9;
  }
  .shot-info {
    position: absolute; left: 12px; right: 12px; bottom: 10px; z-index: 1;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
  }
  .shot-name {
    font-family: var(--mono); font-size: 10.5px; color: rgba(255, 255, 255, .95);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500;
  }
  .shot-tag {
    font-size: 9px; font-weight: 800; letter-spacing: .05em;
    color: #0B0F18; background: rgba(255, 255, 255, .92);
    padding: 2px 6px; border-radius: 4px; flex: none;
  }
  .flow-note { text-align: center; font-size: 13px; color: var(--muted); margin-top: 36px; }
  .flow-note b { color: var(--ink-soft); font-weight: 650; }

  /* 抽象图渐变 */
  .g1 { --img: radial-gradient(circle at 22% 18%, #FDE68A, transparent 55%), radial-gradient(circle at 78% 72%, #FB7185, transparent 58%), linear-gradient(135deg, #6366F1, #0EA5E9); }
  .g2 { --img: radial-gradient(circle at 70% 22%, #A7F3D0, transparent 55%), radial-gradient(circle at 24% 82%, #38BDF8, transparent 52%), linear-gradient(160deg, #064E3B, #065F46); }
  .g3 { --img: radial-gradient(circle at 30% 74%, #FDBA74, transparent 52%), radial-gradient(circle at 80% 26%, #F472B6, transparent 56%), linear-gradient(135deg, #4C1D95, #1E1B4B); }
  .g4 { --img: radial-gradient(circle at 26% 24%, #BFDBFE, transparent 52%), radial-gradient(circle at 74% 78%, #C4B5FD, transparent 56%), linear-gradient(135deg, #1E3A8A, #312E81); }
  .g5 { --img: radial-gradient(circle at 68% 30%, #FEF08A, transparent 50%), radial-gradient(circle at 22% 80%, #F97316, transparent 54%), linear-gradient(150deg, #7F1D1D, #B45309); }
  .g6 { --img: radial-gradient(circle at 32% 30%, #99F6E4, transparent 55%), radial-gradient(circle at 76% 74%, #6EE7B7, transparent 52%), linear-gradient(140deg, #0F766E, #134E4A); }
  .g7 { --img: radial-gradient(circle at 74% 24%, #DDD6FE, transparent 54%), radial-gradient(circle at 20% 76%, #F9A8D4, transparent 52%), linear-gradient(135deg, #312E81, #701A75); }
  .g8 { --img: radial-gradient(circle at 24% 30%, #FECDD3, transparent 52%), radial-gradient(circle at 72% 72%, #FDA4AF, transparent 56%), linear-gradient(130deg, #881337, #4C0519); }
  .g9 { --img: radial-gradient(circle at 64% 26%, #BAE6FD, transparent 54%), radial-gradient(circle at 26% 74%, #93C5FD, transparent 52%), linear-gradient(135deg, #1D4ED8, #172554); }
  .g10 { --img: radial-gradient(circle at 30% 26%, #FEF3C7, transparent 55%), radial-gradient(circle at 76% 76%, #D9F99D, transparent 50%), linear-gradient(135deg, #3F6212, #14532D); }
  .g11 { --img: radial-gradient(circle at 70% 30%, #E9D5FF, transparent 54%), radial-gradient(circle at 28% 78%, #A78BFA, transparent 54%), linear-gradient(150deg, #4C1D95, #1E3A8A); }
  .g12 { --img: radial-gradient(circle at 26% 26%, #FBCFE8, transparent 52%), radial-gradient(circle at 74% 74%, #FDBA74, transparent 52%), linear-gradient(135deg, #9D174D, #7C2D12); }

  /* ============================================================
     12. 价格
     ============================================================ */
  .price-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; align-items: start; }
  .price-grid--two { grid-template-columns: repeat(2, minmax(0, 380px)); justify-content: center; }
  .plan {
    position: relative; padding: 30px 26px 28px;
    background: #fff; border: 1px solid var(--line); border-radius: var(--r-xl);
    box-shadow: var(--sh-sm);
    transition: transform .3s var(--ease), box-shadow .3s var(--ease);
  }
  .plan:hover { transform: translateY(-3px); box-shadow: var(--sh-lift); }
  .plan--pro {
    background: linear-gradient(180deg, #12141C 0%, #0B0F18 100%);
    border-color: #232A3A; color: #fff;
    box-shadow: 0 30px 60px -32px rgba(11, 15, 24, .8);
  }
  .plan--pro h3, .plan--pro .plan-price { color: #fff; }
  .plan--pro .plan-desc, .plan--pro .plan-features li { color: #A6B0C3; }
  .plan--pro .plan-features li svg { color: #7DE2B8; }
  .plan-badge {
    position: absolute; top: -11px; right: 22px;
    font-size: 10px; font-weight: 800; letter-spacing: .06em;
    color: #fff; background: var(--grad); padding: 4px 10px; border-radius: var(--r-full);
    box-shadow: 0 8px 18px -8px rgba(79, 70, 229, .9);
  }
  .plan-name { font-size: 12px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--brand); margin-bottom: 10px; }
  .plan--pro .plan-name { color: #A5B4FC; }
  .plan h3 { font-size: 1.25rem; margin-bottom: 6px; letter-spacing: -.02em; }
  .plan-desc { font-size: 13px; color: var(--body); margin-bottom: 22px; min-height: 20px; }
  .plan-price { display: flex; align-items: baseline; gap: 4px; margin-bottom: 4px; }
  .plan-price b { font-size: 2.4rem; font-weight: 800; letter-spacing: -.04em; line-height: 1; }
  .plan-price span { font-size: 13px; color: var(--muted); font-weight: 500; }
  .plan--pro .plan-price span { color: #7A879E; }
  .plan--pro .plan-year { color: #7A879E; }
  .plan-year { font-size: 11.5px; color: var(--muted); margin-bottom: 22px; }
  .plan .btn { width: 100%; margin-bottom: 24px; }
  .plan-features { display: grid; gap: 11px; padding-top: 22px; border-top: 1px solid var(--line); }
  .plan--pro .plan-features { border-top-color: #232A3A; }
  .plan-features li { display: flex; gap: 9px; align-items: flex-start; font-size: 13px; color: var(--body); line-height: 1.6; }
  .plan-features svg { width: 14px; height: 14px; flex: none; margin-top: 2px; color: var(--brand); }
  .plan-features .off { color: var(--muted); opacity: .65; }
  .plan-features .off svg { color: #CBD2DE; }

  /* ============================================================
     13. FAQ
     ============================================================ */
  .faq-wrap { display: grid; grid-template-columns: .72fr 1.28fr; gap: 56px; align-items: start; }
  .faq-list { border-top: 1px solid var(--line); }
  .faq-item { border-bottom: 1px solid var(--line); }
  .faq-q {
    width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 20px;
    padding: 21px 2px; text-align: left;
    font-size: 15px; font-weight: 650; color: var(--ink); letter-spacing: -.01em;
    transition: color .16s;
  }
  .faq-q:hover { color: var(--brand); }
  .faq-q .ico {
    position: relative; width: 20px; height: 20px; flex: none;
    border-radius: 50%; border: 1px solid var(--line-strong);
    transition: transform .3s var(--ease), border-color .2s, background-color .2s;
  }
  .faq-q .ico::before, .faq-q .ico::after {
    content: ""; position: absolute; top: 50%; left: 50%; background: var(--body);
    transform: translate(-50%, -50%); transition: background-color .2s, opacity .2s;
  }
  .faq-q .ico::before { width: 9px; height: 1.4px; }
  .faq-q .ico::after { width: 1.4px; height: 9px; }
  .faq-item[open] .faq-q .ico { transform: rotate(90deg); background: var(--brand); border-color: var(--brand); }
  .faq-item[open] .faq-q .ico::before, .faq-item[open] .faq-q .ico::after { background: #fff; }
  .faq-item[open] .faq-q .ico::after { opacity: 0; }
  .faq-a { padding: 0 44px 22px 2px; }
  .faq-a p { font-size: 13.5px; line-height: 1.85; }
  .faq-a code {
    font-family: var(--mono); font-size: .92em; background: var(--bg-soft);
    padding: 1px 5px; border-radius: 4px; color: var(--ink-soft);
  }
  summary::-webkit-details-marker { display: none; }
  summary { list-style: none; }

  /* ============================================================
     14. CTA
     ============================================================ */
  .cta-band { padding: 0 0 108px; }
  .cta-inner {
    position: relative; overflow: hidden; border-radius: var(--r-2xl);
    padding: 64px 56px;
    background: linear-gradient(120deg, #171A34 0%, #241B4D 46%, #0B3B4A 100%);
    box-shadow: 0 50px 90px -50px rgba(23, 26, 52, .9);
  }
  .cta-inner::before {
    content: ""; position: absolute; inset: -40% -10% auto auto; width: 620px; height: 620px;
    background: radial-gradient(circle, rgba(124, 58, 237, .55), transparent 62%);
    filter: blur(20px);
  }
  .cta-inner::after {
    content: ""; position: absolute; inset: auto auto -50% -12%; width: 560px; height: 560px;
    background: radial-gradient(circle, rgba(6, 182, 212, .35), transparent 62%);
    filter: blur(20px);
  }
  .cta-content { position: relative; z-index: 1; max-width: 560px; }
  .cta-content .eyebrow { color: #A5B4FC; }
  .cta-content h2 { font-size: clamp(1.9rem, 3.4vw, 2.6rem); color: #fff; margin-bottom: 14px; }
  .cta-content p { font-size: 1rem; line-height: 1.8; color: #B9C2D6; margin-bottom: 30px; }
  .cta-actions { display: flex; flex-wrap: wrap; gap: 12px; }
  .cta-meta { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 24px; font-size: 12.5px; color: #8894AB; }
  .cta-meta span { display: inline-flex; align-items: center; gap: 6px; }
  .cta-meta svg { width: 13px; height: 13px; color: #7DE2B8; }

  /* ============================================================
     15. Footer
     ============================================================ */
  .footer { background: #0A0E16; color: #8C97AB; padding: 72px 0 0; }
  .footer-top { display: grid; grid-template-columns: 1.6fr repeat(4, 1fr); gap: 40px; padding-bottom: 56px; }
  .footer-brand .logo-text { color: #fff; }
  .footer-brand .logo-text em { color: #5D6B82; border-left-color: #2A3040; }
  .footer-brand p { font-size: 13px; line-height: 1.8; color: #8C97AB; margin: 18px 0 20px; max-width: 280px; }
  .footer-status {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 5px 11px; border-radius: var(--r-full);
    background: rgba(14, 159, 110, .1); border: 1px solid rgba(14, 159, 110, .26);
    font-size: 11.5px; font-weight: 600; color: #7DE2B8;
  }
  .footer-status i { width: 6px; height: 6px; border-radius: 50%; background: #34D399; box-shadow: 0 0 0 3px rgba(52, 211, 153, .16); }
  .footer-col h4 {
    font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
    color: #fff; margin-bottom: 16px;
  }
  .footer-col ul { display: grid; gap: 11px; }
  .footer-col a { font-size: 13px; color: #8C97AB; transition: color .16s; }
  .footer-col a:hover { color: #fff; }
  .footer-col .new {
    font-size: 9px; font-weight: 800; letter-spacing: .05em; color: #A5B4FC;
    background: rgba(129, 140, 248, .14); padding: 1px 5px; border-radius: 3px; margin-left: 5px;
    vertical-align: 1px;
  }
  .footer-bottom {
    border-top: 1px solid rgba(255, 255, 255, .08);
    padding: 22px 0 26px; display: flex; justify-content: space-between;
    align-items: center; gap: 16px; flex-wrap: wrap;
    font-size: 12px; color: #64708A;
  }
  .footer-bottom-links { display: flex; gap: 20px; flex-wrap: wrap; }
  .footer-bottom a:hover { color: #C3CBD9; }

  /* 复制提示 */
  .toast {
    position: fixed; left: 50%; bottom: 34px; z-index: 200;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 16px; border-radius: var(--r-full);
    background: #0B0F18; color: #fff; font-size: 13px; font-weight: 600;
    box-shadow: 0 20px 40px -16px rgba(0, 0, 0, .6);
    opacity: 0; transform: translate(-50%, 12px); pointer-events: none;
    transition: opacity .25s var(--ease), transform .25s var(--ease);
  }
  .toast.is-on { opacity: 1; transform: translate(-50%, 0); }
  .toast svg { width: 14px; height: 14px; color: #7DE2B8; }

  /* ============================================================
     16. Responsive
     ============================================================ */
  @media (max-width: 1080px) {
    .hero-inner { gap: 40px; }
    .float-a { left: -10px; }
    .float-b { right: -6px; }
    .api-wrap { gap: 40px; }
    .faq-wrap { gap: 40px; }
    .footer-top { grid-template-columns: 1.4fr repeat(2, 1fr); row-gap: 36px; }
  }
  @media (max-width: 960px) {
    .section { padding: 84px 0; }
    .nav-links { display: none; }
    .nav-login { display: none; }
    .nav-toggle { display: grid; }
    .nav-actions .btn--primary { display: none; }
    .hero { padding: 60px 0 72px; }
    .hero-inner { grid-template-columns: 1fr; gap: 52px; }
    .hero-sub { max-width: 100%; }
    .hero-visual { max-width: 520px; margin: 0 auto; width: 100%; }
    .bento { grid-template-columns: repeat(4, 1fr); }
    .bcard--4 { grid-column: span 4; }
    .bcard--3 { grid-column: span 2; }
    .bcard { grid-column: span 2; }
    .steps { grid-template-columns: 1fr; }
    .step::after { display: none; }
    .api-wrap { grid-template-columns: 1fr; gap: 36px; }
    .faq-wrap { grid-template-columns: 1fr; gap: 28px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .stat:nth-child(3) { border-left: none; }
    .stat:nth-child(1), .stat:nth-child(2) { border-bottom: 1px solid var(--line); }
    .price-grid { grid-template-columns: 1fr; max-width: 480px; margin: 0 auto; }
    .cta-inner { padding: 48px 32px; }
    .footer-top { grid-template-columns: 1fr 1fr; }
    .footer-brand { grid-column: span 2; }
  }
  @media (max-width: 640px) {
    .section { padding: 68px 0; }
    .container { padding: 0 18px; }
    .sec-head { margin-bottom: 40px; }
    .announce-inner { font-size: 12px; gap: 7px; padding: 8px 16px; }
    .hero h1 { font-size: clamp(2rem, 8.6vw, 2.6rem); }
    .hero-sub { font-size: 15px; }
    .hero-cta .btn { flex: 1 1 100%; }
    .float-a { top: -14px; left: 0; }
    .float-b { bottom: -16px; right: 0; }
    .console-url { display: none; }
    .dropzone { padding: 22px 14px; }
    .bento { grid-template-columns: 1fr; }
    .bcard, .bcard--4, .bcard--3 { grid-column: span 1; }
    .stats-grid { grid-template-columns: 1fr; }
    .stat { border-left: none !important; border-bottom: 1px solid var(--line); }
    .stat:last-child { border-bottom: none; }
    .plan { padding: 26px 22px; }
    .cta-inner { padding: 40px 24px; border-radius: var(--r-xl); }
    .cta-band { padding-bottom: 72px; }
    .footer { padding-top: 56px; }
    .footer-top { grid-template-columns: 1fr; gap: 32px; }
    .footer-brand { grid-column: span 1; }
    .footer-bottom { flex-direction: column; align-items: flex-start; }
    .faq-a { padding-right: 12px; }
    .shot { width: 190px; height: 128px; }
    .code-panel { padding: 16px 14px 18px; }
    .code-panel pre { font-size: 11px; }
    .hero-visual::before { opacity: .4; }
  }

  /* 减少动效 */
  @media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }
    *, *::before, *::after {
      animation-duration: .001ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: .001ms !important;
    }
    .reveal { opacity: 1; transform: none; }
    .marquee-track, .flow-track { animation: none; }
  }
</style>
</head>
<body>

<!-- ============ 公告条 ============ -->
<div class="announce">
  <div class="announce-inner">
    <span class="pill-new">NEW</span>
    <span>已内置 <?php echo (int)$apiCount;?> 个图床接口，支持本地上传与自定义 S3 存储</span>
    <a href="#features">查看功能
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </a>
  </div>
</div>

<!-- ============ 导航 ============ -->
<header class="nav" id="nav">
  <div class="nav-inner">
    <a href="./" class="logo" aria-label="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?> 首页">
      <span class="logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 15.5l8-10 8 10"/><circle cx="12" cy="18" r="2.1" fill="#fff" stroke="none"/>
        </svg>
      </span>
      <span class="logo-text"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?><em>聚合图床</em></span>
    </a>

    <nav class="nav-links" aria-label="主导航">
      <a href="#features">产品功能</a>
      <a href="#providers">图床接口</a>
      <a href="#api">开发接入</a>
      <a href="#pricing">套餐配额</a>
      <a href="#faq">常见问题</a>
    </nav>

    <div class="nav-actions">
      <?php if($isUser): ?>
      <a href="<?php echo $userCenterUrl;?>" class="btn btn--primary">进入个人中心
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
      <?php elseif($isAdmin): ?>
      <a href="<?php echo $loginUrl;?>" class="nav-login">用户登录</a>
      <?php else: ?>
      <a href="<?php echo $loginUrl;?>" class="nav-login">登录</a>
      <a href="<?php echo $regEnabled ? $registerUrl : $loginUrl;?>" class="btn btn--primary"><?php echo $regEnabled ? '免费注册' : '登录使用';?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
      <?php endif; ?>
      <button class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="navMobile" aria-label="展开菜单">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <div class="nav-mobile" id="navMobile">
    <a href="#features">产品功能</a>
    <a href="#providers">图床接口</a>
    <a href="#api">开发接入</a>
    <a href="#pricing">套餐配额</a>
    <a href="#faq">常见问题</a>
    <?php if($isUser): ?>
    <a href="<?php echo $userCenterUrl;?>">个人中心</a>
    <?php elseif($isAdmin): ?>
    <a href="<?php echo $loginUrl;?>">用户登录</a>
    <?php else: ?>
    <a href="<?php echo $loginUrl;?>">登录</a>
    <?php endif; ?>
    <a href="<?php echo $primaryCtaUrl;?>" class="btn btn--primary"><?php echo htmlspecialchars($primaryCtaText, ENT_QUOTES, 'UTF-8');?></a>
  </div>
</header>

<main>

<!-- ============ Hero ============ -->
<section class="hero">
  <div class="container hero-inner">
    <div class="hero-text">
      <div class="hero-badge">
        <span class="tag">v<?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8');?></span>
        多图床聚合上传已上线
      </div>

      <h1>一个地址<br><span class="grad-text">聚合所有图床</span></h1>

      <p class="hero-sub">
        把 <?php echo (int)$apiCount;?> 个内置图床接口（含本地上传）与自定义 S3 存储，
        收进一个账号、一个上传入口。网页上传、用户中心与 API 密钥上传都使用同一套套餐与图片记录。
      </p>

      <div class="hero-cta">
        <a href="<?php echo $primaryCtaUrl;?>" class="btn btn--primary btn--lg"><?php echo htmlspecialchars($primaryCtaText, ENT_QUOTES, 'UTF-8');?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <a href="<?php echo $apiDocUrl;?>" class="btn btn--ghost btn--lg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l-6-6 6-6M15 6l6 6-6 6"/></svg>
          查看 API 文档
        </a>
      </div>

      <div class="hero-note">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><?php echo (int)$apiCount;?> 个内置接口</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>默认套餐 <?php echo htmlspecialchars($defaultStorageText, ENT_QUOTES, 'UTF-8');?></span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>API 密钥接入</span>
      </div>
    </div>

    <!-- 上传控制台示意 -->
    <div class="hero-visual">
      <div class="float-card float-a">
        <span class="live"></span> 已内置 <?php echo (int)$apiCount;?> 个接口
      </div>
      <div class="float-card float-b">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>
        密钥 / 会话隔离
      </div>

      <div class="console" aria-hidden="true">
        <div class="console-head">
          <div class="dots"><i></i><i></i><i></i></div>
          <div class="console-url"><?php echo htmlspecialchars(parse_url($apiBase, PHP_URL_HOST) ?: 'localhost', ENT_QUOTES, 'UTF-8');?>/user/</div>
          <div class="console-secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            SSL
          </div>
        </div>

        <div class="dropzone">
          <div class="dz-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>
          </div>
          <p class="dz-title">拖拽图片到这里</p>
          <p class="dz-sub">或 <kbd>Ctrl</kbd> + <kbd>V</kbd> 粘贴 · JPG / PNG / GIF / WebP</p>
        </div>

        <div class="filelist">
          <div class="file">
            <div class="file-thumb g1"></div>
            <div>
              <div class="file-name">mountain-sunrise.jpg</div>
              <div class="bar"><span style="--w:100%; animation-delay:.1s"></span></div>
            </div>
            <div class="file-state">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
              已上传
            </div>
          </div>

          <div class="file">
            <div class="file-thumb g3"></div>
            <div>
              <div class="file-name">studio-portrait.png</div>
              <div class="bar"><span style="--w:100%; animation-delay:.28s"></span></div>
            </div>
            <div class="file-state">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
              已上传
            </div>
          </div>

          <div class="file">
            <div class="file-thumb g6"></div>
            <div>
              <div class="file-name">product-shot-04.webp</div>
              <div class="bar"><span style="--w:68%; animation-delay:.46s"></span></div>
            </div>
            <div class="file-state is-syncing">
              <span class="spin"></span>
              调度中
            </div>
          </div>
        </div>

        <div class="console-foot">
          <span class="chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"/></svg>
            目标接口 · 按套餐可用
          </span>
          <span class="chip chip--ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            3 / 3 完成
          </span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 存储源 ============ -->
<section class="providers" id="providers">
  <?php
  // 展示系统实际内置接口中的代表性名称 + 本地/S3 两类存储
  $providerNames = ['本地上传', '自建图床', '360图床', '七牛云', 'PlcGO图床', 'ImgURL图床', 'Telegraph图床', 'PHOTO图床', 'IMGLOC图床', 'LOC图床', '极速图床', '有图床', 'S3 兼容存储', '自定义 S3'];
  ?>
  <p class="providers-label">已内置 <?php echo (int)$apiCount;?> 个图床接口，覆盖本地上传、第三方图床与 S3 兼容存储</p>
  <div class="marquee" aria-label="支持的图床接口">
    <div class="marquee-track">
      <?php for($set = 0; $set < 2; $set++): ?>
      <div class="prov-set"<?php echo $set === 1 ? ' aria-hidden="true"' : '';?>>
        <?php foreach($providerNames as $providerName): ?>
        <span class="prov"><i></i><?php echo htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8');?></span>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<!-- ============ 数据 ============ -->
<section class="stats">
  <div class="container">
    <div class="stats-grid reveal">
      <div class="stat">
        <div class="stat-num"><span data-count="<?php echo (int)$apiCount;?>" data-decimals="0"><?php echo (int)$apiCount;?></span><em>个</em></div>
        <div class="stat-label">内置图床接口</div>
      </div>
      <div class="stat">
        <div class="stat-num"><span>S3</span><em>兼容</em></div>
        <div class="stat-label">对象存储接入</div>
      </div>
      <div class="stat">
        <div class="stat-num"><span data-count="3" data-decimals="0">3</span><em>种</em></div>
        <div class="stat-label">上传入口</div>
      </div>
      <div class="stat">
        <div class="stat-num"><span><?php echo htmlspecialchars($defaultStorageText, ENT_QUOTES, 'UTF-8');?></span><em>默认</em></div>
        <div class="stat-label">默认套餐配额</div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 功能 Bento ============ -->
<section class="section" id="features">
  <div class="container">
    <div class="sec-head reveal">
      <div class="eyebrow">核心能力</div>
      <h2>从上传到管理，一套账号就够了</h2>
      <p>不需要在多个图床服务之间来回切换。接口、图片记录、套餐配额与 API 密钥都在同一套系统里。</p>
    </div>

    <div class="bento">
      <!-- 多图床聚合 -->
      <article class="bcard bcard--4 reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/></svg>
        </div>
        <h3>多图床聚合</h3>
        <p>内置 <?php echo (int)$apiCount;?> 个图床接口，并支持添加自定义 S3 存储。管理员可启停接口、设置默认接口，并按套餐分组授权可用范围。</p>
        <div class="nodes">
          <div class="node is-active">
            <span class="node-dot"></span>
            <span class="node-name">本地上传</span>
            <span class="node-latency">可用</span>
            <span class="node-flag">默认</span>
          </div>
          <div class="node">
            <span class="node-dot"></span>
            <span class="node-name">自建图床 / 360 图床</span>
            <span class="node-latency">可用</span>
          </div>
          <div class="node">
            <span class="node-dot"></span>
            <span class="node-name">第三方公共图床</span>
            <span class="node-latency">按套餐</span>
          </div>
          <div class="node">
            <span class="node-dot"></span>
            <span class="node-name">自定义 S3 存储</span>
            <span class="node-latency">按配置</span>
          </div>
        </div>
      </article>

      <!-- 网页上传 -->
      <article class="bcard reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>
        </div>
        <h3>网页与用户中心上传</h3>
        <p>拖拽、粘贴或选择文件即可上传，登录后自动记录到用户中心；支持 JPG / PNG / GIF / WebP / BMP，具体上限以所选接口为准。</p>
        <div class="compress">
          <div class="cbar-row"><span style="width:60px;color:var(--ink);font-weight:700">网页上传</span><span>拖拽 / 粘贴 / 选择</span></div>
          <div class="cbar-row"><span style="width:60px">API 上传</span><span>Bearer 密钥鉴权</span></div>
        </div>
      </article>

      <!-- 图片管理 -->
      <article class="bcard reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20V8"/></svg>
        </div>
        <h3>图片与套餐管理</h3>
        <p>用户中心统一查看上传历史、复制链接、删除图片，并管理套餐配额、兑换码与 API 密钥。</p>
        <div class="compress">
          <div class="cbar-row"><span style="width:60px">默认配额</span><span><?php echo htmlspecialchars($defaultStorageText, ENT_QUOTES, 'UTF-8');?></span></div>
          <div class="cbar-row"><span style="width:60px">用量统计</span><span>用户中心实时查看</span></div>
          <span class="save-badge">套餐可升级</span>
        </div>
      </article>

      <!-- 开放 API -->
      <article class="bcard bcard--4 reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg>
        </div>
        <h3>开放 API 上传</h3>
        <p>一套 Bearer Token 鉴权的上传接口，支持服务端调用；传 <code>api</code> 选择图床接口，或用 <code>s3_id</code> 使用自定义 S3。</p>
        <div class="mini-code" aria-hidden="true">
          <div class="mini-code-bar"><i></i><i></i><i></i></div>
          <pre><span class="c">// 上传并拿到图片直链</span>
<span class="f">curl</span> <span class="p">-X POST</span> <?php echo htmlspecialchars($apiEndpoint, ENT_QUOTES, 'UTF-8');?> \
  <span class="p">-H</span> <span class="s">"Authorization: Bearer sk-..."</span> \
  <span class="p">-F</span> <span class="s">"file=@cover.jpg"</span> <span class="p">-F</span> <span class="s">"api=local"</span></pre>
        </div>
      </article>

      <!-- 自定义 S3 -->
      <article class="bcard bcard--3 reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
        </div>
        <h3>自定义 S3 存储</h3>
        <p>管理员可添加多个 S3 兼容存储，配置 Bucket、Region、访问域名与路径前缀，适合自有对象存储或私有部署。</p>
        <div class="domain-stack">
          <div class="domain-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <span>s3.example.com</span><b>S3</b>
          </div>
          <div class="domain-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>
            <span>bucket / path-prefix</span><b>自定义</b>
          </div>
        </div>
      </article>

      <!-- 安全 -->
      <article class="bcard bcard--3 reveal">
        <div class="bcard-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7.5 4v5.2c0 4.7-3.2 7.8-7.5 9.3-4.3-1.5-7.5-4.6-7.5-9.3V7L12 3z"/><path d="M9 12l2 2 4-4"/></svg>
        </div>
        <h3>账号与接口权限</h3>
        <p>密码 bcrypt 存储、CSRF 校验、API 密钥哈希与加密副本、独立登录会话；套餐可绑定可用接口分组。</p>
        <div class="guard-tags">
          <span>bcrypt 密码</span>
          <span>CSRF 校验</span>
          <span>API 密钥</span>
          <span>套餐分组</span>
          <span>会话隔离</span>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- ============ 三步接入 ============ -->
<section class="section section--soft">
  <div class="container">
    <div class="sec-head reveal">
      <div class="eyebrow">接入流程</div>
      <h2>三步开始，先用默认套餐跑起来</h2>
      <p>注册账号 → 创建 API 密钥 → 网页上传或调用接口。接口与存储由管理员统一配置，不需要自己维护服务器。</p>
    </div>

    <div class="steps">
      <div class="step reveal">
        <span class="step-num">STEP 01</span>
        <h3>注册 / 登录账号</h3>
        <p>注册后自动使用系统默认套餐；已有账号可直接登录用户中心查看配额与上传记录。</p>
        <span class="step-code"><i></i><?php echo $regEnabled ? '免费注册' : '登录使用';?></span>
      </div>
      <div class="step reveal" style="transition-delay:.08s">
        <span class="step-num">STEP 02</span>
        <h3>创建 API 密钥</h3>
        <p>在用户中心创建 Bearer 密钥，明文首次展示，之后可在列表中复制，无需再次记录。</p>
        <span class="step-code"><i></i>sk-••••••••••••</span>
      </div>
      <div class="step reveal" style="transition-delay:.16s">
        <span class="step-num">STEP 03</span>
        <h3>上传并获取链接</h3>
        <p>使用网页上传或调用 API，成功后返回图片直链；登录用户可在用户中心统一查看和删除。</p>
        <span class="step-code"><i></i>200 OK · JSON</span>
      </div>
    </div>
  </div>
</section>

<!-- ============ API ============ -->
<section class="section" id="api">
  <div class="container api-wrap">
    <div class="api-copy reveal">
      <div class="eyebrow">开发者体验</div>
      <h2>你写 Markdown<br>上传交给 API</h2>
      <p>使用 Bearer API 密钥调用统一上传接口，图片上传到指定图床接口或自定义 S3，返回 JSON 直链。</p>

      <ul class="api-list">
        <li>
          <span class="tick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>
          <div>
            <strong>Bearer 密钥鉴权</strong>
            <span>在用户中心创建 API 密钥，请求头携带 <code>Authorization: Bearer sk-...</code>。</span>
          </div>
        </li>
        <li>
          <span class="tick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>
          <div>
            <strong>接口 / S3 二选一</strong>
            <span><code>api</code> 传图床接口标识（如 <code>local</code>、<code>360</code>），或用 <code>s3_id</code> 使用自定义 S3。</span>
          </div>
        </li>
        <li>
          <span class="tick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></span>
          <div>
            <strong>标准 JSON 返回</strong>
            <span>成功返回图片直链、文件名、大小与接口类型；失败返回明确的错误码与提示。</span>
          </div>
        </li>
      </ul>

      <div class="api-clients">
        适用于服务端脚本、自动化工具与支持自定义 HTTP 上传的客户端
      </div>
    </div>

    <div class="codebox reveal" style="transition-delay:.08s">
      <div class="codebox-head">
        <div class="tabs" role="tablist" aria-label="接口示例">
          <button class="tab" role="tab" aria-selected="true" aria-controls="panel-curl" id="tab-curl">cURL</button>
          <button class="tab" role="tab" aria-selected="false" aria-controls="panel-js" id="tab-js">JavaScript</button>
          <button class="tab" role="tab" aria-selected="false" aria-controls="panel-py" id="tab-py">Python</button>
        </div>
        <button class="copy-btn" data-copy-target="code-curl">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
          复制
        </button>
      </div>
      <div class="code-body">
        <div class="code-panel is-active" id="panel-curl" role="tabpanel" aria-labelledby="tab-curl">
<pre id="code-curl"><span class="c"># 上传图片并得到 JSON 直链</span>
<span class="f">curl</span> <span class="p">-X POST</span> <span class="s">"<?php echo htmlspecialchars($apiEndpoint, ENT_QUOTES, 'UTF-8');?>"</span> \
  <span class="p">-H</span> <span class="s">"Authorization: Bearer sk-..."</span> \
  <span class="p">-F</span> <span class="s">"file=@cover.jpg"</span> \
  <span class="p">-F</span> <span class="s">"api=local"</span>

<span class="c"># 200 OK</span>
{
  <span class="s">"code"</span>: <span class="n">200</span>,
  <span class="s">"msg"</span>: <span class="s">"上传成功"</span>,
  <span class="s">"data"</span>: {
    <span class="s">"url"</span>: <span class="s">"https://example.com/image.png"</span>,
    <span class="s">"filename"</span>: <span class="s">"cover.jpg"</span>,
    <span class="s">"size"</span>: <span class="n">102400</span>,
    <span class="s">"api_type"</span>: <span class="s">"local"</span>
  }
}</pre>
        </div>

        <div class="code-panel" id="panel-js" role="tabpanel" aria-labelledby="tab-js">
<pre id="code-js"><span class="c">// 浏览器 / Node.js 使用 FormData 上传</span>
<span class="k">const</span> form = <span class="k">new</span> <span class="f">FormData</span>();
form.<span class="f">append</span>(<span class="s">"file"</span>, file);
form.<span class="f">append</span>(<span class="s">"api"</span>, <span class="s">"local"</span>); <span class="c">// 或 form.append("s3_id", "0")</span>

<span class="k">const</span> res = <span class="k">await</span> <span class="f">fetch</span>(<span class="s">"<?php echo htmlspecialchars($apiEndpoint, ENT_QUOTES, 'UTF-8');?>"</span>, {
  method: <span class="s">"POST"</span>,
  headers: { <span class="s">"Authorization"</span>: <span class="s">"Bearer sk-..."</span> },
  body: form
}).<span class="f">then</span>(r =&gt; r.<span class="f">json</span>());

<span class="k">if</span> (res.code === <span class="n">200</span>) console.<span class="f">log</span>(res.data.url);</pre>
        </div>

        <div class="code-panel" id="panel-py" role="tabpanel" aria-labelledby="tab-py">
<pre id="code-py"><span class="k">import</span> requests

resp = requests.<span class="f">post</span>(
    <span class="s">"<?php echo htmlspecialchars($apiEndpoint, ENT_QUOTES, 'UTF-8');?>"</span>,
    headers={<span class="s">"Authorization"</span>: <span class="s">"Bearer sk-..."</span>},
    files={<span class="s">"file"</span>: <span class="f">open</span>(<span class="s">"cover.jpg"</span>, <span class="s">"rb"</span>)},
    data={<span class="s">"api"</span>: <span class="s">"local"</span>},  <span class="c"># 或 {"s3_id": "0"}</span>
    timeout=<span class="n">30</span>,
)

<span class="f">print</span>(resp.<span class="f">json</span>()[<span class="s">"data"</span>][<span class="s">"url"</span>])</pre>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ 图片流 ============ -->
<section class="section section--soft flow">
  <div class="container">
    <div class="sec-head reveal">
      <div class="eyebrow">图片记录</div>
      <h2>每一张图片，都有它的记录</h2>
      <p>用户中心统一查看上传历史、复制链接与删除图片；管理员可查看全站图片记录。</p>
    </div>
  </div>

  <div class="flow-rows" aria-hidden="true">
    <div class="flow-row">
      <div class="flow-track">
        <div class="shot g1"><span class="shot-info"><span class="shot-name">mountain-sunrise.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g2"><span class="shot-info"><span class="shot-name">forest-fog-02.webp</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g3"><span class="shot-info"><span class="shot-name">studio-portrait.png</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g4"><span class="shot-info"><span class="shot-name">ocean-wide.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g5"><span class="shot-info"><span class="shot-name">desert-dusk.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g6"><span class="shot-info"><span class="shot-name">mint-abstract.webp</span><span class="shot-tag">MINIO</span></span></div>
        <div class="shot g7"><span class="shot-info"><span class="shot-name">violet-gradient.png</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g1"><span class="shot-info"><span class="shot-name">mountain-sunrise.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g2"><span class="shot-info"><span class="shot-name">forest-fog-02.webp</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g3"><span class="shot-info"><span class="shot-name">studio-portrait.png</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g4"><span class="shot-info"><span class="shot-name">ocean-wide.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g5"><span class="shot-info"><span class="shot-name">desert-dusk.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g6"><span class="shot-info"><span class="shot-name">mint-abstract.webp</span><span class="shot-tag">MINIO</span></span></div>
        <div class="shot g7"><span class="shot-info"><span class="shot-name">violet-gradient.png</span><span class="shot-tag">S3</span></span></div>
      </div>
    </div>

    <div class="flow-row flow-row--rev">
      <div class="flow-track">
        <div class="shot g8"><span class="shot-info"><span class="shot-name">rose-field.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g9"><span class="shot-info"><span class="shot-name">blue-hour-01.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g10"><span class="shot-info"><span class="shot-name">olive-leaf.webp</span><span class="shot-tag">360</span></span></div>
        <div class="shot g11"><span class="shot-info"><span class="shot-name">lavender-mesh.png</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g12"><span class="shot-info"><span class="shot-name">peach-soft.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g1"><span class="shot-info"><span class="shot-name">banner-hero.webp</span><span class="shot-tag">CDN</span></span></div>
        <div class="shot g3"><span class="shot-info"><span class="shot-name">product-shot-04.webp</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g8"><span class="shot-info"><span class="shot-name">rose-field.jpg</span><span class="shot-tag">LOCAL</span></span></div>
        <div class="shot g9"><span class="shot-info"><span class="shot-name">blue-hour-01.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g10"><span class="shot-info"><span class="shot-name">olive-leaf.webp</span><span class="shot-tag">360</span></span></div>
        <div class="shot g11"><span class="shot-info"><span class="shot-name">lavender-mesh.png</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g12"><span class="shot-info"><span class="shot-name">peach-soft.jpg</span><span class="shot-tag">S3</span></span></div>
        <div class="shot g1"><span class="shot-info"><span class="shot-name">banner-hero.webp</span><span class="shot-tag">CDN</span></span></div>
        <div class="shot g3"><span class="shot-info"><span class="shot-name">product-shot-04.webp</span><span class="shot-tag">LOCAL</span></span></div>
      </div>
    </div>
  </div>

  <p class="flow-note reveal">图片存放位置取决于所选接口：本地上传存于服务器，S3 存于管理员配置的存储桶，第三方图床存于对应服务商。</p>
</section>

<!-- ============ 价格 ============ -->
<section class="section" id="pricing">
  <div class="container">
    <div class="sec-head reveal">
      <div class="eyebrow">套餐与配额</div>
      <h2>先用默认套餐，需要时再升级</h2>
      <p>默认套餐无需付费即可使用；更高等级套餐由管理员配置，通过兑换码开通，到期后自动回退到默认套餐。</p>
    </div>

    <div class="price-grid<?php echo (count($packages) + 1) <= 2 ? ' price-grid--two' : '';?>">
      <?php foreach($packages as $idx => $pkg):
        $pkgStorage = $fmtBytes((int)$pkg['storage_limit']);
        $pkgDays    = (int)$pkg['days'] === 0 ? '长期有效' : ((int)$pkg['days'] . ' 天有效期');
        $pkgLabel   = (int)$pkg['level'] > 0 ? ('VIP' . (int)$pkg['level']) : 'Free';
        $pkgIsDefault = !empty($pkg['is_default']);
        $pkgCtaUrl  = $isUser ? $pricingUrl : ($regEnabled ? $registerUrl : $loginUrl);
        $pkgCtaText = $isUser ? '查看我的套餐' : ($regEnabled ? '免费注册' : '登录使用');
      ?>
      <article class="plan reveal" style="transition-delay:<?php echo $idx * 0.08;?>s">
        <?php if($pkgIsDefault): ?><span class="plan-badge">默认套餐</span><?php endif; ?>
        <div class="plan-name"><?php echo htmlspecialchars($pkgLabel, ENT_QUOTES, 'UTF-8');?></div>
        <h3><?php echo htmlspecialchars((string)$pkg['name'], ENT_QUOTES, 'UTF-8');?></h3>
        <p class="plan-desc"><?php echo $pkgIsDefault ? '注册后自动使用，无需额外操作' : '由管理员配置的可用套餐';?></p>
        <div class="plan-price"><b><?php echo htmlspecialchars($pkgStorage, ENT_QUOTES, 'UTF-8');?></b><span>存储配额</span></div>
        <p class="plan-year"><?php echo htmlspecialchars($pkgDays, ENT_QUOTES, 'UTF-8');?></p>
        <a href="<?php echo $pkgCtaUrl;?>" class="btn btn--ghost"><?php echo $pkgCtaText;?></a>
        <ul class="plan-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg><?php echo htmlspecialchars($pkgStorage, ENT_QUOTES, 'UTF-8');?> 存储配额</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>网页上传与图片管理</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>API 密钥上传</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>套餐绑定的可用接口</li>
        </ul>
      </article>
      <?php endforeach; ?>

      <article class="plan plan--pro reveal" style="transition-delay:<?php echo count($packages) * 0.08;?>s">
        <span class="plan-badge">可升级</span>
        <div class="plan-name">Custom</div>
        <h3>更高等级套餐</h3>
        <p class="plan-desc">适合需要更大存储或更多接口的用户</p>
        <div class="plan-price"><b>按需</b><span>配额</span></div>
        <p class="plan-year">由管理员配置，通过兑换码开通</p>
        <a href="<?php echo $isUser ? $pricingUrl : $loginUrl;?>" class="btn btn--white"><?php echo $isUser ? '查看可用套餐' : '登录查看套餐';?></a>
        <ul class="plan-features">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>更高存储配额</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>更多可用图床接口</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>兑换码自助开通</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>到期回退默认套餐</li>
        </ul>
      </article>
    </div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section section--soft" id="faq">
  <div class="container faq-wrap">
    <div class="reveal">
      <div class="eyebrow">常见问题</div>
      <h2 style="font-size:clamp(1.8rem,3.2vw,2.3rem);margin-bottom:14px;">还有疑问？</h2>
      <p style="font-size:14.5px;line-height:1.85;margin-bottom:22px;">这里整理了接入前最常被问到的几个点。套餐、接口与配额以管理员配置和用户中心实际显示为准。</p>
      <a href="<?php echo $contactEmail !== '' ? 'mailto:' . htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') : '#faq';?>" class="btn btn--ghost" style="font-size:13.5px;padding:11px 18px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
        <?php echo $contactEmail !== '' ? '联系支持' : '查看常见问题';?>
      </a>
    </div>

    <div class="faq-list reveal" style="transition-delay:.08s">
      <details class="faq-item" open>
        <summary class="faq-q">这个系统和普通图床有什么区别？<span class="ico"></span></summary>
        <div class="faq-a"><p>系统把 <?php echo (int)$apiCount;?> 个内置图床接口（含本地上传）与自定义 S3 存储统一到一套账号、套餐和图片记录中。上传时选择具体接口或默认接口，图片实际存放在对应服务商；系统不会自动跨源切换或承诺链接永久有效。</p></div>
      </details>

      <details class="faq-item">
        <summary class="faq-q">我的图片实际存在哪里？<span class="ico"></span></summary>
        <div class="faq-a"><p>取决于上传时选择的接口：本地上传存于本站服务器；S3 存储存于管理员配置的对象存储桶；第三方图床存于对应服务商。系统保存上传记录与图片链接，删除记录不会自动删除第三方服务商上的原图。</p></div>
      </details>

      <details class="faq-item">
        <summary class="faq-q">支持哪些上传方式？<span class="ico"></span></summary>
        <div class="faq-a"><p>登录用户中心后即可拖拽、粘贴或选择文件上传，并查看历史、复制链接和删除图片；服务端或自动化脚本可通过 API 上传。支持 JPG / PNG / GIF / WebP / BMP，具体大小与格式限制以所选接口为准。</p></div>
      </details>

      <details class="faq-item">
        <summary class="faq-q">如何获取 API 密钥？<span class="ico"></span></summary>
        <div class="faq-a"><p>登录用户中心 → API 密钥管理 → 创建密钥。明文首次展示，之后列表只显示前缀，但可以点击复制获取完整密钥；每个用户最多创建 20 个，可随时禁用、删除或重新生成。</p></div>
      </details>

      <details class="faq-item">
        <summary class="faq-q">套餐和配额怎么算？<span class="ico"></span></summary>
        <div class="faq-a"><p>注册后自动使用系统默认套餐（当前默认 <?php echo htmlspecialchars($defaultStorageText, ENT_QUOTES, 'UTF-8');?> 存储）。更高等级套餐由管理员配置，可通过兑换码开通；到期后自动回退默认套餐。具体可用接口由套餐绑定的接口分组决定。</p></div>
      </details>

      <details class="faq-item">
        <summary class="faq-q">支持自动压缩、CDN 或自定义域名吗？<span class="ico"></span></summary>
        <div class="faq-a"><p>不提供自动压缩或自动签发证书。S3 存储可由管理员配置访问域名与路径前缀；第三方图床的链接、限流和可用性由对应服务商决定。系统不承诺跨服务商自动切换。</p></div>
      </details>
    </div>
  </div>
</section>

<!-- ============ CTA ============ -->
<section class="cta-band">
  <div class="container">
    <div class="cta-inner reveal">
      <div class="cta-content">
        <div class="eyebrow">开始使用</div>
        <h2>现在就把图片，收进一个账号</h2>
        <p>注册后即可使用默认套餐，API 密钥在用户中心创建。网页上传与接口上传共用同一套图片记录。</p>
        <div class="cta-actions">
          <a href="<?php echo $primaryCtaUrl;?>" class="btn btn--white btn--lg"><?php echo htmlspecialchars($primaryCtaText, ENT_QUOTES, 'UTF-8');?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          <a href="<?php echo $apiDocUrl;?>" class="btn btn--onDark btn--lg">查看 API 文档</a>
        </div>
        <div class="cta-meta">
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>默认套餐 <?php echo htmlspecialchars($defaultStorageText, ENT_QUOTES, 'UTF-8');?></span>
          <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>支持 API 密钥上传</span>
        </div>
      </div>
    </div>
  </div>
</section>

</main>

<!-- ============ Footer ============ -->
<footer class="footer">
  <div class="container">
    <div class="footer-top">
      <div class="footer-brand">
        <a href="./" class="logo">
          <span class="logo-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M4 15.5l8-10 8 10"/><circle cx="12" cy="18" r="2.1" fill="#fff" stroke="none"/>
            </svg>
          </span>
          <span class="logo-text"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?><em>聚合图床</em></span>
        </a>
        <p>把多个图床接口、本地上传与自定义 S3 收进一个账号，统一管理图片、套餐与 API 密钥。</p>
        <span class="footer-status"><i></i><?php echo (int)$apiCount;?> 个接口 · 用户中心 · API 上传</span>
      </div>

      <div class="footer-col">
        <h4>产品</h4>
        <ul>
          <li><a href="#features">功能概览</a></li>
          <li><a href="#providers">图床接口</a></li>
          <li><a href="#pricing">套餐配额</a></li>
          <li><a href="#faq">常见问题</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>开发者</h4>
        <ul>
          <li><a href="<?php echo $apiDocUrl;?>">API 文档</a></li>
          <li><a href="<?php echo $userCenterUrl;?>">用户中心</a></li>
          <li><a href="<?php echo $userCenterUrl;?>">API 密钥</a></li>
          <li><a href="#faq">常见问题</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>账户</h4>
        <ul>
          <?php if(!$isUser): ?><li><a href="<?php echo $loginUrl;?>">登录</a></li><?php endif; ?>
          <?php if(!$isUser && $regEnabled): ?><li><a href="<?php echo $registerUrl;?>">注册</a></li><?php endif; ?>
          <li><a href="<?php echo $pricingUrl;?>">套餐与兑换码</a></li>
          <li><a href="<?php echo $userCenterUrl;?>">API 密钥</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>联系</h4>
        <ul>
          <?php if($contactEmail !== ''): ?>
          <li><a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8');?>"><?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8');?></a></li>
          <?php endif; ?>
          <li><a href="<?php echo $userCenterUrl;?>">用户中心</a></li>
          <?php if($icp !== ''): ?><li><span><?php echo htmlspecialchars($icp, ENT_QUOTES, 'UTF-8');?></span></li><?php endif; ?>
          <li><a href="<?php echo $apiDocUrl;?>">接口说明</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <span>&copy; <?php echo date('Y');?> <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');?>. 保留所有权利。</span>
      <div class="footer-bottom-links">
        <a href="<?php echo $apiDocUrl;?>">API 文档</a>
        <a href="<?php echo $pricingUrl;?>">套餐配额</a>
        <a href="<?php echo $userCenterUrl;?>">用户中心</a>
      </div>
    </div>
  </div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
  <span>已复制到剪贴板</span>
</div>

<script>
(function () {
  'use strict';

  /* ---------- 导航吸顶阴影 ---------- */
  var nav = document.getElementById('nav');
  var onScroll = function () {
    nav.classList.toggle('is-stuck', window.scrollY > 8);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- 移动端菜单 ---------- */
  var toggle = document.getElementById('navToggle');
  var mobile = document.getElementById('navMobile');
  toggle.addEventListener('click', function () {
    var open = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!open));
    mobile.classList.toggle('is-open', !open);
    toggle.setAttribute('aria-label', open ? '展开菜单' : '收起菜单');
  });
  mobile.addEventListener('click', function (e) {
    if (e.target.closest('a')) {
      toggle.setAttribute('aria-expanded', 'false');
      mobile.classList.remove('is-open');
    }
  });

  /* ---------- 滚动进场 ---------- */
  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  }

  /* ---------- 数字滚动 ---------- */
  var counters = document.querySelectorAll('[data-count]');
  var runCount = function (el) {
    var target = parseFloat(el.getAttribute('data-count'));
    var dec = parseInt(el.getAttribute('data-decimals'), 10) || 0;
    var dur = 1500, start = null;

    var tick = function (now) {
      if (!start) start = now;
      var p = Math.min((now - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = target * eased;
      el.textContent = dec > 0
        ? val.toFixed(dec)
        : Math.round(val).toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(tick);
      else el.textContent = dec > 0 ? target.toFixed(dec) : target.toLocaleString('en-US');
    };
    requestAnimationFrame(tick);
  };

  if ('IntersectionObserver' in window) {
    var ioNum = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          runCount(entry.target);
          ioNum.unobserve(entry.target);
        }
      });
    }, { threshold: 0.6 });
    counters.forEach(function (el) { ioNum.observe(el); });
  } else {
    counters.forEach(runCount);
  }

  /* ---------- 代码 Tab 切换 ---------- */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
  var copyBtn = document.querySelector('.copy-btn');

  var activate = function (tab) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', String(on));
      var panel = document.getElementById(t.getAttribute('aria-controls'));
      if (panel) panel.classList.toggle('is-active', on);
    });
    if (copyBtn) copyBtn.setAttribute('data-copy-target', 'code-' + tab.id.replace('tab-', ''));
  };

  tabs.forEach(function (tab, i) {
    tab.addEventListener('click', function () { activate(tab); });
    tab.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      e.preventDefault();
      var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      next.focus();
      activate(next);
    });
  });

  /* ---------- 复制 + Toast ---------- */
  var toast = document.getElementById('toast');
  var toastTimer;
  var showToast = function (msg) {
    toast.querySelector('span').textContent = msg || '已复制到剪贴板';
    toast.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('is-on'); }, 1900);
  };

  var copyText = function (text, msg) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { showToast(msg); })
        .catch(function () { legacyCopy(text, msg); });
    } else {
      legacyCopy(text, msg);
    }
  };

  var legacyCopy = function (text, msg) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); showToast(msg); } catch (err) { showToast('复制失败，请手动选择'); }
    document.body.removeChild(ta);
  };

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var el = document.getElementById(copyBtn.getAttribute('data-copy-target'));
      var pre = el ? (el.tagName === 'PRE' ? el : el.querySelector('pre')) : null;
      if (!pre) return;
      copyText(pre.innerText, '代码已复制');
    });
  }
})();
</script>

</body>
</html>
