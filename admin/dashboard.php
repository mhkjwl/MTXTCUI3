<?php
/**
 * @file dashboard.php
 * @description 后台仪表盘页面，展示系统概览统计（图片数、用户数、存储用量、接口状态）
 *              及上传趋势（周/月/年）+ 图床接口分布（环形图）
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-09
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// 统计API接口信息
$apiList = array(
    array('key' => '360',        'name' => '360图床',       'icon' => 'mdi-cloud-upload',        'enable' => 'api_360_enable'),
    array('key' => 'local',      'name' => '本地上传',       'icon' => 'mdi-monitor-screenshot',  'enable' => 'api_local_enable'),
    array('key' => 'chevereto',  'name' => 'PlcGO图床',      'icon' => 'mdi-cloud-outline',       'enable' => 'api_chevereto_enable'),
    array('key' => 'zhongzhuan', 'name' => '凌云图床',       'icon' => 'mdi-server',              'enable' => 'api_zhongzhuan_enable'),
    array('key' => 'yiyunt',     'name' => '怡云图床',       'icon' => 'mdi-weather-cloudy',      'enable' => 'api_yiyunt_enable'),
    array('key' => 'cfbed',      'name' => '自建图床',       'icon' => 'mdi-cloud-braces',         'enable' => 'api_cfbed_enable'),
    array('key' => 'scdn',       'name' => 'SCDN图床',       'icon' => 'mdi-flash-outline',       'enable' => 'api_scdn_enable'),
    array('key' => 'imgbb',      'name' => 'ImgBB图床',      'icon' => 'mdi-image-area',          'enable' => 'api_imgbb_enable'),
    array('key' => 'imgurla',    'name' => 'Imgur.LA图床',   'icon' => 'mdi-link-variant',        'enable' => 'api_imgurla_enable'),
    array('key' => 'helloimg',   'name' => 'Hello图床',       'icon' => 'mdi-hand-wave-outline',   'enable' => 'api_helloimg_enable'),
    array('key' => 'stardots',   'name' => 'StarDots图床',   'icon' => 'mdi-star-circle-outline', 'enable' => 'api_stardots_enable'),
    array('key' => 'remit',      'name' => 'Remit图床',      'icon' => 'mdi-image-outline',        'enable' => 'api_remit_enable'),
    array('key' => 'beeimg',     'name' => '蜜蜂图床',       'icon' => 'mdi-bee',                 'enable' => 'api_beeimg_enable'),
    array('key' => 'meituan',    'name' => '美团创作图床',   'icon' => 'mdi-food-outline',        'enable' => 'api_meituan_enable'),
    array('key' => 'alibaba',    'name' => '阿里巴巴图床',   'icon' => 'mdi-shopping-outline',     'enable' => 'api_alibaba_enable'),
    array('key' => 'suning',     'name' => '苏宁易购图床',   'icon' => 'mdi-store-outline',       'enable' => 'api_suning_enable'),
    array('key' => 'meipai',     'name' => '美拍网图床',     'icon' => 'mdi-camera-outline',      'enable' => 'api_meipai_enable'),
    array('key' => 'alipay',     'name' => '支付宝图床',     'icon' => 'mdi-credit-card-outline',  'enable' => 'api_alipay_enable'),
    array('key' => 'phototourl', 'name' => 'PHOTO图床',     'icon' => 'mdi-camera-outline',       'enable' => 'api_phototourl_enable'),
    array('key' => 'imgloc',     'name' => 'IMGLOC图床',    'icon' => 'mdi-image-outline',        'enable' => 'api_imgloc_enable'),
    array('key' => 'locimg',     'name' => 'LOC图床',       'icon' => 'mdi-map-marker-outline',   'enable' => 'api_locimg_enable'),
    array('key' => 'jisu',       'name' => '极速图床',      'icon' => 'mdi-speedometer',          'enable' => 'api_jisu_enable'),
    array('key' => 'yopngs',     'name' => '有图床',        'icon' => 'mdi-image-outline',        'enable' => 'api_yopngs_enable'),
    array('key' => 'feria',      'name' => '风筝图床',      'icon' => 'mdi-kite-outline',         'enable' => 'api_feria_enable'),
    array('key' => 'gurl',       'name' => 'Telegraph图床', 'icon' => 'mdi-telegraph',            'enable' => 'api_gurl_enable'),
    array('key' => 'ljpic',      'name' => '云间图床',      'icon' => 'mdi-weather-cloudy',       'enable' => 'api_ljpic_enable'),
    array('key' => 'nickyam',    'name' => 'Telegraph2图床','icon' => 'mdi-telegraph',            'enable' => 'api_nickyam_enable'),
    array('key' => 'dogimg',     'name' => '狗狗图床',      'icon' => 'mdi-dog',                  'enable' => 'api_dogimg_enable'),
    array('key' => 'matu',       'name' => '宝马图床',      'icon' => 'mdi-car-sports',           'enable' => 'api_matu_enable'),
    array('key' => 'pnglog',     'name' => '盘络图床',      'icon' => 'mdi-image-outline',        'enable' => 'api_pnglog_enable'),
    array('key' => 'lvse',       'name' => '绿色图床',      'icon' => 'mdi-leaf',                 'enable' => 'api_lvse_enable'),
    array('key' => 'fatcat',     'name' => '肥喵图床',      'icon' => 'mdi-cat',                  'enable' => 'api_fatcat_enable'),
    array('key' => '131img',     'name' => '131图床',       'icon' => 'mdi-image-multiple-outline','enable' => 'api_131img_enable'),
    array('key' => 'feimg',      'name' => 'FE图床',        'icon' => 'mdi-cloud-upload-outline', 'enable' => 'api_feimg_enable'),
    array('key' => 'yootn',      'name' => '友藤图床',      'icon' => 'mdi-vector-triangle',      'enable' => 'api_yootn_enable'),
    array('key' => 'czl',        'name' => 'CZL图床',       'icon' => 'mdi-image-outline',        'enable' => 'api_czl_enable'),
    array('key' => 'tutu',       'name' => 'TUTU图床',      'icon' => 'mdi-image-multiple-outline','enable' => 'api_tutu_enable'),
    array('key' => 'uuimg',      'name' => '悠悠图床',      'icon' => 'mdi-cloud-upload-outline', 'enable' => 'api_uuimg_enable'),
    array('key' => 'tuwu',       'name' => '图屋图床',      'icon' => 'mdi-home-outline',         'enable' => 'api_tuwu_enable'),
    array('key' => 'urusai',     'name' => 'UR图床',        'icon' => 'mdi-image-filter-drama',   'enable' => 'api_urusai_enable'),
    array('key' => 'imgcc',      'name' => '云图床',        'icon' => 'mdi-cloud-check-outline',  'enable' => 'api_imgcc_enable'),
    array('key' => 'imgdata',    'name' => 'ImgURL图床',    'icon' => 'mdi-database-outline',     'enable' => 'api_imgdata_enable'),
    array('key' => 'pngcdn',     'name' => '云朵图床',      'icon' => 'mdi-cloud-outline',        'enable' => 'api_pngcdn_enable'),
    array('key' => 'naixiai',    'name' => '奶昔图床',      'icon' => 'mdi-cup-outline',          'enable' => 'api_naixiai_enable'),
    array('key' => 'youzan',     'name' => '有赞图床',      'icon' => 'mdi-storefront-outline',   'enable' => 'api_youzan_enable'),
    array('key' => 'wentian',    'name' => 'WENTIAN图床',   'icon' => 'mdi-cloud-outline',        'enable' => 'api_wentian_enable'),
    // L1 修复：补齐 6 个新接入接口，原 $apiList 与 admin/api.php 不同步导致仪表盘统计遗漏
    array('key' => 'imgw',       'name' => '图网图床',       'icon' => 'mdi-cloud-upload-outline', 'enable' => 'api_imgw_enable'),
    array('key' => 'xwyue',      'name' => '星跃图床',       'icon' => 'mdi-cloud-upload',         'enable' => 'api_xwyue_enable'),
    array('key' => 'keye',       'name' => '珂艺云图床',     'icon' => 'mdi-cloud-sync',           'enable' => 'api_keye_enable'),
    array('key' => 'shaitu',     'name' => '晒图床',         'icon' => 'mdi-image-multiple',       'enable' => 'api_shaitu_enable'),
    array('key' => 'guaigua',    'name' => '乖乖图床',       'icon' => 'mdi-emoticon-happy',       'enable' => 'api_guaigua_enable'),
    array('key' => 'imgtolink',  'name' => 'LINK图床',       'icon' => 'mdi-link-variant',         'enable' => 'api_imgtolink_enable'),
);

// 构造 key => name 映射（用于图床接口分布显示名）
$apiNameMap = [];
foreach ($apiList as $a) { $apiNameMap[$a['key']] = $a['name']; }

$totalCount = count($apiList);
$enabledCount = 0;
$disabledCount = 0;

foreach ($apiList as &$api) {
    $isEnabled = empty($api['enable']) ? true : (isset($conf[$api['enable']]) && $conf[$api['enable']] == '1');
    $api['enabled'] = $isEnabled;
    if ($isEnabled) {
        $enabledCount++;
    } else {
        $disabledCount++;
    }
}
unset($api);

$defaultApi = isset($conf['api_default']) ? $conf['api_default'] : '360';
$defaultName = '未知';
foreach ($apiList as $api) {
    if ($api['key'] === $defaultApi) {
        $defaultName = $api['name'];
        break;
    }
}

// 系统概览统计：图片数量、用户数量、启用用户、存储用量
$totalImages  = function_exists('pkg_safe_count') ? pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_images") : (int)$DB->count("SELECT COUNT(*) FROM eecms_images");
$totalUsers   = function_exists('pkg_safe_count') ? pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users") : (int)$DB->count("SELECT COUNT(*) FROM eecms_users");
$activeUsers  = function_exists('pkg_safe_count') ? pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_users WHERE status=1") : (int)$DB->count("SELECT COUNT(*) FROM eecms_users WHERE status=1");
$totalSizeRaw = function_exists('pkg_safe_count') ? pkg_safe_count($DB, "SELECT COALESCE(SUM(size),0) FROM eecms_images") : (int)$DB->count("SELECT COALESCE(SUM(size),0) FROM eecms_images");

// 存储用量格式化（人类可读）
$_tb = (int)$totalSizeRaw;
if ($_tb >= 1073741824)      { $totalSize = number_format($_tb / 1073741824, 2) . ' GB'; }
elseif ($_tb >= 1048576)     { $totalSize = number_format($_tb / 1048576, 2) . ' MB'; }
elseif ($_tb >= 1024)        { $totalSize = number_format($_tb / 1024, 2) . ' KB'; }
else                         { $totalSize = $_tb . ' B'; }

// ========== 上传趋势数据：从 eecms_upload_daily 表聚合（与 api/stats.php 一致） ==========
// 确保每日统计表存在
$DB->query("CREATE TABLE IF NOT EXISTS eecms_upload_daily (
    stat_date DATE PRIMARY KEY,
    upload_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 本周（周一到周日，7天）
$weekLabels = ['周一','周二','周三','周四','周五','周六','周日'];
$weekData   = [0,0,0,0,0,0,0];
$dayOfWeek = (int)date('N'); // 1=Mon, 7=Sun
$monday = date('Y-m-d', strtotime("-" . ($dayOfWeek - 1) . " days"));
$rs = $DB->fetch_all_prepared("SELECT stat_date, upload_count FROM eecms_upload_daily WHERE stat_date >= ? ORDER BY stat_date", 's', [$monday]);
while($rs && ($row = $DB->fetch($rs))) {
    $idx = (int)date('N', strtotime($row['stat_date'])) - 1; // 0=Mon
    if ($idx >= 0 && $idx <= 6) {
        $weekData[$idx] = (int)$row['upload_count'];
    }
}

// 本月（按周分组，4周）
$monthLabels = ['第1周','第2周','第3周','第4周'];
$monthData   = [0,0,0,0];
$monthStart = date('Y-m-01');
$rs = $DB->fetch_all_prepared("SELECT stat_date, upload_count FROM eecms_upload_daily WHERE stat_date >= ? ORDER BY stat_date", 's', [$monthStart]);
while($rs && ($row = $DB->fetch($rs))) {
    $dayOfMonth = (int)date('j', strtotime($row['stat_date']));
    $weekIdx = min(3, (int)floor(($dayOfMonth - 1) / 7));
    $monthData[$weekIdx] += (int)$row['upload_count'];
}

// 全年（12个月）
$yearLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
$yearData   = [0,0,0,0,0,0,0,0,0,0,0,0];
$yearStart = date('Y-01-01');
$rs = $DB->fetch_all_prepared("SELECT stat_date, upload_count FROM eecms_upload_daily WHERE stat_date >= ? ORDER BY stat_date", 's', [$yearStart]);
while($rs && ($row = $DB->fetch($rs))) {
    $monthIdx = (int)date('n', strtotime($row['stat_date'])) - 1;
    if ($monthIdx >= 0 && $monthIdx <= 11) {
        $yearData[$monthIdx] += (int)$row['upload_count'];
    }
}

$trendData = [
    'week'  => ['labels' => $weekLabels,  'data' => $weekData],
    'month' => ['labels' => $monthLabels, 'data' => $monthData],
    'year'  => ['labels' => $yearLabels,  'data' => $yearData],
];

// ========== 图床接口分布：从 eecms_images.api_type 聚合 ==========
// 取 Top 8 接口，其余合并为"其他"
$apiDistRows = $DB->fetch_all_prepared(
    "SELECT api_type, COUNT(*) AS cnt FROM eecms_images WHERE api_type <> '' GROUP BY api_type ORDER BY cnt DESC",
    '', []
);
$apiDist = [];
$apiDistTotal = 0;
if ($apiDistRows) {
    while ($row = $DB->fetch($apiDistRows)) {
        $key = $row['api_type'];
        $cnt = (int)$row['cnt'];
        $apiDistTotal += $cnt;
        $apiDist[] = [
            'key'  => $key,
            'name' => isset($apiNameMap[$key]) ? $apiNameMap[$key] : $key,
            'cnt'  => $cnt,
        ];
    }
}
// 合并 Top 8 之外为"其他"
$apiDistTop = array_slice($apiDist, 0, 8);
$apiDistOtherCnt = 0;
for ($i = 8; $i < count($apiDist); $i++) {
    $apiDistOtherCnt += $apiDist[$i]['cnt'];
}
if ($apiDistOtherCnt > 0) {
    $apiDistTop[] = ['key' => '_other', 'name' => '其他', 'cnt' => $apiDistOtherCnt];
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>后台首页 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>
html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}

/* ===== 两区块等高底部齐平 ===== */
.trend-card,.dist-card{height:100%;display:flex;flex-direction:column}
.dist-card .dist-body{flex:1;justify-content:center}

/* ===== 上传趋势图（参考 UI3 chart-area） ===== */
.trend-card .card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 26px 0}
.trend-card .card-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;color:var(--text)}
.trend-card .card-title .icon{color:var(--primary)}
.trend-segment{display:inline-flex;background:var(--color-surface);border:1px solid var(--color-border);border-radius:10px;padding:3px}
.trend-segment button{border:none;background:none;padding:5px 14px;border-radius:7px;font-size:12px;font-weight:600;color:var(--color-text-muted);cursor:pointer;transition:all .2s}
.trend-segment button.active{background:var(--color-surface-hover);color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.trend-body{padding:8px 26px 22px}
.trend-legend{display:flex;gap:18px;font-size:12px;color:var(--color-text-muted);margin-bottom:6px}
.trend-legend span{display:inline-flex;align-items:center;gap:6px}
.trend-dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex:none}
.trend-dot-violet{background:#7c5cff;box-shadow:0 0 8px rgba(124,92,255,.5)}
.trend-chart{width:100%;height:240px;display:block}
.trend-grid line{stroke:var(--color-border);stroke-width:1;stroke-dasharray:3 5}
.trend-axis{display:flex;justify-content:space-between;font-size:10.5px;color:var(--color-text-muted);margin-top:8px}
.trend-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:18px;padding-top:16px;border-top:1px dashed var(--color-border)}
.trend-summary div{display:flex;flex-direction:column;gap:2px}
.trend-summary strong{font-size:20px;font-weight:700;color:var(--text);background:var(--gradient-blue);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.trend-summary span{font-size:11px;color:var(--color-text-muted)}
@media(max-width:768px){.trend-summary{grid-template-columns:repeat(2,1fr)}}

/* ===== 图床接口分布环形图（参考 UI3 donut） ===== */
.dist-card .card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 26px 0}
.dist-card .card-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;color:var(--text)}
.dist-card .card-title .icon{color:var(--primary)}
.dist-body{padding:18px 26px 22px;display:flex;flex-direction:column;align-items:center;gap:18px}
.dist-donut{width:160px;height:160px}
.dist-num{font-size:22px;font-weight:700;fill:var(--text)}
.dist-lbl{font-size:10px;fill:var(--color-text-muted)}
.dist-list{width:100%;list-style:none;margin:0;padding:0}
.dist-list li{display:flex;align-items:center;gap:9px;padding:6px 0;font-size:12.5px}
.dist-list li .dist-dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex:none}
.dist-list li .dist-name{flex:1;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dist-list li .dist-cnt{font-weight:600;color:var(--text)}
.dist-list li .dist-pct{font-size:11px;color:var(--color-text-muted);margin-left:6px}
.dist-empty{text-align:center;padding:30px 10px;color:var(--color-text-muted);font-size:13px}
</style>
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('view-dashboard-outline'); ?> 仪表盘
  </div>

  <!-- 系统概览统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon purple"><?php echo icon('image-multiple-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">图片总数</div>
          <div class="stat-value"><?php echo $totalImages; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon orange"><?php echo icon('account-group-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">用户总数</div>
          <div class="stat-value"><?php echo $totalUsers; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('account-check-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">启用用户</div>
          <div class="stat-value"><?php echo $activeUsers; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon cyan"><?php echo icon('database-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">存储用量</div>
          <div class="stat-value" style="font-size:22px;"><?php echo $totalSize; ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 接口统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon blue"><?php echo icon('api'); ?></div>
        <div class="stat-content">
          <div class="stat-label">接口总数</div>
          <div class="stat-value"><?php echo $totalCount; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('check-circle-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">已启用</div>
          <div class="stat-value" id="statEnabled"><?php echo $enabledCount; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon red"><?php echo icon('close-circle-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">已关闭</div>
          <div class="stat-value" id="statDisabled"><?php echo $disabledCount; ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon cyan"><?php echo icon('star-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">默认图床</div>
          <div class="stat-value" style="font-size:18px;"><?php echo $defaultName; ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 上传趋势 + 图床接口分布 -->
  <div class="row g-3">
    <!-- 上传趋势 -->
    <div class="col-lg-7">
      <div class="card trend-card">
        <div class="card-head">
          <div class="card-title"><?php echo icon('history'); ?> 上传趋势</div>
          <div class="trend-segment" id="trendSegment">
            <button type="button" class="active" data-range="week">本周</button>
            <button type="button" data-range="month">本月</button>
            <button type="button" data-range="year">全年</button>
          </div>
        </div>
        <div class="trend-body">
          <div class="trend-legend">
            <span><i class="trend-dot trend-dot-violet"></i>上传量</span>
          </div>
          <svg class="trend-chart" id="trendChart" viewBox="0 0 720 240" preserveAspectRatio="none">
            <defs>
              <linearGradient id="trendGrad" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" stop-color="#7c5cff" stop-opacity=".35"/>
                <stop offset="1" stop-color="#7c5cff" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <g class="trend-grid">
              <line x1="0" x2="720" y1="60" y2="60"/>
              <line x1="0" x2="720" y1="120" y2="120"/>
              <line x1="0" x2="720" y1="180" y2="180"/>
            </g>
            <path id="trendArea" fill="url(#trendGrad)" d=""/>
            <path id="trendLine" fill="none" stroke="#7c5cff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d=""/>
            <g id="trendPoints"></g>
          </svg>
          <div class="trend-axis" id="trendAxis"></div>
          <div class="trend-summary">
            <div><strong id="trendTotal">0</strong><span>合计上传</span></div>
            <div><strong id="trendPeak">0</strong><span>峰值</span></div>
            <div><strong id="trendAvg">0</strong><span>日均</span></div>
            <div><strong id="trendMin">0</strong><span>最低</span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- 图床接口分布 -->
    <div class="col-lg-5">
      <div class="card dist-card">
        <div class="card-head">
          <div class="card-title"><?php echo icon('image-multiple-outline'); ?> 图床接口分布</div>
        </div>
        <div class="dist-body" id="distBody">
          <?php if (empty($apiDistTop) || $apiDistTotal === 0): ?>
            <div class="dist-empty"><?php echo icon('image-off-outline'); ?> 暂无上传记录</div>
          <?php else: ?>
            <svg class="dist-donut" viewBox="0 0 120 120" id="distDonut">
              <circle cx="60" cy="60" r="46" style="stroke:var(--color-border)" stroke-width="14" fill="none"/>
              <?php
              // 环形图分段：周长 = 2πr ≈ 289
              $circumference = 2 * M_PI * 46;
              $offset = 0;
              $distColors = ['#7c5cff','#22c55e','#f59e0b','#ef4477','#0ea5e9','#a855f7','#14b8a6','#f43f5e','#94a3b8'];
              foreach ($apiDistTop as $idx => $d):
                  $pct = $apiDistTotal > 0 ? ($d['cnt'] / $apiDistTotal) : 0;
                  $len = $circumference * $pct;
                  $gap = $circumference - $len;
                  $color = $distColors[$idx % count($distColors)];
              ?>
              <circle cx="60" cy="60" r="46" stroke="<?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>" stroke-width="14" fill="none"
                      stroke-dasharray="<?php echo htmlspecialchars(number_format($len, 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(number_format($gap, 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                      stroke-dashoffset="<?php echo htmlspecialchars(number_format(-$offset, 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                      transform="rotate(-90 60 60)" stroke-linecap="butt"/>
              <?php
                  $offset += $len;
              endforeach;
              $topPct = $apiDistTotal > 0 ? round($apiDistTop[0]['cnt'] / $apiDistTotal * 100) : 0;
              ?>
              <text x="60" y="58" text-anchor="middle" class="dist-num"><?php echo (int)$topPct; ?>%</text>
              <text x="60" y="76" text-anchor="middle" class="dist-lbl"><?php echo htmlspecialchars($apiDistTop[0]['name'], ENT_QUOTES, 'UTF-8'); ?></text>
            </svg>
            <ul class="dist-list">
              <?php foreach ($apiDistTop as $idx => $d):
                  $pct = $apiDistTotal > 0 ? round($d['cnt'] / $apiDistTotal * 100, 1) : 0;
                  $color = $distColors[$idx % count($distColors)];
              ?>
              <li>
                <i class="dist-dot" style="background:<?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>"></i>
                <span class="dist-name"><?php echo htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="dist-cnt"><?php echo (int)$d['cnt']; ?></span>
                <span class="dist-pct"><?php echo htmlspecialchars((string)$pct, ENT_QUOTES, 'UTF-8'); ?>%</span>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="style/js/jquery.min.js"></script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
// ===== 上传趋势图（参考 UI3 chart-area SVG 实现） =====
var TREND_DATA = <?php echo json_encode($trendData, JSON_UNESCAPED_UNICODE); ?>;
var currentRange = 'week';

function renderTrendChart() {
  var item = TREND_DATA[currentRange];
  var data = item.data;
  var labels = item.labels;
  var n = data.length;
  if (n === 0) return;

  var max = Math.max.apply(null, data);
  if (max === 0) max = 1;
  var min = Math.min.apply(null, data);
  var total = data.reduce(function(a, b) { return a + b; }, 0);
  var avg = n > 0 ? Math.round(total / n) : 0;

  // 汇总数字
  document.getElementById('trendTotal').textContent = total;
  document.getElementById('trendPeak').textContent = max;
  document.getElementById('trendAvg').textContent = avg;
  document.getElementById('trendMin').textContent = min;

  // SVG 坐标：viewBox 720x240，留 padding
  var W = 720, H = 240, padTop = 20, padBottom = 20, padX = 30;
  var plotH = H - padTop - padBottom;
  var stepX = (W - padX * 2) / Math.max(1, n - 1);

  // 生成折线路径（平滑曲线用三次贝塞尔）
  var pts = [];
  for (var i = 0; i < n; i++) {
    var x = padX + i * stepX;
    var y = padTop + plotH - (data[i] / max) * plotH;
    if (data[i] === 0) y = padTop + plotH; // 0 值贴底
    pts.push([x, y]);
  }
  // 折线路径
  var linePath = 'M ' + pts[0][0] + ' ' + pts[0][1];
  for (var i = 1; i < pts.length; i++) {
    var prev = pts[i - 1], cur = pts[i];
    var cpx = (prev[0] + cur[0]) / 2;
    linePath += ' C ' + cpx + ' ' + prev[1] + ', ' + cpx + ' ' + cur[1] + ', ' + cur[0] + ' ' + cur[1];
  }
  // 区域填充路径（折线 + 底边闭合）
  var areaPath = linePath + ' L ' + pts[n - 1][0] + ' ' + (H - padBottom) + ' L ' + pts[0][0] + ' ' + (H - padBottom) + ' Z';

  document.getElementById('trendLine').setAttribute('d', linePath);
  document.getElementById('trendArea').setAttribute('d', areaPath);

  // 数据点
  var pointsG = document.getElementById('trendPoints');
  pointsG.innerHTML = '';
  for (var i = 0; i < pts.length; i++) {
    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('cx', pts[i][0]);
    c.setAttribute('cy', pts[i][1]);
    c.setAttribute('r', 3.5);
    c.setAttribute('fill', '#fff');
    c.setAttribute('stroke', '#7c5cff');
    c.setAttribute('stroke-width', 2);
    pointsG.appendChild(c);
  }

  // X 轴标签
  var axis = document.getElementById('trendAxis');
  axis.innerHTML = '';
  for (var i = 0; i < labels.length; i++) {
    var span = document.createElement('span');
    span.textContent = labels[i];
    axis.appendChild(span);
  }
}

// 趋势图切换（局部更新，无刷新）
document.getElementById('trendSegment').addEventListener('click', function(e) {
  var btn = e.target.closest('button');
  if (!btn) return;
  var range = btn.dataset.range;
  if (!range || range === currentRange) return;
  document.querySelectorAll('#trendSegment button').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  currentRange = range;
  renderTrendChart();
});

// 初始化渲染
renderTrendChart();
</script>
</body>
</html>
