<?php
/**
 * @file index.php
 * @description 安装向导入口，含 CSRF 防护与安装锁检测
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
function install_csrf_token() {
    if(empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}
function install_csrf_verify() {
    $sent = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $stored = isset($_SESSION['install_csrf']) ? (string)$_SESSION['install_csrf'] : '';
    return $stored !== '' && $sent !== '' && hash_equals($stored, $sent);
}
error_reporting(0);
@header('Content-Type: text/html; charset=UTF-8');
$do=isset($_GET['do'])?$_GET['do']:'0';
if(file_exists('install.lock')){
	$installed=true;
	$do='0';
}

// ========== 环境检测函数 ==========
function envCheck($label, $required, $current, $ok, $desc='', $level='info'){
	// level: ok|fail|warn
	if($ok){
		$cls = 'env-ok';
		$icon = 'mdi-check-circle';
		$statusText = '通过';
	} elseif($required){
		$cls = 'env-fail';
		$icon = 'mdi-close-circle';
		$statusText = '不通过';
	} else {
		$cls = 'env-warn';
		$icon = 'mdi-alert-circle';
		$statusText = '建议开启';
	}
	return [
		'label' => $label, 'required' => $required, 'current' => $current,
		'ok' => $ok, 'desc' => $desc, 'cls' => $cls, 'icon' => $icon, 'statusText' => $statusText,
	];
}

function checkfunc($f,$m = false) {
	if (function_exists($f)) {
		return '<span class="text-success"><i class="mdi mdi-check-circle"></i> 支持</span>';
	} else {
		if ($m == false) {
			return '<span class="text-warning"><i class="mdi mdi-alert-circle"></i> 不支持</span>';
		} else {
			return '<span class="text-danger"><i class="mdi mdi-close-circle"></i> 不支持</span>';
		}
	}
}

function checkclass($f,$m = false) {
	if (class_exists($f)) {
		return '<span class="text-success"><i class="mdi mdi-check-circle"></i> 支持</span>';
	} else {
		if ($m == false) {
			return '<span class="text-warning"><i class="mdi mdi-alert-circle"></i> 不支持</span>';
		} else {
			return '<span class="text-danger"><i class="mdi mdi-close-circle"></i> 不支持</span>';
		}
	}
}

function checkext($ext, $required=true){
	$loaded = extension_loaded($ext);
	if($loaded){
		return envCheck(ucfirst($ext).' 扩展', $required, '<span class="text-success">已安装</span>', true, 'PHP '.$ext.' 扩展');
	} else {
		return envCheck(ucfirst($ext).' 扩展', $required, '<span class="text-danger">未安装</span>', false, 'PHP '.$ext.' 扩展');
	}
}

// 步骤映射（H1+H2：新增「管理员设置」步骤，符合规范 § 8.4.1 六步安装流程）
$stepMap = ['0'=>1, '1'=>2, '2'=>3, '3'=>3, '4'=>4, '5'=>5, '6'=>6];
$currentStep = isset($stepMap[$do]) ? $stepMap[$do] : 1;
$stepLabels = ['协议说明', '环境检测', '数据库配置', '创建数据表', '管理员设置', '安装完成'];
$stepPercents = [10, 25, 45, 65, 85, 100];
$stepPercent = isset($stepPercents[$currentStep-1]) ? $stepPercents[$currentStep-1] : 10;
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - 图床系统</title>
<link rel="stylesheet" href="../admin/style/css/materialdesignicons.min.css">
<style>
:root{
	--brand:#6d4aff; --brand2:#9d5cff; --bg-dark:#0f0a1e; --card-bg:rgba(255,255,255,.95);
	--ok:#10b981; --fail:#ef4444; --warn:#f59e0b; --text:#1e293b; --text-muted:#64748b;
}
*{box-sizing:border-box}
body{
	margin:0; font-family:-apple-system,'Segoe UI','Microsoft YaHei',sans-serif;
	background:linear-gradient(135deg,#667eea 0%,#764ba2 50%,#6d4aff 100%);
	min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;
}
.install-wrapper{width:100%; max-width:780px; margin:0 auto;}
.install-header{text-align:center; margin-bottom:24px; color:#fff;}
.install-header .logo-icon{
	width:72px; height:72px; margin:0 auto 16px; border-radius:20px;
	background:rgba(255,255,255,.15); backdrop-filter:blur(10px);
	display:flex; align-items:center; justify-content:center;
	border:1px solid rgba(255,255,255,.2);
}
.install-header .logo-icon i{font-size:36px; color:#fff;}
.install-header h1{font-size:24px; font-weight:700; margin:0 0 6px; text-shadow:0 2px 8px rgba(0,0,0,.15);}
.install-header p{font-size:14px; opacity:.85; margin:0;}

/* 步进器 */
.stepper{
	display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:24px;
	padding:20px 30px; background:rgba(255,255,255,.1); backdrop-filter:blur(10px);
	border-radius:16px; border:1px solid rgba(255,255,255,.15);
}
.step-item{display:flex; align-items:center; gap:8px;}
.step-circle{
	width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;
	font-size:14px; font-weight:700; background:rgba(255,255,255,.2); color:rgba(255,255,255,.6);
	border:2px solid rgba(255,255,255,.2); transition:all .4s ease;
}
.step-item.active .step-circle{
	background:#fff; color:var(--brand); border-color:#fff;
	transform:scale(1.1); box-shadow:0 4px 16px rgba(255,255,255,.3);
}
.step-item.done .step-circle{background:var(--ok); color:#fff; border-color:var(--ok);}
.step-label{font-size:12px; color:rgba(255,255,255,.7); white-space:nowrap;}
.step-item.active .step-label{color:#fff; font-weight:600;}
.step-item.done .step-label{color:rgba(255,255,255,.9);}
.step-line{width:36px; height:2px; background:rgba(255,255,255,.2); margin:0 6px;}
.step-line.done{background:var(--ok);}

/* 卡片 */
.install-card{
	background:var(--card-bg); border-radius:20px; overflow:hidden;
	box-shadow:0 20px 60px rgba(0,0,0,.15); backdrop-filter:blur(20px);
}
.card-progress-bar{height:4px; background:#f1f5f9; position:relative; overflow:hidden;}
.card-progress-bar .bar-fill{
	height:100%; background:linear-gradient(90deg,var(--brand),var(--brand2));
	border-radius:0 4px 4px 0; transition:width .6s cubic-bezier(.4,0,.2,1);
}
.card-header-custom{
	padding:20px 28px; border-bottom:1px solid #f1f5f9;
	display:flex; align-items:center; gap:10px;
}
.card-header-custom h3{font-size:18px; font-weight:700; color:var(--text); margin:0;}
.card-header-custom i{font-size:24px; color:var(--brand);}
.card-body-custom{padding:28px;}

/* 环境检测表格 */
.env-section-title{
	font-size:13px; font-weight:700; color:var(--text-muted); text-transform:uppercase;
	letter-spacing:.5px; margin:0 0 12px; display:flex; align-items:center; gap:6px;
}
.env-table{width:100%; border-collapse:separate; border-spacing:0;}
.env-table th{
	font-size:12px; font-weight:600; color:var(--text-muted); text-align:left;
	padding:10px 12px; border-bottom:2px solid #f1f5f9;
}
.env-table td{padding:12px; font-size:14px; border-bottom:1px solid #f8fafc; color:var(--text);}
.env-table tr:last-child td{border-bottom:none;}
.env-table .status-cell{font-weight:600; white-space:nowrap;}
.env-ok{color:var(--ok);}
.env-fail{color:var(--fail);}
.env-warn{color:var(--warn);}

/* 表单 */
.form-group-install{margin-bottom:18px;}
.form-group-install label{font-size:13px; font-weight:600; color:var(--text); margin-bottom:6px; display:block;}
.form-group-install .form-control{
	border:2px solid #e2e8f0; border-radius:10px; padding:10px 14px; font-size:14px;
	transition:all .2s; background:#fff;
}
.form-group-install .form-control:focus{
	border-color:var(--brand); box-shadow:0 0 0 3px rgba(109,74,255,.1); outline:none;
}
.form-hint{font-size:12px; color:var(--text-muted); margin-top:4px;}

/* 按钮 */
.btn-install{
	border:none; border-radius:10px; padding:12px 28px; font-size:14px; font-weight:600;
	cursor:pointer; transition:all .25s; display:inline-flex; align-items:center; gap:8px;
	text-decoration:none;
}
.btn-install-primary{
	background:linear-gradient(135deg,var(--brand),var(--brand2)); color:#fff;
	box-shadow:0 4px 14px rgba(109,74,255,.3);
}
.btn-install-primary:hover{transform:translateY(-1px); box-shadow:0 6px 20px rgba(109,74,255,.4); color:#fff;}
.btn-install-secondary{background:#f1f5f9; color:var(--text-muted);}
.btn-install-secondary:hover{background:#e2e8f0; color:var(--text); text-decoration:none;}
.btn-install-success{background:linear-gradient(135deg,#10b981,#059669); color:#fff; box-shadow:0 4px 14px rgba(16,185,129,.3);}
.btn-install-success:hover{transform:translateY(-1px); color:#fff; text-decoration:none;}
.btn-install:disabled{opacity:.5; cursor:not-allowed; transform:none;}

/* 提示框 */
.alert-install{padding:14px 18px; border-radius:12px; font-size:13px; margin-bottom:16px; display:flex; gap:10px; align-items:flex-start;}
.alert-install i{font-size:20px; flex-shrink:0;}
.alert-install-success{background:#f0fdf4; border:1px solid #bbf7d0; color:#065f46;}
.alert-install-danger{background:#fef2f2; border:1px solid #fecaca; color:#991b1b;}
.alert-install-warning{background:#fffbeb; border:1px solid #fde68a; color:#92400e;}
.alert-install-info{background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;}

/* 完成页 */
.complete-icon{
	width:80px; height:80px; margin:0 auto 20px; border-radius:50%;
	background:linear-gradient(135deg,#10b981,#059669);
	display:flex; align-items:center; justify-content:center;
	box-shadow:0 8px 32px rgba(16,185,129,.3);
	animation:bounceIn .6s ease;
}
.complete-icon i{font-size:40px; color:#fff;}
@keyframes bounceIn{0%{transform:scale(0)}60%{transform:scale(1.1)}100%{transform:scale(1)}}

.nav-btns{display:flex; justify-content:space-between; align-items:center; margin-top:24px; padding-top:20px; border-top:1px solid #f1f5f9;}
@media(max-width:576px){
	.stepper{padding:14px 10px; gap:0;}
	.step-label{display:none;}
	.step-line{width:20px;}
	.card-body-custom{padding:18px;}
	.install-header h1{font-size:20px;}
}

/* 加载动画 */
.loading-dots{display:inline-flex; gap:4px;}
.loading-dots span{
	width:8px; height:8px; border-radius:50%; background:var(--brand);
	animation:dotPulse 1.4s infinite ease-in-out;
}
.loading-dots span:nth-child(2){animation-delay:.2s;}
.loading-dots span:nth-child(3){animation-delay:.4s;}
@keyframes dotPulse{0%,80%,100%{opacity:.3; transform:scale(.8)}40%{opacity:1; transform:scale(1)}}

/* Bootstrap grid 等价实现（替代已移除的 bootstrap.min.css） */
.row{display:flex; flex-wrap:wrap; margin-left:-8px; margin-right:-8px;}
.col-md-4,.col-md-6,.col-md-8{position:relative; width:100%; padding-left:8px; padding-right:8px;}
@media(min-width:768px){
	.col-md-4{flex:0 0 33.333333%; max-width:33.333333%;}
	.col-md-6{flex:0 0 50%; max-width:50%;}
	.col-md-8{flex:0 0 66.666667%; max-width:66.666667%;}
}

/* Bootstrap 文本颜色等价实现 */
.text-success{color:var(--ok);}
.text-warning{color:var(--warn);}
.text-danger{color:var(--fail);}

/* form-control 基础样式（与 .form-group-install .form-control 配合） */
.form-control{display:block; width:100%; font-size:14px; color:var(--text);}
</style>
</head>
<body>

<div class="install-wrapper">

	<!-- 头部 -->
	<div class="install-header">
		<div class="logo-icon"><i class="mdi mdi-cloud-upload"></i></div>
		<h1>图床系统安装向导</h1>
		<p>PHP + MySQL 驱动的现代化图床管理系统</p>
	</div>

	<!-- 步进器 -->
	<div class="stepper">
		<?php for($si=0; $si<6; $si++): ?>
		<div class="step-item <?php echo $currentStep>$si+1?'done':''; ?> <?php echo $currentStep==$si+1?'active':''; ?>">
			<div class="step-circle">
				<?php if($currentStep>$si+1): ?><i class="mdi mdi-check"></i><?php else: echo $si+1; endif; ?>
			</div>
			<span class="step-label"><?php echo $stepLabels[$si]; ?></span>
		</div>
		<?php if($si<5): ?>
		<div class="step-line <?php echo $currentStep>$si+1?'done':''; ?>"></div>
		<?php endif; ?>
		<?php endfor; ?>
	</div>

	<!-- 卡片 -->
	<div class="install-card">
		<div class="card-progress-bar"><div class="bar-fill" style="width:<?php echo $stepPercent; ?>%"></div></div>

<?php if($do=='0'){ ?>
		<!-- 步骤1: 协议说明 -->
		<div class="card-header-custom">
			<i class="mdi mdi-file-document-outline"></i>
			<h3>安装协议与说明</h3>
		</div>
		<div class="card-body-custom">
			<?php if(isset($installed) && $installed): ?>
			<div class="alert-install alert-install-danger">
				<i class="mdi mdi-alert-circle"></i>
				<div>系统已安装。如需重新安装，请先删除 <code>install/install.lock</code> 文件！</div>
			</div>
			<?php endif; ?>

			<div class="alert-install alert-install-info">
				<i class="mdi mdi-information-outline"></i>
				<div>欢迎使用图床系统！本系统采用 PHP + MySQL 开发，支持 46+ 图床接口、S3 存储、用户中心和邮件验证。请按向导逐步完成安装。</div>
			</div>

			<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; max-height:200px; overflow-y:auto; font-size:13px; color:#475569; line-height:1.7; margin-bottom:20px;">
				<strong>授权声明：</strong>本系统是免费可商用的建站系统。您出于自愿而使用本系统，必须了解使用的风险，系统不提供任何形式的使用担保，也不承担任何因使用而产生问题的相关责任。<br><br>
				<strong>注意事项：</strong><br>
				1. 本系统采用伪静态，若主机不支持伪静态请勿使用<br>
				2. Apache 服务器只需开启伪静态功能，系统已配置好<br>
				3. Nginx 服务器需按 readme.txt 中的规则配置伪静态
			</div>

			<div class="nav-btns">
				<span></span>
				<a class="btn-install btn-install-primary" href="index.php?do=1">
					开始安装 <i class="mdi mdi-arrow-right"></i>
				</a>
			</div>
		</div>

<?php } elseif($do=='1'){
// ========== 步骤2: 环境检测 ==========
$phpVer = PHP_VERSION;
// H4 修复：规范 § 8.1 强制 PHP 8.5+，原 7.2 阈值会导致安装后运行时崩溃
$phpVerOk = version_compare($phpVer, '8.5.0', '>=');

// PHP 扩展检测
$extChecks = [
	checkext('mysqli', true),
	checkext('curl', true),
	checkext('json', true),
	checkext('mbstring', true),
	checkext('openssl', false),
	checkext('fileinfo', false),
	checkext('gd', false),
];

// PHP 函数检测
$funcChecks = [
	envCheck('curl_exec()', true, checkfunc('curl_exec', true), function_exists('curl_exec'), '抓取外部图床 API'),
	envCheck('file_get_contents()', true, checkfunc('file_get_contents', true), function_exists('file_get_contents'), '读取文件内容'),
	envCheck('fsockopen()', false, checkfunc('fsockopen', false), function_exists('fsockopen'), 'SMTP 邮件发送（邮箱验证功能需要）'),
	envCheck('json_decode()', true, checkfunc('json_decode', true), function_exists('json_decode'), 'JSON 数据解析'),
	envCheck('mb_substr()', false, checkfunc('mb_substr', false), function_exists('mb_substr'), '多字节字符串处理'),
	envCheck('imagecreatefromjpeg()', false, checkfunc('imagecreatefromjpeg', false), function_exists('imagecreatefromjpeg'), '图片处理（GD 扩展）'),
	envCheck('openssl_encrypt()', false, checkfunc('openssl_encrypt', false), function_exists('openssl_encrypt'), '加密解密（Cookie 安全）'),
];

// PHP 配置项检测
$cfgUploadMax = ini_get('upload_max_filesize');
$cfgPostMax = ini_get('post_max_size');
$cfgMaxExec = ini_get('max_execution_time');
$cfgMemLimit = ini_get('memory_limit');

function parseSizeToBytes($val){
	$val = trim($val);
	$last = strtolower($val[strlen($val)-1]);
	$num = intval($val);
	switch($last){ case 'g': $num *= 1024; case 'm': $num *= 1024; case 'k': $num *= 1024; }
	return $num;
}
$uploadOk = parseSizeToBytes($cfgUploadMax) >= 2*1024*1024;
$postOk = parseSizeToBytes($cfgPostMax) >= 8*1024*1024;
$execOk = (int)$cfgMaxExec >= 30 || $cfgMaxExec === '0';
$memOk = parseSizeToBytes($cfgMemLimit) >= 64*1024*1024 || $cfgMemLimit === '-1';

$cfgChecks = [
	envCheck('upload_max_filesize', false, $cfgUploadMax ?: '未设置', $uploadOk, '文件上传大小限制，建议 ≥ 2M'),
	envCheck('post_max_size', false, $cfgPostMax ?: '未设置', $postOk, 'POST 数据大小限制，建议 ≥ 8M'),
	envCheck('max_execution_time', false, $cfgMaxExec ?: '0', $execOk, '脚本最大执行时间（秒），建议 ≥ 30'),
	envCheck('memory_limit', false, $cfgMemLimit ?: '128M', $memOk, '脚本内存限制，建议 ≥ 64M'),
];

// 目录权限检测
$permChecks = [
	['path' => '../config.php', 'label' => 'config.php（配置文件）', 'type' => 'file', 'required' => true],
	['path' => './',            'label' => 'install/（安装目录）', 'type' => 'dir',  'required' => true],
	['path' => '../api/upload/', 'label' => 'api/upload/（上传缓存）', 'type' => 'dir',  'required' => false],
];
$permAllOk = true;
$permResults = [];
foreach ($permChecks as $pc) {
	if ($pc['type'] === 'dir') {
		$exists = is_dir($pc['path']);
		$writable = $exists && is_writable($pc['path']);
	} else {
		$exists = file_exists($pc['path']);
		$writable = $exists ? is_writable($pc['path']) : is_writable(dirname($pc['path']));
	}
	$pc['ok'] = $writable;
	if ($pc['required'] && !$writable) $permAllOk = false;
	$permResults[] = $pc;
}

// 汇总：是否有必须项不通过
$envAllOk = $phpVerOk && $permAllOk;
foreach($extChecks as $c){ if($c['required'] && !$c['ok']) $envAllOk = false; }
foreach($funcChecks as $c){ if($c['required'] && !$c['ok']) $envAllOk = false; }
?>
		<div class="card-header-custom">
			<i class="mdi mdi-clipboard-check-outline"></i>
			<h3>环境检测</h3>
		</div>
		<div class="card-body-custom">

			<!-- PHP 版本 -->
			<div class="env-section-title"><i class="mdi mdi-language-php"></i> PHP 版本</div>
			<table class="env-table">
				<thead><tr><th style="width:30%">检测项</th><th style="width:15%">需求</th><th style="width:15%">当前</th><th style="width:40%">说明</th></tr></thead>
				<tbody>
					<tr>
							<td>PHP 版本</td>
							<td><span class="env-fail">≥ 8.5 必须</span></td>
							<td class="<?php echo $phpVerOk?'env-ok':'env-fail'; ?>"><?php echo $phpVer; ?></td>
							<td><?php echo $phpVerOk ? '版本符合规范要求' : 'PHP 版本过低，请升级到 8.5+'; ?></td>
						</tr>
				</tbody>
			</table>

			<div style="height:16px"></div>

			<!-- PHP 扩展 -->
			<div class="env-section-title"><i class="mdi mdi-package-variant-closed"></i> PHP 扩展检测</div>
			<table class="env-table">
				<thead><tr><th style="width:30%">扩展</th><th style="width:15%">需求</th><th style="width:15%">状态</th><th style="width:40%">用途</th></tr></thead>
				<tbody>
					<?php foreach($extChecks as $c): ?>
					<tr>
						<td><?php echo $c['label']; ?></td>
						<td><?php echo $c['required']?'<span class="env-fail">必须</span>':'<span class="env-warn">建议</span>'; ?></td>
						<td class="<?php echo $c['cls']; ?>"><?php echo $c['current']; ?></td>
						<td><?php echo $c['desc']; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="height:16px"></div>

			<!-- PHP 函数 -->
			<div class="env-section-title"><i class="mdi mdi-function"></i> PHP 函数检测</div>
			<table class="env-table">
				<thead><tr><th style="width:30%">函数</th><th style="width:15%">需求</th><th style="width:15%">状态</th><th style="width:40%">用途</th></tr></thead>
				<tbody>
					<?php foreach($funcChecks as $c): ?>
					<tr>
						<td><code><?php echo $c['label']; ?></code></td>
						<td><?php echo $c['required']?'<span class="env-fail">必须</span>':'<span class="env-warn">建议</span>'; ?></td>
						<td class="<?php echo $c['cls']; ?>"><?php echo $c['current']; ?></td>
						<td><?php echo $c['desc']; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="height:16px"></div>

			<!-- PHP 配置项 -->
			<div class="env-section-title"><i class="mdi mdi-tune"></i> PHP 配置项</div>
			<table class="env-table">
				<thead><tr><th style="width:30%">配置项</th><th style="width:15%">需求</th><th style="width:15%">当前值</th><th style="width:40%">说明</th></tr></thead>
				<tbody>
					<?php foreach($cfgChecks as $c): ?>
					<tr>
						<td><code><?php echo $c['label']; ?></code></td>
						<td><span class="env-warn">建议</span></td>
						<td class="<?php echo $c['cls']; ?>"><?php echo $c['current']; ?></td>
						<td><?php echo $c['desc']; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="height:16px"></div>

			<!-- 目录权限 -->
			<div class="env-section-title"><i class="mdi mdi-folder-lock-outline"></i> 目录权限检测</div>
			<table class="env-table">
				<thead><tr><th style="width:35%">路径</th><th style="width:15%">需求</th><th style="width:15%">状态</th><th style="width:35%">说明</th></tr></thead>
				<tbody>
					<?php foreach($permResults as $pc): ?>
					<tr>
						<td><?php echo htmlspecialchars($pc['label']); ?></td>
						<td><?php echo $pc['required']?'<span class="env-fail">必须</span>':'<span class="env-warn">建议</span>'; ?></td>
						<td class="<?php echo $pc['ok']?'env-ok':'env-fail'; ?>"><?php echo $pc['ok']?'可写':'不可写'; ?></td>
						<td><?php echo $pc['ok']?'权限正常':($pc['required']?'请 chmod 755 赋予写权限':'不影响安装，上传功能不可用'); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if(!$envAllOk): ?>
			<div class="alert-install alert-install-danger" style="margin-top:16px;">
				<i class="mdi mdi-alert-circle-outline"></i>
				<div>
					<strong>部分必须项未通过，无法继续安装！</strong><br>
					请解决上述标记为「必须」的红色项后刷新此页面。<br>
					<code style="font-size:12px;">chmod 755 . && chmod 755 ../ && chmod -R 777 ../api/upload/</code>
				</div>
			</div>
			<?php endif; ?>

			<div class="nav-btns">
				<a class="btn-install btn-install-secondary" href="index.php?do=0"><i class="mdi mdi-arrow-left"></i> 上一步</a>
				<?php if($envAllOk): ?>
				<a class="btn-install btn-install-success" href="index.php?do=2">下一步 <i class="mdi mdi-arrow-right"></i></a>
				<?php else: ?>
				<button class="btn-install btn-install-secondary" disabled>环境不达标</button>
				<?php endif; ?>
			</div>
		</div>

<?php } elseif($do=='2'){ ?>
		<!-- 步骤3: 数据库配置 -->
		<div class="card-header-custom">
			<i class="mdi mdi-database-plus-outline"></i>
			<h3>数据库配置</h3>
		</div>
		<div class="card-body-custom">
			<?php if(defined("SAE_ACCESSKEY")): ?>
			<div class="alert-install alert-install-info">
				<i class="mdi mdi-information-outline"></i>
				<div>检测到您使用的是 SAE 空间，支持一键安装。</div>
			</div>
			<a class="btn-install btn-install-primary" href="?do=3">下一步 <i class="mdi mdi-arrow-right"></i></a>
			<?php else: ?>
			<div class="alert-install alert-install-info">
				<i class="mdi mdi-database-outline"></i>
				<div>请填写数据库连接信息。如果已手动配置好 <code>config.php</code>，可<a href="?do=3&jump=1" style="color:var(--brand);font-weight:600;">点击此处跳过</a>。</div>
			</div>
			<form action="?do=3" method="post">
			<input type="hidden" name="csrf_token" value="<?php echo install_csrf_token();?>">
			<div class="row">
					<div class="col-md-8">
						<div class="form-group-install">
							<label>数据库地址</label>
							<input type="text" class="form-control" name="db_host" value="localhost" placeholder="通常为 localhost 或 127.0.0.1">
						</div>
					</div>
					<div class="col-md-4">
						<div class="form-group-install">
							<label>端口</label>
							<input type="text" class="form-control" name="db_port" value="3306" placeholder="3306">
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group-install">
							<label>数据库用户名</label>
							<input type="text" class="form-control" name="db_user" placeholder="数据库用户名">
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group-install">
							<label>数据库密码</label>
							<input type="password" class="form-control" name="db_pwd" placeholder="数据库密码">
						</div>
					</div>
				</div>
				<div class="form-group-install">
					<label>数据库名</label>
					<input type="text" class="form-control" name="db_name" placeholder="数据库名称">
					<div class="form-hint">请确保数据库已创建，且用户有访问权限</div>
				</div>
				<div class="nav-btns">
					<a class="btn-install btn-install-secondary" href="index.php?do=1"><i class="mdi mdi-arrow-left"></i> 上一步</a>
					<button type="submit" class="btn-install btn-install-primary">保存配置 <i class="mdi mdi-content-save"></i></button>
				</div>
			</form>
			<?php endif; ?>
		</div>

<?php } elseif($do=='3'){ ?>
		<!-- 步骤3: 保存数据库 -->
		<div class="card-header-custom">
			<i class="mdi mdi-database-check-outline"></i>
			<h3>数据库连接</h3>
		</div>
		<div class="card-body-custom">
<?php
require './db.class.php';
$jump = isset($_GET['jump']) ? $_GET['jump'] : '';
if(defined("SAE_ACCESSKEY") || $jump=='1'){
	if(defined("SAE_ACCESSKEY"))include_once '../inc/sae.php';
	else include_once '../config.php';
	if(empty($dbconfig['user'])||!isset($dbconfig['pwd'])||empty($dbconfig['dbname'])) {
		echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div>请先填写好数据库并保存后再安装！</div></div>';
		echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回</a>';
	} else {
		$con=DB::connect($dbconfig['host'],$dbconfig['user'],$dbconfig['pwd'],$dbconfig['dbname'],$dbconfig['port']);
		if(!$con){
			$errNo = DB::connect_errno();
			$errMsg = DB::connect_error();
			$tip = '';
			if($errNo==2002) $tip='数据库地址填写错误';
			elseif($errNo==1045) $tip='数据库用户名或密码填写错误';
			elseif($errNo==1049) $tip='数据库名不存在';
			else $tip='['.$errNo.']'.$errMsg;
			echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-close-circle"></i><div><strong>连接数据库失败</strong><br>'.$tip.'</div></div>';
			echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回修改</a>';
		}else{
			mysqli_query($con, "set names utf8mb4");
			echo '<div class="alert-install alert-install-success"><i class="mdi mdi-check-circle"></i><div>数据库连接成功！配置文件已保存。</div></div>';
			$check_result = DB::get_row("SHOW TABLES LIKE 'eecms_config'");
			if(empty($check_result)){
				echo '<div class="nav-btns"><span></span><a class="btn-install btn-install-primary" href="?do=4">创建数据表 <i class="mdi mdi-arrow-right"></i></a></div>';
			} else {
				echo '<div class="alert-install alert-install-warning"><i class="mdi mdi-alert"></i><div>系统检测到数据库中已有数据表。</div></div>';
				echo '<div style="display:flex;gap:10px;justify-content:flex-end;">';
				echo '<a href="?do=6" class="btn-install btn-install-success"><i class="mdi mdi-skip-next"></i> 跳过安装</a>';
				echo '<a href="javascript:void(0)" onclick="confirmFreshInstall()" class="btn-install btn-install-secondary"><i class="mdi mdi-refresh"></i> 强制全新安装</a>';
				echo '</div>';
			}
		}
	}
} else {
	if(!install_csrf_verify()) {
		echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div>安全校验失败，请刷新页面后重试！</div></div>';
		echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回</a>';
	} else {
	$db_host=isset($_POST['db_host'])?$_POST['db_host']:null;
	$db_port=isset($_POST['db_port'])?$_POST['db_port']:null;
	$db_user=isset($_POST['db_user'])?$_POST['db_user']:null;
	$db_pwd=isset($_POST['db_pwd'])?$_POST['db_pwd']:'';
	$db_name=isset($_POST['db_name'])?$_POST['db_name']:null;

	if($db_host===null || $db_port===null || $db_user===null || $db_name===null || $db_host==='' || $db_port==='' || $db_user==='' || $db_name===''){
		echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div>保存错误，请确保每项都不为空</div></div>';
		echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回修改</a>';
	} else {
		$db_port = (int)$db_port;
		// M5 修复：原用 addslashes() 拼接单引号字符串，但 addslashes 会把 " 转义为 \"、NUL 转义为 \0，
		//          而单引号字符串中 \" 与 \0 并非合法转义序列，读回时凭据会被破坏（多出反斜杠）。
		//          改用 var_export() 生成合法 PHP 字面量，确保含任意特殊字符的数据库凭据都能正确回读
		$config="<?php
/*数据库配置*/
\$dbconfig=array(
	'host' => " . var_export($db_host, true) . ",
	'port' => " . var_export($db_port, true) . ",
	'user' => " . var_export($db_user, true) . ",
	'pwd' => " . var_export($db_pwd, true) . ",
	'dbname' => " . var_export($db_name, true) . "
);
?>";
		$con=DB::connect($db_host,$db_user,$db_pwd,$db_name,$db_port);
		if(!$con){
			$errNo = DB::connect_errno();
			$tip = '';
			if($errNo==2002) $tip='数据库地址填写错误';
			elseif($errNo==1045) $tip='数据库用户名或密码填写错误';
			elseif($errNo==1049) $tip='数据库名不存在';
			else $tip='['.$errNo.']'.DB::connect_error();
			echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-close-circle"></i><div><strong>连接数据库失败</strong><br>'.$tip.'</div></div>';
			echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回修改</a>';
		} elseif(@file_put_contents('../config.php',$config)){
			mysqli_query($con, "set names utf8mb4");
			echo '<div class="alert-install alert-install-success"><i class="mdi mdi-check-circle"></i><div>数据库连接成功！配置文件已保存到 config.php。</div></div>';
			$check_result = DB::get_row("SHOW TABLES LIKE 'eecms_config'");
			if(empty($check_result))
				echo '<div class="nav-btns"><span></span><a class="btn-install btn-install-primary" href="?do=4">创建数据表 <i class="mdi mdi-arrow-right"></i></a></div>';
			else {
				echo '<div class="alert-install alert-install-warning"><i class="mdi mdi-alert"></i><div>系统检测到数据库中已有数据表。</div></div>';
				echo '<div style="display:flex;gap:10px;justify-content:flex-end;">';
				echo '<a href="?do=6" class="btn-install btn-install-success"><i class="mdi mdi-skip-next"></i> 跳过安装</a>';
				echo '<a href="javascript:void(0)" onclick="confirmFreshInstall()" class="btn-install btn-install-secondary"><i class="mdi mdi-refresh"></i> 强制全新安装</a>';
				echo '</div>';
			}
		} else {
			echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div>配置文件写入失败，请赋予网站根目录写入权限（chmod 755）后重试。</div></div>';
			echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回修改</a>';
		}
	}
	}
}
?>
		</div>

<?php } elseif($do=='4'){ ?>
		<!-- 步骤4: 创建数据表 -->
		<div class="card-header-custom">
			<i class="mdi mdi-database-edit-outline"></i>
			<h3>创建数据表</h3>
		</div>
		<div class="card-body-custom">
<?php
if(defined("SAE_ACCESSKEY"))include_once '../inc/sae.php';
else include_once '../config.php';
if(empty($dbconfig['user'])||!isset($dbconfig['pwd'])||empty($dbconfig['dbname'])) {
	echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div>请先填写好数据库并保存后再安装！</div></div>';
	echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回</a>';
} else {
	require './db.class.php';
	$sql=file_get_contents("install.sql");
	$sql=explode(';',$sql);
	$cn = DB::connect($dbconfig['host'],$dbconfig['user'],$dbconfig['pwd'],$dbconfig['dbname'],$dbconfig['port']);
	if (!$cn){
		echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-close-circle"></i><div>数据库连接失败，请检查 config.php 配置是否正确</div></div>';
		echo '<a class="btn-install btn-install-secondary" href="index.php?do=2"><i class="mdi mdi-arrow-left"></i> 返回</a>';
	} else {
		mysqli_query($cn, "set sql_mode = ''");
		mysqli_query($cn, "set names utf8mb4");
		$t=0; $e=0; $error='';
		for($i=0;$i<count($sql);$i++) {
			$sql[$i]=trim($sql[$i]);
			if ($sql[$i]=='')continue;
			if(DB::query($sql[$i])) {
				++$t;
			} else {
				++$e;
				$error .= htmlspecialchars(DB::error(), ENT_QUOTES, 'UTF-8') . '<br/>';
			}
		}
		if($e==0) {
			echo '<div class="alert-install alert-install-success"><i class="mdi mdi-check-circle"></i><div><strong>数据表创建成功！</strong><br>SQL 执行：成功 '.$t.' 句 / 失败 '.$e.' 句</div></div>';
			echo '<div class="nav-btns"><span></span><a class="btn-install btn-install-success" href="index.php?do=5">完成安装 <i class="mdi mdi-arrow-right"></i></a></div>';
		} else {
			error_log('Install SQL error: ' . $error);
			echo '<div class="alert-install alert-install-danger"><i class="mdi mdi-close-circle"></i><div><strong>安装过程中出现错误</strong><br>SQL 执行：成功 '.$t.' 句 / 失败 '.$e.' 句<br>错误详情已记录，请联系管理员或检查数据库配置</div></div>';
			echo '<div class="nav-btns"><span></span><a class="btn-install btn-install-primary" href="index.php?do=4"><i class="mdi mdi-refresh"></i> 重试</a></div>';
		}
	}
}
?>
		</div>

<?php } elseif($do=='5'){
// ========== 步骤5: 管理员账户设置（H1+H2：废除默认 admin/123456，用户设置强密码，password_hash 存储）==========
$adminErr = '';
$showComplete = false;
$completedUser = 'admin';
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!install_csrf_verify()) {
        $adminErr = '安全校验失败，请刷新页面后重试';
    } else {
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminPwd = $_POST['admin_pwd'] ?? '';
        $adminPwdConfirm = $_POST['admin_pwd_confirm'] ?? '';
        $adminEmail = trim($_POST['admin_email'] ?? '');
        // 校验用户名
        if($adminUser === '' || mb_strlen($adminUser, 'UTF-8') > 64) {
            $adminErr = '管理员用户名不能为空且不能超过 64 个字符';
        }
        // 校验密码强度（≥8 位，含大小写+数字+特殊字符，规范 § 8.4.3）
        elseif(mb_strlen($adminPwd, 'UTF-8') < 8
            || !preg_match('/[A-Z]/', $adminPwd)
            || !preg_match('/[a-z]/', $adminPwd)
            || !preg_match('/[0-9]/', $adminPwd)
            || !preg_match('/[^A-Za-z0-9]/', $adminPwd)) {
            $adminErr = '密码至少 8 位，且必须包含大写字母、小写字母、数字和特殊字符';
        }
        elseif($adminPwd !== $adminPwdConfirm) {
            $adminErr = '两次输入的密码不一致';
        }
        else {
            require_once './db.class.php';
            include_once '../config.php';
            $cn = DB::connect($dbconfig['host'],$dbconfig['user'],$dbconfig['pwd'],$dbconfig['dbname'],$dbconfig['port']);
            if(!$cn) {
                $adminErr = '数据库连接失败，请返回上一步检查配置';
            } else {
                mysqli_query($cn, "set names utf8mb4");
                // password_hash 存储（规范 § 8.2.3），使用预处理防止 SQL 注入
                $hash = password_hash($adminPwd, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($cn, "UPDATE eecms_config SET main=? WHERE name='admin_user'");
                mysqli_stmt_bind_param($stmt, 's', $adminUser);
                $ok1 = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $stmt2 = mysqli_prepare($cn, "UPDATE eecms_config SET main=? WHERE name='admin_pwd'");
                mysqli_stmt_bind_param($stmt2, 's', $hash);
                $ok2 = mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                if($adminEmail !== '') {
                    $stmt3 = mysqli_prepare($cn, "UPDATE eecms_config SET main=? WHERE name='email'");
                    mysqli_stmt_bind_param($stmt3, 's', $adminEmail);
                    mysqli_stmt_execute($stmt3);
                    mysqli_stmt_close($stmt3);
                }
                if($ok1 && $ok2) {
                    @file_put_contents("install.lock",'安装锁');
                    $showComplete = true;
                    $completedUser = $adminUser;
                } else {
                    $adminErr = '管理员账户保存失败，请重试';
                }
            }
        }
    }
}

if($showComplete) {
    // 显示安装完成页（不显示密码明文，符合规范 § 8.4.3）
?>
		<!-- 步骤6: 安装完成 -->
		<div class="card-body-custom" style="text-align:center; padding:48px 28px;">
			<div class="complete-icon"><i class="mdi mdi-check"></i></div>
			<h2 style="font-size:22px; font-weight:700; color:var(--text); margin:0 0 8px;">安装完成！</h2>
			<p style="font-size:14px; color:var(--text-muted); margin:0 0 24px;">系统已成功安装，可以开始使用了</p>
			<div class="alert-install alert-install-success" style="text-align:left;">
				<i class="mdi mdi-account-check"></i>
				<div>
					<strong>管理员账号：</strong><?php echo htmlspecialchars($completedUser, ENT_QUOTES, 'UTF-8'); ?><br>
					<span style="color:var(--ok);font-size:12px;margin-top:4px;display:block;"><i class="mdi mdi-check-circle"></i> 密码已使用 bcrypt 安全哈希存储，请用刚才设置的密码登录</span>
				</div>
			</div>
			<div class="alert-install alert-install-warning" style="text-align:left;">
				<i class="mdi mdi-shield-alert"></i>
				<div>为安全起见，请删除 <code>install/</code> 目录或确认 <code>install.lock</code> 文件已创建。</div>
			</div>
			<div style="display:flex; gap:12px; justify-content:center; margin-top:24px;">
				<a href="../" class="btn-install btn-install-secondary"><i class="mdi mdi-home"></i> 网站首页</a>
				<a href="../admin/" class="btn-install btn-install-primary"><i class="mdi mdi-cog"></i> 后台管理</a>
			</div>
		</div>
<?php } else { ?>
		<!-- 步骤5: 管理员账户设置 -->
		<div class="card-header-custom">
			<i class="mdi mdi-account-plus-outline"></i>
			<h3>管理员账户设置</h3>
		</div>
		<div class="card-body-custom">
		<?php if($adminErr): ?>
			<div class="alert-install alert-install-danger"><i class="mdi mdi-alert-circle"></i><div><?php echo htmlspecialchars($adminErr, ENT_QUOTES, 'UTF-8'); ?></div></div>
		<?php else: ?>
			<div class="alert-install alert-install-info"><i class="mdi mdi-shield-account-outline"></i><div>请设置后台管理员账户和密码。密码将使用 bcrypt 哈希安全存储（规范 § 8.2.3），不再使用默认弱密码。</div></div>
		<?php endif; ?>
			<form action="?do=5" method="post">
			<input type="hidden" name="csrf_token" value="<?php echo install_csrf_token();?>">
				<div class="form-group-install">
					<label>管理员用户名</label>
					<input type="text" class="form-control" name="admin_user" value="admin" maxlength="64" required autocomplete="username">
					<div class="form-hint">后台登录用户名，建议修改为非默认值</div>
				</div>
				<div class="row">
					<div class="col-md-6">
						<div class="form-group-install">
							<label>登录密码</label>
							<input type="password" class="form-control" name="admin_pwd" required autocomplete="new-password">
							<div class="form-hint">至少 8 位，含大小写字母+数字+特殊字符</div>
						</div>
					</div>
					<div class="col-md-6">
						<div class="form-group-install">
							<label>确认密码</label>
							<input type="password" class="form-control" name="admin_pwd_confirm" required autocomplete="new-password">
						</div>
					</div>
				</div>
				<div class="form-group-install">
					<label>管理员邮箱（可选）</label>
					<input type="email" class="form-control" name="admin_email" placeholder="用于密码找回">
				</div>
				<div class="nav-btns">
					<a class="btn-install btn-install-secondary" href="index.php?do=4"><i class="mdi mdi-arrow-left"></i> 上一步</a>
					<button type="submit" class="btn-install btn-install-primary">完成安装 <i class="mdi mdi-check"></i></button>
				</div>
			</form>
		</div>
<?php } ?>

<?php } elseif($do=='6'){ ?>
		<!-- 步骤5: 跳过安装完成 -->
		<div class="card-body-custom" style="text-align:center; padding:48px 28px;">
			<div class="complete-icon" style="background:linear-gradient(135deg,#6d4aff,#9d5cff);box-shadow:0 8px 32px rgba(109,74,255,.3);"><i class="mdi mdi-skip-forward"></i></div>
			<h2 style="font-size:22px; font-weight:700; color:var(--text); margin:0 0 8px;">跳过安装完成</h2>
			<p style="font-size:14px; color:var(--text-muted); margin:0 0 24px;">数据库数据保持不变</p>
<?php
	@file_put_contents("install.lock",'安装锁');
?>
			<div class="alert-install alert-install-success" style="text-align:left;">
				<i class="mdi mdi-check-circle"></i>
				<div>已跳过安装，数据库数据保持不变。如需重新安装，请删除 <code>install/install.lock</code> 文件后再访问此页面。</div>
			</div>
			<div style="display:flex; gap:12px; justify-content:center; margin-top:24px;">
				<a href="../" class="btn-install btn-install-secondary"><i class="mdi mdi-home"></i> 网站首页</a>
				<a href="../admin/" class="btn-install btn-install-primary"><i class="mdi mdi-cog"></i> 后台管理</a>
			</div>
		</div>

<?php } ?>

	</div><!-- /.install-card -->

	<div style="text-align:center; margin-top:20px; color:rgba(255,255,255,.6); font-size:12px;">
		图床系统 V1.0 &middot; 基于 PHP + MySQL
	</div>

</div><!-- /.install-wrapper -->

<!-- H3 修复：用 SweetAlert2 替代浏览器原生 confirm()，符合规范 § 3.2 / § 4.2 -->
<script src="../admin/style/js/sweetalert2.min.js"></script>
<script>
function confirmFreshInstall(){
    Swal.fire({
        title: '确认全新安装',
        text: '全新安装将会清空所有数据，此操作不可恢复！',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '确认全新安装',
        cancelButtonText: '取消',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed) window.location.href = 'index.php?do=4';
    });
}
</script>

</body>
</html>
