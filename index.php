<?php
/**
 * @file index.php
 * @description 图床主入口页面，加载图床配置并渲染前端上传界面
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

    require ('./header.php');

    // 防止浏览器缓存页面，确保后台修改访问控制/接口分组后前端立即生效
    // 必须放在 session_start()（common.php 内）之后，以覆盖 session 的默认缓存策略
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // ========== API 配置（统一配置源，消除三处硬编码） ==========
    $apiConfig = get_api_config();

    $defaultApi = isset($conf['api_default']) ? $conf['api_default'] : '';
    $defaultValid = false;
    if ($defaultApi !== '') {
        $defEnableKey = 'api_'.$defaultApi.'_enable';
        $defaultValid = isset($conf[$defEnableKey]) && $conf[$defEnableKey] == '1';
    }
    if (!$defaultValid) {
        $defaultApi = '';
        foreach ($apiConfig as $key => $cfg) {
            $ek = 'api_'.$key.'_enable';
            if (isset($conf[$ek]) && $conf[$ek] == '1') { $defaultApi = $key; break; }
        }
    }

    $s3Configs = array();
    if(isset($conf['s3_storage_configs']) && !empty($conf['s3_storage_configs'])) {
        $decoded = json_decode($conf['s3_storage_configs'], true);
        if(is_array($decoded)) {
            foreach($decoded as &$s3cfg) {
                if(isset($s3cfg['secret_key'])) {
                    $s3cfg['secret_key'] = ct_decrypt($s3cfg['secret_key']);
                }
            }
            unset($s3cfg);
            foreach($decoded as $i => $s3) {
                if(isset($s3['enabled']) && $s3['enabled'] === '1') {
                    $s3Configs[$i] = $s3;
                }
            }
        }
    }
    $siteName   = isset($conf['name'])    ? $conf['name']    : '图床';
    // 弹窗通知内容：为空则前端不弹出；配合内容哈希实现"关闭后不再弹出，直到内容变更"
    $siteNotice = !empty($conf['jieshao']) ? $conf['jieshao'] : '';
    $siteNoticeHash = $siteNotice !== '' ? md5($siteNotice) : '';
    $siteWarning = !empty($conf['Copyright']) ? $conf['Copyright'] : '';
    $siteEmail  = isset($conf['email'])  && $conf['email']  !== '' ? $conf['email']
                : (isset($conf['picui_email']) ? $conf['picui_email'] : '');

    // 登录状态（来自 member.php，通过 common.php 加载）
    $isLoggedIn = isset($islogin) && $islogin == 1;

    // ========== 访客预览模式（管理员后台登录时生效） ==========
    // 设计意图：管理员从后台登录后访问前端时，应以访客身份呈现访问控制，
    // 便于直接验证「访问控制」页配置的访客分组、强制隐藏本地上传等设置是否生效。
    // 实现要点：管理员后台登录状态 ($isLoggedIn) 优先于用户中心登录状态 ($isUserLoggedIn)，
    // 仅作用于前端 index.php 的访问控制与 UI 呈现，不影响用户中心 (user/) 的真实登录态。
    // 历史问题：此前仅判断 $isUserLoggedIn，若管理员同时登录了用户中心（如 admin1），
    // 则 $isUserLoggedIn=true 会覆盖访客视图，导致无法在前端验证访客权限配置，表现为
    // 「强制隐藏访客本地上传」开关不生效。
    $isAdminPreviewGuest = false;
    if($isLoggedIn) {
        $isAdminPreviewGuest = true;
        // 覆盖为访客身份，使后续 forceHideLocal / 套餐过滤 / UI 均按访客呈现
        $isUserLoggedIn = false;
        $currentUserId   = 0;
        $currentUser     = null;
        $currentUserRole = 'guest';
    }

    // ========== 套餐接口过滤 ==========
    // 前端访问控制基于用户中心登录状态（$isUserLoggedIn）。
    // 管理员后台登录时 $isUserLoggedIn 已被上方覆盖为 false，因此会按访客权限呈现。
    $pkgUserId = 0;
    if($isUserLoggedIn) {
        $pkgUserId = (int)$currentUserId;
    }

    // 获取用户可用接口列表
    $allowedApiInfo = ['api_keys'=>[], 's3_ids'=>[]];
    $hasApiRestriction = false;
    if($pkgUserId >= 0) {
        $allowedApiInfo = get_user_allowed_apis($DB, $pkgUserId);
        // has_group=true 表示用户已绑定分组，即使分组内接口全部已关闭，
        // 也应标记为受限，防止前端放行全部已启用接口
        $hasApiRestriction = !empty($allowedApiInfo['api_keys']) || !empty($allowedApiInfo['s3_ids']) || !empty($allowedApiInfo['has_group']);
    }

    // 未登录用户强制隐藏本地上传
    // 注意：管理员后台登录时 $isUserLoggedIn 已在上方被覆盖为 false，
    // 因此 forceHideLocal 也会对管理员预览模式生效，可直观验证开关效果。
    $guestHideLocal = isset($conf['guest_hide_local']) ? $conf['guest_hide_local'] : '0';
    $forceHideLocal = !$isUserLoggedIn && $guestHideLocal === '1';

    // 上传需要登录：后台开启 upload_require_login 且用户未登录时，
    // 上传按钮显示「登录后开始上传」并跳转登录页，而非触发上传。
    // 注意：管理员预览模式下 $isUserLoggedIn 已被覆盖为 false，因此也会按访客呈现。
    $uploadRequireLogin = isset($conf['upload_require_login']) ? $conf['upload_require_login'] : '0';
    $needLoginToUpload = ($uploadRequireLogin === '1') && !$isUserLoggedIn;

    // 如果默认接口被过滤掉，选一个新的
    if($hasApiRestriction || $forceHideLocal) {
        $defaultFiltered = false;
        if($forceHideLocal && $defaultApi === 'local') $defaultFiltered = true;
        if($hasApiRestriction && !in_array($defaultApi, $allowedApiInfo['api_keys'])) $defaultFiltered = true;
        if($defaultFiltered) {
            $defaultApi = '';
            if($hasApiRestriction) {
                foreach($allowedApiInfo['api_keys'] as $ak) {
                    $ek = 'api_'.$ak.'_enable';
                    if(isset($conf[$ek]) && $conf[$ek] == '1') { $defaultApi = $ak; break; }
                }
            }
            if($defaultApi === '' && !$forceHideLocal && !$hasApiRestriction) {
                foreach($apiConfig as $key => $cfg) {
                    if($forceHideLocal && $key === 'local') continue;
                    $ek = 'api_'.$key.'_enable';
                    if(isset($conf[$ek]) && $conf[$ek] == '1') { $defaultApi = $key; break; }
                }
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($siteName);?> - 图床上传</title>
  <meta name="description" content="<?php echo isset($conf['description']) ? htmlspecialchars($conf['description'], ENT_QUOTES, 'UTF-8') : ''; ?>">
  <meta name="keywords" content="<?php echo isset($conf['keywords']) ? htmlspecialchars($conf['keywords'], ENT_QUOTES, 'UTF-8') : ''; ?>">
  <link href="admin/style/css/materialdesignicons.min.css" rel="stylesheet">
  <link href="bd/qd.css" rel="stylesheet">
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
    [data-theme="dark"] .sidebar{background:rgba(30,26,46,0.62);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .soft-card,
    [data-theme="dark"] .tag-cluster span,
    [data-theme="dark"] .hero-badge,
    [data-theme="dark"] .queue-item,
    [data-theme="dark"] .timeline-item,
    [data-theme="dark"] .feature-list li,
    [data-theme="dark"] .mini-stats div,
    [data-theme="dark"] .insight-footer div,
    [data-theme="dark"] .profile-stats div,
    [data-theme="dark"] .security-item,
    [data-theme="dark"] .toggle-item,
    [data-theme="dark"] .record-item,
    [data-theme="dark"] .gallery-card{background:rgba(30,26,46,0.72);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .ghost-btn{background:rgba(40,35,60,0.8);color:var(--text)}
    [data-theme="dark"] .field input,
    [data-theme="dark"] .field textarea,
    [data-theme="dark"] .field select{background-color:rgba(30,26,46,0.78);color:var(--text)}
    [data-theme="dark"] .field input:focus,
    [data-theme="dark"] .field textarea:focus,
    [data-theme="dark"] .field select:focus{background-color:#1e1a2e}
    [data-theme="dark"] .code-card pre{background:#0a0c1a}

    /* ============ 集成样式（JS 生成的组件 + 系统 ID 适配） ============ */
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei","Helvetica Neue",Arial,sans-serif}

    /* 移动端侧边栏 */
    .menu-toggle{display:none;position:fixed;top:16px;left:16px;z-index:200;width:44px;height:44px;border-radius:14px;border:0;background:var(--panel-strong);box-shadow:var(--shadow);cursor:pointer;font-size:20px;color:var(--text)}
    .sidebar-overlay{display:none;position:fixed;inset:0;z-index:150;background:rgba(0,0,0,0.4);backdrop-filter:blur(2px)}
    .sidebar-overlay.show{display:block}
    @media(max-width:1280px){
      .menu-toggle{display:flex;align-items:center;justify-content:center}
      .sidebar{position:fixed;left:0;top:0;bottom:0;z-index:180;transform:translateX(-100%);transition:transform .3s ease;height:100vh}
      .sidebar.open{transform:translateX(0)}
    }

    /* Toast 通知 */
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

    /* 确认弹窗 */
    .modal-overlay{display:none;position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
    .modal-overlay.show{display:flex}
    .modal-box{background:var(--panel-strong);backdrop-filter:blur(24px);border-radius:24px;box-shadow:var(--shadow);border:1px solid var(--line);padding:28px;max-width:420px;width:90%;animation:modal-in .3s var(--ease-out)}
    @keyframes modal-in{from{opacity:0;transform:scale(0.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    .modal-title{margin:0 0 12px;font-size:1.2rem;font-weight:700}
    .modal-body{margin:0 0 24px;color:var(--muted);line-height:1.6}
    .modal-actions{display:flex;gap:12px;justify-content:flex-end}
    .modal-btn{padding:12px 20px;border-radius:14px;border:0;cursor:pointer;font:inherit;font-weight:600;transition:transform .2s ease,background .2s ease}
    .modal-btn:hover{transform:translateY(-1px)}
    .modal-btn.cancel{background:rgba(128,139,194,0.12);color:var(--muted)}
    .modal-btn.confirm{background:var(--gradient-main);color:#fff;box-shadow:0 8px 20px rgba(133,102,255,0.22)}

    /* API 选择器 */
    .api-select-wrap{display:flex;align-items:center;gap:12px}
    .api-select{padding:12px 40px 12px 16px;border-radius:14px;background:rgba(255,255,255,0.8);border:1px solid rgba(128,139,194,0.16);color:var(--text);font:inherit;font-size:0.92rem;font-weight:600;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%236a7191' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;min-width:180px}
    [data-theme="dark"] .api-select{background-color:rgba(30,26,46,0.8)}
    .max-size-hint{font-size:0.82rem;color:var(--muted);font-weight:600}

    /* 上传区适配 */
    .upload-card{padding:28px}
    .upload-zone{padding:32px;border:2px dashed rgba(136,142,212,0.28);border-radius:26px;background:linear-gradient(135deg,rgba(123,140,255,0.06),rgba(255,122,198,0.04));text-align:center;cursor:pointer;transition:border .25s ease,background .25s ease}
    .upload-zone.dragover{border-color:var(--primary);background:linear-gradient(135deg,rgba(123,140,255,0.14),rgba(255,122,198,0.1))}
    .upload-zone .dropzone-icon{width:60px;height:60px;border-radius:20px;background:var(--gradient-main);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#fff;font-size:28px}
    .upload-zone h4{margin:0 0 8px;font-size:1.15rem}
    .upload-zone p{margin:0 0 16px}
    .upload-zone .browse-btn{margin:0 auto}
    .file-names{margin-top:12px;font-size:0.84rem;color:var(--muted);text-align:left}
    .file-names div{padding:4px 0}

    /* 文件队列 */
    .queue-list{margin-top:20px}
    .queue-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .queue-header b{color:var(--primary)}
    .queue-item{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:16px;background:rgba(255,255,255,0.75);border:1px solid rgba(128,139,194,0.12);margin-bottom:10px}
    [data-theme="dark"] .queue-item{background:rgba(30,26,46,0.72)}
    .queue-item .file-icon{font-size:24px;color:var(--primary)}
    .queue-item .queue-info{flex:1;min-width:0}
    .queue-item .name{font-weight:600;font-size:0.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .queue-item .meta{font-size:0.82rem;color:var(--muted);margin-top:2px}
    .queue-cancel{width:32px;height:32px;border-radius:10px;border:0;background:rgba(255,127,168,0.12);color:#e53e6b;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s ease}
    .queue-cancel:hover{background:rgba(255,127,168,0.22)}

    /* 上传操作 */
    .upload-actions{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;justify-content:center}
    .upload-actions .primary-btn{display:inline-flex;align-items:center;gap:8px}
    .upload-actions .primary-btn:disabled{opacity:0.5;cursor:not-allowed;box-shadow:none}
    .upload-actions .ghost-btn{display:inline-flex;align-items:center;gap:8px}

    /* 进度条 */
    .progress-wrap{margin-top:20px;padding:18px;border-radius:18px;background:rgba(123,140,255,0.06)}
    .progress-title{font-weight:700;margin-bottom:12px;font-size:0.92rem}
    .progress-bar-wrap{height:12px;background:rgba(123,140,255,0.12);border-radius:999px;overflow:hidden}
    .progress-bar{display:block;height:100%;background:var(--gradient-main);border-radius:inherit;transition:width .4s ease}
    .progress-detail{margin-top:8px;font-size:0.82rem;color:var(--muted)}

    /* 结果区 */
    .result-wrap{margin-top:20px}
    .result-success{padding:18px;border-radius:18px;background:rgba(82,216,162,0.08);border:1px solid rgba(82,216,162,0.2)}
    .result-link{display:flex;gap:10px;align-items:center}
    .result-link input{flex:1;padding:12px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;font-size:0.88rem;color:var(--text)}
    [data-theme="dark"] .result-link input{background:rgba(30,26,46,0.8)}
    .result-error{padding:18px;border-radius:18px;background:rgba(255,127,168,0.08);border:1px solid rgba(255,127,168,0.2);color:#e53e6b}

    /* 历史记录 */
    .history-item{display:flex;align-items:center;gap:14px;padding:14px;border-radius:18px;background:rgba(255,255,255,0.75);border:1px solid rgba(128,139,194,0.12);transition:transform .2s ease,box-shadow .2s ease}
    [data-theme="dark"] .history-item{background:rgba(30,26,46,0.72)}
    .history-item:hover{transform:translateY(-2px);box-shadow:0 16px 30px rgba(126,129,188,0.12)}
    .history-thumb{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,rgba(123,140,255,0.2),rgba(255,122,198,0.16));display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--primary);flex-shrink:0;overflow:hidden}
    .history-thumb img{width:100%;height:100%;object-fit:cover}
    .history-info{flex:1;min-width:0}
    .history-info .name{font-weight:600;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .history-info .meta{font-size:0.8rem;color:var(--muted);margin-top:2px}
    .history-link{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--muted);text-decoration:none;flex-shrink:0;transition:background .2s ease,color .2s ease}
    .history-link:hover{background:rgba(123,140,255,0.1);color:var(--primary)}
    .history-empty{text-align:center;padding:40px 20px;color:var(--muted);font-size:0.92rem}

    /* 图表工具提示 */
    .chart-tooltip{position:absolute;background:var(--panel-strong);backdrop-filter:blur(18px);border:1px solid var(--line);border-radius:12px;padding:8px 12px;font-size:0.82rem;pointer-events:none;z-index:10;box-shadow:var(--shadow);display:none}
    .chart-tooltip.show{display:block}
    #trendChart{position:relative}
    .trend-dot:hover{r:5}
    /* 趋势图汇总 4 列 */
    .insight-footer{grid-template-columns:repeat(4,1fr)}
    @media(max-width:768px){.insight-footer{grid-template-columns:repeat(2,1fr)}}

    /* 存储卡 */
    .storage-value{font-size:2rem;font-weight:800;background:var(--gradient-main);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}

    /* 横幅 */
    .admin-preview-banner{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:16px;background:linear-gradient(90deg,rgba(123,140,255,0.12),rgba(155,125,255,0.12));border:1px solid rgba(123,140,255,0.2);color:#7160ff;font-size:0.88rem;line-height:1.5;margin-bottom:18px}
    [data-theme="dark"] .admin-preview-banner{color:#a5a0ff}
    .admin-preview-banner i{font-size:18px;flex-shrink:0;color:var(--primary)}
    .admin-preview-banner a{color:inherit;text-decoration:underline;font-weight:600}
    .site-warning-banner{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:16px;background:linear-gradient(90deg,rgba(255,127,168,0.12),rgba(255,193,87,0.08));border:1px solid rgba(255,127,168,0.2);color:#e53e6b;font-size:0.88rem;margin-bottom:18px}
    .guest-banner{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:16px;background:linear-gradient(90deg,rgba(123,140,255,0.08),rgba(125,211,252,0.06));border:1px solid rgba(123,140,255,0.12);color:var(--muted);font-size:0.88rem;margin-bottom:18px}
    .guest-banner a{color:var(--primary);text-decoration:none;font-weight:600}

    /* 站点通知弹窗 */
    .notice-modal-overlay{display:none;position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
    .notice-modal-overlay.show{display:flex}
    .notice-modal{background:var(--panel-strong);backdrop-filter:blur(24px);border-radius:24px;box-shadow:var(--shadow);border:1px solid var(--line);padding:32px;max-width:480px;width:90%}
    .notice-modal-head{font-size:1.3rem;font-weight:800;margin-bottom:16px}
    .notice-modal-body{color:var(--muted);line-height:1.7;white-space:pre-wrap;max-height:50vh;overflow-y:auto}
    .notice-modal-foot{margin-top:24px;text-align:right}
    .notice-modal-btn{padding:12px 24px;border-radius:14px;border:0;background:var(--gradient-main);color:#fff;font-weight:600;cursor:pointer}

    /* 时钟（topbar 内） */
    #clockDisplay{font-size:0.82rem;color:var(--muted);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;white-space:nowrap;display:flex;align-items:center}

    /* 主题切换按钮 */
    #themeToggle{width:40px;height:40px;border-radius:12px;border:0;background:rgba(255,255,255,0.7);color:var(--text);cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;transition:background .2s ease;flex-shrink:0}
    [data-theme="dark"] #themeToggle{background:rgba(30,26,46,0.7)}
    #themeToggle:hover{background:rgba(123,140,255,0.12)}

    /* 隐藏元素（JS 需要但不需要显示） */
    .hidden-ref{display:none}
  </style>
</head>
<body>
  <button class="menu-toggle" id="menuToggle"><i class="mdi mdi-menu"></i></button>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="page-shell">
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <div class="brand-mark"><span></span><span></span><span></span></div>
        <div>
          <strong><?php echo htmlspecialchars($siteName);?></strong>
          <p>图床控制台</p>
        </div>
      </div>

      <nav class="side-nav">
        <a class="active" href="#upload"><i class="mdi mdi-upload"></i> 上传图片</a>
        <a href="#history"><i class="mdi mdi-history"></i> 上传历史</a>
        <?php if($isUserLoggedIn): ?>
        <a href="user/"><i class="mdi mdi-account-circle"></i> 个人中心</a>
        <?php endif; ?>
      </nav>

      <?php if($isUserLoggedIn): ?>
      <div class="storage-card soft-card">
        <div class="section-caption">存储</div>
        <div class="storage-value" id="storageTag">--</div>
        <div class="meter"><span id="storageFill" style="width:0%"></span></div>
        <p>已使用 <span id="storageUsed">0</span> / <span id="storageTotal">0</span></p>
      </div>
      <?php endif; ?>

      <div class="tag-cluster">
        <span>CDN</span>
        <span>水印</span>
        <span>防盗链</span>
        <span>WebP</span>
      </div>

      <?php if(!empty($siteEmail)): ?>
      <div class="contact-card soft-card">
        <div class="section-caption">联系我们</div>
        <div class="contact-email" onclick="copyContactEmail('<?php echo htmlspecialchars($siteEmail, ENT_QUOTES); ?>')">
          <i class="mdi mdi-email-outline"></i>
          <span><?php echo htmlspecialchars($siteEmail); ?></span>
          <i class="mdi mdi-content-copy contact-copy-icon"></i>
        </div>
      </div>
      <?php endif; ?>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div>
          <p class="eyebrow">IMAGE HOSTING</p>
          <h1><?php echo htmlspecialchars($siteName);?></h1>
        </div>
        <div class="topbar-actions">
          <div id="clockDisplay"><span></span></div>
          <button id="themeToggle" type="button" title="切换主题"><i class="mdi mdi-weather-night"></i></button>
          <?php if(!$isUserLoggedIn): ?>
          <a class="ghost-btn" href="api_doc.php"><i class="mdi mdi-file-document-outline"></i> API 文档</a>
          <a class="ghost-btn" href="user/login.php"><i class="mdi mdi-login"></i> 登录</a>
          <a class="primary-btn" href="user/register.php"><i class="mdi mdi-account-plus"></i> 注册</a>
          <?php else: ?>
          <a class="ghost-btn" href="api_doc.php"><i class="mdi mdi-file-document-outline"></i> API 文档</a>
          <a class="ghost-btn" href="user/"><i class="mdi mdi-account-circle"></i> 个人中心</a>
          <?php endif; ?>
        </div>
      </header>

      <?php if($isAdminPreviewGuest): ?>
      <div class="admin-preview-banner">
        <i class="mdi mdi-eye-outline"></i>
        <span><strong>访客预览模式</strong>：已检测到管理员后台登录，前端正以访客身份呈现，便于验证访问控制配置。
          <a href="admin/">返回后台</a>
        </span>
      </div>
      <?php endif; ?>

      <?php if(!empty($siteWarning)): ?>
      <div class="site-warning-banner">
        <i class="mdi mdi-alert-circle-outline"></i>
        <span><?php echo htmlspecialchars($siteWarning);?></span>
      </div>
      <?php endif; ?>

      <?php if(!$isUserLoggedIn && empty($isAdminPreviewGuest)): ?>
      <div class="guest-banner">
        <i class="mdi mdi-information-outline"></i>
        <span>当前为访客模式，<a href="user/login.php">登录</a> 后可管理图片和使用更多功能</span>
      </div>
      <?php endif; ?>

      <!-- 上传区 -->
      <section class="upload-card soft-card" id="upload">
        <div class="section-heading">
          <div>
            <p class="section-caption">Upload Center</p>
            <h3>上传图片</h3>
          </div>
          <div class="api-select-wrap">
            <select class="api-select" id="apiSelect">
              <?php
              $apiIdx = 0;
              foreach($apiConfig as $key => $cfg):
                $enableKey = 'api_'.$key.'_enable';
                $aliasKey  = 'api_'.$key.'_alias';
                $maxKey    = 'api_'.$key.'_maxsize';
                $enabled = isset($conf[$enableKey]) && $conf[$enableKey] == '1';
                if($enabled):
                  if($forceHideLocal && $key === 'local') { $apiIdx++; continue; }
                  if($hasApiRestriction && !in_array($key, $allowedApiInfo['api_keys'])) { $apiIdx++; continue; }
                  $alias = isset($conf[$aliasKey]) && $conf[$aliasKey] !== '' ? $conf[$aliasKey] : $cfg['name'];
                  $maxSize = isset($conf[$maxKey]) && $conf[$maxKey] > 0 ? (float)$conf[$maxKey] : (float)$cfg['max_size'];
                  $sel = ($key === $defaultApi) ? ' selected' : '';
              ?>
              <option value="<?php echo htmlspecialchars((string)$key);?>" data-maxsize="<?php echo $maxSize;?>" data-type="api"<?php echo $sel;?>><?php echo htmlspecialchars($alias);?></option>
              <?php
                  $apiIdx++;
                endif;
              endforeach;
              // S3 接口
              if(!$hasApiRestriction || !empty($allowedApiInfo['s3_ids'])):
                foreach($s3Configs as $i => $s3):
                  if($hasApiRestriction && !in_array($i, $allowedApiInfo['s3_ids'])) continue;
                  $s3Name = isset($s3['name']) ? $s3['name'] : ('S3-'.$i);
                  $s3Max = isset($s3['max_size']) ? (float)$s3['max_size'] : 10;
                  $sel = ('s3_'.$i === $defaultApi) ? ' selected' : '';
              ?>
              <option value="s3_<?php echo $i;?>" data-maxsize="<?php echo $s3Max;?>" data-type="s3" data-s3id="<?php echo $i;?>"<?php echo $sel;?>><?php echo htmlspecialchars($s3Name);?></option>
              <?php
                endforeach;
              endif;
              ?>
            </select>
            <span class="max-size-hint">当前：<span id="selectedHostName">-</span> · 最大 <span id="maxSizeDisplay">10 MB</span></span>
          </div>
        </div>

        <div class="upload-zone" id="dropZone">
          <div class="dropzone-icon" id="dropZoneIcon"><i class="mdi mdi-cloud-upload-outline"></i></div>
          <h4 id="dropZoneTitle">点击选择或拖拽图片到此处</h4>
          <p id="dropZoneSubtitle">支持 JPG / PNG / GIF / WEBP，单文件最大 <span id="detailMaxSize">10 MB</span></p>
          <div class="file-names" id="dropZoneFileNames" style="display:none;"></div>
          <button class="primary-btn" id="browseBtn" type="button"><i class="mdi mdi-folder-open-outline"></i> 浏览文件</button>
          <input type="file" id="fileInput" multiple accept="image/*" style="display:none;">
          <p id="fileHint"></p>
        </div>

        <div class="queue-list" id="fileListWrap" style="display:none;">
          <div class="queue-header">
            <span>已选择 <b id="fileCountEl">0</b> 个文件</span>
            <button class="ghost-btn small" id="clearFilesBtn" type="button"><i class="mdi mdi-delete-outline"></i> 清空</button>
          </div>
          <div id="fileListEl"></div>
        </div>

        <div class="upload-actions">
          <?php if($needLoginToUpload): ?>
          <button class="primary-btn" id="uploadBtn" type="button" data-need-login="1">
            <i class="mdi mdi-login" id="btnIcon"></i>
            <span id="btnLabel">登录后开始上传</span>
          </button>
          <?php else: ?>
          <button class="primary-btn" id="uploadBtn" type="button" disabled>
            <i class="mdi mdi-upload" id="btnIcon"></i>
            <span id="btnLabel">开始上传</span>
            (<span id="uploadCountEl">0</span>)
          </button>
          <button class="ghost-btn" id="clearAllBtn" type="button"><i class="mdi mdi-close"></i> 清空全部</button>
          <?php endif; ?>
        </div>

        <div class="progress-wrap" id="progressWrap" style="display:none;">
          <div class="progress-title" id="progressTitle">上传中...</div>
          <div class="progress-bar-wrap"><div class="progress-bar" id="progressBar" style="width:0%"></div></div>
          <div class="progress-detail" id="progressDetail"></div>
        </div>

        <div class="result-wrap" id="resultWrap" style="display:none;">
          <div class="result-success" id="successBox">
            <div class="result-link">
              <input type="text" id="resultLinkText" readonly>
              <button class="ghost-btn small" id="copyBtn" type="button"><i class="mdi mdi-content-copy"></i> 复制</button>
            </div>
          </div>
          <div class="result-error" id="errorBox" style="display:none;">
            <p id="errorMsg"></p>
          </div>
        </div>

        <p class="status-text" id="statusText" style="margin-top:12px;font-size:0.84rem;color:var(--muted);"></p>
      </section>

      <!-- 统计卡片 -->
      <section class="stats-grid">
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip blue">图床数量</span><strong id="hostCount">0</strong></div>
          <p>当前可用的图床接口数</p>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip green">总上传</span><strong id="totalUploads">0</strong></div>
          <p>全站累计上传图片数</p>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip rose">今日上传</span><strong id="todayUploads">0</strong></div>
          <p>今日全站上传图片数</p>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip violet">成功率</span><strong id="successRate">--</strong></div>
          <p>最近上传成功率</p>
        </article>
      </section>

      <!-- 访问趋势 -->
      <article class="insight-card soft-card">
        <div class="section-heading">
          <div>
            <p class="section-caption">Traffic Insight</p>
            <h3>访问趋势</h3>
          </div>
          <span class="tiny-switch">最近 7 天</span>
        </div>
        <div class="insight-footer">
          <div><strong id="trendTotal">0</strong><span>本周上传</span></div>
          <div><strong id="trendPeak">0</strong><span>峰值</span></div>
          <div><strong id="trendAvg">0</strong><span>日均</span></div>
          <div><strong id="trendMin">0</strong><span>最低</span></div>
        </div>
      </article>

      <!-- 上传历史 -->
      <section class="gallery-section soft-card" id="history">
        <div class="section-heading">
          <div>
            <p class="section-caption">Upload History</p>
            <h3>上传历史</h3>
          </div>
          <button class="ghost-btn small" id="clearHistoryBtn" type="button"><i class="mdi mdi-trash-can-outline"></i> 清空历史</button>
        </div>
        <div id="historyList">
          <div class="history-empty">暂无上传记录</div>
        </div>
        <div style="text-align:center;margin-top:18px;">
          <button class="ghost-btn" id="loadMoreBtn" type="button">加载更多</button>
        </div>
      </section>

      <!-- 隐藏的引用元素（JS 需要） -->
      <div class="hidden-ref">
        <span id="detailName"></span>
      </div>
    </main>
  </div>

  <!-- 站点通知弹窗 -->
  <?php if(!empty($siteNotice)): ?>
  <div class="notice-modal-overlay" id="noticeModalOverlay">
    <div class="notice-modal">
      <div class="notice-modal-head"><?php echo htmlspecialchars($siteName);?> 通知</div>
      <div class="notice-modal-body"><?php echo htmlspecialchars($siteNotice);?></div>
      <div class="notice-modal-foot">
        <button class="notice-modal-btn" id="noticeModalClose">我知道了</button>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var hash = '<?php echo $siteNoticeHash;?>';
      var dismissed = localStorage.getItem('noticeDismissed_' + hash);
      if(!dismissed){
        setTimeout(function(){ document.getElementById('noticeModalOverlay').classList.add('show'); }, 500);
      }
      document.getElementById('noticeModalClose').addEventListener('click', function(){
        document.getElementById('noticeModalOverlay').classList.remove('show');
        localStorage.setItem('noticeDismissed_' + hash, '1');
      });
    })();
  </script>
  <?php endif; ?>

  <!-- 确认弹窗 -->
  <div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
      <h3 class="modal-title" id="modalTitle"></h3>
      <p class="modal-body" id="modalBody"></p>
      <div class="modal-actions">
        <button class="modal-btn cancel" id="modalCancel">取消</button>
        <button class="modal-btn confirm" id="modalConfirm">确认</button>
      </div>
    </div>
  </div>

  <!-- Toast 容器 -->
  <div class="notif-container" id="notifContainer"></div>

  <!-- qd 设计系统通用交互 -->
  <script src="bd/qd.js"></script>

<script>
// ===== CSRF 令牌（用于上传等 POST 请求） =====
var csrfToken = '<?php echo function_exists("csrf_token") ? csrf_token() : ""; ?>';

// ===== 全局工具 =====
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// 复制联系邮箱到剪贴板
function copyContactEmail(email) {
  var icon = event.currentTarget.querySelector('.contact-copy-icon');
  if (navigator.clipboard) {
    navigator.clipboard.writeText(email).then(function() {
      if (icon) { icon.className = 'mdi mdi-check contact-copy-icon'; }
      setTimeout(function() { if (icon) { icon.className = 'mdi mdi-content-copy contact-copy-icon'; } }, 1500);
    });
  } else {
    var ta = document.createElement('textarea');
    ta.value = email; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); if (icon) { icon.className = 'mdi mdi-check contact-copy-icon'; } setTimeout(function() { if (icon) { icon.className = 'mdi mdi-content-copy contact-copy-icon'; } }, 1500); } catch(e) {}
    document.body.removeChild(ta);
  }
}
function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// L3: URL 协议白名单校验，防止 javascript:/vbscript:/data: 等 URL 注入
function safeUrl(url) {
  var u = String(url).trim().toLowerCase();
  if(u === '' || u === '#') return url;
  // 允许的协议白名单
  if(/^(https?:|mailto:|tel:|ftp:|\/|\.\/|\.\.\/|#|data:image\/)/.test(u)) return url;
  return ''; // 不安全协议返回空
}

// ===== 自定义通知 =====
function notify(msg, type) {
  type = type || 'info';
  var icons = { success: 'mdi-check-circle', error: 'mdi-alert-circle', warning: 'mdi-alert', info: 'mdi-information' };
  var el = document.createElement('div');
  el.className = 'notif ' + type;
  el.innerHTML = '<i class="mdi ' + (icons[type] || icons.info) + '"></i><span>' + esc(msg) + '</span>';
  document.getElementById('notifContainer').appendChild(el);
  setTimeout(function() {
    el.classList.add('notif-out');
    setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
  }, 2800);
}

// ===== 自定义确认框 =====
function showConfirm(title, msg) {
  return new Promise(function(resolve) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').textContent = msg;
    document.getElementById('modalOverlay').classList.add('show');
    var confirm = document.getElementById('modalConfirm');
    var cancel = document.getElementById('modalCancel');
    var overlay = document.getElementById('modalOverlay');
    var c = confirm.cloneNode(true); confirm.parentNode.replaceChild(c, confirm);
    var cc = cancel.cloneNode(true); cancel.parentNode.replaceChild(cc, cancel);
    c.addEventListener('click', function() { overlay.classList.remove('show'); resolve(true); });
    cc.addEventListener('click', function() { overlay.classList.remove('show'); resolve(false); });
    overlay.addEventListener('click', function(e) { if (e.target === overlay) { overlay.classList.remove('show'); resolve(false); } }, { once: true });
  });
}

// ===== DOM =====
var apiSelect = document.getElementById('apiSelect');
var dropZone = document.getElementById('dropZone');
var fileInput = document.getElementById('fileInput');
var fileHint = document.getElementById('fileHint');
var fileListWrap = document.getElementById('fileListWrap');
var fileListEl = document.getElementById('fileListEl');
var fileCountEl = document.getElementById('fileCountEl');
var uploadBtn = document.getElementById('uploadBtn');
var uploadCountEl = document.getElementById('uploadCountEl');
var btnIcon = document.getElementById('btnIcon');
var btnLabel = document.getElementById('btnLabel');
var clearAllBtn = document.getElementById('clearAllBtn');
var statusText = document.getElementById('statusText');
var progressWrap = document.getElementById('progressWrap');
var progressTitle = document.getElementById('progressTitle');
var progressDetail = document.getElementById('progressDetail');
var progressBar = document.getElementById('progressBar');
var resultWrap = document.getElementById('resultWrap');
var successBox = document.getElementById('successBox');
var errorBox = document.getElementById('errorBox');
var errorMsg = document.getElementById('errorMsg');
var resultLinkText = document.getElementById('resultLinkText');
var copyBtn = document.getElementById('copyBtn');
var historyList = document.getElementById('historyList');
var clockDisplay = document.getElementById('clockDisplay');
var maxSizeDisplay = document.getElementById('maxSizeDisplay');

// ===== 状态 =====
var files = [];
var uploading = false;
var uploadHistory = JSON.parse(localStorage.getItem('uploadHistory') || '[]');
var isLoggedIn = <?php echo $isUserLoggedIn ? 'true' : 'false'; ?>;

// ===== 主题切换 =====
(function initTheme() {
  var saved = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  var icon = document.querySelector('#themeToggle i');
  if (icon) icon.className = saved === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
})();
document.getElementById('themeToggle').addEventListener('click', function() {
  var current = document.documentElement.getAttribute('data-theme');
  var next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
  this.querySelector('i').className = next === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
  renderTrendChart();
});

// ===== 侧边栏 =====
document.getElementById('menuToggle').addEventListener('click', function() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
});
document.getElementById('sidebarOverlay').addEventListener('click', function() {
  document.getElementById('sidebar').classList.remove('open');
  this.classList.remove('show');
});
document.querySelectorAll('.side-nav a').forEach(function(item) {
  item.addEventListener('click', function(e) {
    if (this.getAttribute('href') && this.getAttribute('href').startsWith('#')) {
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebarOverlay').classList.remove('show');
    }
  });
});

// ===== 时钟 =====
function tick() {
  var n = new Date();
  clockDisplay.querySelector('span').textContent = n.toLocaleString('zh-CN', { hour12: false });
}
tick(); setInterval(tick, 1000);

// ===== 图床切换 =====
function updateDetail() {
  if (apiSelect.options.length === 0) return;
  var opt = apiSelect.options[apiSelect.selectedIndex];
  document.getElementById('detailName').textContent = opt.textContent;
  document.getElementById('selectedHostName').textContent = opt.textContent;
  var maxSize = parseFloat(opt.dataset.maxsize) || 10;
  var sizeText = maxSize >= 1 ? (maxSize + ' MB') : (Math.round(maxSize * 1024) + ' KB');
  maxSizeDisplay.textContent = sizeText;
  document.getElementById('detailMaxSize').textContent = sizeText;
}
apiSelect.addEventListener('change', updateDetail);
updateDetail();

// ===== 统计 =====
var hostCount = apiSelect.options.length;
document.getElementById('hostCount').textContent = hostCount;

// ===== 文件处理 =====
function addFiles(newFiles) {
  var imgs = Array.from(newFiles).filter(function(f) { return f.type.startsWith('image/'); });
  if (imgs.length === 0) { notify('请选择图片文件 (JPG, PNG, GIF 等)', 'warning'); return; }
  files = files.concat(imgs);
  renderFiles();
  fileInput.value = '';
  notify('已添加 ' + imgs.length + ' 张图片', 'info');
}

function renderFiles() {
  if (files.length === 0) {
    fileListWrap.style.display = 'none';
    // 恢复拖拽区默认状态
    document.getElementById('dropZoneTitle').textContent = '点击选择或拖拽图片到此处';
    document.getElementById('dropZoneSubtitle').style.display = '';
    document.getElementById('dropZoneFileNames').style.display = 'none';
    document.getElementById('dropZoneFileNames').innerHTML = '';
    document.getElementById('dropZoneIcon').querySelector('i').className = 'mdi mdi-cloud-upload-outline';
    document.getElementById('browseBtn').innerHTML = '<i class="mdi mdi-folder-open-outline"></i> 浏览文件';
  }
  else {
    fileListWrap.style.display = 'block';
    fileCountEl.textContent = files.length;
    var h = '';
    files.forEach(function(f, i) {
      h += '<div class="queue-item">' +
        '<i class="mdi mdi-file-image file-icon"></i>' +
        '<div class="queue-info"><div class="name">' + esc(f.name) + '</div><div class="meta">' + (f.size / 1024).toFixed(1) + ' KB</div></div>' +
        '<button class="queue-cancel" data-idx="' + i + '"><i class="mdi mdi-close"></i></button></div>';
    });
    fileListEl.innerHTML = h;
    fileListEl.querySelectorAll('.queue-cancel').forEach(function(el) {
      el.addEventListener('click', function() {
        files.splice(parseInt(this.dataset.idx), 1);
        renderFiles(); updateBtn();
        notify('已移除该文件', 'info');
      });
    });

    // 在拖拽区内显示已选文件名
    var dzTitle = document.getElementById('dropZoneTitle');
    var dzSubtitle = document.getElementById('dropZoneSubtitle');
    var dzFileNames = document.getElementById('dropZoneFileNames');
    var dzIcon = document.getElementById('dropZoneIcon').querySelector('i');

    if (files.length === 1) {
      dzTitle.textContent = files[0].name;
      dzSubtitle.style.display = 'none';
      dzIcon.className = 'mdi mdi-file-image-outline';
    } else {
      dzTitle.textContent = '已选择 ' + files.length + ' 个文件';
      dzSubtitle.style.display = 'none';
      dzIcon.className = 'mdi mdi-file-multiple-outline';
    }
    document.getElementById('browseBtn').innerHTML = '<i class="mdi mdi-check-circle-outline"></i> 已选中文件';

    // 显示文件名列表（最多5个）
    var nameHtml = '';
    var showCount = Math.min(files.length, 5);
    for (var i = 0; i < showCount; i++) {
      var size = (files[i].size / 1024).toFixed(1);
      nameHtml += '<div style="font-size:12px;color:var(--text-2);padding:2px 0;border-bottom:1px solid var(--border-2);">' +
        '<i class="mdi mdi-image-outline" style="color:var(--primary);margin-right:4px;"></i>' + esc(files[i].name) +
        ' <span style="color:var(--text-muted);">(' + size + ' KB)</span></div>';
    }
    if (files.length > 5) {
      nameHtml += '<div style="font-size:11px;color:var(--text-muted);padding:2px 0;">还有 ' + (files.length - 5) + ' 个文件...</div>';
    }
    dzFileNames.innerHTML = nameHtml;
    dzFileNames.style.display = 'block';
  }
  updateBtn();
}

function updateBtn() {
  uploadBtn.disabled = uploadBtn.dataset.needLogin ? false : (files.length === 0 || uploading);
  if(uploadCountEl) uploadCountEl.textContent = files.length;
  statusText.textContent = files.length > 0 ? '已选 ' + files.length + ' 个文件' : '未选择文件';
  if(clearAllBtn) clearAllBtn.style.display = (files.length > 0 && !uploading) ? '' : 'none';
}

// ===== 清空 =====
async function clearFiles() {
  if (files.length === 0) return;
  if (await showConfirm('清空文件', '确定要清空所有已选文件吗？')) {
    files = []; renderFiles(); notify('已清空所有文件', 'success');
  }
}
async function clearHistory() {
  if (uploadHistory.length === 0) return;
  if (await showConfirm('清空历史', '确定要清空所有上传历史记录吗？')) {
    uploadHistory = []; localStorage.setItem('uploadHistory', '[]'); renderHistory(); notify('已清空历史记录', 'success');
  }
}

// ===== 拖拽/粘贴 =====
dropZone.addEventListener('click', function() { fileInput.click(); });
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('dragover'); });
dropZone.addEventListener('drop', function(e) { e.preventDefault(); dropZone.classList.remove('dragover'); if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
fileInput.addEventListener('change', function(e) { if (e.target.files.length) addFiles(e.target.files); });
document.addEventListener('paste', function(e) {
  var items = e.clipboardData && e.clipboardData.items; if (!items) return;
  var fs = [];
  for (var i = 0; i < items.length; i++) { if (items[i].type.startsWith('image/')) { var f = items[i].getAsFile(); if (f) fs.push(f); } }
  if (fs.length) { addFiles(fs); fileHint.textContent = '已粘贴 ' + fs.length + ' 张图片'; setTimeout(function() { fileHint.textContent = ''; }, 2000); }
});

// ===== 上传逻辑 =====
uploadBtn.addEventListener('click', function() {
  if (uploading) return;
  if (uploadBtn.dataset.needLogin) { window.location.href = 'user/login.php'; return; }
  if (files.length === 0) { notify('请先选择要上传的图片', 'warning'); return; }

  uploading = true;
  updateBtn();
  btnIcon.className = 'mdi mdi-loading mdi-spin';
  btnLabel.textContent = '上传中...';

  resultWrap.style.display = 'none';
  successBox.style.display = 'none';
  errorBox.style.display = 'none';

  progressWrap.style.display = 'block';
  progressTitle.textContent = '正在上传...';
  progressDetail.textContent = '准备上传 ' + files.length + ' 张图片';
  progressBar.style.width = '0%';

  var apiKey = apiSelect.value;
  var opt = apiSelect.options[apiSelect.selectedIndex];
  var isS3 = (opt.dataset.type === 's3');
  var url = './api/upload.php';
  var maxSize = parseFloat(opt.dataset.maxsize) || 10;
  var maxBytes = maxSize * 1024 * 1024;
  var done = 0, okList = [], errList = [];

  var oversized = files.filter(function(f) { return f.size > maxBytes; });
  if (oversized.length > 0) {
    var sizeLabel = maxSize >= 1 ? (maxSize + ' MB') : (Math.round(maxSize * 1024) + ' KB');
    oversized.forEach(function(f) { errList.push({ name: f.name, msg: '文件大小 ' + (f.size / 1024 / 1024).toFixed(1) + 'MB 超过限制 ' + sizeLabel }); });
    files = files.filter(function(f) { return f.size <= maxBytes; });
    if (files.length === 0) {
      notify('所有文件都超过 ' + sizeLabel + ' 的大小限制', 'error');
      finish();
      return;
    }
    notify(oversized.length + ' 个文件超过大小限制已跳过', 'warning');
    progressDetail.textContent = '准备上传 ' + files.length + ' 张图片（' + oversized.length + ' 张跳过）';
  }

  function next(idx) {
    if (idx >= files.length) { finish(); return; }
    var file = files[idx];
    var fd = new FormData();
    fd.append('file', file);
    if (isS3) { fd.append('s3_id', opt.dataset.s3id); }
    else { fd.append('api', apiKey); }

    progressTitle.textContent = '上传中 ' + (idx + 1) + ' / ' + files.length;
    progressDetail.textContent = file.name;
    progressBar.style.width = ((idx / files.length) * 100) + '%';

    // F1 修复：CSRF token 改用 X-CSRF-Token Header 传递（与 M5 修复模式一致，不再放 Body）
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken } })
      .then(function(res) {
        if (!res.ok) {
          return res.text().then(function(t) {
            errList.push({ name: file.name, msg: '状态码 ' + res.status + (t ? '：' + t.substring(0, 200) : '') });
            done++; next(idx + 1);
          });
        }
        return res.text().then(function(raw) {
          var body;
          try { body = JSON.parse(raw); } catch(e) { body = null; }
          var isObj = body && typeof body === 'object';
          var code = isObj ? (Number(body.code) || (body.status === true ? 200 : 0)) : 0;
          var ok = isObj && (body.status === true || code === 200);
          var path = isObj ? (body.path || (body.data && body.data.links && body.data.links.url)) : '';
          var msg = isObj ? (body.msg || body.message || '上传失败') : ('接口返回非 JSON：' + raw.substring(0, 100));
          done++;
          if (ok && path) { okList.push({ name: file.name, url: path }); }
          else { errList.push({ name: file.name, msg: code ? '状态码 ' + code + (msg ? '：' + msg : '') : msg }); }
          next(idx + 1);
        });
      })
      .catch(function(err) {
        done++;
        errList.push({ name: file.name, msg: '网络错误：' + (err.message || '无法连接') });
        next(idx + 1);
      });
  }

  function finish() {
    try {
      progressBar.style.width = '100%';
      setTimeout(function() { progressWrap.style.display = 'none'; }, 300);
      resultWrap.style.display = 'block';

      if (okList.length > 0) {
        successBox.style.display = 'block';
        errorBox.style.display = 'none';

        var lastUrl = okList[okList.length - 1].url;
        resultLinkText.value = okList.length === 1 ? lastUrl : ('共 ' + okList.length + ' 张，最后一张：' + lastUrl);
        resultLinkText._allLinks = okList.map(function(x) { return x.url; }).join('\n');

        okList.forEach(function(item) {
          uploadHistory.unshift({ time: new Date().toLocaleString('zh-CN', { hour12: false }), file: item.name, url: item.url });
        });
        if (uploadHistory.length > 200) uploadHistory = uploadHistory.slice(0, 200);
        localStorage.setItem('uploadHistory', JSON.stringify(uploadHistory));
        renderHistory();

        var msg = okList.length === 1 ? '上传成功！' : (okList.length + ' 张上传成功');
        if (errList.length > 0) msg += '，' + errList.length + ' 张失败';
        notify(msg, 'success');
      }

      if (errList.length > 0) {
        errorBox.style.display = 'block';
        errorMsg.textContent = errList.map(function(x) { return x.name + ': ' + x.msg; }).join('\n');
        if (okList.length === 0) {
          successBox.style.display = 'none';
          notify('上传失败，请检查网络或图床配置', 'error');
        }
      }

      if (okList.length > 0 || errList.length > 0) {
        recordStats(okList.length, errList.length);
      }
    } finally {
      files = [];
      renderFiles();
      uploading = false;
      btnIcon.className = 'mdi mdi-upload';
      btnLabel.textContent = '上传';
      if(uploadCountEl) uploadCountEl.textContent = '0';
      uploadBtn.disabled = uploadBtn.dataset.needLogin ? false : true;
    }
  }

  next(0);
});

// ===== 服务端统计同步 =====
function recordStats(successCnt, failCnt) {
  var fd = new FormData();
  fd.append('success', successCnt);
  fd.append('fail', failCnt);
  // F1 修复：CSRF token 改用 X-CSRF-Token Header 传递（与 M5 修复模式一致，不再放 Body）
  fetch('./api/stats.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrfToken } })
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d.status) updateStatDisplay(d); }).catch(function() {});
}

function fetchStats() {
  fetch('./api/stats.php')
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d.status) updateStatDisplay(d); }).catch(function() {});
}

function updateStatDisplay(d) {
  document.getElementById('totalUploads').textContent = d.total_success.toLocaleString();
  document.getElementById('todayUploads').textContent = d.today_success;
  var totalAttempts = d.total_success + d.total_fail;
  var rate = totalAttempts > 0 ? Math.round((d.total_success / totalAttempts) * 100) : 100;
  document.getElementById('successRate').textContent = rate + '%';
  localStorage.setItem('totalUploads', d.total_success);
  localStorage.setItem('todayUploads', d.today_success);

  // 更新趋势图数据
  updateTrendData(d.today_success);

  // 如果服务端返回了趋势数据，用真实数据替换
  if (d.trend) {
    updateTrendFromServer(d.trend);
  }
}

// ===== 我的存储（与个人中心一致：真实配额，来源 package_info）=====
function formatSize(bytes){
  bytes = parseInt(bytes) || 0;
  if(bytes < 1024) return bytes + ' B';
  if(bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
  if(bytes < 1073741824) return (bytes/1048576).toFixed(2) + ' MB';
  return (bytes/1073741824).toFixed(2) + ' GB';
}

function loadStorageWidget(){
  var fillEl = document.getElementById('storageFill');
  if(!fillEl) return; // 未登录不显示该模块
  fetch('./api/user_api.php?action=package_info&_t=' + Date.now())
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.code !== 0 || !d.data) return;
      var used = parseInt(d.data.storage_used) || 0;
      var limit = parseInt(d.data.storage_limit);
      if(isNaN(limit)) limit = 0;
      var isUnlimited = (limit === -1);
      var pct = 0;
      if(!isUnlimited && limit > 0){
        pct = Math.min(100, Math.round((used / limit) * 100));
      }
      fillEl.style.width = isUnlimited ? '100%' : Math.max(pct, 4) + '%';
      var tagEl = document.getElementById('storageTag');
      if(tagEl){
        if(isUnlimited){ tagEl.textContent = '无限'; tagEl.style.background = ''; tagEl.style.color = ''; }
        else if(pct >= 90){ tagEl.textContent = '已满'; tagEl.style.background = 'rgba(255,71,87,0.12)'; tagEl.style.color = 'var(--danger)'; }
        else if(pct >= 70){ tagEl.textContent = '紧张'; tagEl.style.background = 'rgba(255,166,0,0.12)'; tagEl.style.color = 'var(--warning)'; }
        else { tagEl.textContent = '正常'; tagEl.style.background = ''; tagEl.style.color = ''; }
      }
      var usedEl = document.getElementById('storageUsed');
      if(usedEl) usedEl.textContent = '已用 ' + formatSize(used);
      var totalEl = document.getElementById('storageTotal');
      if(totalEl) totalEl.textContent = isUnlimited ? '上限 无限制' : '上限 ' + formatSize(limit);
    })
    .catch(function(){});
}
loadStorageWidget();

// ===== 弹窗通知（首次打开弹出，关闭后不再弹出，直到内容变更）=====
(function(){
  var overlay = document.getElementById('noticeModalOverlay');
  if(!overlay) return;
  var noticeHash = '<?php echo $siteNoticeHash;?>';
  var STORAGE_KEY = 'site_notice_closed_hash';
  var savedHash = null;
  try { savedHash = localStorage.getItem(STORAGE_KEY); } catch(e){}
  if(savedHash !== noticeHash){
    overlay.classList.add('show');
  }
  document.getElementById('noticeModalClose').addEventListener('click', function(){
    overlay.classList.remove('show');
    try { localStorage.setItem(STORAGE_KEY, noticeHash); } catch(e){}
  });
})();

// ===== 历史从API获取 =====
function fetchHistoryFromApi() {
  fetch('./api/v1/images?page=1&order=newest')
    .then(function(r) { return r.json(); })
    .then(function(body) {
      if (body.status === true && body.data && body.data.data && body.data.data.length > 0) {
        var seen = {};
        uploadHistory.forEach(function(h) { seen[h.url] = true; });
        body.data.data.forEach(function(img) {
          var url = (img.links && img.links.url) || '';
          if (url && !seen[url]) {
            uploadHistory.unshift({ time: img.date || img.human_date || '', file: img.name || '', url: url });
            seen[url] = true;
          }
        });
        if (uploadHistory.length > 200) uploadHistory = uploadHistory.slice(0, 200);
        localStorage.setItem('uploadHistory', JSON.stringify(uploadHistory));
        renderHistory();
      }
    }).catch(function() {});
}

// ===== 复制 =====
copyBtn.addEventListener('click', function() {
  var text = resultLinkText._allLinks || resultLinkText.value;
  if (!text) { notify('没有可复制的链接', 'warning'); return; }

  var origHTML = copyBtn.innerHTML;
  copyBtn.innerHTML = '<i class="mdi mdi-check"></i> 已复制';
  copyBtn.style.background = 'var(--success)';

  var done = function(ok) {
    notify(ok ? '链接已复制到剪贴板！' : '复制失败，请手动复制', ok ? 'success' : 'error');
    setTimeout(function() {
      copyBtn.innerHTML = origHTML;
      copyBtn.style.background = '';
    }, 2000);
  };

  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function() { done(true); }).catch(function() { fallbackCopy(text, done); });
    } else { fallbackCopy(text, done); }
  } catch(e) { fallbackCopy(text, done); }

  function fallbackCopy(t, cb) {
    var ta = document.createElement('textarea');
    ta.value = t; ta.style.position = 'fixed'; ta.style.left = '-9999px'; ta.style.top = '0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); cb(true); } catch(e) { cb(false); }
    document.body.removeChild(ta);
  }
});

// ===== 图片预览代理 =====
function getPreviewUrl(url) {
  var proxyDomains = ['oss.yootn.com'];
  var baiduDomains = ['z1.oocc.top'];
  for (var i = 0; i < proxyDomains.length; i++) {
    if (url.indexOf(proxyDomains[i]) !== -1) return './api/imgbrige.php?url=' + encodeURIComponent(url);
  }
  for (var j = 0; j < baiduDomains.length; j++) {
    if (url.indexOf(baiduDomains[j]) !== -1) return 'https://image.baidu.com/search/down?url=' + encodeURIComponent(url);
  }
  return url;
}

// ===== 历史渲染 =====
function buildHistoryItem(item) {
  var thumb = escAttr(safeUrl(getPreviewUrl(item.url)));
  return '<div class="history-item">' +
    '<div class="history-thumb">' +
      '<img src="' + thumb + '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\';" loading="lazy">' +
      '<i class="mdi mdi-file-image" style="display:none;"></i>' +
    '</div>' +
    '<div class="history-info"><div class="name">' + esc(item.file) + '</div><div class="meta">' + esc(item.time) + '</div></div>' +
    '<a href="' + escAttr(safeUrl(item.url)) + '" target="_blank" rel="noopener" class="history-link"><i class="mdi mdi-open-in-new"></i></a></div>';
}

function renderHistory() {
  if (uploadHistory.length === 0) {
    historyList.innerHTML = '<div class="history-empty">暂无上传记录</div>';
    return;
  }
  historyList.innerHTML = uploadHistory.slice(0, 8).map(buildHistoryItem).join('');
}

document.getElementById('loadMoreBtn').addEventListener('click', function() {
  var cur = historyList.querySelectorAll('.history-item').length;
  var show = uploadHistory.slice(0, cur + 10);
  historyList.innerHTML = show.map(buildHistoryItem).join('');
  if (cur + 10 >= uploadHistory.length) { this.innerHTML = '已加载全部'; this.disabled = true; }
});

// ===== 事件绑定 =====
document.getElementById('clearFilesBtn').addEventListener('click', clearFiles);
if(clearAllBtn) clearAllBtn.addEventListener('click', clearFiles);
document.getElementById('clearHistoryBtn').addEventListener('click', clearHistory);

// ===== 趋势图 =====
var trendData = {
  week: { labels: ['周一','周二','周三','周四','周五','周六','周日'], data: [0,0,0,0,0,0,0] },
  month: { labels: ['第1周','第2周','第3周','第4周'], data: [0,0,0,0] },
  year: { labels: ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'], data: [0,0,0,0,0,0,0,0,0,0,0,0] }
};
var currentRange = 'week';
var trendLoaded = false; // 是否已从服务端加载过真实数据

// 从服务端趋势数据更新本地
function updateTrendFromServer(serverTrend) {
  if (!serverTrend) return;
  if (serverTrend.week && serverTrend.week.data) {
    trendData.week.labels = serverTrend.week.labels || trendData.week.labels;
    trendData.week.data = serverTrend.week.data;
  }
  if (serverTrend.month && serverTrend.month.data) {
    trendData.month.labels = serverTrend.month.labels || trendData.month.labels;
    trendData.month.data = serverTrend.month.data;
  }
  if (serverTrend.year && serverTrend.year.data) {
    trendData.year.labels = serverTrend.year.labels || trendData.year.labels;
    trendData.year.data = serverTrend.year.data;
  }
  trendLoaded = true;
  renderTrendChart();
}

function updateTrendData(todayCount) {
  // 在服务端数据未到达前，用今日上传量临时填充本周今天的数据
  if (!trendLoaded) {
    var today = new Date().getDay();
    today = today === 0 ? 6 : today - 1;
    trendData.week.data[today] = todayCount;
    renderTrendChart();
  }
}

function renderTrendChart() {
  var data = trendData[currentRange].data;
  var n = data.length;

  // 更新汇总数字（不渲染图表）
  var total = data.reduce(function(a,b) { return a+b; }, 0);
  var avg = n > 0 ? Math.round(total / n) : 0;
  var peak = Math.max.apply(null, data);
  var min = Math.min.apply(null, data);
  document.getElementById('trendTotal').textContent = total;
  document.getElementById('trendAvg').textContent = avg;
  document.getElementById('trendPeak').textContent = peak;
  document.getElementById('trendMin').textContent = min;
}

// 趋势图切换
document.querySelectorAll('.chart-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.chart-tab').forEach(function(t) { t.classList.remove('active'); });
    this.classList.add('active');
    currentRange = this.dataset.range;
    renderTrendChart();
  });
});

// ===== 初始化 =====
(function init() {
  renderFiles();
  renderHistory();

  // 缓存显示
  var cachedTotal = localStorage.getItem('totalUploads') || '0';
  var cachedToday = localStorage.getItem('todayUploads') || '0';
  document.getElementById('totalUploads').textContent = parseInt(cachedTotal).toLocaleString();
  document.getElementById('todayUploads').textContent = cachedToday;

  // 初始化趋势图：先用缓存今日数据临时填充，等服务端数据到达后替换
  var todayUploads = parseInt(cachedToday || '0');
  var today = new Date().getDay();
  today = today === 0 ? 6 : today - 1;
  trendData.week.data[today] = todayUploads;

  renderTrendChart();
  fetchStats();
  fetchHistoryFromApi();
})();
</script>
</body>
</html>
