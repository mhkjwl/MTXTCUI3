<?php
/**
 * @file index.php
 * @description 用户中心仪表盘，上传管理图片、修改密码、查看统计
 * @author AI
 * @version 1.3.6-dev
 * @date 2026-08-05
 */
declare(strict_types=1);

/**
 * 用户中心 - 仪表盘
 * 上传、管理图片、修改密码、查看统计
 * 超级管理员可查看所有用户图片
 */
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require('../inc/common.php');

// 未登录跳转到登录页
if(!$isUserLoggedIn) {
    header('Location: login.php');
    exit;
}

// ========== 图床配置（统一配置源，与主站 index.php / api/upload.php 共用 get_api_config()） ==========
// 修复：原硬编码缺失 imgw/xwyue/keye/shaitu/guaigua/imgtolink 6 个接口，用户中心无法选择这些图床
$apiConfig = get_api_config();

$defaultApi = isset($conf['api_default']) ? $conf['api_default'] : '';
$defaultValid = false;
if($defaultApi !== '') {
    $defEnableKey = 'api_'.$defaultApi.'_enable';
    $defaultValid = isset($conf[$defEnableKey]) && $conf[$defEnableKey] == '1';
}
if(!$defaultValid) {
    $defaultApi = '';
    foreach($apiConfig as $key => $cfg) {
        $ek = 'api_'.$key.'_enable';
        if(isset($conf[$ek]) && $conf[$ek] == '1') { $defaultApi = $key; break; }
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

$siteName    = isset($conf['name']) ? $conf['name'] : '图床';
// 弹窗通知内容：为空则前端不弹出；配合内容哈希实现"关闭后不再弹出，直到内容变更"
$siteNotice  = !empty($conf['jieshao']) ? $conf['jieshao'] : '';
$siteNoticeHash = $siteNotice !== '' ? md5($siteNotice) : '';
// 警告固定横幅内容
$siteWarning = !empty($conf['Copyright']) ? $conf['Copyright'] : '';
$siteEmail   = isset($conf['email']) && $conf['email'] !== '' ? $conf['email']
             : (isset($conf['picui_email']) ? $conf['picui_email'] : '');
$csrfToken   = csrf_token();
$username    = isset($currentUser['username']) ? $currentUser['username'] : '用户';
$userAvatar  = !empty($currentUser['avatar']) ? $currentUser['avatar'] : '';
$userRole    = $currentUserRole;
$isSuperAdmin = ($userRole === 'super_admin');
$firstChar   = mb_substr($username, 0, 1, 'UTF-8');

// 个人信息展示字段（用于 home-section 顶部 profile-card）
$userEmail       = isset($currentUser['email']) && $currentUser['email'] !== '' ? $currentUser['email'] : '';
$userCreatedAt   = isset($currentUser['created_at']) && $currentUser['created_at'] ? $currentUser['created_at'] : '';
$userLastLogin   = isset($currentUser['last_login']) && $currentUser['last_login'] ? $currentUser['last_login'] : '';
$userEmailDisplay = $userEmail !== '' ? htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') : '未设置';
$userCreatedText  = $userCreatedAt !== '' ? date('Y-m-d', strtotime($userCreatedAt)) : '-';
$userLastLoginText = $userLastLogin !== '' ? date('Y-m-d H:i', strtotime($userLastLogin)) : '从未登录';

// ========== 套餐接口过滤 ==========
$allowedApiInfo = ['api_keys'=>[], 's3_ids'=>[]];
$hasApiRestriction = false;
if(!$isSuperAdmin) {
    $allowedApiInfo = get_user_allowed_apis($DB, (int)$currentUserId);
    // has_group=true 表示用户已绑定分组，即使分组内接口全部已关闭，
    // 也应标记为受限，防止前端放行全部已启用接口
    $hasApiRestriction = !empty($allowedApiInfo['api_keys']) || !empty($allowedApiInfo['s3_ids']) || !empty($allowedApiInfo['has_group']);
    // 如果默认接口被过滤掉，选一个新的
    if($hasApiRestriction && !in_array($defaultApi, $allowedApiInfo['api_keys'])) {
        $defaultApi = '';
        foreach($allowedApiInfo['api_keys'] as $ak) {
            $ek = 'api_'.$ak.'_enable';
            if(isset($conf[$ek]) && $conf[$ek] == '1') { $defaultApi = $ak; break; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>用户中心 - <?php echo htmlspecialchars($siteName);?></title>
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
    [data-theme="dark"] .sidebar{background:rgba(30,26,46,0.62);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .soft-card,
    [data-theme="dark"] .tag-cluster span,
    [data-theme="dark"] .hero-badge,
    [data-theme="dark"] .queue-item,
    [data-theme="dark"] .mini-stats div,
    [data-theme="dark"] .insight-footer div,
    [data-theme="dark"] .profile-stats div,
    [data-theme="dark"] .usage-item,
    [data-theme="dark"] .record-item,
    [data-theme="dark"] .gallery-card,
    [data-theme="dark"] .stat-card{background:rgba(30,26,46,0.72);border-color:rgba(123,140,255,0.14)}
    [data-theme="dark"] .ghost-btn{background:rgba(40,35,60,0.8);color:var(--text)}
    [data-theme="dark"] .field input,
    [data-theme="dark"] .field textarea,
    [data-theme="dark"] .field select,
    [data-theme="dark"] .api-select,
    [data-theme="dark"] #gallerySearch,
    [data-theme="dark"] #redeemCodeInput,
    [data-theme="dark"] #akKeyName,
    [data-theme="dark"] input[type="text"],
    [data-theme="dark"] input[type="password"]{background-color:rgba(30,26,46,0.78);color:var(--text);border-color:rgba(123,140,255,0.16)}
    [data-theme="dark"] .result-link input{background:rgba(30,26,46,0.8)}

    /* ============ 集成样式 ============ */
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
    /* 移动端：topbar-actions 允许换行 + profile-card 纵向 + stats-grid 2列 */
    @media(max-width:768px){
      .topbar-actions{flex-wrap:wrap;justify-content:flex-end;gap:8px}
      .profile-card{flex-direction:column;text-align:center;gap:16px;padding:20px}
      .profile-avatar{width:64px;height:64px;font-size:26px}
      .profile-head{flex-direction:column;align-items:center;gap:12px}
      .profile-name-row{justify-content:center}
      .profile-head-actions{flex-direction:column;gap:10px;width:100%}
      .profile-head-actions .profile-pwd-btn{width:100%;justify-content:center}
      .profile-meta{grid-template-columns:1fr;gap:10px 16px}
      .profile-meta-item{justify-content:center;font-size:0.84rem}
      .stats-grid{grid-template-columns:1fr!important}
      #home-section .hero-grid{grid-template-columns:1fr}
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
    .modal-box{background:var(--panel-strong);backdrop-filter:blur(24px);border-radius:24px;box-shadow:var(--shadow);border:1px solid var(--line);padding:28px;max-width:460px;width:90%;animation:modal-in .3s var(--ease-out)}
    @keyframes modal-in{from{opacity:0;transform:scale(0.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    .modal-title{margin:0 0 16px;font-size:1.2rem;font-weight:700;display:flex;align-items:center;gap:8px}
    .modal-body{margin:0 0 24px;color:var(--muted);line-height:1.6}
    .modal-actions{display:flex;gap:12px;justify-content:flex-end}
    .modal-btn{padding:12px 20px;border-radius:14px;border:0;cursor:pointer;font:inherit;font-weight:600;transition:transform .2s ease,background .2s ease;display:inline-flex;align-items:center;gap:6px}
    .modal-btn:hover{transform:translateY(-1px)}
    .modal-btn.cancel{background:rgba(128,139,194,0.12);color:var(--muted)}
    .modal-btn.primary,.modal-btn.confirm{background:var(--gradient-main);color:#fff;box-shadow:0 8px 20px rgba(133,102,255,0.22)}

    /* 密码表单 */
    .pwd-form-group{margin-bottom:14px}
    .pwd-form-group label{display:block;font-size:0.88rem;font-weight:600;margin-bottom:6px;color:var(--text)}
    .pwd-form-group input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.92rem;color:var(--text);transition:border .2s ease}
    .pwd-form-group input:focus{outline:none;border-color:var(--primary)}

    /* API 选择器 */
    .api-select-wrap{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .api-select-wrap label{font-size:0.88rem;font-weight:600;color:var(--text)}
    .api-select{padding:12px 40px 12px 16px;border-radius:14px;background:rgba(255,255,255,0.8);border:1px solid rgba(128,139,194,0.16);color:var(--text);font:inherit;font-size:0.92rem;font-weight:600;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%236a7191' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;min-width:180px;flex:1;max-width:320px}

    /* 上传区适配 */
    .upload-card{padding:28px}
    /* 套餐区缩小 + 上传区加宽 */
    .hero-grid{grid-template-columns:0.85fr 1.15fr}
    @media(max-width:1024px){.hero-grid{grid-template-columns:1fr}}
    .upload-zone{padding:32px;border:2px dashed rgba(136,142,212,0.28);border-radius:26px;background:linear-gradient(135deg,rgba(123,140,255,0.06),rgba(255,122,198,0.04));text-align:center;cursor:pointer;transition:border .25s ease,background .25s ease}
    .upload-zone.dragover{border-color:var(--primary);background:linear-gradient(135deg,rgba(123,140,255,0.14),rgba(255,122,198,0.1))}
    .upload-zone .dropzone-icon{width:60px;height:60px;border-radius:20px;background:var(--gradient-main);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#fff;font-size:28px}
    .upload-zone h4{margin:0 0 8px;font-size:1.15rem}
    .upload-zone h3{margin:0 0 8px;font-size:1.15rem}
    .upload-zone p{margin:0 0 16px;color:var(--muted)}
    .upload-zone .browse-btn{margin:0 auto}
    .file-names{margin-top:12px;font-size:0.84rem;color:var(--muted);text-align:left}
    .file-names div{padding:4px 0}
    .upload-formats{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px}
    .format-tag{padding:4px 10px;border-radius:999px;background:rgba(123,140,255,0.1);color:var(--muted);font-size:0.78rem;font-weight:600}

    /* 文件队列 */
    .queue-section{margin-top:20px}
    .queue-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .queue-head .queue-title{font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px}
    .queue-head .count{color:var(--primary);font-weight:700}
    .queue-clear{padding:6px 12px;border-radius:10px;border:0;background:rgba(255,127,168,0.12);color:#e53e6b;cursor:pointer;font-size:0.84rem;display:inline-flex;align-items:center;gap:4px}
    .queue-clear:hover{background:rgba(255,127,168,0.22)}
    .queue-list{display:grid;gap:10px}
    .queue-item{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:16px;background:rgba(255,255,255,0.75);border:1px solid rgba(128,139,194,0.12)}
    [data-theme="dark"] .queue-item{background:rgba(30,26,46,0.72)}
    .queue-item .file-icon{font-size:24px;color:var(--primary)}
    .queue-item .queue-info{flex:1;min-width:0}
    .queue-item .name{font-weight:600;font-size:0.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .queue-item .meta{font-size:0.82rem;color:var(--muted);margin-top:2px}
    .queue-cancel{width:32px;height:32px;border-radius:10px;border:0;background:rgba(255,127,168,0.12);color:#e53e6b;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0}

    /* 上传操作 */
    .upload-actions{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;align-items:center;justify-content:center}
    .upload-actions .primary-btn{display:inline-flex;align-items:center;gap:8px}
    .upload-actions .primary-btn:disabled{opacity:0.5;cursor:not-allowed}
    .upload-actions .ghost-btn{display:inline-flex;align-items:center;gap:8px}
    .status-text{margin-top:12px;font-size:0.84rem;color:var(--muted)}

    /* 进度条 */
    .progress-wrap{margin-top:20px;padding:18px;border-radius:18px;background:rgba(123,140,255,0.06);display:none}
    .progress-title{font-weight:700;margin-bottom:12px;font-size:0.92rem}
    .progress-detail{font-size:0.82rem;color:var(--muted);margin-top:8px}
    .progress-bar-wrap{height:12px;background:rgba(123,140,255,0.12);border-radius:999px;overflow:hidden}
    .progress-bar{display:block;height:100%;background:var(--gradient-main);border-radius:inherit;transition:width .4s ease}

    /* 结果区 */
    .result-wrap{margin-top:20px;display:none}
    .result-success{padding:18px;border-radius:18px;background:rgba(82,216,162,0.08);border:1px solid rgba(82,216,162,0.2)}
    .result-link{display:flex;gap:10px;align-items:center;margin-top:8px}
    .result-link input{flex:1;padding:12px 16px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;font-size:0.88rem;color:var(--text)}
    .result-error{padding:18px;border-radius:18px;background:rgba(255,127,168,0.08);border:1px solid rgba(255,127,168,0.2);color:#e53e6b;margin-top:12px}

    /* 个人信息卡片 */
    .profile-card{display:flex;align-items:center;gap:24px;padding:24px 28px;margin-bottom:24px}
    .profile-avatar-wrap{flex-shrink:0}
    .profile-avatar{width:80px;height:80px;border-radius:50%;background:var(--gradient-main);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:32px;overflow:hidden;box-shadow:0 6px 18px rgba(123,140,255,0.25)}
    .profile-avatar img{width:100%;height:100%;object-fit:cover}
    .profile-info{flex:1;min-width:0}
    .profile-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
    .profile-head-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0}
    .profile-name-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .profile-name{margin:0;font-size:1.4rem;font-weight:800;color:var(--text)}
    .profile-pwd-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,0.7);color:var(--text);font-size:0.86rem;font-weight:600;cursor:pointer;transition:all .2s ease;flex-shrink:0}
    [data-theme="dark"] .profile-pwd-btn{background:rgba(30,26,46,0.7)}
    .profile-pwd-btn:hover{background:rgba(123,140,255,0.12);border-color:var(--primary);color:var(--primary)}
    .profile-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 24px}
    .profile-meta-item{display:flex;align-items:center;gap:8px;font-size:0.88rem;min-width:0}
    .profile-meta-item i{color:var(--primary);font-size:18px;flex-shrink:0}
    .profile-meta-label{color:var(--muted);flex-shrink:0}
    .profile-meta-value{color:var(--text);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}

    /* 统计卡片 */
    .stat-card .stat-top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}
    .stat-card .stat-chip{padding:8px 12px;border-radius:999px;font-size:0.78rem;font-weight:700;color:#fff}
    .stat-card .stat-chip.blue{background:linear-gradient(135deg,#67b7ff,#59e3ff)}
    .stat-card .stat-chip.green{background:linear-gradient(135deg,#52d8a2,#7be495)}
    .stat-card .stat-chip.rose{background:linear-gradient(135deg,#ff7fa8,#ff9d6c)}
    .stat-card .stat-chip.violet{background:linear-gradient(135deg,#8e7dff,#c67dff)}
    .stat-val{font-size:1.8rem;font-weight:800;background:var(--gradient-main);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
    .stat-lbl{color:var(--muted);font-size:0.84rem;margin-top:4px}

    /* 套餐/存储 */
    .plan-row{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:16px}
    .plan-row .price-row{font-size:1.2rem;font-weight:800}
    .plan-row p{color:var(--muted);font-size:0.88rem;margin-top:4px}
    .usage-list{display:grid;gap:14px}
    .usage-item{padding:14px;border-radius:16px;background:rgba(255,255,255,0.6);border:1px solid var(--line)}
    [data-theme="dark"] .usage-item{background:rgba(30,26,46,0.6)}
    .usage-top{display:flex;justify-content:space-between;font-size:0.88rem;margin-bottom:8px}
    .usage-top b{color:var(--text);font-weight:700}
    .redeem-row{display:flex;gap:10px;margin-top:14px}
    .redeem-row input{flex:1;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.92rem;color:var(--text)}
    .redeem-row input:focus{outline:none;border-color:var(--primary)}

    /* 图库 */
    .gallery-toolbar{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
    .gallery-search{flex:1;min-width:180px;position:relative;display:flex;align-items:center}
    .gallery-search i{position:absolute;left:14px;color:var(--muted);font-size:18px;pointer-events:none}
    .gallery-search input{width:100%;padding:12px 14px 12px 40px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.92rem;color:var(--text)}
    .gallery-search input:focus{outline:none;border-color:var(--primary)}
    .user-filter-wrap{display:flex;align-items:center;gap:8px}
    .user-filter-wrap label{font-size:0.84rem;color:var(--muted);white-space:nowrap}
    .user-filter{padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,0.8);font:inherit;font-size:0.88rem;color:var(--text);cursor:pointer}
    .gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
    /* 图片卡片 */
    .img-card{border-radius:18px;overflow:hidden;background:rgba(255,255,255,0.75);border:1px solid rgba(128,139,194,0.12);transition:transform .2s ease,box-shadow .2s ease;display:flex;flex-direction:column}
    [data-theme="dark"] .img-card{background:rgba(30,26,46,0.72)}
    .img-card:hover{transform:translateY(-3px);box-shadow:0 16px 30px rgba(126,129,188,0.12)}
    /* 缩略图 */
    .img-thumb{position:relative;aspect-ratio:1;overflow:hidden;background:linear-gradient(135deg,rgba(123,140,255,0.08),rgba(255,122,198,0.06))}
    .img-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s ease}
    .img-card:hover .img-thumb img{transform:scale(1.05)}
    .img-thumb .placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:36px;color:var(--muted);opacity:0.4}
    /* 悬浮操作按钮 */
    .img-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(180deg,transparent 40%,rgba(0,0,0,0.55));opacity:0;transition:opacity .25s ease}
    .img-card:hover .img-overlay{opacity:1}
    .overlay-btn{width:38px;height:38px;border-radius:12px;border:0;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:transform .2s ease,background .2s ease;color:#fff;background:rgba(255,255,255,0.18);backdrop-filter:blur(8px);text-decoration:none}
    .overlay-btn:hover{transform:translateY(-2px) scale(1.05);background:rgba(255,255,255,0.32)}
    .overlay-btn.copy:hover{background:linear-gradient(135deg,#67b7ff,#59e3ff)}
    .overlay-btn.view:hover{background:linear-gradient(135deg,#8e7dff,#c67dff)}
    .overlay-btn.delete:hover{background:linear-gradient(135deg,#ff7fa8,#ff9d6c)}
    /* 元信息 */
    .img-meta{padding:12px 14px;flex:1;display:flex;flex-direction:column;gap:6px}
    .img-meta .name{font-size:0.86rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
    .img-meta .info{display:flex;flex-wrap:wrap;gap:6px 10px;font-size:0.76rem;color:var(--muted)}
    .img-meta .info span{display:inline-flex;align-items:center;gap:3px;white-space:nowrap}
    .img-meta .info .api-tag{padding:1px 7px;border-radius:999px;background:rgba(123,140,255,0.12);color:var(--primary);font-weight:600;font-size:0.72rem}
    .gallery-empty{text-align:center;padding:40px 20px;color:var(--muted);grid-column:1/-1}
    .gallery-empty .icon{font-size:40px;margin-bottom:8px;opacity:0.5}
    .gallery-empty .title{font-size:0.92rem;font-weight:600;color:var(--text)}
    .gallery-empty .desc{font-size:0.82rem;margin-top:4px}
    .pagination{display:flex;gap:6px;justify-content:center;margin-top:18px;flex-wrap:wrap}
    .pagination button{padding:8px 14px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,0.7);color:var(--text);cursor:pointer;font:inherit;font-size:0.84rem}
    .pagination button.active{background:var(--gradient-main);color:#fff;border-color:transparent}
    .pagination button:disabled{opacity:0.4;cursor:not-allowed}

    /* API 密钥列表 */
    .ak-item{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:16px;background:rgba(255,255,255,0.75);border:1px solid rgba(128,139,194,0.12);margin-bottom:10px;transition:transform .2s ease,box-shadow .2s ease}
    [data-theme="dark"] .ak-item{background:rgba(30,26,46,0.72)}
    .ak-item:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(126,129,188,0.1)}
    .ak-item .ak-icon{width:40px;height:40px;border-radius:12px;background:var(--gradient-soft);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:20px;flex-shrink:0}
    .ak-item .ak-info{flex:1;min-width:0}
    .ak-item .ak-name{font-weight:600;font-size:0.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ak-item .ak-meta{font-size:0.8rem;color:var(--primary);margin-top:3px;font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace}
    .ak-item .ak-time{font-size:0.76rem;color:var(--muted);margin-top:3px}
    .ak-item .ak-status{font-size:0.78rem;font-weight:600;min-width:60px;text-align:center;padding:4px 10px;border-radius:999px;flex-shrink:0}
    .ak-item .ak-status.on{background:rgba(82,216,162,0.14);color:#22a36d}
    .ak-item .ak-status.off{background:rgba(255,127,168,0.14);color:#e53e6b}
    .ak-item .ak-actions{display:flex;gap:6px;flex-shrink:0}
    .ak-item .ak-actions button{padding:8px 12px;border-radius:10px;border:0;background:rgba(128,139,194,0.12);color:var(--muted);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s ease,color .2s ease}
    .ak-item .ak-actions button:hover{background:rgba(123,140,255,0.18);color:var(--primary)}
    .ak-item .ak-actions button.danger:hover{background:rgba(255,127,168,0.18);color:#e53e6b}

    /* 明文密钥突出显示 */
    .ak-plain-key{
      position:relative;
      background:linear-gradient(135deg,rgba(123,140,255,0.1),rgba(155,125,255,0.06),rgba(255,122,198,0.04));
      border:2px solid var(--primary);
      border-radius:16px;
      padding:18px 20px;
      font-family:var(--font-mono);
      font-size:1.05rem;
      font-weight:600;
      line-height:1.6;
      word-break:break-all;
      color:var(--primary);
      box-shadow:0 0 24px rgba(123,140,255,0.18);
      animation:akPlainPulse 2s ease-in-out infinite;
    }
    @keyframes akPlainPulse{
      0%,100%{box-shadow:0 0 24px rgba(123,140,255,0.18)}
      50%{box-shadow:0 0 36px rgba(123,140,255,0.32)}
    }
    [data-theme="dark"] .ak-plain-key{
      background:linear-gradient(135deg,rgba(155,125,255,0.16),rgba(123,140,255,0.08));
      color:#b4a3ff;
      border-color:#9b7dff;
    }

    /* 图片查看器 */
    .viewer-overlay{display:none;position:fixed;inset:0;z-index:6000;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:40px}
    .viewer-overlay.show{display:flex}
    .viewer-close{position:absolute;top:20px;right:20px;width:44px;height:44px;border-radius:14px;border:0;background:rgba(255,255,255,0.12);color:#fff;cursor:pointer;font-size:22px;display:flex;align-items:center;justify-content:center}
    .viewer-img{max-width:90%;max-height:80vh;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,0.5)}
    .viewer-info{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.6);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,0.12);border-radius:14px;padding:12px 18px;color:#fff;font-size:0.88rem;max-width:90%;text-align:center}

    /* 存储卡 */
    .storage-value{font-size:1.6rem;font-weight:800;background:var(--gradient-main);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}

    /* 横幅 */
    .site-warning-banner{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:16px;background:linear-gradient(90deg,rgba(255,127,168,0.12),rgba(255,193,87,0.08));border:1px solid rgba(255,127,168,0.2);color:#e53e6b;font-size:0.88rem;margin-bottom:18px}

    /* 站点通知弹窗 */
    .notice-modal-overlay{display:none;position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
    .notice-modal-overlay.show{display:flex}
    .notice-modal{background:var(--panel-strong);backdrop-filter:blur(24px);border-radius:24px;box-shadow:var(--shadow);border:1px solid var(--line);padding:28px;max-width:480px;width:90%}
    .notice-modal-head{display:flex;align-items:center;gap:8px;font-size:1.2rem;font-weight:800;margin-bottom:16px}
    .notice-modal-body{color:var(--muted);line-height:1.7;white-space:pre-wrap;max-height:50vh;overflow-y:auto;margin-bottom:20px}
    .notice-modal-foot{text-align:right}
    .notice-modal-btn{padding:12px 24px;border-radius:14px;border:0;background:var(--gradient-main);color:#fff;font-weight:600;cursor:pointer}

    /* 时钟（topbar 内） */
    #clockDisplay{font-size:0.82rem;color:var(--muted);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace;white-space:nowrap;display:flex;align-items:center}

    /* 主题切换按钮 */
    #themeToggle{width:40px;height:40px;border-radius:12px;border:0;background:rgba(255,255,255,0.7);color:var(--text);cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;transition:background .2s ease;flex-shrink:0}
    [data-theme="dark"] #themeToggle{background:rgba(30,26,46,0.7)}
    #themeToggle:hover{background:rgba(123,140,255,0.12)}

    /* 头像 */
    .avatar-circle{width:40px;height:40px;border-radius:50%;background:var(--gradient-main);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:16px;overflow:hidden;flex-shrink:0}
    .avatar-circle img{width:100%;height:100%;object-fit:cover}
    .role-badge{padding:5px 12px;border-radius:999px;font-size:0.86rem;font-weight:700}
    .role-badge.admin{background:linear-gradient(135deg,#ff7fa8,#ff9d6c);color:#fff}
    .role-badge.user{background:linear-gradient(135deg,#67b7ff,#59e3ff);color:#fff}

    /* 页脚 */
    .footer{margin-top:32px;padding:24px;text-align:center;color:var(--muted);font-size:0.84rem;border-top:1px solid var(--line)}
    .footer a{color:var(--primary);text-decoration:none}
    .footer a:hover{text-decoration:underline}

    /* 套餐购买 AJAX 加载区域 */
    .pricing-section-wrap{padding:20px 0}
    .pricing-loading,.pricing-error{text-align:center;padding:60px 20px;color:var(--muted)}
    .pricing-loading i,.pricing-error i{font-size:36px;display:block;margin-bottom:12px}
    .pricing-loading i{color:var(--primary)}
    .pricing-error i{color:var(--danger)}
    .pricing-error p{margin:8px 0 16px;font-size:0.92rem}
    .pricing-content{animation:pricing-fade-in .3s ease}
    @keyframes pricing-fade-in{from{opacity:0}to{opacity:1}}
  </style>
</head>
<body>
  <button class="menu-toggle" id="menuToggle"><i class="mdi mdi-menu"></i></button>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php if($siteNotice !== ''): ?>
  <!-- 站内通知弹窗 -->
  <div class="notice-modal-overlay" id="noticeModalOverlay">
    <div class="notice-modal" role="dialog" aria-modal="true">
      <div class="notice-modal-head">
        <i class="mdi mdi-bullhorn-outline"></i>
        <span>站内通知</span>
      </div>
      <div class="notice-modal-body"><?php echo htmlspecialchars($siteNotice);?></div>
      <div class="notice-modal-foot">
        <button type="button" class="notice-modal-btn" id="noticeModalClose">知道了</button>
      </div>
    </div>
  </div>
<?php endif; ?>

  <div class="page-shell">
    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <div class="brand-mark"><span></span><span></span><span></span></div>
        <div>
          <strong><?php echo htmlspecialchars($siteName);?></strong>
          <p>个人中心</p>
        </div>
      </div>

      <nav class="side-nav">
        <a class="active" href="#home-section"><i class="mdi mdi-home-outline"></i> 首页</a>
        <a href="#gallery-section"><i class="mdi mdi-image-multiple-outline"></i> 我的图片</a>
        <a href="#pricing-section"><i class="mdi mdi-package-variant"></i> 套餐中心</a>
        <a href="#apikeys-section"><i class="mdi mdi-key-variant"></i> API 密钥</a>
        <?php if($isSuperAdmin): ?>
        <a href="../admin/index.php"><i class="mdi mdi-shield-account-outline"></i> 管理后台</a>
        <a href="../admin/user.php"><i class="mdi mdi-account-edit-outline"></i> 修改信息</a>
        <a href="../admin/setting.php"><i class="mdi mdi-cog-outline"></i> 系统设置</a>
        <?php endif; ?>
        <a href="../index.php"><i class="mdi mdi-home-outline"></i> 返回首页</a>
        <a href="logout.php"><i class="mdi mdi-logout"></i> 退出登录</a>
      </nav>

      <div class="storage-card soft-card">
        <div class="section-caption">我的存储</div>
        <div class="storage-value" id="storageTag">--</div>
        <div class="meter"><span id="storageFill" style="width:0%"></span></div>
        <p>已使用 <span id="storageUsed">0 B</span> / <span id="storageTotal">0 B</span></p>
      </div>

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

    <!-- ============ MAIN ============ -->
    <main class="main-content">
      <!-- TOPBAR -->
      <header class="topbar">
        <div>
          <p class="eyebrow">USER CENTER</p>
          <h1>个人中心</h1>
        </div>
        <div class="topbar-actions">
          <div id="clockDisplay"><span></span></div>
          <button id="themeToggle" type="button" title="切换主题"><i class="mdi mdi-weather-night"></i></button>
          <button id="navChangePwd" type="button" title="修改密码"><i class="mdi mdi-lock-reset"></i></button>
          <a class="ghost-btn" href="../api_doc.php"><i class="mdi mdi-file-document-outline"></i> API 文档</a>
          <div class="avatar-circle">
            <?php if($userAvatar): ?>
            <img src="<?php echo htmlspecialchars($userAvatar);?>" alt="avatar">
            <?php else: ?>
            <?php echo htmlspecialchars($firstChar);?>
            <?php endif; ?>
          </div>
          <span class="role-badge <?php echo $isSuperAdmin ? 'admin' : 'user';?>">
            <?php echo $isSuperAdmin ? '超级管理员' : '普通用户';?>
          </span>
        </div>
      </header>

      <?php if($siteWarning): ?>
      <div class="site-warning-banner">
        <i class="mdi mdi-alert-circle-outline"></i>
        <span><?php echo htmlspecialchars($siteWarning);?></span>
      </div>
      <?php endif; ?>

      <!-- 首页聚合：个人信息 + 统计 + 套餐 + 上传 -->
      <section id="home-section" class="page-section">
      <!-- 个人信息卡片 -->
      <article class="profile-card soft-card" id="profileCard">
        <div class="profile-avatar-wrap">
          <div class="profile-avatar">
            <?php if($userAvatar !== ''): ?>
              <img src="<?php echo htmlspecialchars($userAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
            <?php else: ?>
              <span><?php echo htmlspecialchars($firstChar, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="profile-info">
          <div class="profile-head">
            <div class="profile-name-row">
              <h3 class="profile-name"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></h3>
              <span class="role-badge <?php echo $isSuperAdmin ? 'admin' : 'user'; ?>"><?php echo $isSuperAdmin ? '超级管理员' : '普通用户'; ?></span>
            </div>
            <div class="profile-head-actions">
              <button type="button" class="profile-pwd-btn" id="profileChangePwdBtn" title="修改密码">
                <i class="mdi mdi-lock-reset"></i> 修改密码
              </button>
              <a href="logout.php" class="profile-pwd-btn" title="退出登录" style="text-decoration:none;">
                <i class="mdi mdi-logout"></i> 退出登录
              </a>
            </div>
          </div>
          <div class="profile-meta">
            <div class="profile-meta-item">
              <i class="mdi mdi-email-outline"></i>
              <span class="profile-meta-label">邮箱</span>
              <span class="profile-meta-value"><?php echo $userEmailDisplay; ?></span>
            </div>
            <div class="profile-meta-item">
              <i class="mdi mdi-package-variant-closed"></i>
              <span class="profile-meta-label">套餐</span>
              <span class="profile-meta-value" id="profilePkgName">-</span>
            </div>
            <div class="profile-meta-item">
              <i class="mdi mdi-calendar-account-outline"></i>
              <span class="profile-meta-label">注册时间</span>
              <span class="profile-meta-value"><?php echo $userCreatedText; ?></span>
            </div>
            <div class="profile-meta-item">
              <i class="mdi mdi-login-variant"></i>
              <span class="profile-meta-label">最后登录</span>
              <span class="profile-meta-value"><?php echo $userLastLoginText; ?></span>
            </div>
          </div>
        </div>
      </article>

      <!-- 统计卡片 -->
      <section class="stats-grid" id="stats-section">
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip blue">我的上传</span></div>
          <div class="stat-val" id="statMyImages">0</div>
          <div class="stat-lbl">累计图片数</div>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip green">存储</span></div>
          <div class="stat-val" id="statMySize">0</div>
          <div class="stat-lbl">已用空间</div>
        </article>
        <?php if($isSuperAdmin): ?>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip violet">总用户</span></div>
          <div class="stat-val" id="statTotalUsers">0</div>
          <div class="stat-lbl">注册用户</div>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip rose">今日</span></div>
          <div class="stat-val" id="statTodayUploads">0</div>
          <div class="stat-lbl">今日上传</div>
        </article>
        <?php else: ?>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip violet">图片数</span></div>
          <div class="stat-val" id="statTotalImages">0</div>
          <div class="stat-lbl">我的图片</div>
        </article>
        <article class="stat-card soft-card">
          <div class="stat-top"><span class="stat-chip rose">状态</span></div>
          <div class="stat-val">在线</div>
          <div class="stat-lbl">账户状态</div>
        </article>
        <?php endif; ?>
      </section>

      <!-- 套餐中心 + 上传区 -->
      <section class="hero-grid">
        <article class="subscription-card soft-card" id="package-section">
          <div class="section-heading">
            <div>
              <p class="section-caption">Current Plan</p>
              <h3>我的套餐</h3>
            </div>
            <span class="stat-chip violet" id="pkgStatusBadge" style="display:none;">活跃中</span>
          </div>
          <div class="plan-row">
            <div>
              <strong class="price-row"><i class="mdi mdi-crown-outline"></i> <span id="pkgName">加载中...</span></strong>
              <p>等级 <span id="pkgLevelBadge">-</span> · <span id="pkgExpireText">到期时间：-</span></p>
              <input type="hidden" id="pkgExpireWrap" value="">
            </div>
          </div>
          <div class="usage-list">
            <div class="usage-item">
              <div class="usage-top">
                <span>存储空间</span>
                <b id="pkgStorageNum">0 B / 0 B</b>
              </div>
              <div class="meter"><span id="pkgStorageFill" style="width:0%"></span></div>
              <div class="usage-top" style="margin-top:6px;">
                <span id="pkgStorageUsed">已用 0 B</span>
                <span id="pkgStorageTotal">总量 0 B</span>
              </div>
            </div>
          </div>
          <div class="redeem-row">
            <input type="text" id="redeemCodeInput" placeholder="请输入兑换码" autocomplete="off">
            <button class="primary-btn small" id="redeemBtn" type="button"><i class="mdi mdi-ticket-confirmation-outline"></i> 兑换</button>
          </div>
        </article>

        <article class="upload-card soft-card" id="upload-section">
          <div class="section-heading">
            <div>
              <p class="section-caption">Upload Center</p>
              <h3>上传图片</h3>
            </div>
            <span class="max-size-hint" id="selectedHostName">-</span>
          </div>

          <div class="api-select-wrap">
            <label>选择图床</label>
            <select class="api-select" id="apiSelect">
              <?php
              $apiIdx = 0;
              foreach($apiConfig as $key => $cfg):
                  $enableKey = 'api_'.$key.'_enable';
                  $aliasKey  = 'api_'.$key.'_alias';
                  $maxKey    = 'api_'.$key.'_maxsize';
                  $enabled = isset($conf[$enableKey]) && $conf[$enableKey] == '1';
                  if($enabled):
                      if($hasApiRestriction && !in_array($key, $allowedApiInfo['api_keys'])) { $apiIdx++; continue; }
                      $name = (isset($conf[$aliasKey]) && $conf[$aliasKey] !== '') ? $conf[$aliasKey] : $cfg['name'];
                      $maxSize = (isset($conf[$maxKey]) && $conf[$maxKey] !== '') ? floatval($conf[$maxKey]) : $cfg['max_size'];
                      $sel = ($defaultApi === $key) ? ' selected' : '';
              ?>
              <option value="<?php echo htmlspecialchars((string)$key);?>" data-maxsize="<?php echo $maxSize;?>" data-type="api"<?php echo $sel;?>><?php echo htmlspecialchars($name);?></option>
              <?php endif; $apiIdx++; endforeach; ?>
              <?php if(!empty($s3Configs)): ?>
              <?php
              $hasVisibleS3 = false;
              foreach($s3Configs as $i => $s3):
                  if($hasApiRestriction && !in_array($i, $allowedApiInfo['s3_ids'])) continue;
                  $hasVisibleS3 = true;
              endforeach;
              if($hasVisibleS3):
              ?>
              <optgroup label="── S3 存储 ──">
              <?php foreach($s3Configs as $i => $s3):
                  if($hasApiRestriction && !in_array($i, $allowedApiInfo['s3_ids'])) continue;
                  $s3Name = htmlspecialchars($s3['name'], ENT_QUOTES, 'UTF-8');
                  $s3MaxSize = floatval($s3['max_size'] ?? '10');
                  if($s3MaxSize <= 0) $s3MaxSize = 10;
              ?>
                <option value="s3:<?php echo $i;?>" data-maxsize="<?php echo $s3MaxSize;?>" data-type="s3" data-s3id="<?php echo $i;?>"><?php echo $s3Name;?></option>
              <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php endif; ?>
            </select>
            <span class="max-size-hint">最大 <span id="maxSizeDisplay">10 MB</span></span>
          </div>

          <div class="upload-zone" id="dropZone">
            <div class="dropzone-icon" id="dropZoneIcon"><i class="mdi mdi-cloud-upload-outline"></i></div>
            <h3 id="dropZoneTitle">点击选择或拖拽图片到此处</h3>
            <p id="dropZoneSubtitle">支持 JPG / PNG / GIF / WEBP · 单文件最大 <span id="maxSizeDisplay">10 MB</span></p>
            <div class="file-names" id="dropZoneFileNames" style="display:none;"></div>
            <button class="primary-btn" id="browseBtn" type="button"><i class="mdi mdi-folder-open-outline"></i> 浏览文件</button>
            <div class="upload-formats">
              <span class="format-tag">JPG</span>
              <span class="format-tag">PNG</span>
              <span class="format-tag">GIF</span>
              <span class="format-tag">WebP</span>
              <span class="format-tag">SVG</span>
              <span class="format-tag">BMP</span>
            </div>
            <input type="file" id="fileInput" accept="image/*" multiple style="display:none;">
            <div id="fileHint" style="margin-top:10px;font-size:12px;color:var(--muted);"></div>
          </div>

          <div class="queue-section" id="fileListWrap" style="display:none;">
            <div class="queue-head">
              <span class="queue-title"><i class="mdi mdi-file-multiple"></i> 已选文件 <span class="count" id="fileCountEl">0</span></span>
              <button class="queue-clear" id="clearFilesBtn" type="button"><i class="mdi mdi-close"></i> 清空</button>
            </div>
            <div class="queue-list" id="fileListEl"></div>
          </div>

          <div class="upload-actions">
            <button class="primary-btn" id="uploadBtn" type="button" disabled>
              <i class="mdi mdi-upload" id="btnIcon"></i>
              <span id="btnLabel">上传</span>
              (<span id="uploadCountEl">0</span>)
            </button>
            <button class="ghost-btn" id="clearAllBtn" type="button" style="display:none;"><i class="mdi mdi-trash-can-outline"></i> 清空</button>
          </div>
          <div class="status-text" id="statusText">未选择文件</div>

          <div class="progress-wrap" id="progressWrap">
            <div class="progress-title" id="progressTitle">正在上传...</div>
            <div class="progress-bar-wrap"><div class="progress-bar" id="progressBar" style="width:0%"></div></div>
            <div class="progress-detail" id="progressDetail"></div>
          </div>

          <div class="result-wrap" id="resultWrap">
            <div class="result-success" id="successBox" style="display:none;">
              <div class="result-link">
                <input type="text" id="resultLinkText" readonly>
                <button class="ghost-btn small" id="copyBtn" type="button"><i class="mdi mdi-content-copy"></i> 复制</button>
              </div>
            </div>
            <div class="result-error" id="errorBox" style="display:none;">
              <p id="errorMsg"></p>
            </div>
          </div>
        </article>
      </section>
      </section><!-- /home-section -->

      <!-- 图片管理 -->
      <section class="gallery-section soft-card page-section" id="gallery-section" style="display:none">
        <div class="section-heading">
          <div>
            <p class="section-caption">Image Gallery</p>
            <h3>图片管理</h3>
          </div>
          <button class="ghost-btn small" id="refreshGallery" type="button"><i class="mdi mdi-refresh"></i> 刷新</button>
        </div>
        <div class="gallery-toolbar">
          <div class="gallery-search">
            <i class="mdi mdi-magnify"></i>
            <input type="text" id="gallerySearch" placeholder="搜索文件名...">
          </div>
          <?php if($isSuperAdmin): ?>
          <div class="user-filter-wrap">
            <label><i class="mdi mdi-filter-variant"></i> 筛选用户</label>
            <select class="user-filter" id="userFilter">
              <option value="0">全部用户</option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <div class="gallery-grid" id="galleryGrid">
          <div class="gallery-empty">
            <div class="icon"><i class="mdi mdi-image-off-outline"></i></div>
            <div>加载中...</div>
          </div>
        </div>
        <div class="pagination" id="pagination"></div>
      </section>

      <!-- API 密钥管理 -->
      <section class="soft-card page-section" id="apikeys-section" style="padding:28px;display:none;">
        <div class="section-heading">
          <div>
            <p class="section-caption">API Keys</p>
            <h3>API 密钥管理</h3>
          </div>
          <button class="primary-btn" onclick="akOpenCreate()" type="button"><i class="mdi mdi-plus"></i> 生成新密钥</button>
        </div>
        <p style="font-size:0.88rem;color:var(--muted);margin-bottom:14px;line-height:1.6;">
          <i class="mdi mdi-information-outline"></i> 用于对外 API 上传，请求头携带 <code style="background:var(--surface-2);padding:2px 6px;border-radius:4px;font-family:var(--font-mono);color:var(--primary);">Authorization: Bearer sk-xxx</code> 即可鉴权。
          <span style="color:var(--warning);font-weight:600;">明文仅在生成/重置时展示一次，请立即复制保存。</span>
        </p>
        <div id="akListContainer">
          <div style="text-align:center;padding:32px;color:var(--muted);"><i class="mdi mdi-loading mdi-spin"></i> 加载中...</div>
        </div>
      </section>

      <!-- 套餐购买（AJAX 加载 pricing.php?embed=1） -->
      <section id="pricing-section" class="page-section pricing-section-wrap" style="display:none">
        <div class="pricing-loading" id="pricingLoading">
          <i class="mdi mdi-loading mdi-spin"></i> 正在加载套餐购买页面...
        </div>
        <div class="pricing-error" id="pricingError" style="display:none">
          <i class="mdi mdi-alert-circle-outline"></i>
          <p>加载失败，请重试</p>
          <button type="button" class="ghost-btn" id="pricingRetry"><i class="mdi mdi-refresh"></i> 重试</button>
        </div>
        <div class="pricing-content" id="pricingContent"></div>
      </section>

      <!-- 页脚 -->
      <footer class="footer">
        <div>&copy; <?php echo date('Y');?> <?php echo htmlspecialchars($siteName);?> 版权所有</div>
        <?php if(!empty($conf['time'])):
          $startTime = strtotime($conf['time']);
          $diff = time() - $startTime;
          if ($diff > 0) {
              $years = floor($diff / (365*24*3600));
              $rem = $diff % (365*24*3600);
              $months = floor($rem / (30*24*3600));
              $rem = $rem % (30*24*3600);
              $days = floor($rem / (24*3600));
              $rt = '';
              if ($years > 0) $rt .= $years.'年';
              if ($months > 0) $rt .= $months.'个月';
              if ($days > 0) $rt .= $days.'天';
              if ($rt === '') $rt = '1天';
          } else { $rt = '0天'; }
        ?>
        <div style="margin-top:4px;"><i class="mdi mdi-clock-outline"></i> 已稳定运营 <strong><?php echo $rt;?></strong></div>
        <?php endif; ?>
        <?php if(!empty($conf['icp'])): ?>
        <div style="margin-top:4px;"><a target="_blank" href="https://beian.miit.gov.cn/" rel="noopener"><?php echo htmlspecialchars($conf['icp']);?></a></div>
        <?php endif; ?>
      </footer>
    </main>
  </div>

  <!-- Toast 容器 -->
  <div class="notif-container" id="notifContainer"></div>

  <!-- qd 设计系统通用交互 -->
  <script src="../bd/qd.js"></script>

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

  <!-- 修改密码弹窗 -->
  <div class="modal-overlay" id="pwdModalOverlay">
    <div class="modal-box">
      <h3 class="modal-title"><i class="mdi mdi-lock-reset"></i> 修改密码</h3>
      <div class="modal-body">
        <form id="pwdForm" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');?>">
          <div class="pwd-form-group">
            <label for="oldPassword">原密码</label>
            <input type="password" id="oldPassword" name="old_password" placeholder="请输入原密码" required>
          </div>
          <div class="pwd-form-group">
            <label for="newPassword">新密码</label>
            <input type="password" id="newPassword" name="new_password" placeholder="6-64位字符" required>
          </div>
          <div class="pwd-form-group">
            <label for="confirmNewPassword">确认新密码</label>
            <input type="password" id="confirmNewPassword" name="confirm_new_password" placeholder="请再次输入新密码" required>
          </div>
        </form>
      </div>
      <div class="modal-actions">
        <button class="modal-btn cancel" id="pwdCancel">取消</button>
        <button class="modal-btn primary" id="pwdConfirm"><i class="mdi mdi-check"></i> 确认修改</button>
      </div>
    </div>
  </div>

  <!-- 生成 API 密钥弹窗 -->
  <div class="modal-overlay" id="createKeyOverlay">
    <div class="modal-box">
      <h3 class="modal-title"><i class="mdi mdi-key-plus"></i> 生成新 API 密钥</h3>
      <div class="modal-body">
        <div class="pwd-form-group">
          <label for="akKeyName">密钥名称</label>
          <input type="text" id="akKeyName" placeholder="如：CLI 上传工具 / 个人博客" maxlength="100" required>
        </div>
        <p style="font-size:0.84rem;color:var(--muted);margin-top:8px;">
          <i class="mdi mdi-alert-circle-outline"></i> 名称用于区分不同用途的密钥，最多 100 字符。生成后明文仅展示一次。
        </p>
      </div>
      <div class="modal-actions">
        <button class="modal-btn cancel" id="akCreateCancel">取消</button>
        <button class="modal-btn primary" id="akCreateConfirm"><i class="mdi mdi-check"></i> 生成</button>
      </div>
    </div>
  </div>

  <!-- 明文密钥展示弹窗（仅展示一次） -->
  <div class="modal-overlay" id="plainKeyOverlay">
    <div class="modal-box">
      <h3 class="modal-title" style="color:var(--warning);"><i class="mdi mdi-alert"></i> 密钥已生成（仅展示一次）</h3>
      <div class="modal-body">
        <p style="color:var(--danger);font-size:0.88rem;margin-bottom:10px;">
          <i class="mdi mdi-alert-circle"></i> 请立即复制保存，关闭后将无法再次查看！
        </p>
        <div id="akPlainBox" class="ak-plain-key"></div>
        <p style="font-size:0.84rem;color:var(--muted);margin-top:8px;">前缀：<span id="akPlainPrefix" style="font-family:var(--font-mono);color:var(--primary);font-weight:600;"></span></p>
      </div>
      <div class="modal-actions">
        <button class="modal-btn primary" id="akCopyBtn"><i class="mdi mdi-content-copy"></i> 复制密钥</button>
        <button class="modal-btn confirm" id="akPlainClose">我已保存</button>
      </div>
    </div>
  </div>

  <!-- 图片查看器 -->
  <div class="viewer-overlay" id="viewerOverlay">
    <button class="viewer-close" id="viewerClose"><i class="mdi mdi-close"></i></button>
    <img class="viewer-img" id="viewerImg" src="" alt="">
    <div class="viewer-info" id="viewerInfo"></div>
  </div>
<script>
// ===== 全局配置 =====
var csrfToken = <?php echo json_encode($csrfToken);?>;
var isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false';?>;
var username = <?php echo json_encode($username);?>;

// ===== 全局工具 =====
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
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
function escAttr(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
// L3: URL 协议白名单校验，防止 javascript:/vbscript:/data: 等 URL 注入
function safeUrl(url){
  var u=String(url).trim().toLowerCase();
  if(u===''||u==='#')return url;
  if(/^(https?:|mailto:|tel:|ftp:|\/|\.\/|\.\.\/|#|data:image\/)/.test(u))return url;
  return '';
}
function formatSize(bytes){
  if(bytes == -1) return '无限制';
  if(!bytes||bytes<=0) return '0 B';
  if(bytes<1024) return bytes+' B';
  if(bytes<1048576) return (bytes/1024).toFixed(1)+' KB';
  if(bytes<1073741824) return (bytes/1048576).toFixed(1)+' MB';
  return (bytes/1073741824).toFixed(2)+' GB';
}
function formatDate(str){
  if(!str) return '';
  return str.replace('T',' ').substring(0,16);
}

// ===== 自定义通知 =====
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
  }, 2800);
}

// ===== 自定义确认框 =====
function showConfirm(title, msg, confirmText){
  return new Promise(function(resolve){
    document.getElementById('modalTitle').innerHTML = '<i class="mdi mdi-help-circle-outline"></i> ' + esc(title);
    document.getElementById('modalBody').textContent = msg;
    var confirmBtn = document.getElementById('modalConfirm');
    if(confirmText) confirmBtn.textContent = confirmText;
    else confirmBtn.textContent = '确定';
    var overlay = document.getElementById('modalOverlay');
    overlay.classList.add('show');
    var c = confirmBtn.cloneNode(true); confirmBtn.parentNode.replaceChild(c, confirmBtn);
    var cancel = document.getElementById('modalCancel');
    var cc = cancel.cloneNode(true); cancel.parentNode.replaceChild(cc, cancel);
    c.addEventListener('click', function(){ overlay.classList.remove('show'); resolve(true); });
    cc.addEventListener('click', function(){ overlay.classList.remove('show'); resolve(false); });
    overlay.addEventListener('click', function(e){ if(e.target===overlay){ overlay.classList.remove('show'); resolve(false); } }, {once:true});
  });
}

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

// ===== 时钟 =====
var clockDisplay = document.getElementById('clockDisplay');
function tick(){
  var n = new Date();
  clockDisplay.querySelector('span').textContent = n.toLocaleString('zh-CN', { hour12: false });
}
tick(); setInterval(tick, 1000);

// ===== 侧边栏 =====
document.getElementById('menuToggle').addEventListener('click', function(){
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
});
document.getElementById('sidebarOverlay').addEventListener('click', function(){
  document.getElementById('sidebar').classList.remove('open');
  this.classList.remove('show');
});
// ===== Section 切换显示 =====
// 隐藏所有 .page-section，仅显示目标 section；同步侧边栏 active 状态
// sectionFirstLoad：首屏初始化标志，避免与脚本末尾的预加载（loadStats/loadImages/loadPackageInfo/akLoadList）重复触发 AJAX
var sectionFirstLoad = true;
function switchSection(targetId, skipPush){
  // 隐藏所有 .page-section
  document.querySelectorAll('.page-section').forEach(function(el){
    el.style.display = 'none';
  });
  // 定位目标
  var target = document.getElementById(targetId);
  if(!target){
    targetId = 'home-section';
    target = document.getElementById(targetId);
  }
  // 显示目标
  target.style.display = '';
  // 更新侧边栏 active
  document.querySelectorAll('.side-nav a').forEach(function(a){
    a.classList.remove('active');
  });
  var activeLink = document.querySelector('.side-nav a[href="#' + targetId + '"]');
  if(activeLink) activeLink.classList.add('active');
  // 切换区块时 AJAX 无感刷新对应内容（§ 2.5 无刷新操作规范）
  // pricing-section 总是触发加载（loadPricingSection 有 data-loaded 缓存，重复调用安全）
  // 其他区块首屏跳过（避免与脚本末尾的预加载重复请求），用户主动切换时刷新
  if(targetId === 'pricing-section'){
    loadPricingSection();
  } else if(!sectionFirstLoad){
    refreshSection(targetId);
  }
  // 同步 URL（不带 hash），用 pushState 便于浏览器前进/后退
  // 注意：仅用 history.state 记忆 section，不用 sessionStorage
  // 原因：history.state 跨页面跳转后丢失，重新进入用户中心时回到首页；页面内刷新时保留，仍能恢复当前 section
  if(!skipPush && history.pushState){
    history.pushState({section: targetId}, '', window.location.pathname + window.location.search);
  }
  sectionFirstLoad = false;
}

// 按区块刷新对应内容（用户切换 section 时触发，确保显示最新数据）
function refreshSection(targetId){
  switch(targetId){
    case 'home-section':
      // 首页：刷新统计卡片 + 套餐信息（上传/兑换/删除可能影响这些数据）
      loadStats();
      loadPackageInfo();
      break;
    case 'gallery-section':
      // 我的图片：刷新图片列表（保持当前页）+ 统计（删除/上传后数量变化）
      loadImages(currentPage);
      loadStats();
      break;
    case 'apikeys-section':
      // API 密钥：刷新密钥列表（生成/重置/启停/删除后状态同步）
      akLoadList();
      break;
  }
}

// ===== pricing-section AJAX 无感嵌入 =====
// 首次切换时 fetch pricing.php?embed=1，缓存后不再重复请求
function loadPricingSection(){
  var content = document.getElementById('pricingContent');
  var loading = document.getElementById('pricingLoading');
  var errorBox = document.getElementById('pricingError');
  // 已缓存则直接显示
  if(content.getAttribute('data-loaded') === '1'){
    loading.style.display = 'none';
    errorBox.style.display = 'none';
    content.style.display = '';
    return;
  }
  // 显示加载中，隐藏错误和内容
  loading.style.display = '';
  errorBox.style.display = 'none';
  content.style.display = 'none';
  fetch('pricing.php?embed=1', {credentials:'same-origin'})
    .then(function(res){
      if(!res.ok) throw new Error('HTTP ' + res.status);
      return res.text();
    })
    .then(function(html){
      content.innerHTML = html;
      loading.style.display = 'none';
      content.style.display = '';
      content.setAttribute('data-loaded', '1');
      // innerHTML 插入的 <script> 不会自动执行，需手动重建并插入 head
      content.querySelectorAll('script').forEach(function(oldScript){
        var newScript = document.createElement('script');
        if(oldScript.src){
          newScript.src = oldScript.src;
        } else {
          newScript.textContent = oldScript.textContent;
        }
        oldScript.parentNode.removeChild(oldScript);
        document.head.appendChild(newScript);
      });
    })
    .catch(function(){
      loading.style.display = 'none';
      errorBox.style.display = '';
      notify('套餐购买页面加载失败', 'error');
    });
}

// pricing 重试按钮：清除缓存后重新加载
document.getElementById('pricingRetry').addEventListener('click', function(){
  var content = document.getElementById('pricingContent');
  content.removeAttribute('data-loaded');
  content.innerHTML = '';
  loadPricingSection();
});

// 侧边栏导航点击：区段切换 / 按钮型 / 外链
document.querySelectorAll('.side-nav a').forEach(function(item){
  item.addEventListener('click', function(e){
    var href = this.getAttribute('href');
    // 按钮型链接（修改密码等）不参与区段切换
    if(this.id === 'navChangePwd'){
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebarOverlay').classList.remove('show');
      return;
    }
    // 区段切换链接（href 以 # 开头且长度大于 1，有对应 section）
    if(href && href.length > 1 && href.charAt(0) === '#'){
      var targetId = href.substring(1);
      if(document.getElementById(targetId)){
        e.preventDefault();
        switchSection(targetId);
      }
      // 关闭移动端抽屉
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebarOverlay').classList.remove('show');
      return;
    }
    // 其他 hash 链接（href="#"）仅关闭抽屉
    if(href && href.charAt(0) === '#'){
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebarOverlay').classList.remove('show');
    }
  });
});

// 监听 popstate（浏览器前进/后退导航，URL 不带 hash）
window.addEventListener('popstate', function(e){
  var s = (e.state && e.state.section) ? e.state.section : null;
  if(!s || !document.getElementById(s)){ s = 'home-section'; }
  switchSection(s, true);
});

// 首屏初始化：仅用 history.state 恢复 section；URL 始终无 hash
// history.state 跨页面跳转后丢失 → 从外部进入用户中心时回到首页
// history.state 页面内刷新时保留 → 用户中心内部刷新仍能恢复当前 section
(function initSection(){
  // 清理首屏可能残留的 hash（如旧链接分享带入）
  if(window.location.hash){
    history.replaceState(null, '', window.location.pathname + window.location.search);
  }
  var section = (history.state && history.state.section) ? history.state.section : null;
  if(!section || !document.getElementById(section)){
    section = 'home-section';
  }
  switchSection(section, true);
  if(history.replaceState){
    history.replaceState({section: section}, '', window.location.pathname + window.location.search);
  }
})();

// ===== 图片预览代理 =====
function getPreviewUrl(url){
  if(!url) return '';
  var proxyDomains = ['oss.yootn.com'];
  var baiduDomains = ['z1.oocc.top'];
  for(var i=0;i<proxyDomains.length;i++){
    if(url.indexOf(proxyDomains[i])!==-1) return '../api/imgbrige.php?url='+encodeURIComponent(url);
  }
  for(var j=0;j<baiduDomains.length;j++){
    if(url.indexOf(baiduDomains[j])!==-1) return 'https://image.baidu.com/search/down?url='+encodeURIComponent(url);
  }
  return url;
}

// ===== DOM 引用 =====
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
var maxSizeDisplay = document.getElementById('maxSizeDisplay');
var galleryGrid = document.getElementById('galleryGrid');
var pagination = document.getElementById('pagination');

// ===== 状态 =====
var files = [];
var uploading = false;
var currentPage = 1;
var totalPages = 1;
var totalImages = 0;
var filterUserId = 0;
var searchKeyword = '';

// ===== 图床切换 =====
function updateDetail(){
  if(apiSelect.options.length === 0) return;
  var opt = apiSelect.options[apiSelect.selectedIndex];
  document.getElementById('selectedHostName').textContent = opt.textContent;
  var maxSize = parseFloat(opt.dataset.maxsize) || 10;
  var sizeText = maxSize >= 1 ? (maxSize+' MB') : (Math.round(maxSize*1024)+' KB');
  maxSizeDisplay.textContent = sizeText;
}
apiSelect.addEventListener('change', updateDetail);
updateDetail();

// ===== 文件处理 =====
function addFiles(newFiles){
  var imgs = Array.from(newFiles).filter(function(f){ return f.type.startsWith('image/'); });
  if(imgs.length === 0){ notify('请选择图片文件 (JPG, PNG, GIF 等)', 'warning'); return; }
  files = files.concat(imgs);
  renderFiles();
  fileInput.value = '';
  notify('已添加 '+imgs.length+' 张图片', 'info');
}
function renderFiles(){
  if(files.length === 0){
    fileListWrap.style.display = 'none';
    // 恢复拖拽区默认状态
    document.getElementById('dropZoneTitle').textContent = '点击选择或拖拽图片到此处';
    document.getElementById('dropZoneSubtitle').style.display = '';
    var dzFileNames = document.getElementById('dropZoneFileNames');
    dzFileNames.style.display = 'none';
    dzFileNames.innerHTML = '';
    document.getElementById('dropZoneIcon').querySelector('i').className = 'mdi mdi-cloud-upload-outline';
    document.getElementById('browseBtn').innerHTML = '<i class="mdi mdi-folder-open-outline"></i> 浏览文件';
  }
  else {
    fileListWrap.style.display = 'block';
    fileCountEl.textContent = files.length;
    var h = '';
    files.forEach(function(f, i){
      h += '<div class="queue-item">' +
        '<i class="mdi mdi-file-image file-icon"></i>' +
        '<div class="queue-info"><div class="name">'+esc(f.name)+'</div><div class="meta">'+(f.size/1024).toFixed(1)+' KB</div></div>' +
        '<button class="queue-cancel" data-idx="'+i+'"><i class="mdi mdi-close"></i></button></div>';
    });
    fileListEl.innerHTML = h;
    fileListEl.querySelectorAll('.queue-cancel').forEach(function(el){
      el.addEventListener('click', function(){
        files.splice(parseInt(this.dataset.idx), 1);
        renderFiles(); updateBtn();
        notify('已移除该文件', 'info');
      });
    });

    // 更新拖拽区显示已选文件名
    var dzTitle = document.getElementById('dropZoneTitle');
    var dzSubtitle = document.getElementById('dropZoneSubtitle');
    var dzFileNames2 = document.getElementById('dropZoneFileNames');
    var dzIcon = document.getElementById('dropZoneIcon').querySelector('i');

    if(files.length === 1){
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
    for(var i = 0; i < showCount; i++){
      var size = (files[i].size / 1024).toFixed(1);
      nameHtml += '<div style="font-size:12px;color:var(--text-muted);padding:2px 0;border-bottom:1px solid var(--border-2);">' +
        '<i class="mdi mdi-image-outline" style="color:var(--primary);margin-right:4px;"></i>' + esc(files[i].name) +
        ' <span style="color:var(--text-muted);">(' + size + ' KB)</span></div>';
    }
    if(files.length > 5){
      nameHtml += '<div style="font-size:11px;color:var(--text-muted);padding:2px 0;">还有 ' + (files.length - 5) + ' 个文件...</div>';
    }
    dzFileNames2.innerHTML = nameHtml;
    dzFileNames2.style.display = 'block';
  }
  updateBtn();
}
function updateBtn(){
  uploadBtn.disabled = (files.length === 0 || uploading);
  uploadCountEl.textContent = files.length;
  statusText.textContent = files.length > 0 ? '已选 '+files.length+' 个文件' : '未选择文件';
  clearAllBtn.style.display = (files.length > 0 && !uploading) ? '' : 'none';
}

// ===== 清空 =====
async function clearFiles(){
  if(files.length === 0) return;
  if(await showConfirm('清空文件', '确定要清空所有已选文件吗？')){
    files = []; renderFiles(); notify('已清空所有文件', 'success');
  }
}

// ===== 拖拽/粘贴 =====
dropZone.addEventListener('click', function(){ fileInput.click(); });
dropZone.addEventListener('dragover', function(e){ e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', function(){ dropZone.classList.remove('dragover'); });
dropZone.addEventListener('drop', function(e){ e.preventDefault(); dropZone.classList.remove('dragover'); if(e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
fileInput.addEventListener('change', function(e){ if(e.target.files.length) addFiles(e.target.files); });
document.addEventListener('paste', function(e){
  var items = e.clipboardData && e.clipboardData.items; if(!items) return;
  var fs = [];
  for(var i=0;i<items.length;i++){ if(items[i].type.startsWith('image/')){ var f=items[i].getAsFile(); if(f) fs.push(f); } }
  if(fs.length){ addFiles(fs); fileHint.textContent='已粘贴 '+fs.length+' 张图片'; setTimeout(function(){fileHint.textContent='';},2000); }
});

// ===== 记录到全站统计（首页 api/stats.php） =====
function recordStats(successCnt, failCnt) {
  var fd = new FormData();
  fd.append('success', successCnt);
  fd.append('fail', failCnt);
  fd.append('csrf_token', csrfToken);
  fetch('../api/stats.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) { /* 静默记录，不更新本页显示 */ }).catch(function() {});
}

// ===== 上传逻辑 =====
uploadBtn.addEventListener('click', function(){
  if(uploading) return;
  if(files.length === 0){ notify('请先选择要上传的图片', 'warning'); return; }

  uploading = true;
  updateBtn();
  btnIcon.className = 'mdi mdi-loading mdi-spin';
  btnLabel.textContent = '上传中...';

  resultWrap.style.display = 'none';
  successBox.style.display = 'none';
  errorBox.style.display = 'none';

  progressWrap.style.display = 'block';
  progressTitle.textContent = '正在上传...';
  progressDetail.textContent = '准备上传 '+files.length+' 张图片';
  progressBar.style.width = '0%';

  var apiKey = apiSelect.value;
  var opt = apiSelect.options[apiSelect.selectedIndex];
  var isS3 = (opt.dataset.type === 's3');
  var url = '../api/upload.php';
  var maxSize = parseFloat(opt.dataset.maxsize) || 10;
  var maxBytes = maxSize * 1024 * 1024;
  var done = 0, okList = [], errList = [];

  var oversized = files.filter(function(f){ return f.size > maxBytes; });
  if(oversized.length > 0){
    var sizeLabel = maxSize >= 1 ? (maxSize+' MB') : (Math.round(maxSize*1024)+' KB');
    oversized.forEach(function(f){ errList.push({name:f.name, msg:'文件大小 '+(f.size/1024/1024).toFixed(1)+'MB 超过限制 '+sizeLabel}); });
    files = files.filter(function(f){ return f.size <= maxBytes; });
    if(files.length === 0){
      notify('所有文件都超过 '+sizeLabel+' 的大小限制', 'error');
      finish();
      return;
    }
    notify(oversized.length+' 个文件超过大小限制已跳过', 'warning');
    progressDetail.textContent = '准备上传 '+files.length+' 张图片（'+oversized.length+' 张跳过）';
  }

  function next(idx){
    if(idx >= files.length){ finish(); return; }
    var file = files[idx];
    var fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', csrfToken);
    if(isS3){ fd.append('s3_id', opt.dataset.s3id); }
    else { fd.append('api', apiKey); }

    progressTitle.textContent = '上传中 '+(idx+1)+' / '+files.length;
    progressDetail.textContent = file.name;
    progressBar.style.width = ((idx/files.length)*100)+'%';

    fetch(url, {method:'POST', body:fd})
      .then(function(res){
        if(!res.ok){
          return res.text().then(function(t){
            errList.push({name:file.name, msg:'状态码 '+res.status+(t?'：'+t.substring(0,200):'')});
            done++; next(idx+1);
          });
        }
        return res.text().then(function(raw){
          var body;
          try { body = JSON.parse(raw); } catch(e){ body = null; }
          var isObj = body && typeof body === 'object';
          var code = isObj ? (Number(body.code) || (body.status === true ? 200 : 0)) : 0;
          var ok = isObj && (body.status === true || code === 200);
          var path = isObj ? (body.path || (body.data && body.data.links && body.data.links.url)) : '';
          var msg = isObj ? (body.msg || body.message || '上传失败') : ('接口返回非 JSON：'+raw.substring(0,100));
          done++;
          if(ok && path){ okList.push({name:file.name, url:path}); }
          else { errList.push({name:file.name, msg: code ? '状态码 '+code+(msg?'：'+msg:'') : msg}); }
          next(idx+1);
        });
      })
      .catch(function(err){
        done++;
        errList.push({name:file.name, msg:'网络错误：'+(err.message||'无法连接')});
        next(idx+1);
      });
  }

  function finish(){
    try {
      progressBar.style.width = '100%';
      setTimeout(function(){ progressWrap.style.display = 'none'; }, 300);
      resultWrap.style.display = 'block';

      if(okList.length > 0){
        successBox.style.display = 'block';
        errorBox.style.display = 'none';
        var lastUrl = okList[okList.length-1].url;
        resultLinkText.value = okList.length === 1 ? lastUrl : ('共 '+okList.length+' 张，最后一张：'+lastUrl);
        resultLinkText._allLinks = okList.map(function(x){ return x.url; }).join('\n');
        var msg = okList.length === 1 ? '上传成功！' : (okList.length+' 张上传成功');
        if(errList.length > 0) msg += '，'+errList.length+' 张失败';
        notify(msg, 'success');
      }
      if(errList.length > 0){
        errorBox.style.display = 'block';
        errorMsg.textContent = errList.map(function(x){ return x.name+': '+x.msg; }).join('\n');
        if(okList.length === 0){
          successBox.style.display = 'none';
          notify('上传失败，请检查网络或图床配置', 'error');
        }
      }
    } finally {
      files = [];
      renderFiles();
      uploading = false;
      btnIcon.className = 'mdi mdi-upload';
      btnLabel.textContent = '上传';
      uploadCountEl.textContent = '0';
      uploadBtn.disabled = true;
      // 刷新统计和图片列表
      loadStats();
      loadImages(1);
      // 记录到全站统计（首页 api/stats.php）
      if (okList.length > 0 || errList.length > 0) {
        recordStats(okList.length, errList.length);
      }
    }
  }

  next(0);
});

// ===== 复制 =====
copyBtn.addEventListener('click', function(){
  var text = resultLinkText._allLinks || resultLinkText.value;
  if(!text){ notify('没有可复制的链接', 'warning'); return; }
  var origHTML = copyBtn.innerHTML;
  copyBtn.innerHTML = '<i class="mdi mdi-check"></i> 已复制';
  copyBtn.style.background = 'var(--success)';
  var doneFn = function(ok){
    notify(ok ? '链接已复制到剪贴板！' : '复制失败，请手动复制', ok ? 'success' : 'error');
    setTimeout(function(){ copyBtn.innerHTML = origHTML; copyBtn.style.background = ''; }, 2000);
  };
  try {
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(function(){doneFn(true);}).catch(function(){fallbackCopy(text,doneFn);});
    } else { fallbackCopy(text, doneFn); }
  } catch(e){ fallbackCopy(text, doneFn); }
  function fallbackCopy(t, cb){
    var ta = document.createElement('textarea');
    ta.value = t; ta.style.position='fixed'; ta.style.left='-9999px'; ta.style.top='0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); cb(true); } catch(e){ cb(false); }
    document.body.removeChild(ta);
  }
});

// ===== 统计加载 =====
function loadStats(){
  fetch('../api/user_api.php?action=stats&_t=' + Date.now())
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.code !== 0) return;
      // 我的上传 = 当前用户上传的图片数量（直接从 eecms_images 表统计，实时准确）
      document.getElementById('statMyImages').textContent = (d.my_images||0).toLocaleString();
      document.getElementById('statMySize').textContent = d.my_size_formatted || formatSize(d.my_size);

      if(d.is_super_admin){
        document.getElementById('statTotalUsers').textContent = (d.total_users||0).toLocaleString();
        document.getElementById('statTodayUploads').textContent = (d.today_images||0).toLocaleString();
      } else {
        // 普通用户：我的图片 = 当前用户上传的图片数量
        document.getElementById('statTotalImages').textContent = (d.my_images||0).toLocaleString();
      }

      // 侧边栏存储概览（基于真实配额）
      var usedBytes = d.my_size || 0;
      var limitBytes = d.storage_limit || 0;
      var pct = (limitBytes > 0) ? Math.min(100, Math.round((usedBytes / limitBytes) * 100)) : 0;
      var fillEl = document.getElementById('storageFill');
      if(fillEl) fillEl.style.width = Math.max(pct, 2) + '%';
      var usedEl = document.getElementById('storageUsed');
      if(usedEl) usedEl.textContent = '已用 ' + (d.my_size_formatted || formatSize(usedBytes));
      var totalEl = document.getElementById('storageTotal');
      if(totalEl) totalEl.textContent = (limitBytes < 0) ? '上限 无限制' : '上限 ' + (d.storage_limit_formatted || formatSize(limitBytes));
    })
    .catch(function(){});
}

// ===== 图片列表加载 =====
function loadImages(page){
  currentPage = page || 1;
  var url = '../api/user_api.php?action=images&page='+currentPage+'&per_page=20&_t='+Date.now();
  if(isSuperAdmin && filterUserId > 0) url += '&user_id='+filterUserId;

  galleryGrid.innerHTML = '<div class="gallery-empty"><div class="icon"><i class="mdi mdi-loading mdi-spin"></i></div><div class="title">加载中...</div></div>';

  fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.code !== 0){
        galleryGrid.innerHTML = '<div class="gallery-empty"><div class="icon"><i class="mdi mdi-alert-circle-outline"></i></div><div class="title">加载失败</div><div class="desc">'+esc(d.msg||'')+'</div></div>';
        return;
      }
      totalImages = d.total || 0;
      totalPages = d.total_pages || 1;

      // 填充用户筛选器
      if(isSuperAdmin && d.users && d.users.length > 0){
        var sel = document.getElementById('userFilter');
        var curVal = sel.value;
        var h = '<option value="0">全部用户</option>';
        d.users.forEach(function(u){
          var selAttr = (parseInt(curVal) === u.id) ? ' selected' : '';
          h += '<option value="'+u.id+'"'+selAttr+'>'+esc(u.username)+(u.role==='super_admin'?' (管理员)':'')+'</option>';
        });
        sel.innerHTML = h;
      }

      var images = d.data || [];
      // 搜索过滤
      if(searchKeyword){
        images = images.filter(function(img){
          return img.filename && img.filename.toLowerCase().indexOf(searchKeyword.toLowerCase()) !== -1;
        });
      }

      if(images.length === 0){
        galleryGrid.innerHTML = '<div class="gallery-empty"><div class="icon"><i class="mdi mdi-image-off-outline"></i></div><div class="title">暂无图片</div><div class="desc">上传你的第一张图片吧</div></div>';
        pagination.innerHTML = '';
        return;
      }

      var html = '';
      images.forEach(function(img){
        var previewUrl = getPreviewUrl(img.url);
        var thumb = escAttr(safeUrl(previewUrl));
        var fullUrl = escAttr(safeUrl(img.url));
        html += '<div class="img-card" data-id="'+img.id+'">' +
          '<div class="img-thumb">' +
            '<img src="'+thumb+'" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\';">' +
            '<i class="mdi mdi-file-image placeholder" style="display:none;"></i>' +
            '<div class="img-overlay">' +
              '<button class="overlay-btn copy" data-url="'+fullUrl+'" title="复制链接"><i class="mdi mdi-content-copy"></i></button>' +
              '<a class="overlay-btn view" href="'+fullUrl+'" target="_blank" rel="noopener" title="新窗口打开"><i class="mdi mdi-open-in-new"></i></a>' +
              '<button class="overlay-btn view" data-view="'+fullUrl+'" data-name="'+escAttr(img.filename)+'" title="预览"><i class="mdi mdi-eye-outline"></i></button>' +
              '<button class="overlay-btn delete" data-id="'+img.id+'" data-name="'+escAttr(img.filename)+'" title="删除"><i class="mdi mdi-trash-can-outline"></i></button>' +
            '</div>' +
          '</div>' +
          '<div class="img-meta">' +
            '<div class="name" title="'+escAttr(img.filename)+'">'+esc(img.filename)+'</div>' +
            '<div class="info">' +
              (isSuperAdmin ? '<span><i class="mdi mdi-account-outline"></i>'+esc(img.username||'匿名')+'</span>' : '') +
              '<span><i class="mdi mdi-file-outline"></i>'+formatSize(img.size)+'</span>' +
              (img.api_type ? '<span class="api-tag">'+esc(img.api_type)+'</span>' : '') +
              '<span><i class="mdi mdi-clock-outline"></i>'+formatDate(img.created_at)+'</span>' +
            '</div>' +
          '</div>' +
        '</div>';
      });
      galleryGrid.innerHTML = html;
      renderPagination();

      // 绑定事件
      galleryGrid.querySelectorAll('.overlay-btn.copy').forEach(function(btn){
        btn.addEventListener('click', function(){ copyToClipboard(this.dataset.url); });
      });
      galleryGrid.querySelectorAll('.overlay-btn.delete').forEach(function(btn){
        btn.addEventListener('click', function(){ handleDeleteImage(this.dataset.id, this.dataset.name); });
      });
      galleryGrid.querySelectorAll('[data-view]').forEach(function(btn){
        btn.addEventListener('click', function(){
          openViewer(this.dataset.view, this.dataset.name);
        });
      });
    })
    .catch(function(err){
      galleryGrid.innerHTML = '<div class="gallery-empty"><div class="icon"><i class="mdi mdi-alert-circle-outline"></i></div><div class="title">网络错误</div><div class="desc">请稍后重试</div></div>';
    });
}

// ===== 分页渲染 =====
function renderPagination(){
  if(totalPages <= 1){ pagination.innerHTML = ''; return; }
  var html = '';
  html += '<button class="page-btn" '+(currentPage<=1?'disabled':'')+' data-page="'+(currentPage-1)+'"><i class="mdi mdi-chevron-left"></i></button>';
  var start = Math.max(1, currentPage - 2);
  var end = Math.min(totalPages, currentPage + 2);
  if(start > 1){
    html += '<button class="page-btn" data-page="1">1</button>';
    if(start > 2) html += '<span class="page-info">...</span>';
  }
  for(var i = start; i <= end; i++){
    html += '<button class="page-btn '+(i===currentPage?'active':'')+'" data-page="'+i+'">'+i+'</button>';
  }
  if(end < totalPages){
    if(end < totalPages-1) html += '<span class="page-info">...</span>';
    html += '<button class="page-btn" data-page="'+totalPages+'">'+totalPages+'</button>';
  }
  html += '<button class="page-btn" '+(currentPage>=totalPages?'disabled':'')+' data-page="'+(currentPage+1)+'"><i class="mdi mdi-chevron-right"></i></button>';
  html += '<span class="page-info">'+currentPage+' / '+totalPages+' 页</span>';
  pagination.innerHTML = html;
  pagination.querySelectorAll('.page-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      if(this.disabled) return;
      var p = parseInt(this.dataset.page);
      if(p && p !== currentPage) loadImages(p);
    });
  });
}

// ===== 删除图片 =====
async function handleDeleteImage(id, name){
  var ok = await showConfirm('删除图片', '确定要删除「'+name+'」吗？此操作不可撤销。', '删除');
  if(!ok) return;
  var fd = new FormData();
  fd.append('id', id);
  fd.append('csrf_token', csrfToken);
  fetch('../api/user_api.php?action=delete_image', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.code === 0){
        notify(d.msg || '已删除', 'success');
        loadImages(currentPage);
        loadStats();
      } else {
        notify(d.msg || '删除失败', 'error');
      }
    })
    .catch(function(){ notify('网络错误，删除失败', 'error'); });
}

// ===== 复制到剪贴板 =====
function copyToClipboard(text){
  if(!text){ notify('没有可复制的内容', 'warning'); return; }
  try {
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(function(){
        notify('链接已复制！', 'success');
      }).catch(function(){ fallbackCopy(text); });
    } else { fallbackCopy(text); }
  } catch(e){ fallbackCopy(text); }
  function fallbackCopy(t){
    var ta = document.createElement('textarea');
    ta.value = t; ta.style.position='fixed'; ta.style.left='-9999px';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); notify('链接已复制！', 'success'); } catch(e){ notify('复制失败，请手动复制', 'error'); }
    document.body.removeChild(ta);
  }
}

// ===== 图片预览器 =====
function openViewer(url, name){
  var overlay = document.getElementById('viewerOverlay');
  var img = document.getElementById('viewerImg');
  var info = document.getElementById('viewerInfo');
  img.src = getPreviewUrl(url);
  info.textContent = name || '';
  overlay.classList.add('show');
}
document.getElementById('viewerClose').addEventListener('click', function(){
  document.getElementById('viewerOverlay').classList.remove('show');
  document.getElementById('viewerImg').src = '';
});
document.getElementById('viewerOverlay').addEventListener('click', function(e){
  if(e.target === this){
    this.classList.remove('show');
    document.getElementById('viewerImg').src = '';
  }
});

// ===== 修改密码 =====
document.getElementById('navChangePwd').addEventListener('click', function(e){
  e.preventDefault();
  document.getElementById('pwdModalOverlay').classList.add('show');
});
// 个人信息卡片内的修改密码按钮（复用同一弹窗）
var profilePwdBtn = document.getElementById('profileChangePwdBtn');
if(profilePwdBtn){
  profilePwdBtn.addEventListener('click', function(e){
    e.preventDefault();
    document.getElementById('pwdModalOverlay').classList.add('show');
  });
}
document.getElementById('pwdCancel').addEventListener('click', function(){
  document.getElementById('pwdModalOverlay').classList.remove('show');
  document.getElementById('pwdForm').reset();
});
document.getElementById('pwdModalOverlay').addEventListener('click', function(e){
  if(e.target === this){
    this.classList.remove('show');
    document.getElementById('pwdForm').reset();
  }
});
document.getElementById('pwdConfirm').addEventListener('click', function(){
  var oldPwd = document.getElementById('oldPassword').value;
  var newPwd = document.getElementById('newPassword').value;
  var confirmPwd = document.getElementById('confirmNewPassword').value;
  if(!oldPwd || !newPwd || !confirmPwd){ notify('请填写所有字段', 'warning'); return; }
  if(newPwd.length < 6 || newPwd.length > 64){ notify('新密码长度需要 6-64 个字符', 'warning'); return; }
  if(newPwd !== confirmPwd){ notify('两次输入的新密码不一致', 'warning'); return; }

  var btn = this;
  var origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 提交中...';

  var fd = new FormData();
  fd.append('old_password', oldPwd);
  fd.append('new_password', newPwd);
  fd.append('csrf_token', csrfToken);

  fetch('../api/user_api.php?action=change_password', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false;
      btn.innerHTML = origHTML;
      if(d.code === 0){
        btn.disabled = true;
        notify(d.msg || '密码修改成功，即将跳转到登录页...', 'success');
        document.getElementById('pwdModalOverlay').classList.remove('show');
        document.getElementById('pwdForm').reset();
        setTimeout(function(){
          window.location.href = 'login.php';
        }, 1500);
      } else {
        notify(d.msg || '修改失败', 'error');
      }
    })
    .catch(function(){
      btn.disabled = false;
      btn.innerHTML = origHTML;
      notify('网络错误，修改失败', 'error');
    });
});

// ===== 搜索 =====
var searchTimer;
document.getElementById('gallerySearch').addEventListener('input', function(){
  searchKeyword = this.value.trim();
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function(){ loadImages(1); }, 350);
});

// ===== 用户筛选 =====
if(isSuperAdmin){
  document.getElementById('userFilter').addEventListener('change', function(){
    filterUserId = parseInt(this.value) || 0;
    loadImages(1);
  });
}

// ===== 刷新按钮 =====
document.getElementById('refreshGallery').addEventListener('click', function(){
  var icon = this.querySelector('i');
  icon.classList.add('mdi-spin');
  loadImages(currentPage);
  loadStats();
  setTimeout(function(){ icon.classList.remove('mdi-spin'); }, 800);
});

// ===== 事件绑定 =====
document.getElementById('clearFilesBtn').addEventListener('click', clearFiles);
clearAllBtn.addEventListener('click', clearFiles);

// ===== 套餐中心 =====
function loadPackageInfo(){
  fetch('../api/user_api.php?action=package_info&_t=' + Date.now())
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.code !== 0 || !d.data) return;
      var info = d.data;
      var nameEl = document.getElementById('pkgName');
      var badgeEl = document.getElementById('pkgLevelBadge');
      var expireTextEl = document.getElementById('pkgExpireText');
      var statusBadge = document.getElementById('pkgStatusBadge');

      nameEl.textContent = info.package_name || '免费版';

      // 同步填充个人信息卡的套餐名 + 到期时间
      var profilePkgEl = document.getElementById('profilePkgName');
      if(profilePkgEl){
        var pkgText = info.package_name || '免费版';
        if(info.is_expired){
          pkgText += '（已过期）';
        } else if(info.level > 0){
          // 避免套餐名已含 VIP 等级时重复拼接（如 package_name="VIP1" + level=1 → "VIP1 · VIP1"）
          var levelStr = 'VIP' + info.level;
          if(pkgText.toUpperCase().indexOf(levelStr.toUpperCase()) === -1){
            pkgText += ' · VIP' + info.level;
          }
        }
        // 追加到期时间：永久 / 具体日期（已过期也显示，便于用户知晓过期时间）
        if(info.is_permanent){
          pkgText += ' · 永久';
        } else if(info.expire_time){
          var expDate = String(info.expire_time).replace('T',' ').substring(0, 10);
          if(expDate){
            pkgText += ' · ' + expDate + '到期';
          }
        }
        profilePkgEl.textContent = pkgText;
      }

      // 等级徽章
      badgeEl.className = 'pkg-level-badge';
      if(info.is_expired){
        badgeEl.classList.add('expired');
        badgeEl.textContent = '已过期';
      } else if(info.level > 0){
        badgeEl.textContent = 'VIP' + info.level;
      } else {
        badgeEl.classList.add('free');
        badgeEl.textContent = '免费版';
      }

      // 状态徽章
      if(statusBadge){
        if(info.is_expired){
          statusBadge.style.display = '';
          statusBadge.style.background = 'rgba(255,71,87,0.1)';
          statusBadge.style.color = 'var(--danger)';
          statusBadge.style.borderColor = 'rgba(255,71,87,0.15)';
          statusBadge.textContent = '套餐已过期';
        } else if(info.level > 0){
          statusBadge.style.display = '';
          statusBadge.textContent = '生效中';
        } else {
          statusBadge.style.display = 'none';
        }
      }

      // 到期时间
      if(info.is_permanent){
        expireTextEl.textContent = '到期时间：永久';
      } else if(info.expire_time){
        var expStr = formatDate(info.expire_time);
        if(info.is_expired){
          expireTextEl.innerHTML = '到期时间：' + esc(expStr) + ' <span class="expired-warn">已过期，请兑换续期</span>';
        } else {
          expireTextEl.textContent = '到期时间：' + expStr;
        }
      } else {
        expireTextEl.textContent = '到期时间：永久';
      }

      // 存储用量
      var used = parseInt(info.storage_used) || 0;
      var limit = parseInt(info.storage_limit);
      if(isNaN(limit)) limit = 0;
      var isUnlimited = (limit === -1);
      var pct = 0;
      if(!isUnlimited && limit > 0){
        pct = Math.min(100, Math.round((used / limit) * 100));
      }
      document.getElementById('pkgStorageNum').textContent = formatSize(used) + ' / ' + formatSize(limit);
      document.getElementById('pkgStorageUsed').textContent = '已用 ' + formatSize(used);
      document.getElementById('pkgStorageTotal').textContent = isUnlimited ? '总量 无限制' : '总量 ' + formatSize(limit);

      var fillEl = document.getElementById('pkgStorageFill');
      fillEl.className = 'pkg-progress-fill';
      if(isUnlimited){
        fillEl.style.width = '100%';
      } else {
        if(pct >= 90){
          fillEl.classList.add('full');
        } else if(pct >= 70){
          fillEl.classList.add('warn');
        }
        fillEl.style.width = Math.max(pct, 2) + '%';
      }

      // 同步更新侧边栏存储概览（使用真实配额）
      var sideFill = document.getElementById('storageFill');
      var sideTag = document.getElementById('storageTag');
      var sideUsed = document.getElementById('storageUsed');
      var sideTotal = document.getElementById('storageTotal');
      if(sideFill){
        sideFill.style.width = isUnlimited ? '100%' : Math.max(pct, 4) + '%';
      }
      if(sideTag){
        if(isUnlimited){ sideTag.textContent = '无限'; sideTag.style.background = ''; sideTag.style.color = ''; }
        else if(pct >= 90){ sideTag.textContent = '已满'; sideTag.style.background = 'rgba(255,71,87,0.12)'; sideTag.style.color = 'var(--danger)'; }
        else if(pct >= 70){ sideTag.textContent = '紧张'; sideTag.style.background = 'rgba(255,166,0,0.12)'; sideTag.style.color = 'var(--warning)'; }
        else { sideTag.textContent = '正常'; sideTag.style.background = ''; sideTag.style.color = ''; }
      }
      if(sideUsed){ sideUsed.textContent = '已用 ' + formatSize(used); }
      if(sideTotal){ sideTotal.textContent = isUnlimited ? '上限 无限制' : '上限 ' + formatSize(limit); }
    })
    .catch(function(){});
}

document.getElementById('redeemBtn').addEventListener('click', function(){
  var code = document.getElementById('redeemCodeInput').value.trim();
  if(!code){ notify('请输入兑换码', 'warning'); return; }

  var btn = this;
  var origHTML = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 兑换中...';

  var fd = new FormData();
  fd.append('code', code);
  fd.append('csrf_token', csrfToken);

  fetch('../api/user_api.php?action=redeem', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false;
      btn.innerHTML = origHTML;
      if(d.code === 0){
        notify(d.msg || '兑换成功，正在刷新以应用新套餐...', 'success');
        document.getElementById('redeemCodeInput').value = '';
        // 刷新整页：图床接口下拉是 PHP 按当前套餐渲染的，AJAX 无法局部更新；
        // 用带时间戳的跳转强制浏览器重新拉取页面（绕过任何缓存），同步接口列表/存储/套餐名等所有数据
        setTimeout(function(){
          var url = window.location.href.split('#')[0];
          var sep = url.indexOf('?') >= 0 ? '&' : '?';
          window.location.href = url + sep + '_t=' + Date.now();
        }, 1500);
      } else {
        notify(d.msg || '兑换失败', 'error');
      }
    })
    .catch(function(){
      btn.disabled = false;
      btn.innerHTML = origHTML;
      notify('网络错误，兑换失败', 'error');
    });
});

// 回车提交兑换码
document.getElementById('redeemCodeInput').addEventListener('keydown', function(e){
  if(e.key === 'Enter'){ e.preventDefault(); document.getElementById('redeemBtn').click(); }
});

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

// ===== 初始化 =====
loadStats();
loadImages(1);
loadPackageInfo();
// ===== API 密钥管理 =====
function akShowOverlay(id){ document.getElementById(id).classList.add('show'); }
function akHideOverlay(id){ document.getElementById(id).classList.remove('show'); }
var akCurrentPlain = '';

function akLoadList(){
  var c = document.getElementById('akListContainer');
  c.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted);"><i class="mdi mdi-loading mdi-spin"></i> 加载中...</div>';
  fetch('../api/api_keys.php?action=list&_t='+Date.now())
    .then(function(r){return r.json();})
    .then(function(res){
      if(res.code !== 0){ c.innerHTML = '<div style="text-align:center;padding:24px;color:var(--danger);">'+esc(res.msg)+'</div>'; return; }
      akRenderList(res.data || []);
    })
    .catch(function(){ c.innerHTML = '<div style="text-align:center;padding:24px;color:var(--danger);">网络错误</div>'; });
}

function akRenderList(list){
  var c = document.getElementById('akListContainer');
  if(!list || list.length === 0){
    c.innerHTML = '<div class="gallery-empty"><div class="icon"><i class="mdi mdi-key-remove"></i></div><div class="title">暂无 API 密钥</div><div class="desc">点击上方「生成新密钥」按钮创建</div></div>';
    return;
  }
  var html = '';
  list.forEach(function(k){
    var isOn = k.status===1;
    var stHtml = isOn
      ? '<span class="ak-status on"><i class="mdi mdi-check-circle"></i> 启用</span>'
      : '<span class="ak-status off"><i class="mdi mdi-close-circle"></i> 禁用</span>';
    var toggleIcon = isOn?'mdi-pause':'mdi-play';
    var toggleTitle = isOn?'禁用':'启用';
    var toggleClass = isOn?'':'danger';
    html += '<div class="ak-item">'
      + '<div class="ak-icon"><i class="mdi mdi-key-variant"></i></div>'
      + '<div class="ak-info">'
      + '  <div class="ak-name">'+esc(k.name)+'</div>'
      + '  <div class="ak-meta">'+esc(k.key_prefix)+'</div>'
      + '  <div class="ak-time"><i class="mdi mdi-clock-outline"></i> 创建 '+esc(k.created_at||'-')+' · 使用 '+(k.last_used_at?esc(k.last_used_at):'未使用')+'</div>'
      + '</div>'
      + stHtml
      + '<div class="ak-actions">'
      + '  <button onclick="akRegen('+k.id+')" title="重新生成"><i class="mdi mdi-refresh"></i></button>'
      + '  <button onclick="akToggle('+k.id+','+k.status+')" title="'+toggleTitle+'"><i class="mdi '+toggleIcon+'"></i></button>'
      + '  <button class="danger" onclick="akDelete('+k.id+')" title="删除"><i class="mdi mdi-delete"></i></button>'
      + '</div>'
      + '</div>';
  });
  c.innerHTML = html;
}

function akOpenCreate(){
  document.getElementById('akKeyName').value = '';
  akShowOverlay('createKeyOverlay');
  setTimeout(function(){ document.getElementById('akKeyName').focus(); }, 50);
}
document.getElementById('akCreateCancel').addEventListener('click', function(){ akHideOverlay('createKeyOverlay'); });
document.getElementById('akCreateConfirm').addEventListener('click', function(){
  var name = document.getElementById('akKeyName').value.trim();
  if(name === ''){ notify('请输入密钥名称', 'warning'); return; }
  if(name.length > 100){ notify('名称不能超过 100 字符', 'warning'); return; }
  var btn = document.getElementById('akCreateConfirm');
  btn.disabled = true; btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 生成中';
  var fd = new FormData();
  fd.append('csrf_token', csrfToken);
  fd.append('name', name);
  fetch('../api/api_keys.php?action=create', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(res){
      btn.disabled = false; btn.innerHTML = '<i class="mdi mdi-check"></i> 生成';
      if(res.code !== 0){ notify(res.msg, 'error'); return; }
      akHideOverlay('createKeyOverlay');
      akShowPlain(res.api_key, res.key_prefix);
      akLoadList();
      notify('密钥已生成，请立即复制保存', 'success');
    })
    .catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="mdi mdi-check"></i> 生成'; notify('网络错误', 'error'); });
});

function akShowPlain(plain, prefix){
  akCurrentPlain = plain;
  document.getElementById('akPlainBox').textContent = plain;
  document.getElementById('akPlainPrefix').textContent = prefix;
  akShowOverlay('plainKeyOverlay');
}
document.getElementById('akCopyBtn').addEventListener('click', function(){
  if(!akCurrentPlain) return;
  var ta = document.createElement('textarea');
  ta.value = akCurrentPlain; ta.style.position='fixed'; ta.style.opacity='0';
  document.body.appendChild(ta); ta.select();
  try{ document.execCommand('copy'); notify('已复制到剪贴板', 'success'); }
  catch(e){ notify('复制失败，请手动选择复制', 'warning'); }
  document.body.removeChild(ta);
});
document.getElementById('akPlainClose').addEventListener('click', function(){
  akCurrentPlain = '';
  akHideOverlay('plainKeyOverlay');
});

function akRegen(id){
  showConfirm('重新生成密钥', '旧密钥明文将立即失效，使用旧密钥的客户端需更新。新明文仅展示一次。', '确认重置').then(function(ok){
    if(!ok) return;
    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('id', id);
    fetch('../api/api_keys.php?action=regen', {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.code !== 0){ notify(res.msg, 'error'); return; }
        akShowPlain(res.api_key, res.key_prefix);
        akLoadList();
        notify('密钥已重新生成', 'success');
      })
      .catch(function(){ notify('网络错误', 'error'); });
  });
}

function akToggle(id, status){
  var act = status===1?'禁用':'启用';
  showConfirm(act+'密钥', '确定要'+act+'此密钥吗？', act).then(function(ok){
    if(!ok) return;
    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('id', id);
    fd.append('status', status===1?0:1);
    fetch('../api/api_keys.php?action=toggle', {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.code !== 0){ notify(res.msg, 'error'); return; }
        notify(res.msg, 'success');
        akLoadList();
      })
      .catch(function(){ notify('网络错误', 'error'); });
  });
}

function akDelete(id){
  showConfirm('删除密钥', '删除后该密钥立即失效，且无法恢复。确定删除？', '删除').then(function(ok){
    if(!ok) return;
    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('id', id);
    fetch('../api/api_keys.php?action=delete', {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.code !== 0){ notify(res.msg, 'error'); return; }
        notify(res.msg, 'success');
        akLoadList();
      })
      .catch(function(){ notify('网络错误', 'error'); });
  });
}

// 页面加载时拉取 API 密钥列表
akLoadList();
</script>
</body>
</html>
