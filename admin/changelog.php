<?php
/**
 * @file changelog.php
 * @description 后台更新日志页面：仅展示远程在线更新（版本对比 + 更新日志 + 一键升级，对接 gxrz 管理主站），
 *              不包含本地开发日志（docs/版本日志.md 为开发文档，含敏感信息，严禁暴露公网）
 * @author AI
 * @version 1.3.9-dev
 * @date 2026-08-07
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// ============================================================
// 在线更新对接（检查远程更新 + 一键升级）
// 依据：docs/在线更新对接集成文档.md（gxrz 管理主站）
// 远程主站：https://wtgh.dpdns.org/
// 接口：version.json（版本信息）、api/api.php（更新日志，目录重构后位于 api/ 子目录）
// ============================================================
define('REMOTE_UPDATE_HOST', 'https://wtgh.dpdns.org/');

/**
 * 获取远程更新信息（版本 + 更新日志）
 *
 * @return array{ok:bool, version:?array, changelog:?array, error?:string}
 */
function fetch_remote_update_info(): array
{
    $result = ['ok' => false, 'version' => null, 'changelog' => null];

    // 1. 获取 version.json（在线升级必需：download_url/signature 均在其中）
    $ch1 = curl_init(REMOTE_UPDATE_HOST . 'version.json');
    curl_setup_ssl($ch1);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $verRaw = curl_exec($ch1);
    $verHttp = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
    curl_close($ch1);

    if($verRaw !== false && $verHttp === 200) {
        $verData = json_decode($verRaw, true);
        if(is_array($verData) && isset($verData['latest_version'])) {
            $result['version'] = $verData;
            $result['ok'] = true; // 仅 version.json 可用才视为完整可用（升级依赖它）
        } else {
            $result['error'] = '远程版本数据格式错误';
        }
    } else {
        // version.json 不可用（如远程尚未发布更新包）：
        // 记录原因但继续抓取日志，降级为「仅展示日志」模式
        // 提示文案不含技术细节（version.json/HTTP 状态码），避免向用户暴露内部实现
        $result['error'] = '远程更新包暂未发布，在线升级暂不可用';
    }

    // 2. 获取更新日志 api/api.php（无论 version.json 是否可用都抓取）
    // 依据：在线更新对接集成文档.md § 七「管理主站 API 速查」
    // 注意：目录重构后日志 API 位于 api/ 子目录，不是根目录 api.php
    $ch2 = curl_init(REMOTE_UPDATE_HOST . 'api/api.php');
    curl_setup_ssl($ch2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $logRaw = curl_exec($ch2);
    $logHttp = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if($logRaw !== false && $logHttp === 200) {
        $logData = json_decode($logRaw, true);
        if(is_array($logData) && isset($logData['data'])) {
            $result['changelog'] = $logData['data'];
        }
    }

    return $result;
}

/**
 * 远程日志条目类型 → Bootstrap 徽章 class
 * 依据：文档 § 七 changelog 条目类型表
 *
 * @param string $type 条目类型
 * @return array{class:string,label:string}
 */
function get_remote_type_badge(string $type): array
{
    $map = [
        'feature'     => ['class' => 'bg-primary', 'label' => '新功能'],
        'fix'         => ['class' => 'bg-danger', 'label' => '修复'],
        'improvement' => ['class' => 'bg-success', 'label' => '优化'],
        'security'    => ['class' => 'bg-warning text-dark', 'label' => '安全'],
        'breaking'    => ['class' => 'bg-purple', 'label' => '破坏性'],
        'deprecation' => ['class' => 'bg-secondary', 'label' => '废弃'],
    ];
    $key = strtolower(trim($type));
    return $map[$key] ?? ['class' => 'bg-secondary', 'label' => htmlspecialchars($type, ENT_QUOTES, 'UTF-8')];
}

/**
 * 渲染远程更新日志卡片 HTML（PHP 端复用）
 *
 * @param mixed $changelog 远程日志数组（api/api.php 的 data 字段）
 * @return string 卡片 HTML
 */
function render_remote_changelog_html($changelog): string
{
    if(empty($changelog) || !is_array($changelog)) {
        return '<div class="ou-empty">远程暂无更新日志</div>';
    }
    $html = '';
    // 按版本号降序排序：最新版显示在最上面
    usort($changelog, function($a, $b) {
        return version_compare((string)($b['version'] ?? '0'), (string)($a['version'] ?? '0'));
    });
    foreach($changelog as $logEntry) {
        if(!is_array($logEntry)) continue;
        $logVer  = htmlspecialchars((string)($logEntry['version'] ?? ''), ENT_QUOTES, 'UTF-8');
        $logDate = htmlspecialchars((string)($logEntry['date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $items   = is_array($logEntry['items'] ?? null) ? $logEntry['items'] : [];
        $html .= '<div class="ou-ver-card">'
               . '<div class="ou-ver-head"><span class="ou-ver-num">v' . $logVer . '</span>'
               . '<span class="ou-ver-date">' . $logDate . '</span></div>'
               . '<div class="ou-ver-body"><ul class="ou-changelog-list">';
        foreach($items as $item) {
            if(!is_array($item)) continue;
            $badge = get_remote_type_badge((string)($item['type'] ?? ''));
            $desc  = htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<li><span class="ou-item-badge ' . $badge['class'] . '">' . $badge['label'] . '</span>'
                   . '<span class="ou-item-desc">' . $desc . '</span></li>';
        }
        $html .= '</ul></div></div>';
    }
    return $html;
}

/**
 * 获取本地版本号（被动识别）
 * 优先读取 ROOT . 'version.json'，回退到 APP_VERSION 常量
 *
 * @return string
 */
function get_local_version(): string
{
    $versionFile = ROOT . 'version.json';
    if(file_exists($versionFile)) {
        $data = json_decode((string)file_get_contents($versionFile), true);
        if(is_array($data)) {
            if(isset($data['version'])) return (string)$data['version'];
            if(isset($data['latest_version'])) return (string)$data['latest_version'];
        }
    }
    return defined('APP_VERSION') ? APP_VERSION : '1.0.0';
}

/**
 * 规范化版本号：去除预发布后缀（-dev/-alpha/-beta/-rc 等）
 * 用于版本比较时忽略开发版/预发布版标识，避免 1.3.10-dev 与 1.3.10 误判为不同版本
 *
 * @param string $ver 原始版本号（如 1.3.10-dev）
 * @return string 规范化后的版本号（如 1.3.10）
 */
function normalize_version(string $ver): string
{
    return preg_replace('/-([a-zA-Z]+).*$/', '', $ver);
}

/**
 * 全量备份应用根目录到 backup/bf.zip
 * 排除目录：backup/、logs/、.git/、temp_update/、node_modules/、.env/
 * 排除文件：config.php（数据库配置）、*.bak 等备份/临时文件
 * 目的：备份用于更新失败回滚，但不得包含数据库凭据/Cookie 等敏感数据（backup/ 虽已被 Web 层拦截，仍应纵深防御）
 *
 * @return array{ok:bool, path:?string, error?:string}
 */
function create_backup_zip(): array
{
    $backupDir  = ROOT . 'backup';
    $backupPath = $backupDir . '/bf.zip';

    if(!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    $zip = new ZipArchive();
    if($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'path' => null, 'error' => '无法创建备份压缩包'];
    }

    // 排除目录
    $excludeDir = ['backup', 'logs', '.git', 'temp_update', 'node_modules', '.env', 'docs'];
    // 排除敏感/临时文件（正则，匹配相对路径）
    $excludeFile = [
        '#^config\.php$#i',            // 数据库配置（凭据）
        '#\.(bak|backup|old|orig|tmp|swp|save|~)$#i', // 备份/临时文件
        '#\.(log|jsonl)$#i',           // 运行/开发日志
    ];

    $iter = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
            function($file, $key, $iter) use ($excludeDir, $excludeFile) {
                $relPath = substr((string)$file->getPathname(), strlen(ROOT));
                $relPath = ltrim($relPath, '/\\');
                $topDir  = explode('/', str_replace('\\', '/', $relPath))[0];
                if(in_array($topDir, $excludeDir, true)) {
                    return false;
                }
                // 目录自身放行（递归遍历需要），文件做精确匹配
                if($file->isFile()) {
                    $normPath = str_replace('\\', '/', $relPath);
                    foreach($excludeFile as $rule) {
                        if(preg_match($rule, $normPath)) {
                            return false;
                        }
                    }
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach($iter as $file) {
        if(!$file->isFile()) continue;
        $relPath = substr((string)$file->getPathname(), strlen(ROOT));
        // 统一为 ZIP 标准的正斜杠分隔符
        $relPath = str_replace('\\', '/', $relPath);
        $relPath = ltrim($relPath, '/');
        $zip->addFile((string)$file->getPathname(), $relPath);
    }

    $zip->close();
    return ['ok' => true, 'path' => $backupPath];
}

// AJAX 端点：GET ?action=check_update，只读查询无需 CSRF
if(isset($_GET['action']) && $_GET['action'] === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $remote = fetch_remote_update_info();
    $localVer = get_local_version();
    $hasUpdate = ($remote['ok'] && isset($remote['version']['latest_version']))
        ? (version_compare(normalize_version($localVer), $remote['version']['latest_version']) < 0)
        : false;
    echo json_encode([
        'code' => 0,
        'local_version' => $localVer,
        'has_update' => $hasUpdate,
        'remote' => $remote,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX 端点：POST ?action=do_update，执行在线更新（写操作，必须验证 CSRF）
if(isset($_GET['action']) && $_GET['action'] === 'do_update') {
    header('Content-Type: application/json; charset=utf-8');

    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 405, 'msg' => '仅允许 POST 请求'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CSRF 校验
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfBody   = $_POST['csrf_token'] ?? '';
    $token = defined('CSRF_TOKEN') ? CSRF_TOKEN : (function_exists('csrf_token') ? csrf_token() : '');
    if(empty($token) || (!hash_equals($token, $csrfHeader) && !hash_equals($token, $csrfBody))) {
        echo json_encode(['code' => 403, 'msg' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $localVer = get_local_version();
    $steps = [];

    // Step 1: 检查更新
    $remote = fetch_remote_update_info();
    if(!$remote['ok'] || !isset($remote['version']['latest_version'])) {
        echo json_encode(['code' => 1, 'msg' => $remote['error'] ?? '无法获取远程版本信息'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rv = $remote['version'];
    $remoteVer = $rv['latest_version'];
    if(version_compare(normalize_version($localVer), $remoteVer) >= 0) {
        echo json_encode(['code' => 1, 'msg' => '当前已是最新版本，无需更新'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $steps[] = '检查更新 ✓';

    // 检查 ZipArchive
    if(!class_exists('ZipArchive')) {
        echo json_encode(['code' => 1, 'msg' => 'PHP 缺少 ZipArchive 扩展，无法解压更新包'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $downloadUrl = $rv['download_url'] ?? '';
    $signature   = $rv['signature'] ?? '';
    if(empty($downloadUrl) || empty($signature)) {
        echo json_encode(['code' => 1, 'msg' => '远程版本信息缺少下载地址或签名'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 环境要求检查：PHP 版本必须满足远程声明的最低要求（文档 § 八「服务器环境要求」）
    $minPhp = $rv['min_php_version'] ?? '';
    if($minPhp !== '' && version_compare(PHP_VERSION, $minPhp, '<')) {
        echo json_encode(['code' => 1, 'msg' => 'PHP 版本过低：需 ' . $minPhp . '+，当前 ' . PHP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Step 2: 下载更新包
    $tempDir = sys_get_temp_dir() . '/eecms_update_' . date('Ymd_His');
    if(!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $zipPath = $tempDir . '/update_' . $remoteVer . '.zip';

    $ch = curl_init($downloadUrl);
    curl_setup_ssl($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $zipData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($zipData === false || $httpCode !== 200) {
        echo json_encode(['code' => 1, 'msg' => '下载更新包失败（HTTP ' . $httpCode . '）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if(file_put_contents($zipPath, $zipData) === false) {
        echo json_encode(['code' => 1, 'msg' => '无法写入临时文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $steps[] = '下载更新包 ✓';

    // Step 3: 签名校验（SHA-256）
    $localHash = hash_file('sha256', $zipPath);
    if($localHash !== $signature) {
        @unlink($zipPath);
        @rmdir($tempDir);
        echo json_encode(['code' => 1, 'msg' => '签名校验失败，更新包可能被篡改'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $steps[] = '签名校验 ✓';

    // Step 4: 全量备份 → backup/bf.zip（覆盖旧备份）
    $backupResult = create_backup_zip();
    if(!$backupResult['ok']) {
        @unlink($zipPath);
        @rmdir($tempDir);
        echo json_encode(['code' => 1, 'msg' => $backupResult['error'] ?? '全量备份失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $steps[] = '全量备份 → backup/bf.zip ✓';

    // Step 5: 解压覆盖（更新包内含完整目录结构 + version.json）
    $zip = new ZipArchive();
    if($zip->open($zipPath) !== true) {
        echo json_encode(['code' => 1, 'msg' => '无法打开 ZIP 文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 排除目录（对齐文档 § 九「安全机制-文件排除」+ gxrz Updater.class.php）：
    // client/（更新器自身）、temp_update/（临时）、backup/（备份）、config/（数据库配置等敏感文件）、.git/
    $excludeDirs = ['backup/', '.git/', 'config/', 'temp_update/', 'client/'];
    for($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        $skip = false;
        foreach($excludeDirs as $ex) {
            if(strpos($entryName, $ex) === 0) {
                $skip = true;
                break;
            }
        }
        if($skip) continue;

        $targetPath = ROOT . $entryName;
        // 防路径遍历
        if(strpos(realpath(dirname($targetPath)) ?: '', realpath(ROOT) ?: '') !== 0) continue;

        if(substr($entryName, -1) === '/') {
            if(!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        } else {
            $dir = dirname($targetPath);
            if(!is_dir($dir)) @mkdir($dir, 0755, true);
            file_put_contents($targetPath, $zip->getFromIndex($i));
        }
    }
    $zip->close();
    $steps[] = '解压覆盖 ✓';

    // Step 6: 执行迁移脚本（如有）
    $migrationScript = $rv['migration_script'] ?? null;
    if(!empty($migrationScript)) {
        $migrateFile = ROOT . $migrationScript;
        if(file_exists($migrateFile)) {
            @include($migrateFile);
            @unlink($migrateFile);
        }
        $steps[] = '执行迁移 ✓';
    }

    // Step 7: 被动识别版本号（读取覆盖后的 version.json，禁止主动写入）
    $detectedVersion = get_local_version();
    $steps[] = '版本号识别 → ' . $detectedVersion . ' ✓';

    // 清理临时文件
    @unlink($zipPath);
    @rmdir($tempDir);

    echo json_encode([
        'code' => 0,
        'msg'  => '更新成功，当前版本 ' . $detectedVersion,
        'data' => [
            'success'         => true,
            'steps'           => $steps,
            'current_version' => $detectedVersion,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 服务端预取远程信息（页面首次加载时展示）
$remoteUpdate = fetch_remote_update_info();
$localVersion = get_local_version();
$hasRemoteUpdate = ($remoteUpdate['ok'] && isset($remoteUpdate['version']['latest_version']))
    ? (version_compare(normalize_version($localVersion), $remoteUpdate['version']['latest_version']) < 0)
    : false;
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>更新日志 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
<style>
html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}
/* 在线更新检查区块 */
.online-update-section{margin-bottom:24px;border:1px solid var(--color-border);border-radius:var(--radius-lg);overflow:hidden;background:var(--color-surface);box-shadow:var(--shadow-lg)}
.online-update-section .ou-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(135deg,rgba(59,130,246,0.08),rgba(99,102,241,0.06));border-bottom:1px solid var(--color-border)}
.online-update-section .ou-title{display:flex;align-items:center;gap:8px;font-size:1.05rem;font-weight:700;color:var(--color-text-primary)}
.online-update-section .ou-title svg{font-size:22px;color:var(--color-primary)}
.online-update-section .ou-body{padding:16px 18px}
.ou-status-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.ou-status-item{display:flex;flex-direction:column;gap:2px}
.ou-status-item .label{font-size:0.76rem;color:var(--color-text-muted)}
.ou-status-item .value{font-size:1rem;font-weight:600;color:var(--color-text-primary);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace}
.ou-status-badge{margin-left:auto;padding:6px 14px;border-radius:999px;font-size:0.82rem;font-weight:600}
.ou-badge-update{background:rgba(245,158,11,0.12);color:var(--color-warning);border:1px solid rgba(245,158,11,0.3)}
.ou-badge-latest{background:rgba(34,197,94,0.12);color:var(--color-success);border:1px solid rgba(34,197,94,0.3)}
.ou-badge-error{background:rgba(239,68,68,0.12);color:var(--color-danger);border:1px solid rgba(239,68,68,0.3)}
.ou-btn-update{margin-left:8px;padding:6px 18px;font-size:0.84rem;font-weight:600;border-radius:var(--radius-sm);border:none;background:var(--gradient-blue);color:#fff;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.ou-btn-update:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(59,130,246,0.35)}
.ou-btn-update:disabled{opacity:.6;cursor:not-allowed;transform:none}
.ou-changelog-list{list-style:none;padding:0;margin:0}
.ou-changelog-list li{display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px dashed var(--color-border);font-size:0.88rem}
.ou-changelog-list li:last-child{border-bottom:none}
.ou-ver-card{margin-bottom:14px;border:1px solid var(--color-border);border-radius:var(--radius-sm);overflow:hidden;background:var(--color-surface)}
.ou-ver-card .ou-ver-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(59,130,246,0.04);border-bottom:1px solid var(--color-border)}
.ou-ver-card .ou-ver-num{font-size:0.95rem;font-weight:700;color:var(--color-text-primary);font-family:"SF Mono",Consolas,Monaco,Menlo,"Courier New",monospace}
.ou-ver-card .ou-ver-date{font-size:0.8rem;color:var(--color-text-muted)}
.ou-ver-card .ou-ver-body{padding:10px 14px}
.ou-changelog-list .ou-item-badge{flex-shrink:0;font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:6px;color:#fff;white-space:nowrap}
.ou-changelog-list .ou-item-desc{color:var(--color-text-primary);line-height:1.5}
.ou-info-line{font-size:0.82rem;color:var(--color-text-muted);margin-top:10px;padding-top:10px;border-top:1px solid var(--color-border)}
.ou-info-line code{background:rgba(59,130,246,0.08);padding:1px 6px;border-radius:4px;color:var(--color-primary);font-size:0.8rem}
.ou-version-compare{display:flex;align-items:center;gap:8px;font-size:0.86rem;color:var(--color-text-muted)}
.ou-version-compare .arrow{color:var(--color-primary);font-weight:700}
.ou-empty{text-align:center;padding:20px;color:var(--color-text-muted);font-size:0.88rem}
</style>
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('history'); ?> 更新日志
  </div>

  <!-- 在线更新检查区块 -->
  <div class="online-update-section" id="onlineUpdateSection">
    <div class="ou-header">
      <div class="ou-title">
        <?php echo icon('cloud-download'); ?> 在线更新检查
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="btnRefreshUpdate" onclick="refreshRemoteUpdate()">
        <?php echo icon('refresh'); ?> 重新检查
      </button>
    </div>
    <div class="ou-body" id="ouBody">
      <?php if(!$remoteUpdate['ok']): ?>
        <div class="ou-status-row">
          <div class="ou-status-item">
            <span class="label">本地版本</span>
            <span class="value">v<?php echo htmlspecialchars($localVersion, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <span class="ou-status-badge ou-badge-error">
            <?php echo icon('alert-circle-outline'); ?> 远程未发布更新包
          </span>
        </div>
        <?php if(!empty($remoteUpdate['changelog']) && is_array($remoteUpdate['changelog'])): ?>
          <div class="ou-info-line">
            <?php echo icon('information-outline'); ?>
            <?php echo htmlspecialchars($remoteUpdate['error'] ?? '远程更新包暂未发布', ENT_QUOTES, 'UTF-8'); ?>
            ，仅展示远程更新日志。
          </div>
          <?php echo render_remote_changelog_html($remoteUpdate['changelog']); ?>
        <?php else: ?>
          <div class="ou-empty"><?php echo htmlspecialchars($remoteUpdate['error'] ?? '无法连接更新服务器', ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
      <?php else:
        $rv = $remoteUpdate['version'];
        $remoteVer = $rv['latest_version'] ?? '-';
        $releaseDate = $rv['release_date'] ?? '-';
        $pkgType = $rv['package_type'] ?? 'update';
        $minPhp = $rv['min_php_version'] ?? '-';
      ?>
        <div class="ou-status-row">
          <div class="ou-status-item">
            <span class="label">本地版本</span>
            <span class="value">v<?php echo htmlspecialchars($localVersion, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="ou-version-compare"><?php echo icon('arrow-right'); ?></div>
          <div class="ou-status-item">
            <span class="label">远程最新</span>
            <span class="value">v<?php echo htmlspecialchars($remoteVer, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="ou-status-item">
            <span class="label">发布日期</span>
            <span class="value" style="font-family:inherit"><?php echo htmlspecialchars($releaseDate, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <?php if($hasRemoteUpdate): ?>
            <span class="ou-status-badge ou-badge-update">
              <?php echo icon('arrow-up-bold-circle-outline'); ?> 发现新版本
            </span>
            <button class="ou-btn-update" id="btnDoUpdate" onclick="doUpdate()">
              <?php echo icon('cloud-upload-outline'); ?> 在线升级
            </button>
          <?php else: ?>
            <span class="ou-status-badge ou-badge-latest">
              <?php echo icon('check-circle-outline'); ?> 已是最新版本
            </span>
          <?php endif; ?>
        </div>

        <?php echo render_remote_changelog_html($remoteUpdate['changelog'] ?? null); ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// 重新检查更新（AJAX，GET 只读查询无需 CSRF）
function refreshRemoteUpdate() {
    var btn = document.getElementById('btnRefreshUpdate');
    var body = document.getElementById('ouBody');
    if(!btn || !body) return;
    btn.disabled = true;
    btn.innerHTML = ''+eeIcon('loading','icon-spin')+' 检查中...';
    fetch('changelog.php?action=check_update', {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(res){
          renderRemoteUpdate(res);
          btn.disabled = false;
          btn.innerHTML = ''+eeIcon('refresh')+' 重新检查';
      })
      .catch(function(err){
          body.innerHTML = '<div class="ou-status-row"><div class="ou-status-item"><span class="label">本地版本</span><span class="value">v' + escapeHtml('<?php echo htmlspecialchars($localVersion, ENT_QUOTES, 'UTF-8'); ?>') + '</span></div><span class="ou-status-badge ou-badge-error">'+eeIcon('alert-circle-outline')+' 网络错误</span></div><div class="ou-empty">请求失败：' + escapeHtml(String(err)) + '</div>';
          btn.disabled = false;
          btn.innerHTML = ''+eeIcon('refresh')+' 重新检查';
      });
}

function renderRemoteUpdate(res) {
    var body = document.getElementById('ouBody');
    if(!body) return;
    var local = res.local_version || '?';
    var remote = res.remote || {};
    if(!remote.ok) {
        // 远程未发布更新包（version.json 不可用）：若日志可取则降级展示，否则显示错误
        var cl = remote.changelog;
        if(cl && Array.isArray(cl) && cl.length > 0) {
            body.innerHTML =
                '<div class="ou-status-row">' +
                  '<div class="ou-status-item"><span class="label">本地版本</span><span class="value">v' + escapeHtml(local) + '</span></div>' +
                  '<span class="ou-status-badge ou-badge-error">'+eeIcon('alert-circle-outline')+' 远程未发布更新包</span>' +
                '</div>' +
                '<div class="ou-info-line">'+eeIcon('information-outline')+' ' + escapeHtml(remote.error || '远程更新包暂未发布') + '，仅展示远程更新日志。</div>' +
                renderChangelogHtml(cl);
        } else {
            body.innerHTML = '<div class="ou-status-row"><div class="ou-status-item"><span class="label">本地版本</span><span class="value">v' + escapeHtml(local) + '</span></div><span class="ou-status-badge ou-badge-error">'+eeIcon('alert-circle-outline')+' 远程连接失败</span></div><div class="ou-empty">' + escapeHtml(remote.error || '无法连接更新服务器') + '</div>';
        }
        return;
    }
    var v = remote.version || {};
    var remoteVer = v.latest_version || '-';
    var releaseDate = v.release_date || '-';
    var hasUpdate = res.has_update;
    var badgeHtml = hasUpdate
        ? '<span class="ou-status-badge ou-badge-update">'+eeIcon('arrow-up-bold-circle-outline')+' 发现新版本</span><button class="ou-btn-update" id="btnDoUpdate" onclick="doUpdate()">'+eeIcon('cloud-upload-outline')+' 在线升级</button>'
        : '<span class="ou-status-badge ou-badge-latest">'+eeIcon('check-circle-outline')+' 已是最新版本</span>';
    body.innerHTML =
        '<div class="ou-status-row">' +
          '<div class="ou-status-item"><span class="label">本地版本</span><span class="value">v' + escapeHtml(local) + '</span></div>' +
          '<div class="ou-version-compare">'+eeIcon('arrow-right')+'</div>' +
          '<div class="ou-status-item"><span class="label">远程最新</span><span class="value">v' + escapeHtml(remoteVer) + '</span></div>' +
          '<div class="ou-status-item"><span class="label">发布日期</span><span class="value" style="font-family:inherit">' + escapeHtml(releaseDate) + '</span></div>' +
          badgeHtml +
        '</div>' +
        renderChangelogHtml(remote.changelog);
}

// 渲染远程更新日志卡片 HTML（JS 端复用）
function renderChangelogHtml(cl) {
    var changelogHtml = '';
    if(cl && Array.isArray(cl) && cl.length > 0) {
        // 按版本号降序排序：最新版显示在最上面
        cl = cl.slice().sort(function(a, b) {
            var pa = String(a.version || '0').split('.');
            var pb = String(b.version || '0').split('.');
            var len = Math.max(pa.length, pb.length);
            for(var i = 0; i < len; i++) {
                var na = parseInt(pa[i] || '0', 10);
                var nb = parseInt(pb[i] || '0', 10);
                if(na !== nb) return nb - na;
            }
            return 0;
        });
        cl.forEach(function(entry){
            var ev = escapeHtml(entry.version || '');
            var ed = escapeHtml(entry.date || '');
            changelogHtml += '<div class="ou-ver-card"><div class="ou-ver-head"><span class="ou-ver-num">v' + ev + '</span><span class="ou-ver-date">' + ed + '</span></div><div class="ou-ver-body"><ul class="ou-changelog-list">';
            var items = entry.items || [];
            items.forEach(function(item){
                var badge = getRemoteTypeBadge(item.type || '');
                changelogHtml += '<li><span class="ou-item-badge ' + badge[0] + '">' + badge[1] + '</span><span class="ou-item-desc">' + escapeHtml(item.description || '') + '</span></li>';
            });
            changelogHtml += '</ul></div></div>';
        });
    } else {
        changelogHtml = '<div class="ou-empty">远程暂无更新日志</div>';
    }
    return changelogHtml;
}

function getRemoteTypeBadge(type) {
    var map = {
        'feature': ['bg-primary', '新功能'],
        'fix': ['bg-danger', '修复'],
        'improvement': ['bg-success', '优化'],
        'security': ['bg-warning text-dark', '安全'],
        'breaking': ['bg-purple', '破坏性'],
        'deprecation': ['bg-secondary', '废弃']
    };
    var key = (type || '').toLowerCase().trim();
    return map[key] || ['bg-secondary', escapeHtml(type)];
}

function escapeHtml(str) {
    if(str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
</script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<meta name="csrf-token" content="<?php echo function_exists('csrf_token') ? csrf_token() : ''; ?>">
<script>
// 在线升级（POST + CSRF，危险操作需二次确认）
function doUpdate() {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var btn = document.getElementById('btnDoUpdate');

    if(typeof Swal === 'undefined') {
        alert2('SweetAlert2 未加载，无法显示确认弹窗');
        return;
    }

    Swal.fire({
        title: '确认在线升级？',
        html: '<div style="text-align:left;line-height:1.8">系统将自动执行以下步骤：<br>'
            + '1. 下载远程更新包<br>'
            + '2. SHA-256 签名校验<br>'
            + '3. 全量备份当前文件 → backup/bf.zip<br>'
            + '4. 解压覆盖<br>'
            + '5. 被动识别新版本号<br><br>'
            + '<span style="color:var(--color-danger);font-size:0.84rem">更新期间请勿关闭页面，更新完成后页面将自动刷新</span>'
            + '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '确认升级',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result) {
        if(!result.isConfirmed) return;

        // 禁用按钮，显示进度
        if(btn) {
            btn.disabled = true;
            btn.innerHTML = ''+eeIcon('loading','icon-spin')+' 升级中...';
        }

        Swal.fire({
            title: '正在升级',
            html: '<div id="updateProgress" style="text-align:left;line-height:2;font-size:0.88rem">'
                + ''+eeIcon('loading','icon-spin')+' 下载更新包中...<br>'
                + '<span style="color:var(--color-text-muted)">请耐心等待，勿关闭页面</span>'
                + '</div>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false
        });

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);

        fetch('changelog.php?action=do_update', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if(res.code === 0 && res.data && res.data.success) {
                var stepsHtml = res.data.steps.map(function(s) {
                    return '<div style="color:var(--color-success)">'+eeIcon('check-circle')+' ' + escapeHtml(s) + '</div>';
                }).join('');
                Swal.fire({
                    title: '升级成功',
                    html: '<div style="text-align:left;line-height:2;font-size:0.88rem">' + stepsHtml + '</div>',
                    icon: 'success',
                    confirmButtonText: '确定',
                    confirmButtonColor: '#6366f1',
                    timer: 3000,
                    timerProgressBar: true
                }).then(function() {
                    // 局部刷新更新区块
                    refreshRemoteUpdate();
                    if(btn) {
                        btn.disabled = false;
                        btn.innerHTML = ''+eeIcon('cloud-upload-outline')+' 在线升级';
                    }
                });
            } else {
                Swal.fire({
                    title: '升级失败',
                    text: res.msg || '未知错误',
                    icon: 'error',
                    confirmButtonText: '确定',
                    confirmButtonColor: '#6366f1'
                });
                if(btn) {
                    btn.disabled = false;
                    btn.innerHTML = ''+eeIcon('cloud-upload-outline')+' 在线升级';
                }
            }
        })
        .catch(function(err) {
            Swal.fire({
                title: '网络错误',
                text: String(err),
                icon: 'error',
                confirmButtonText: '确定',
                confirmButtonColor: '#6366f1'
            });
            if(btn) {
                btn.disabled = false;
                btn.innerHTML = ''+eeIcon('cloud-upload-outline')+' 在线升级';
            }
        });
    });
}

function alert2(msg) {
    var d = document.createElement('div');
    d.style.cssText = 'position:fixed;top:20px;right:20px;padding:12px 20px;background:var(--color-danger);color:#fff;border-radius:8px;z-index:99999;font-size:0.88rem';
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(function(){ d.remove(); }, 3000);
}
</script>
</body>
</html>
