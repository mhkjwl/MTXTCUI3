<?php
/**
 * @file api/stats.php
 * @description 上传统计接口，返回与记录上传成功/失败统计（累计与每日趋势）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 上传统计接口
 * GET  → 返回 {total_success, total_fail, today_success, today_fail, trend:{week,month,year}}
 * POST → 记录上传结果（success/fail 参数），返回更新后的统计
 *
 * 统计数据存储在 eecms_config（累计值）和 eecms_upload_daily（每日累计值）中，
 * 不依赖 eecms_images 表，因此用户删除图片记录不会影响统计数据。
 */
require ('../inc/common.php');
header('Content-Type: application/json');

$currentDate = date('Y-m-d');

// 确保每日统计表存在（累计统计，不因图片删除而减少）
// CREATE TABLE IF NOT EXISTS 很轻量，保留以确保旧站点也有该表
$DB->query("CREATE TABLE IF NOT EXISTS eecms_upload_daily (
    stat_date DATE PRIMARY KEY,
    upload_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// M4 修复：一次性历史数据迁移改用配置标志 stats_daily_migrated 守卫，
//          原实现每次请求都执行 COUNT(*) + SHOW TABLES 检查，仪表盘高频轮询时产生无谓开销
if (empty($conf['stats_daily_migrated'])) {
    // 如果 eecms_upload_daily 为空但 eecms_images 有数据，则从 eecms_images 导入历史数据
    $dailyCount = $DB->get_row("SELECT COUNT(*) AS cnt FROM eecms_upload_daily");
    if ($dailyCount && (int)$dailyCount['cnt'] === 0) {
        $imagesTableExists = $DB->get_row("SHOW TABLES LIKE 'eecms_images'");
        if ($imagesTableExists) {
            $rs = $DB->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM eecms_images GROUP BY DATE(created_at)");
            while($rs && ($row = $DB->fetch($rs))) {
                $cnt = (int)$row['c'];
                $DB->query_prepared(
                    "INSERT INTO eecms_upload_daily SET stat_date=?, upload_count=? ON DUPLICATE KEY UPDATE upload_count = upload_count + ?",
                    'sii',
                    [$row['d'], $cnt, $cnt]
                );
            }
        }
    }
    // 无论是否导入数据，都标记迁移已完成，避免后续请求重复检查
    $DB->query_prepared(
        "INSERT INTO eecms_config SET `name`=?,`main`=? ON DUPLICATE KEY UPDATE `main`=?",
        'sss',
        ['stats_daily_migrated', '1', '1']
    );
    $conf['stats_daily_migrated'] = '1';
}

// 从数据库读取当前值
$totalOk    = isset($conf['upload_total_ok'])    ? intval($conf['upload_total_ok'])    : 0;
$totalFail  = isset($conf['upload_total_fail'])  ? intval($conf['upload_total_fail'])  : 0;
$todayDate  = isset($conf['upload_today_date'])  ? $conf['upload_today_date']           : '';
$todayOk    = isset($conf['upload_today_ok'])    ? intval($conf['upload_today_ok'])     : 0;
$todayFail  = isset($conf['upload_today_fail'])  ? intval($conf['upload_today_fail'])   : 0;

// 跨天清零
if ($todayDate !== $currentDate) {
    $todayOk   = 0;
    $todayFail = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 校验：防止第三方站点伪造统计数据
    if(!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['status' => false, 'msg' => '安全校验失败']);
        exit;
    }
    $okCnt   = isset($_POST['success']) ? max(0, intval($_POST['success'])) : 0;
    $failCnt = isset($_POST['fail'])    ? max(0, intval($_POST['fail']))    : 0;

    // 原子更新总计数（避免并发竞态条件）
    // M3 修复：原用 intval() 字符串拼接 SQL，违反 § 8.3.4 预处理语句规范，改为 query_prepared
    $DB->query_prepared(
        "INSERT INTO eecms_config SET `name`='upload_total_ok',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
        'ii',
        [$okCnt, $okCnt]
    );
    $DB->query_prepared(
        "INSERT INTO eecms_config SET `name`='upload_total_fail',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
        'ii',
        [$failCnt, $failCnt]
    );

    // 今日计数：先读取库中记录的日期，跨天则先清零再写入本次计数
    $dateRow = $DB->get_row("SELECT `main` FROM eecms_config WHERE `name`='upload_today_date'");
    $storedDate = ($dateRow && isset($dateRow['main'])) ? $dateRow['main'] : '';
    if ($storedDate !== $currentDate) {
        // 跨天：日期翻页，今日计数从本次计数重新开始
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_date',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
            'ss',
            [$currentDate, $currentDate]
        );
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_ok',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
            'ii',
            [$okCnt, $okCnt]
        );
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_fail',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
            'ii',
            [$failCnt, $failCnt]
        );
    } else {
        // 当天：原子累加
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_ok',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
            'ii',
            [$okCnt, $okCnt]
        );
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_fail',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
            'ii',
            [$failCnt, $failCnt]
        );
    }

    // 更新每日累计统计（不因图片删除而减少）
    $DB->query_prepared(
        "INSERT INTO eecms_upload_daily SET stat_date=?, upload_count=? ON DUPLICATE KEY UPDATE upload_count = upload_count + ?",
        'sii',
        [$currentDate, $okCnt, $okCnt]
    );

    // 读回最新值
    $rs = $DB->query("SELECT * FROM eecms_config WHERE `name` IN ('upload_total_ok','upload_total_fail','upload_today_ok','upload_today_fail')");
    while($rs && ($row = $DB->fetch($rs))) {
        if($row['name'] === 'upload_total_ok')   $totalOk   = intval($row['main']);
        if($row['name'] === 'upload_total_fail') $totalFail = intval($row['main']);
        if($row['name'] === 'upload_today_ok')   $todayOk   = intval($row['main']);
        if($row['name'] === 'upload_today_fail') $todayFail = intval($row['main']);
    }
}

// ========== 趋势数据：从 eecms_upload_daily 表按日期聚合查询 ==========
// 使用独立的每日统计表，而非 eecms_images 表，
// 确保用户删除图片记录后统计数据不会减少。
$trend = ['week' => [], 'month' => [], 'year' => []];

// --- 本周（周一到周日，7天）---
$weekLabels = ['周一','周二','周三','周四','周五','周六','周日'];
$weekData   = [0,0,0,0,0,0,0];
$dayOfWeek = date('N'); // 1=Mon, 7=Sun
$monday = date('Y-m-d', strtotime("-" . ($dayOfWeek - 1) . " days"));
$rs = $DB->fetch_all_prepared("SELECT stat_date, upload_count FROM eecms_upload_daily WHERE stat_date >= ? ORDER BY stat_date", 's', [$monday]);
while($rs && ($row = $DB->fetch($rs))) {
    $ts = strtotime($row['stat_date']);
    $idx = (int)date('N', $ts) - 1; // 0=Mon
    if ($idx >= 0 && $idx <= 6) {
        $weekData[$idx] = (int)$row['upload_count'];
    }
}
$trend['week'] = ['labels' => $weekLabels, 'data' => $weekData];

// --- 本月（按周分组，4周）---
$monthLabels = ['第1周','第2周','第3周','第4周'];
$monthData   = [0,0,0,0];
$monthStart = date('Y-m-01');
$rs = $DB->fetch_all_prepared("SELECT stat_date, upload_count FROM eecms_upload_daily WHERE stat_date >= ? ORDER BY stat_date", 's', [$monthStart]);
while($rs && ($row = $DB->fetch($rs))) {
    $dayOfMonth = (int)date('j', strtotime($row['stat_date']));
    $weekIdx = min(3, (int)floor(($dayOfMonth - 1) / 7));
    $monthData[$weekIdx] += (int)$row['upload_count'];
}
$trend['month'] = ['labels' => $monthLabels, 'data' => $monthData];

// --- 全年（12个月）---
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
$trend['year'] = ['labels' => $yearLabels, 'data' => $yearData];

echo json_encode([
    'status'        => true,
    'total_success' => $totalOk,
    'total_fail'    => $totalFail,
    'today_success' => $todayOk,
    'today_fail'    => $todayFail,
    'trend'         => $trend,
]);
