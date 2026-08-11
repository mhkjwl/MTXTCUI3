<?php
/**
 * @file packages.php
 * @description 套餐管理页面，支持创建/编辑/删除用户套餐，配置等级权重、存储配额、有效天数及接口分组绑定
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

function json_exit($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== AJAX 接口 ==========
if(isset($_POST['action'])) {
    if(!csrf_verify()) {
        json_exit(1, '安全校验失败，请刷新页面后重试！');
    }
    $action = $_POST['action'];

    // ----- 获取套餐列表 -----
    if($action === 'list') {
        $packages = get_all_packages($DB);
        $list = [];
        $defaultCount = 0;
        $vipCount = 0;
        foreach($packages as $pkg) {
            $list[] = [
                'id'            => (int)$pkg['id'],
                'name'          => $pkg['name'],
                'level'         => (int)$pkg['level'],
                'storage_limit' => (int)$pkg['storage_limit'],
                'days'          => (int)$pkg['days'],
                'group_id'      => (int)$pkg['group_id'],
                'group_name'    => $pkg['group_name'] ? $pkg['group_name'] : '',
                'is_default'    => (int)$pkg['is_default'],
                'created_at'    => $pkg['created_at'],
            ];
            if((int)$pkg['is_default'] === 1) $defaultCount++;
            if((int)$pkg['level'] > 0) $vipCount++;
        }
        json_exit(0, 'ok', [
            'packages' => $list,
            'summary'  => [
                'total'   => count($list),
                'default' => $defaultCount,
                'vip'     => $vipCount,
            ],
        ]);
    }

    // ----- 创建套餐 -----
    if($action === 'create') {
        $name       = trim($_POST['name'] ?? '');
        $level      = intval($_POST['level'] ?? -1);
        $storageMB  = intval($_POST['storage_limit'] ?? 0);
        $days       = intval($_POST['days'] ?? -1);
        $groupId    = intval($_POST['group_id'] ?? 0);
        $isDefault  = intval($_POST['is_default'] ?? 0);

        if($name === '') json_exit(1, '套餐名称不能为空');
        if(mb_strlen($name) > 64) json_exit(1, '套餐名称不能超过64个字符');
        if($level < 0) json_exit(1, '等级权重必须为0或正整数');
        if($storageMB < -1 || $storageMB == 0) json_exit(1, '存储大小必须大于0或为-1（无限制）');
        if($days < 0) json_exit(1, '有效天数不能为负数');
        if(!in_array($isDefault, [0, 1])) json_exit(1, '默认参数错误');
        // M12 修复：group_id > 0 时校验分组存在性，防止无效引用
        if($groupId > 0) {
            $grp = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_api_groups WHERE id=?", 'i', [$groupId]);
            if(!$grp) json_exit(1, '所选接口分组不存在');
        }

        // level 唯一性校验（M3 修复：仅检查启用记录，软删除已将 level 置为负值释放占用）
        $dup = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_packages WHERE level=? AND status=1", 'i', [$level]);
        if($dup) json_exit(1, '等级权重 ' . $level . ' 已被使用，请更换');

        $storageBytes = ($storageMB == -1) ? -1 : $storageMB * 1048576;

        $newId = $DB->insert_prepared("INSERT INTO eecms_packages (name, level, storage_limit, days, group_id, is_default, status) VALUES (?, ?, ?, ?, ?, ?, 1)", 'siiiii', [$name, $level, $storageBytes, $days, $groupId, $isDefault]);
        // H15 修复：不返回 DB error 详情，避免泄露表结构
        if(!$newId) {
            $dbErr = $DB->error();
            error_log('[packages] 创建套餐失败：' . $dbErr);
            // 检测 UNIQUE 约束冲突
            if(strpos($dbErr, 'Duplicate') !== false || strpos($dbErr, '1062') !== false) {
                json_exit(1, '等级权重 ' . $level . ' 已被使用，请更换');
            }
            json_exit(1, '套餐创建失败，请重试');
        }

        // is_default=1 时取消其他默认
        if($isDefault == 1) {
            pkg_safe_query_prepared($DB, "UPDATE eecms_packages SET is_default=0 WHERE is_default=1 AND status=1 AND id != ?", 'i', [$newId]);
        }

        log_admin_action($DB, 'create', 'package', $newId, ['name' => $name, 'level' => $level, 'storage_mb' => $storageMB, 'days' => $days]);
        json_exit(0, '套餐「' . $name . '」创建成功');
    }

    // ----- 编辑套餐 -----
    if($action === 'update') {
        $id        = intval($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $level     = intval($_POST['level'] ?? -1);
        $storageMB = intval($_POST['storage_limit'] ?? 0);
        $days      = intval($_POST['days'] ?? -1);
        $groupId   = intval($_POST['group_id'] ?? 0);
        $isDefault = intval($_POST['is_default'] ?? 0);

        if($id <= 0) json_exit(1, '参数错误');
        if($name === '') json_exit(1, '套餐名称不能为空');
        if(mb_strlen($name) > 64) json_exit(1, '套餐名称不能超过64个字符');
        if($level < 0) json_exit(1, '等级权重必须为0或正整数');
        if($storageMB < -1 || $storageMB == 0) json_exit(1, '存储大小必须大于0或为-1（无限制）');
        if($days < 0) json_exit(1, '有效天数不能为负数');
        if(!in_array($isDefault, [0, 1])) json_exit(1, '默认参数错误');
        // M12 修复：编辑时同样校验 group_id 存在性
        if($groupId > 0) {
            $grp = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_api_groups WHERE id=?", 'i', [$groupId]);
            if(!$grp) json_exit(1, '所选接口分组不存在');
        }

        $exists = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_packages WHERE id=? AND status=1", 'i', [$id]);
        if(!$exists) json_exit(1, '套餐不存在');

        // level 唯一性校验（M3 修复：排除自身且仅检查启用记录）
        $dup = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_packages WHERE level=? AND id != ? AND status=1", 'ii', [$level, $id]);
        if($dup) json_exit(1, '等级权重 ' . $level . ' 已被使用，请更换');

        $storageBytes = ($storageMB == -1) ? -1 : $storageMB * 1048576;

        if($DB->query_prepared("UPDATE eecms_packages SET name=?, level=?, storage_limit=?, days=?, group_id=?, is_default=? WHERE id=?", 'siiiiii', [$name, $level, $storageBytes, $days, $groupId, $isDefault, $id]) === false) {
            json_exit(1, '套餐更新失败，请重试！');
        }

        // is_default=1 时取消其他默认
        if($isDefault == 1) {
            pkg_safe_query_prepared($DB, "UPDATE eecms_packages SET is_default=0 WHERE is_default=1 AND status=1 AND id != ?", 'i', [$id]);
        }

        log_admin_action($DB, 'update', 'package', $id, ['name' => $name, 'level' => $level, 'storage_mb' => $storageMB, 'days' => $days]);
        json_exit(0, '套餐「' . $name . '」更新成功');
    }

    // ----- 删除套餐（软删除） -----
    if($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $pkg = pkg_safe_get_row_prepared($DB, "SELECT * FROM eecms_packages WHERE id=? AND status=1", 'i', [$id]);
        if(!$pkg) json_exit(1, '套餐不存在');

        // 默认套餐不允许删除
        if((int)$pkg['is_default'] === 1) json_exit(1, '默认套餐不允许删除，请先取消默认设置');

        // 检查是否有用户正在使用
        $now = date('Y-m-d H:i:s');
        $usageCount = pkg_safe_count_prepared($DB, "SELECT COUNT(*) FROM eecms_user_subs WHERE package_id=? AND expire_time > ?", 'is', [$id, $now]);
        if($usageCount > 0) json_exit(1, '该套餐有 ' . $usageCount . ' 个用户正在使用，无法删除');

        // M3 修复：软删除时将 level 置为负值（-id），释放原 level 以便复用，同时满足 DB UNIQUE 约束
        pkg_safe_query_prepared($DB, "UPDATE eecms_packages SET status=0, level=-ABS(?) WHERE id=?", 'ii', [$id, $id]);
        log_admin_action($DB, 'delete', 'package', $id, ['name' => $pkg['name']]);
        json_exit(0, '套餐「' . $pkg['name'] . '」已删除');
    }

    // ----- 获取接口分组列表 -----
    if($action === 'get_groups') {
        $groupRows = pkg_safe_get_all($DB, "SELECT * FROM eecms_api_groups ORDER BY id");
        $groups = [];
        foreach($groupRows as $row) {
            $groups[] = [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'description' => $row['description'],
            ];
        }
        json_exit(0, 'ok', $groups);
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
<title>套餐管理 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('package-variant-closed'); ?> 套餐管理
  </div>

  <!-- 统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon blue"><?php echo icon('package-variant'); ?></div>
        <div class="stat-content">
          <div class="stat-label">套餐总数</div>
          <div class="stat-value" id="statTotal">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('star-circle'); ?></div>
        <div class="stat-content">
          <div class="stat-label">默认套餐</div>
          <div class="stat-value" id="statDefault">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon orange"><?php echo icon('crown'); ?></div>
        <div class="stat-content">
          <div class="stat-label">VIP套餐数</div>
          <div class="stat-value" id="statVip">0</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 套餐列表 -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="card-title"><?php echo icon('package-variant-closed'); ?> 套餐列表</div>
      <button type="button" class="btn btn-primary btn-sm" onclick="openCreate()"><?php echo icon('plus'); ?> 新增套餐</button>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th style="width:60px;">ID</th>
              <th>套餐名称</th>
              <th style="width:100px;">等级</th>
              <th style="width:120px;">存储大小</th>
              <th style="width:100px;">有效天数</th>
              <th style="width:140px;">绑定分组</th>
              <th style="width:90px;">默认</th>
              <th style="width:120px;">操作</th>
            </tr>
          </thead>
          <tbody id="pkgTableBody">
            <tr><td colspan="8" class="text-center text-muted py-5"><?php echo icon('loading', 'icon-spin'); ?> 加载中...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- 新增/编辑套餐 Modal -->
<div class="modal fade" id="pkgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pkgModalTitle"><?php echo icon('package-plus'); ?> 新增套餐</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <form id="pkgForm">
          <input type="hidden" id="pkgId">
          <div class="mb-3">
            <label class="form-label">套餐名称</label>
            <input type="text" class="form-control" id="pkgName" placeholder="如：免费版、VIP月卡" maxlength="64" required>
          </div>
          <div class="mb-3">
            <label class="form-label">等级权重</label>
            <input type="number" class="form-control" id="pkgLevel" min="0" step="1" placeholder="0=免费版，数字越大等级越高" required>
            <small class="text-muted">权重越大等级越高，VIP(N) 继承所有 ≤N 等级套餐的接口权限</small>
          </div>
          <div class="mb-3">
            <label class="form-label">存储大小 (MB)</label>
            <input type="number" class="form-control" id="pkgStorage" min="-1" step="1" placeholder="如：100 表示 100MB，-1 表示无限制" required>
            <small class="text-muted">以 MB 为单位，保存时自动转换为字节。-1 表示无限制存储</small>
          </div>
          <div class="mb-3">
            <label class="form-label">有效天数</label>
            <input type="number" class="form-control" id="pkgDays" min="0" step="1" placeholder="0=永久有效" required>
            <small class="text-muted">0 表示永久有效</small>
          </div>
          <div class="mb-3">
            <label class="form-label">绑定接口分组</label>
            <select class="form-select" id="pkgGroup">
              <option value="0">不绑定分组</option>
            </select>
            <small class="text-muted">用户使用该套餐时只能上传到已绑定分组的接口</small>
          </div>
          <div class="mb-0">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="pkgIsDefault">
              <label class="form-label mb-0" for="pkgIsDefault">设为默认套餐</label>
            </div>
            <small class="text-muted">新注册用户将自动获得默认套餐</small>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="savePackage()"><?php echo icon('content-save'); ?> 保存</button>
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
var pkgModal;
var _groupsCache = [];
var _packagesCache = [];

window.addEventListener('DOMContentLoaded', function(){
    pkgModal = new bootstrap.Modal(document.getElementById('pkgModal'));
    loadGroups();
    loadPackages();

    // H8 修复：事件委托替代内联 onclick，避免字符串拼接导致 XSS
    $('#pkgTableBody').on('click', '.act-edit-pkg', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) openEdit(id);
    });
    $('#pkgTableBody').on('click', '.act-delete-pkg', function(){
        var id = parseInt($(this).data('id'), 10);
        var name = $(this).data('name') || '';
        if(!isNaN(id)) deletePackage(id, name);
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

function formatStorage(bytes){
    if(bytes == -1) return '<span class="text-success">无限制</span>';
    if(bytes >= 1073741824){
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }
    return Math.round(bytes / 1048576) + ' MB';
}

function formatDays(days){
    if(days == 0) return '<span class="text-muted">永久</span>';
    return days + ' 天';
}

function loadPackages(){
    $.ajax({
        url: 'packages.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'list' },
        success: function(res){
            if(res.code === 0){
                renderTable(res.data);
                renderSummary(res.data);
                _packagesCache = res.data.packages || [];
            } else {
                toast('error', res.msg);
            }
        },
        error: function(){
            toast('error', '网络错误，请重试');
        }
    });
}

function renderSummary(data){
    if(data.summary){
        $('#statTotal').text(data.summary.total);
        $('#statDefault').text(data.summary.default);
        $('#statVip').text(data.summary.vip);
    }
}

function renderTable(data){
    var tbody = $('#pkgTableBody');
    tbody.empty();
    if(!data.packages || data.packages.length === 0){
        tbody.append('<tr><td colspan="8" class="text-center text-muted py-5"><span style="font-size:48px;display:block;margin-bottom:8px;">'+eeIcon('package-variant-closed')+'</span>暂无套餐数据</td></tr>');
        return;
    }
    $.each(data.packages, function(i, p){
        var levelBadge = p.level > 0
            ? '<span class="badge-status" style="background:#fef3c7;color:#92400e;">'+eeIcon('crown-outline')+' LV' + p.level + '</span>'
            : '<span class="badge-status" style="background:#e0f2fe;color:#075985;">'+eeIcon('account-outline')+' 免费版</span>';
        var defaultBadge = p.is_default == 1
            ? '<span class="badge-status badge-status-on">'+eeIcon('star')+' 默认</span>'
            : '<span class="text-muted">—</span>';
        var groupText = p.group_name ? escHtml(p.group_name) : '<span class="text-muted">未绑定</span>';

        var actions = '<div class="btn-group btn-group-sm">';
        actions += '<button type="button" class="btn btn-outline-primary act-edit-pkg" data-id="' + p.id + '" title="编辑">'+eeIcon('pencil')+'</button>';
        if(p.is_default == 1){
            actions += '<button type="button" class="btn btn-outline-secondary" disabled title="默认套餐不可删除">'+eeIcon('delete')+'</button>';
        } else {
            actions += '<button type="button" class="btn btn-outline-danger act-delete-pkg" data-id="' + p.id + '" data-name="' + escHtml(p.name) + '" title="删除">'+eeIcon('delete')+'</button>';
        }
        actions += '</div>';

        var row = '<tr>' +
            '<td>' + p.id + '</td>' +
            '<td><strong>' + escHtml(p.name) + '</strong></td>' +
            '<td>' + levelBadge + '</td>' +
            '<td>' + formatStorage(p.storage_limit) + '</td>' +
            '<td>' + formatDays(p.days) + '</td>' +
            '<td>' + groupText + '</td>' +
            '<td>' + defaultBadge + '</td>' +
            '<td>' + actions + '</td>' +
            '</tr>';
        tbody.append(row);
    });
}

function loadGroups(callback){
    $.ajax({
        url: 'packages.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_groups' },
        success: function(res){
            if(res.code === 0){
                _groupsCache = res.data || [];
                if(typeof callback === 'function') callback();
            } else {
                toast('error', res.msg || '加载接口分组失败');
            }
        },
        error: function(){ toast('error', '加载接口分组失败，请刷新重试'); }
    });
}

function renderGroupSelect(selected){
    var html = '<option value="0">不绑定分组</option>';
    $.each(_groupsCache, function(i, g){
        html += '<option value="' + g.id + '"' + (selected == g.id ? ' selected' : '') + '>' + escHtml(g.name) + '</option>';
    });
    $('#pkgGroup').html(html);
}

function openCreate(){
    $('#pkgModalTitle').html(eeIcon('package-plus')+' 新增套餐');
    $('#pkgId').val('');
    $('#pkgName').val('');
    $('#pkgLevel').val('0');
    $('#pkgStorage').val('');
    $('#pkgDays').val('0');
    $('#pkgIsDefault').prop('checked', false);
    loadGroups(function(){ renderGroupSelect(0); });
    pkgModal.show();
}

function openEdit(id){
    var p = null;
    for(var i = 0; i < _packagesCache.length; i++){
        if(_packagesCache[i].id == id){ p = _packagesCache[i]; break; }
    }
    if(!p){ toast('error', '套餐数据不存在'); return; }

    $('#pkgModalTitle').html(eeIcon('package-edit')+' 编辑套餐');
    $('#pkgId').val(p.id);
    $('#pkgName').val(p.name);
    $('#pkgLevel').val(p.level);
    $('#pkgStorage').val(p.storage_limit == -1 ? -1 : Math.round(p.storage_limit / 1048576));
    $('#pkgDays').val(p.days);
    $('#pkgIsDefault').prop('checked', p.is_default == 1);
    loadGroups(function(){ renderGroupSelect(p.group_id); });
    pkgModal.show();
}

function savePackage(){
    var id = $('#pkgId').val();
    var data = {
        action: id ? 'update' : 'create',
        name: $('#pkgName').val().trim(),
        level: $('#pkgLevel').val(),
        storage_limit: $('#pkgStorage').val(),
        days: $('#pkgDays').val(),
        group_id: $('#pkgGroup').val(),
        is_default: $('#pkgIsDefault').is(':checked') ? 1 : 0
    };
    if(id) data.id = id;

    if(!data.name){ toast('warning', '请输入套餐名称'); return; }
    if(data.level === '' || data.level < 0){ toast('warning', '请输入有效的等级权重'); return; }
    if(!data.storage_limit || (data.storage_limit != -1 && data.storage_limit <= 0)){ toast('warning', '存储大小必须大于0或为-1（无限制）'); return; }
    if(data.days === '' || data.days < 0){ toast('warning', '有效天数不能为负数'); return; }

    var isEdit = !!id;
    var confirmTitle = isEdit ? '确认修改套餐' : '确认创建套餐';
    var confirmText = isEdit ? '确定要保存对此套餐的修改吗？' : '确定要创建新套餐「'+data.name+'」吗？';
    Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认',
        cancelButtonText: '取消',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url: 'packages.php', type: 'POST', dataType: 'json', data: data,
            success: function(res){
                if(res.code === 0){
                    pkgModal.hide();
                    toast('success', res.msg);
                    loadPackages();
                } else {
                    toast('error', res.msg);
                }
            },
            error: function(){ toast('error', '网络错误，请重试'); }
        });
    });
}

function deletePackage(id, name){
    Swal.fire({
        title: '确认删除',
        html: '确定要删除套餐 <strong>' + escHtml(name) + '</strong> 吗？<br>删除后将无法在列表中显示。',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '确认删除',
        cancelButtonText: '取消',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            $.ajax({
                url: 'packages.php', type: 'POST', dataType: 'json',
                data: { action: 'delete', id: id },
                success: function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadPackages();
                    } else {
                        toast('error', res.msg);
                    }
                },
                error: function(){ toast('error', '网络错误，请重试'); }
            });
        }
    });
}
</script>
</body>
</html>
