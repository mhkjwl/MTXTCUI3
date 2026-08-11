<?php
/**
 * @file redeem.php
 * @description 兑换码管理页面，支持批量生成/查看/删除/导出套餐兑换码，含按状态和批次号筛选及详情查看功能
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// 预加载所有套餐（用于生成兑换码下拉，与列表无关）
$packages = get_all_packages($DB);

function json_exit($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 根据筛选条件构造 WHERE 子句（带表别名 r）
 */
function redeem_build_where($DB, $filter, $batchNo) {
    $where = ' WHERE 1=1';
    $types = '';
    $params = [];
    if($filter === 'used') {
        $where .= " AND r.used_user_id > 0";
    } elseif($filter === 'unused') {
        $where .= " AND r.used_user_id = 0 AND (r.expires_at IS NULL OR r.expires_at >= NOW())";
    } elseif($filter === 'expired') {
        $where .= " AND r.used_user_id = 0 AND r.expires_at IS NOT NULL AND r.expires_at < NOW()";
    }
    if($batchNo !== '') {
        $where .= " AND r.batch_no=?";
        $types .= 's';
        $params[] = $batchNo;
    }
    return ['where' => $where, 'types' => $types, 'params' => $params];
}

// ========== AJAX 接口 ==========
if(isset($_POST['action'])) {
    $action = $_POST['action'];

    // export 单独处理：返回纯文本（每行一个码），前端用 Blob 下载
    if($action === 'export') {
        if(!csrf_verify()) {
            header('Content-Type: text/plain; charset=utf-8');
            echo '安全校验失败，请刷新页面后重试';
            exit;
        }
        $filter  = $_POST['filter'] ?? 'all';
        $batchNo = trim($_POST['batch_no'] ?? '');
        // H7 修复：导出 filter 改为白名单校验，仅允许 unused/expired
        //         原黑名单只拦截 used/all，任意其他值会落入 redeem_build_where 的兜底分支
        //         返回全部记录（含已使用兑换码明文），导致 H17 防护被绕过
        if(!in_array($filter, ['unused', 'expired'], true)) {
            $filter = 'unused';
        }
        $w       = redeem_build_where($DB, $filter, $batchNo);

        $codeRows = [];
        $rs = $DB->fetch_all_prepared("SELECT r.code FROM eecms_redeem_codes r" . $w['where'] . " ORDER BY r.id DESC", $w['types'], $w['params']);
        if($rs) {
            while(($row = $DB->fetch($rs))) {
                $codeRows[] = $row;
            }
        }
        $codes = [];
        foreach($codeRows as $row) {
            $codes[] = $row['code'];
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $codes);
        exit;
    }

    // 其余操作统一 JSON 输出 + CSRF 校验
    if(!csrf_verify()) {
        json_exit(1, '安全校验失败，请刷新页面后重试！');
    }

    // ---- list: 分页返回兑换码列表 ----
    if($action === 'list') {
        $page    = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(500, intval($_POST['perPage'] ?? 20)));
        $filter  = $_POST['filter'] ?? 'all';
        $batchNo = trim($_POST['batch_no'] ?? '');

        if(!in_array($filter, ['all', 'unused', 'used', 'expired'])) $filter = 'all';

        $w = redeem_build_where($DB, $filter, $batchNo);

        $total      = $DB->count_prepared("SELECT COUNT(*) FROM eecms_redeem_codes r" . $w['where'], $w['types'], $w['params']);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if($page > $totalPages) $page = $totalPages;
        $start = ($page - 1) * $perPage;

        $codeRows = [];
        // H9 修复：LIMIT 子句改为预处理占位符，禁止 SQL 字符串拼接
        $listTypes  = $w['types'] . 'ii';
        $listParams = array_merge($w['params'], [$start, $perPage]);
        $rs = $DB->fetch_all_prepared("SELECT r.*, p.name AS package_name FROM eecms_redeem_codes r LEFT JOIN eecms_packages p ON r.target_package_id=p.id" . $w['where'] . " ORDER BY r.id DESC LIMIT ?, ?", $listTypes, $listParams);
        if($rs) {
            while(($row = $DB->fetch($rs))) {
                $codeRows[] = $row;
            }
        }
        $now   = date('Y-m-d H:i:s');
        $codes = [];
        foreach($codeRows as $row) {
            $status = 'unused';
            if((int)$row['used_user_id'] > 0) {
                $status = 'used';
            } elseif($row['expires_at'] !== null && $row['expires_at'] < $now) {
                $status = 'expired';
            }
            $codes[] = [
                'id'                => (int)$row['id'],
                'code'              => $row['code'],
                'target_package_id' => (int)$row['target_package_id'],
                'package_name'      => $row['package_name'] !== null ? $row['package_name'] : '(套餐已删除)',
                'custom_days'       => $row['custom_days'] !== null ? (int)$row['custom_days'] : null,
                'used_user_id'      => (int)$row['used_user_id'],
                'used_at'           => $row['used_at'],
                'expires_at'        => $row['expires_at'],
                'created_at'        => $row['created_at'],
                'batch_no'          => $row['batch_no'],
                'status'            => $status,
            ];
        }

        json_exit(0, 'ok', [
            'codes'      => $codes,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'perPage'    => $perPage,
        ]);
    }

    // ---- generate: 批量生成兑换码 ----
    if($action === 'generate') {
        $targetPackageId = intval($_POST['target_package_id'] ?? 0);
        $count           = intval($_POST['count'] ?? 0);
        $customDays      = (isset($_POST['custom_days']) && $_POST['custom_days'] !== '') ? intval($_POST['custom_days']) : null;
        $expiresAt       = (isset($_POST['expires_at']) && $_POST['expires_at'] !== '') ? trim($_POST['expires_at']) : null;

        if($targetPackageId <= 0) json_exit(1, '请选择目标套餐');
        // M13 修复：校验目标套餐存在且启用（status=1），禁止对已删除套餐生成兑换码
        $pkg = pkg_safe_get_row_prepared($DB, "SELECT id, name FROM eecms_packages WHERE id=? AND status=1", 'i', [$targetPackageId]);
        if(!$pkg) json_exit(1, '目标套餐不存在或已删除，请刷新后重试');
        if($count < 1 || $count > 500) json_exit(1, '生成数量必须在 1-500 之间');
        if($customDays !== null && $customDays <= 0) json_exit(1, '自定义天数必须大于 0');
        if($expiresAt !== null) {
            // datetime-local 格式 YYYY-MM-DDTHH:MM -> MySQL DATETIME
            $expiresAt = str_replace('T', ' ', $expiresAt);
            $ts = strtotime($expiresAt);
            if($ts === false || $ts < time()) {
                json_exit(1, '兑换码过期时间必须是未来的时间');
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $result = generate_redeem_codes($DB, $targetPackageId, $count, $customDays, $expiresAt);
        if(!$result['ok']) json_exit(1, $result['msg']);

        $batchNo = $result['batch_no'];
        log_admin_action($DB, 'redeem_generate', 'package', $targetPackageId, ['count' => $count, 'batch_no' => $batchNo]);

        json_exit(0, $result['msg'], ['codes' => $result['codes'], 'batch_no' => $batchNo]);
    }

    // ---- delete: 删除单个兑换码（仅未使用） ----
    if($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $row = pkg_safe_get_row_prepared($DB, "SELECT id, code, used_user_id FROM eecms_redeem_codes WHERE id=?", 'i', [$id]);
        if(!$row) json_exit(1, '兑换码不存在');
        if((int)$row['used_user_id'] > 0) json_exit(1, '已使用的兑换码不能删除');

        $ok = pkg_safe_query_prepared($DB, "DELETE FROM eecms_redeem_codes WHERE id=?", 'i', [$id]);
        if($ok === false || $ok <= 0) json_exit(1, '删除失败，请重试');

        log_admin_action($DB, 'redeem_delete', 'redeem_code', $id, ['code' => $row['code']]);
        json_exit(0, '兑换码已删除');
    }

    // ---- delete_selected: 批量删除选中的兑换码（仅未使用） ----
    if($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        if(!is_array($ids)) json_exit(1, '参数错误');

        $intIds = [];
        foreach($ids as $id) {
            $id = intval($id);
            if($id > 0) $intIds[] = $id;
        }
        // 去重
        $intIds = array_values(array_unique($intIds));
        if(count($intIds) === 0) json_exit(1, '请选择要删除的兑换码');
        if(count($intIds) > 500) json_exit(1, '单次最多删除 500 条');

        // 仅删除未使用的（已使用的保留以备查）
        $rows = pkg_safe_get_all_prepared($DB, "SELECT id, code FROM eecms_redeem_codes WHERE id IN (" . implode(',', array_fill(0, count($intIds), '?')) . ") AND used_user_id = 0", str_repeat('i', count($intIds)), $intIds);
        $deletedIds = [];
        $codes = [];
        foreach($rows as $row) {
            $deletedIds[] = (int)$row['id'];
            $codes[] = $row['code'];
        }
        if(count($deletedIds) === 0) {
            json_exit(1, '所选兑换码均已被使用，无法删除');
        }
        // H9 修复：DELETE 加 used_user_id=0 条件，防止窗口期内被兑换的码被删除
        $ok = pkg_safe_query_prepared($DB, "DELETE FROM eecms_redeem_codes WHERE id IN (" . implode(',', array_fill(0, count($deletedIds), '?')) . ") AND used_user_id = 0", str_repeat('i', count($deletedIds)), $deletedIds);
        $affected = ($ok === false) ? 0 : (int)$ok;

        // L13 修复：日志仅记录数量和码前缀摘要，不记录完整码列表
        $codeSummary = array_map(function($c){ return substr($c, 0, 4) . '****'; }, $codes);
        log_admin_action($DB, 'redeem_delete_selected', 'redeem_code', 0, ['count' => $affected, 'code_prefixes' => $codeSummary]);
        $skipped = count($intIds) - $affected;
        $msg = "已删除 {$affected} 个未使用的兑换码";
        if($skipped > 0) $msg .= "（跳过 {$skipped} 个已使用/已过期的）";
        json_exit(0, $msg, ['count' => $affected, 'skipped' => $skipped]);
    }

    // ---- delete_batch: 按批次号删除未使用的兑换码 ----
    if($action === 'delete_batch') {
        $batchNo = trim($_POST['batch_no'] ?? '');
        if($batchNo === '') json_exit(1, '批次号不能为空');

        // 仅删除未使用的（已使用的保留以备查）
        // M8 修复：query_prepared 返回 bool，改用 pkg_safe_query_prepared 获取真实行数
        $ok = pkg_safe_query_prepared($DB, "DELETE FROM eecms_redeem_codes WHERE batch_no=? AND used_user_id = 0", 's', [$batchNo]);
        $affected = ($ok === false) ? 0 : (int)$ok;

        log_admin_action($DB, 'redeem_delete_batch', 'redeem_code', 0, ['batch_no' => $batchNo, 'count' => $affected]);
        json_exit(0, "已删除 {$affected} 个未使用的兑换码", ['count' => $affected]);
    }

    // ---- stats: 统计信息 ----
    if($action === 'stats') {
        $total   = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_redeem_codes");
        $used    = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_redeem_codes WHERE used_user_id > 0");
        $unused  = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_redeem_codes WHERE used_user_id = 0 AND (expires_at IS NULL OR expires_at >= NOW())");
        $expired = pkg_safe_count($DB, "SELECT COUNT(*) FROM eecms_redeem_codes WHERE used_user_id = 0 AND expires_at IS NOT NULL AND expires_at < NOW()");

        json_exit(0, 'ok', [
            'total'   => $total,
            'used'    => $used,
            'unused'  => $unused,
            'expired' => $expired,
        ]);
    }

    // ---- detail: 查询单个兑换码详情（含目标套餐、绑定分组、使用者信息） ----
    if($action === 'detail') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $row = pkg_safe_get_row_prepared($DB, "SELECT * FROM eecms_redeem_codes WHERE id=?", 'i', [$id]);
        if(!$row) json_exit(1, '兑换码不存在');

        // 目标套餐信息
        $pkg = pkg_safe_get_row_prepared($DB, "SELECT name, level, storage_limit, days, group_id FROM eecms_packages WHERE id=?", 'i', [(int)$row['target_package_id']]);

        // 绑定接口分组
        $groupName = '';
        $groupApiCount = 0;
        if($pkg && (int)$pkg['group_id'] > 0) {
            $grp = pkg_safe_get_row_prepared($DB, "SELECT name FROM eecms_api_groups WHERE id=?", 'i', [(int)$pkg['group_id']]);
            $groupName = $grp ? $grp['name'] : '(分组已删除)';
            $groupApiCount = pkg_safe_count_prepared($DB, "SELECT COUNT(*) FROM eecms_api_group_items WHERE group_id=?", 'i', [(int)$pkg['group_id']]);
        }

        // 使用者信息
        $usedUser = null;
        if((int)$row['used_user_id'] > 0) {
            $usedUser = pkg_safe_get_row_prepared($DB, "SELECT id, username, email FROM eecms_users WHERE id=?", 'i', [(int)$row['used_user_id']]);
        }

        // 状态判定
        $now = date('Y-m-d H:i:s');
        $status = 'unused';
        $statusText = '未使用';
        if((int)$row['used_user_id'] > 0) {
            $status = 'used';
            $statusText = '已使用';
        } elseif($row['expires_at'] !== null && $row['expires_at'] < $now) {
            $status = 'expired';
            $statusText = '已过期';
        }

        json_exit(0, 'ok', [
            'id'                  => (int)$row['id'],
            'code'                => $row['code'],
            'status'              => $status,
            'status_text'         => $statusText,
            'target_package_id'   => (int)$row['target_package_id'],
            'package_name'        => $pkg ? $pkg['name'] : '(套餐已删除)',
            'package_level'       => $pkg ? (int)$pkg['level'] : null,
            'package_storage'     => $pkg ? (int)$pkg['storage_limit'] : null,
            'package_days'        => $pkg ? (int)$pkg['days'] : null,
            'package_group'       => $groupName,
            'package_group_count' => $groupApiCount,
            'custom_days'         => $row['custom_days'] !== null ? (int)$row['custom_days'] : null,
            'used_user_id'        => (int)$row['used_user_id'],
            'used_username'       => $usedUser ? $usedUser['username'] : null,
            'used_email'          => $usedUser ? $usedUser['email'] : null,
            'used_at'             => $row['used_at'],
            'expires_at'          => $row['expires_at'],
            'created_at'          => $row['created_at'],
            'batch_no'            => $row['batch_no'],
        ]);
    }

    json_exit(1, '未知操作');
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>兑换码管理 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}
.detail-grid{display:flex;flex-direction:column}
.detail-row{display:flex;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--color-border)}
.detail-row:last-child{border-bottom:none}
.detail-label{width:110px;flex-shrink:0;font-size:13px;color:var(--color-text-muted);font-weight:500}
.detail-value{flex:1;font-size:14px;color:var(--color-text-primary);word-break:break-all}
</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('ticket-percent-outline'); ?> 兑换码管理
  </div>

  <!-- 统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon blue"><?php echo icon('ticket-percent'); ?></div>
        <div class="stat-content">
          <div class="stat-label">兑换码总数</div>
          <div class="stat-value" id="statTotal">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('ticket-confirmation-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">未使用</div>
          <div class="stat-value" id="statUnused">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon orange"><?php echo icon('check-decagram'); ?></div>
        <div class="stat-content">
          <div class="stat-label">已使用</div>
          <div class="stat-value" id="statUsed">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon red"><?php echo icon('clock-alert-outline'); ?></div>
        <div class="stat-content">
          <div class="stat-label">已过期</div>
          <div class="stat-value" id="statExpired">0</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 筛选栏 -->
  <div class="card">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">状态</label>
          <select class="form-select" id="filterSelect">
            <option value="all">全部状态</option>
            <option value="unused">未使用</option>
            <option value="used">已使用</option>
            <option value="expired">已过期</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">批次号</label>
          <input type="text" class="form-control" id="batchInput" placeholder="输入批次号筛选" onkeydown="if(event.key==='Enter')loadCodes(1)">
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-primary w-100" onclick="loadCodes(1)"><?php echo icon('magnify'); ?> 搜索</button>
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilter()"><?php echo icon('refresh'); ?> 重置</button>
        </div>
        <div class="col-md-3 text-end">
          <button type="button" class="btn btn-success" onclick="openGenerate()"><?php echo icon('plus-circle'); ?> 生成兑换码</button>
          <button type="button" class="btn btn-outline-info" onclick="exportCodes()"><?php echo icon('download'); ?> 导出</button>
        </div>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-12">
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteBatch()"><?php echo icon('trash-can-outline'); ?> 按批次删除未使用</button>
          <span class="text-muted" style="font-size:12px;margin-left:8px;">将删除上方"批次号"输入框中对应批次的未使用兑换码</span>
        </div>
      </div>
    </div>
  </div>

  <!-- 兑换码列表 -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?php echo icon('ticket-percent-outline'); ?> 兑换码列表 <span class="text-muted" style="font-size:13px;font-weight:400;" id="listCount"></span></div>
      <div id="batchBar" class="batch-toolbar" style="display:none;">
        <span class="batch-info"><?php echo icon('checkbox-multiple-marked-outline'); ?> 已选择 <strong id="selectedCount">0</strong> 项</span>
        <button type="button" class="btn btn-danger btn-sm" onclick="batchDeleteSelected()"><?php echo icon('delete-sweep'); ?> 批量删除</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()"><?php echo icon('close'); ?> 取消选择</button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th style="width:40px;"><input type="checkbox" id="checkAll" onclick="toggleAll(this)" title="全选当前页"></th>
              <th style="width:60px;">ID</th>
              <th>兑换码</th>
              <th>目标套餐</th>
              <th style="width:100px;">自定义天数</th>
              <th style="width:90px;">状态</th>
              <th style="width:90px;">使用者ID</th>
              <th style="width:150px;">使用时间</th>
              <th style="width:150px;">创建时间</th>
              <th style="width:130px;">批次号</th>
              <th style="width:120px;">操作</th>
            </tr>
          </thead>
          <tbody id="codesTableBody">
            <tr><td colspan="11" class="text-center text-muted py-5"><?php echo icon('loading', 'icon-spin'); ?> 加载中...</td></tr>
          </tbody>
        </table>
      </div>
      <div id="codesPagination" class="d-flex justify-content-center py-3"></div>
    </div>
  </div>

</div>

<!-- 生成兑换码 Modal -->
<div class="modal fade" id="generateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo icon('ticket-plus'); ?> 生成兑换码</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info"><?php echo icon('information-outline'); ?> 批量生成兑换码，生成后可在下方查看、复制或导出。</div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">目标套餐 <span class="text-danger">*</span></label>
            <select class="form-select" id="genPackage">
              <option value="">请选择套餐</option>
              <?php foreach($packages as $pkg): ?>
              <?php $daysText = (int)$pkg['days'] === 0 ? '永久' : $pkg['days'].'天'; ?>
              <option value="<?php echo (int)$pkg['id']; ?>"><?php echo htmlspecialchars('VIP'.$pkg['level'].' · '.$pkg['name'].'（'.$daysText.'）'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">生成数量 <span class="text-danger">*</span></label>
            <input type="number" class="form-control" id="genCount" value="10" min="1" max="500" placeholder="1-500">
          </div>
          <div class="col-md-6">
            <label class="form-label">自定义天数（可选）</label>
            <input type="number" class="form-control" id="genDays" min="1" placeholder="留空则取套餐天数">
          </div>
          <div class="col-md-6">
            <label class="form-label">兑换码过期时间（可选）</label>
            <input type="datetime-local" class="form-control" id="genExpires">
          </div>
        </div>

        <div class="mt-5">
          <button type="button" class="btn btn-primary" id="btnGenerate" onclick="generateCodes()"><?php echo icon('plus-circle'); ?> 立即生成</button>
        </div>

        <!-- 生成结果 -->
        <div id="genResult" style="display:none;" class="mt-3">
          <hr>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div style="font-size:14px;font-weight:600;color:#1e293b;">
              <?php echo icon('check-circle'); ?>
              生成成功：<span id="genBatchLabel" class="text-muted"></span>（共 <span id="genCountLabel" style="color:#3b82f6;font-weight:700;">0</span> 个）
            </div>
            <div>
              <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyGeneratedCodes()"><?php echo icon('content-copy'); ?> 复制全部</button>
              <button type="button" class="btn btn-outline-info btn-sm" onclick="exportGeneratedCodes()"><?php echo icon('download'); ?> 导出本批次</button>
            </div>
          </div>
          <textarea class="form-control" id="genCodesArea" rows="8" readonly style="font-family:monospace;font-size:13px;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<!-- 兑换码详情 Modal -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo icon('information-outline'); ?> 兑换码详情</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div id="detailBody" class="detail-grid"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyDetailCode()"><?php echo icon('content-copy'); ?> 复制兑换码</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script src="style/js/jquery.min.js"></script>
<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
var CSRF_TOKEN = '<?php echo csrf_token();?>';
// M5 修复：CSRF Token 统一通过 Header 传递，不再放 POST Body
$.ajaxSetup({ beforeSend: function(xhr){ xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } });
var generateModal;
var detailModal;
var _detailCode = '';
var _currentPage = 1;
var _lastGeneratedCodes = [];
var _lastGeneratedBatch = '';

window.addEventListener('DOMContentLoaded', function(){
    generateModal = new bootstrap.Modal(document.getElementById('generateModal'));
    detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    loadStats();
    loadCodes(1);

    // M4 修复：事件委托替代内联 onclick/onchange，避免字符串拼接导致 XSS
    $('#codesTableBody').on('change', '.row-check', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) toggleRow(id, this.checked);
    });
    $('#codesTableBody').on('click', '.act-filter-batch', function(){
        var batch = $(this).data('batch') || '';
        filterByBatch(batch);
    });
    $('#codesTableBody').on('click', '.act-view-code', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) viewDetail(id);
    });
    $('#codesTableBody').on('click', '.act-delete-code', function(){
        var id = parseInt($(this).data('id'), 10);
        var code = $(this).data('code') || '';
        if(!isNaN(id)) deleteCode(id, code);
    });
    $('#codesPagination').on('click', '.page-link', function(e){
        e.preventDefault();
        var li = $(this).closest('.page-item');
        if(li.hasClass('disabled')) return;
        var page = parseInt($(this).data('page'), 10);
        if(!isNaN(page) && page > 0) loadCodes(page);
    });
});

function escHtml(str){
    if(str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function toast(type, msg, timer){
    if(type === 'success'){
        Swal.fire({title:msg, icon:'success', timer:1200, showConfirmButton:false});
    } else if(type === 'error'){
        Swal.fire('错误', msg, 'error');
    } else if(type === 'warning'){
        Swal.fire('提示', msg, 'warning');
    } else {
        Swal.fire('提示', msg, 'info');
    }
}

// ============ 统计 ============
function loadStats(){
    $.ajax({
        url:'redeem.php', type:'POST', dataType:'json',
        data:{ action:'stats' },
        success:function(res){
            if(res.code === 0){
                $('#statTotal').text(res.data.total);
                $('#statUsed').text(res.data.used);
                $('#statUnused').text(res.data.unused);
                $('#statExpired').text(res.data.expired);
            }
        },
        error:function(){ toast('error', '加载统计数据失败，请刷新重试'); }
    });
}

// ============ 列表 ============
function loadCodes(page){
    _currentPage = page;
    $.ajax({
        url:'redeem.php', type:'POST', dataType:'json',
        data:{
            action:'list',
            page:page,
            perPage:20,
            filter:$('#filterSelect').val(),
            batch_no:$('#batchInput').val().trim()
        },
        success:function(res){
            if(res.code === 0){
                renderTable(res.data);
                renderPagination(res.data);
                $('#listCount').text('（共 ' + res.data.total + ' 条）');
            } else {
                toast('error', res.msg);
            }
        },
        error:function(){ toast('error', '网络错误，请重试'); }
    });
}

function renderTable(data){
    var tbody = $('#codesTableBody');
    tbody.empty();
    if(!data.codes || data.codes.length === 0){
        tbody.append('<tr><td colspan="11" class="text-center text-muted py-5"><span style="font-size:48px;display:block;margin-bottom:8px;">'+eeIcon('ticket-outline')+'</span>暂无兑换码数据</td></tr>');
        syncCheckAll();
        return;
    }
    $.each(data.codes, function(i, c){
        var statusBadge = '';
        if(c.status === 'used'){
            statusBadge = '<span class="badge-status" style="background:#fff7ed;color:#c2410c;">'+eeIcon('check-decagram')+' 已使用</span>';
        } else if(c.status === 'expired'){
            statusBadge = '<span class="badge-status" style="background:#fef2f2;color:#b91c1c;">'+eeIcon('clock-alert-outline')+' 已过期</span>';
        } else {
            statusBadge = '<span class="badge-status badge-status-on">'+eeIcon('ticket-confirmation-outline')+' 未使用</span>';
        }

        var daysCell = c.custom_days !== null ? c.custom_days + ' 天' : '<span class="text-muted">跟随套餐</span>';
        var usedUser = c.used_user_id > 0 ? c.used_user_id : '<span class="text-muted">-</span>';
        var usedAt = c.used_at ? escHtml(c.used_at) : '<span class="text-muted">-</span>';
        var expiresAt = c.expires_at ? '<div style="font-size:12px;color:#94a3b8;">过期：'+escHtml(c.expires_at)+'</div>' : '';
        var batchCell = c.batch_no
            ? '<a href="javascript:void(0)" title="点击按此批次筛选" class="act-filter-batch" data-batch="'+escHtml(c.batch_no)+'">'+escHtml(c.batch_no)+'</a>'
            : '<span class="text-muted">-</span>';

        var checkDisabled = (c.status !== 'unused');
        var checkedAttr = _selectedIds.has(c.id) ? ' checked' : '';
        var checkCell = checkDisabled
            ? '<input type="checkbox" class="row-check" disabled title="已使用/已过期不可选">'
            : '<input type="checkbox" class="row-check" value="'+c.id+'"'+checkedAttr+' data-id="'+c.id+'">';

        var actions = '<div class="btn-group btn-group-sm">';
        actions += '<button type="button" class="btn btn-outline-info act-view-code" data-id="'+c.id+'" title="查看详情">'+eeIcon('eye-outline')+'</button>';
        if(c.status === 'unused'){
            actions += '<button type="button" class="btn btn-outline-danger act-delete-code" data-id="'+c.id+'" data-code="'+escHtml(c.code)+'" title="删除">'+eeIcon('delete')+'</button>';
        } else {
            actions += '<button type="button" class="btn btn-outline-secondary" disabled title="已使用/已过期不可删除">'+eeIcon('delete-off')+'</button>';
        }
        actions += '</div>';

        var row = '<tr>' +
            '<td>'+checkCell+'</td>' +
            '<td>'+c.id+'</td>' +
            '<td><code style="font-size:13px;color:#4338ca;">'+escHtml(c.code)+'</code>'+expiresAt+'</td>' +
            '<td>'+escHtml(c.package_name)+'</td>' +
            '<td>'+daysCell+'</td>' +
            '<td>'+statusBadge+'</td>' +
            '<td>'+usedUser+'</td>' +
            '<td class="text-muted" style="font-size:12px;">'+usedAt+'</td>' +
            '<td class="text-muted" style="font-size:12px;">'+escHtml(c.created_at)+'</td>' +
            '<td style="font-size:12px;">'+batchCell+'</td>' +
            '<td>'+actions+'</td>' +
            '</tr>';
        tbody.append(row);
    });
    syncCheckAll();
}

// ============ 批量选择 ============
var _selectedIds = new Set();

function toggleRow(id, checked){
    if(checked){
        _selectedIds.add(id);
    } else {
        _selectedIds.delete(id);
    }
    updateBatchBar();
}

function toggleAll(master){
    var checked = master.checked;
    $('#codesTableBody .row-check').not(':disabled').each(function(){
        var id = parseInt($(this).val(), 10);
        if(isNaN(id)) return;
        if(checked){
            _selectedIds.add(id);
            this.checked = true;
        } else {
            _selectedIds.delete(id);
            this.checked = false;
        }
    });
    updateBatchBar();
}

function syncCheckAll(){
    var visibleChecks = $('#codesTableBody .row-check').not(':disabled');
    if(visibleChecks.length === 0){
        $('#checkAll').prop('indeterminate', false).prop('checked', false);
        updateBatchBar();
        return;
    }
    var checkedCount = 0;
    visibleChecks.each(function(){
        if(_selectedIds.has(parseInt($(this).val(), 10))) checkedCount++;
    });
    $('#checkAll').prop('indeterminate', checkedCount > 0 && checkedCount < visibleChecks.length);
    $('#checkAll').prop('checked', checkedCount === visibleChecks.length);
    updateBatchBar();
}

function updateBatchBar(){
    var count = _selectedIds.size;
    $('#selectedCount').text(count);
    $('#batchBar').toggle(count > 0);
}

function clearSelection(){
    _selectedIds.clear();
    $('#codesTableBody .row-check').prop('checked', false);
    $('#checkAll').prop('checked', false).prop('indeterminate', false);
    updateBatchBar();
}

function batchDeleteSelected(){
    if(_selectedIds.size === 0){ toast('warning', '请先选择要删除的兑换码'); return; }
    var ids = Array.from(_selectedIds);
    Swal.fire({
        title:'确认批量删除',
        html:'确定要删除选中的 <strong style="color:#ef4444;">'+ids.length+'</strong> 个兑换码吗？<br><span class="text-muted" style="font-size:13px;">仅未使用的兑换码会被删除，已使用的将保留。</span><br><span class="text-danger" style="font-size:13px;">该操作不可恢复！</span>',
        icon:'warning',
        showCancelButton:true,
        confirmButtonText:'确认删除',
        cancelButtonText:'取消',
        confirmButtonColor:'#ef4444',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'redeem.php', type:'POST', dataType:'json',
                data:{ action:'delete_selected', ids:ids },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        clearSelection();
                        loadStats();
                        loadCodes(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

function renderPagination(data){
    var box = $('#codesPagination');
    box.empty();
    if(data.totalPages <= 1) return;
    var nav = $('<nav><ul class="pagination mb-0"></ul></nav>');
    var ul = nav.find('ul');
    // M4 修复：分页 onclick 改用 data-page + 事件委托
    ul.append('<li class="page-item '+(data.page<=1?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+(data.page-1)+'">上一页</a></li>');
    var start = Math.max(1, data.page - 2);
    var end = Math.min(data.totalPages, data.page + 2);
    if(start > 1){
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="1">1</a></li>');
        if(start > 2) ul.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
    }
    for(var p = start; p <= end; p++){
        ul.append('<li class="page-item '+(p===data.page?'active':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+p+'">'+p+'</a></li>');
    }
    if(end < data.totalPages){
        if(end < data.totalPages - 1) ul.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
        ul.append('<li class="page-item"><a class="page-link" href="javascript:void(0)" data-page="'+data.totalPages+'">'+data.totalPages+'</a></li>');
    }
    ul.append('<li class="page-item '+(data.page>=data.totalPages?'disabled':'')+'"><a class="page-link" href="javascript:void(0)" data-page="'+(data.page+1)+'">下一页</a></li>');
    box.append(nav);
}

function resetFilter(){
    $('#filterSelect').val('all');
    $('#batchInput').val('');
    loadCodes(1);
}

function filterByBatch(batchNo){
    $('#batchInput').val(batchNo);
    $('#filterSelect').val('all');
    loadCodes(1);
}

// ============ 删除 ============
function deleteCode(id, code){
    Swal.fire({
        title:'确认删除',
        html:'确定要删除兑换码 <code style="color:#4338ca;">'+escHtml(code)+'</code> 吗？<br>该操作不可恢复！',
        icon:'warning',
        showCancelButton:true,
        confirmButtonText:'确认删除',
        cancelButtonText:'取消',
        confirmButtonColor:'#ef4444',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'redeem.php', type:'POST', dataType:'json',
                data:{ action:'delete', id:id },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadStats();
                        loadCodes(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

function deleteBatch(){
    var batchNo = $('#batchInput').val().trim();
    if(!batchNo){
        toast('warning', '请先在"批次号"输入框中填写要删除的批次号');
        return;
    }
    Swal.fire({
        title:'按批次删除',
        html:'确定要删除批次 <strong>'+escHtml(batchNo)+'</strong> 下所有<strong class="text-success">未使用</strong>的兑换码吗？<br>已使用的兑换码将保留以备查。<br>该操作不可恢复！',
        icon:'warning',
        showCancelButton:true,
        confirmButtonText:'确认删除',
        cancelButtonText:'取消',
        confirmButtonColor:'#ef4444',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url:'redeem.php', type:'POST', dataType:'json',
                data:{ action:'delete_batch', batch_no:batchNo },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadStats();
                        loadCodes(_currentPage);
                    } else {
                        toast('error', res.msg);
                    }
                },
                error:function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}

// ============ 生成 ============
function openGenerate(){
    // 重置表单与结果区
    $('#genPackage').val('');
    $('#genCount').val(10);
    $('#genDays').val('');
    $('#genExpires').val('');
    $('#genResult').hide();
    $('#genCodesArea').val('');
    _lastGeneratedCodes = [];
    _lastGeneratedBatch = '';
    generateModal.show();
}

function generateCodes(){
    var data = {
        action:'generate',
        target_package_id:$('#genPackage').val(),
        count:$('#genCount').val(),
        custom_days:$('#genDays').val().trim(),
        expires_at:$('#genExpires').val().trim()
    };
    if(!data.target_package_id){ toast('warning', '请选择目标套餐'); return; }
    if(data.count < 1 || data.count > 500){ toast('warning', '生成数量必须在 1-500 之间'); return; }
    if(data.custom_days !== '' && (parseInt(data.custom_days) <= 0)){ toast('warning', '自定义天数必须大于 0'); return; }

    var btn = document.getElementById('btnGenerate');
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = eeIcon('loading', 'icon-spin')+' 生成中...';

    $.ajax({
        url:'redeem.php', type:'POST', dataType:'json',
        data:data,
        success:function(res){
            if(res.code === 0){
                toast('success', res.msg);
                _lastGeneratedCodes = res.data.codes || [];
                _lastGeneratedBatch = res.data.batch_no || '';
                renderGeneratedCodes(_lastGeneratedCodes, _lastGeneratedBatch);
                loadStats();
                loadCodes(_currentPage);
            } else {
                toast('error', res.msg);
            }
        },
        error:function(){ toast('error', '网络错误，请重试'); }
    }).always(function(){
        btn.disabled = false;
        btn.innerHTML = orig;
    });
}

function renderGeneratedCodes(codes, batchNo){
    $('#genBatchLabel').text('批次 ' + batchNo);
    $('#genCountLabel').text(codes.length);
    $('#genCodesArea').val(codes.join('\n'));
    $('#genResult').show();
}

function copyGeneratedCodes(){
    if(_lastGeneratedCodes.length === 0){ toast('warning', '暂无可复制的兑换码'); return; }
    var text = _lastGeneratedCodes.join('\n');
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){
            toast('success', '已复制 ' + _lastGeneratedCodes.length + ' 个兑换码到剪贴板');
        }).catch(function(){ fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text){
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('success', '已复制到剪贴板'); }
    catch(e){ toast('error', '复制失败，请手动复制'); }
    document.body.removeChild(ta);
}

function exportGeneratedCodes(){
    if(_lastGeneratedCodes.length === 0){ toast('warning', '暂无可导出的兑换码'); return; }
    var text = _lastGeneratedCodes.join('\n');
    downloadText(text, 'redeem_codes_' + _lastGeneratedBatch + '.txt');
    toast('success', '已导出 ' + _lastGeneratedCodes.length + ' 个兑换码');
}

// ============ 导出（按当前筛选） ============
function exportCodes(){
    $.ajax({
        url:'redeem.php', type:'POST', dataType:'text',
        data:{
            action:'export',
            filter:$('#filterSelect').val(),
            batch_no:$('#batchInput').val().trim()
        },
        success:function(text){
            if(!text){ toast('info', '当前筛选条件下没有兑换码'); return; }
            var fname = 'redeem_codes_' + new Date().toISOString().slice(0,10) + '.txt';
            downloadText(text, fname);
            toast('success', '已导出兑换码');
        },
        error:function(){ toast('error', '导出失败，请重试'); }
    });
}

function downloadText(text, filename){
    var blob = new Blob([text], { type:'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
}

// ============ 详情 ============
function viewDetail(id){
    $.ajax({
        url:'redeem.php', type:'POST', dataType:'json',
        data:{ action:'detail', id:id },
        success:function(res){
            if(res.code === 0){
                renderDetail(res.data);
                detailModal.show();
            } else {
                toast('error', res.msg);
            }
        },
        error:function(){ toast('error', '加载详情失败，请重试'); }
    });
}

function renderDetail(d){
    _detailCode = d.code || '';

    var pkgLevel = d.package_level !== null ? 'VIP' + d.package_level : '-';
    var pkgStorage = d.package_storage !== null ? formatBytes(d.package_storage) : '-';
    var pkgDays = d.package_days !== null ? (d.package_days === 0 ? '永久' : d.package_days + ' 天') : '-';
    var pkgGroup = '<span class="text-muted">未绑定</span>';
    if(d.package_group){
        pkgGroup = escHtml(d.package_group);
        if(d.package_group_count !== null){
            pkgGroup += ' <span class="text-muted" style="font-size:12px;">（'+d.package_group_count+' 个接口）</span>';
        }
    }
    var customDays = d.custom_days !== null ? d.custom_days + ' 天' : '<span class="text-muted">跟随套餐</span>';
    var usedUser = d.used_user_id > 0
        ? escHtml(d.used_username || ('UID:' + d.used_user_id)) + (d.used_email ? ' <span class="text-muted" style="font-size:12px;">('+escHtml(d.used_email)+')</span>' : '')
        : '<span class="text-muted">-</span>';
    var usedAt = d.used_at ? escHtml(d.used_at) : '<span class="text-muted">-</span>';
    var expiresAt = d.expires_at ? escHtml(d.expires_at) : '<span class="text-muted">永久有效</span>';

    var statusBadge = '';
    if(d.status === 'used'){
        statusBadge = '<span class="badge-status" style="background:#fff7ed;color:#c2410c;">'+eeIcon('check-decagram')+' 已使用</span>';
    } else if(d.status === 'expired'){
        statusBadge = '<span class="badge-status" style="background:#fef2f2;color:#b91c1c;">'+eeIcon('clock-alert-outline')+' 已过期</span>';
    } else {
        statusBadge = '<span class="badge-status badge-status-on">'+eeIcon('ticket-confirmation-outline')+' 未使用</span>';
    }

    var rows = [
        ['兑换码',      '<code style="color:#4338ca;font-size:14px;">'+escHtml(d.code)+'</code>'],
        ['状态',        statusBadge],
        ['目标套餐',    escHtml(d.package_name) + (d.package_level !== null ? '（'+pkgLevel+'）' : '')],
        ['套餐存储',    pkgStorage],
        ['套餐天数',    pkgDays],
        ['绑定分组',    pkgGroup],
        ['自定义天数',  customDays],
        ['使用者',      usedUser],
        ['使用时间',    usedAt],
        ['兑换码过期',  expiresAt],
        ['创建时间',    escHtml(d.created_at)],
        ['批次号',      escHtml(d.batch_no || '-')]
    ];

    var html = '';
    for(var i = 0; i < rows.length; i++){
        html += '<div class="detail-row"><span class="detail-label">'+rows[i][0]+'</span><span class="detail-value">'+rows[i][1]+'</span></div>';
    }
    $('#detailBody').html(html);
}

function copyDetailCode(){
    if(!_detailCode){ toast('warning', '暂无可复制的兑换码'); return; }
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(_detailCode).then(function(){
            toast('success', '已复制兑换码到剪贴板');
        }).catch(function(){ fallbackCopy(_detailCode); });
    } else {
        fallbackCopy(_detailCode);
    }
}

function formatBytes(bytes){
    if(bytes === -1) return '无限制';
    if(bytes <= 0) return '0 B';
    var units = ['B','KB','MB','GB','TB'];
    var i = 0;
    while(bytes >= 1024 && i < units.length - 1){ bytes /= 1024; i++; }
    return bytes.toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
}
</script>
</body>
</html>
