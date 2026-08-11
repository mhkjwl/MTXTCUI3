<?php
/**
 * @file images.php
 * @description 后台图片管理页面，提供图片列表查看、按用户/日期/文件名筛选、单张/批量删除及统计信息等AJAX接口
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

    if($action === 'list') {
        $page    = max(1, intval($_POST['page'] ?? 1));
        $perPage = 20;
        $userId  = intval($_POST['user_id'] ?? 0);
        $search  = trim($_POST['search'] ?? '');
        $dateFrom = trim($_POST['date_from'] ?? '');
        $dateTo   = trim($_POST['date_to'] ?? '');

        $where = ' WHERE 1=1';
        $types = '';
        $params = [];
        if($userId > 0) {
            $where .= " AND user_id=?";
            $types .= 'i';
            $params[] = $userId;
        }
        if($search !== '') {
            // Escape LIKE wildcards to prevent wildcard injection
            $escSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search);
            $where .= " AND filename LIKE ? ESCAPE '\\\\'";
            $types .= 's';
            $params[] = '%'.$escSearch.'%';
        }
        if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where .= " AND created_at >= ?";
            $types .= 's';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where .= " AND created_at <= ?";
            $types .= 's';
            $params[] = $dateTo . ' 23:59:59';
        }

        $total      = (int)$DB->count_prepared("SELECT COUNT(*) FROM eecms_images{$where}", $types, $params);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if($page > $totalPages) $page = $totalPages;
        $start = ($page - 1) * $perPage;

        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$start, $perPage]);
        $rs = $DB->fetch_all_prepared("SELECT * FROM eecms_images{$where} ORDER BY id DESC LIMIT ?, ?", $listTypes, $listParams);
        $images = [];
        while($rs && ($row = $DB->fetch($rs))) {
            $images[] = [
                'id'         => (int)$row['id'],
                'user_id'    => (int)$row['user_id'],
                'username'   => $row['username'],
                'filename'   => $row['filename'],
                'url'        => $row['url'],
                'thumb_url'  => $row['thumb_url'],
                'size'       => (int)$row['size'],
                'api_type'   => $row['api_type'],
                'ip'         => $row['ip'],
                'created_at' => $row['created_at'],
            ];
        }

        // 用户列表（用于筛选下拉）：列出所有注册用户，而不仅是有上传记录的用户，
        // 否则用户尚未上传图片时下拉框里无法选中该用户
        // M1 修复：原查询无 LIMIT，用户量增大后会拉取全表导致内存/性能问题，限制 500 条
        $usersList = [];
        $urs = $DB->query("SELECT id AS user_id, username FROM eecms_users ORDER BY username ASC LIMIT 500");
        while($urow = $DB->fetch($urs)) {
            $usersList[] = [
                'user_id'  => (int)$urow['user_id'],
                'username' => $urow['username'],
            ];
        }

        json_exit(0, 'ok', [
            'images'     => $images,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'perPage'    => $perPage,
            'users'      => $usersList,
        ]);
    }

    if($action === 'stats') {
        $totalImages = (int)$DB->count("SELECT COUNT(*) FROM eecms_images");
        $totalSize   = (int)$DB->count("SELECT COALESCE(SUM(size),0) FROM eecms_images");
        $totalUsers  = (int)$DB->count("SELECT COUNT(DISTINCT user_id) FROM eecms_images WHERE user_id > 0");
        json_exit(0, 'ok', [
            'total_images' => $totalImages,
            'total_size'   => $totalSize,
            'total_users'  => $totalUsers,
        ]);
    }

    if($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if($id <= 0) json_exit(1, '参数错误');

        $img = $DB->get_row_prepared("SELECT id, user_id, url FROM eecms_images WHERE id=?", 'i', [$id]);
        if(!$img) json_exit(1, '图片记录不存在');

        $DB->query_prepared("DELETE FROM eecms_images WHERE id=?", 'i', [$id]);
        // 联动删除本地存储的文件（本地上传的图片）
        try_delete_local_image($img['url']);
        // 更新对应用户的上传计数（H7 修复：使用预处理语句，禁止 SQL 字符串拼接）
        if($img['user_id'] > 0) {
            $DB->query_prepared("UPDATE eecms_users SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?", 'i', [$img['user_id']]);
        }
        json_exit(0, '图片已删除');
    }

    if($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        if(!is_array($ids)) json_exit(1, '参数错误');

        $intIds = [];
        foreach($ids as $id) {
            $id = intval($id);
            if($id > 0) $intIds[] = $id;
        }
        $intIds = array_values(array_unique($intIds));
        if(count($intIds) === 0) json_exit(1, '请选择要删除的图片');
        if(count($intIds) > 500) json_exit(1, '单次最多删除 500 条');

        // 构建 IN 占位符（预处理语句，禁止字符串拼接）
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));
        $phTypes = str_repeat('i', count($intIds));
        // 获取要删除图片的 user_id 和 url（用于更新上传计数、联动删除本地文件）
        $rows = pkg_safe_get_all_prepared($DB, "SELECT id, user_id, url FROM eecms_images WHERE id IN ($placeholders)", $phTypes, $intIds);
        if(count($rows) === 0) json_exit(1, '图片记录不存在');

        $ok = pkg_safe_query_prepared($DB, "DELETE FROM eecms_images WHERE id IN ($placeholders)", $phTypes, $intIds);
        $affected = ($ok === false) ? 0 : (int)$ok;

        // 联动删除本地存储的文件
        foreach($rows as $row) {
            try_delete_local_image($row['url']);
        }

        // 更新对应用户的上传计数
        $userCounts = [];
        foreach($rows as $row) {
            $uid = (int)$row['user_id'];
            if($uid > 0) {
                if(!isset($userCounts[$uid])) $userCounts[$uid] = 0;
                $userCounts[$uid]++;
            }
        }
        foreach($userCounts as $uid => $cnt) {
            $DB->query_prepared("UPDATE eecms_users SET upload_count = GREATEST(0, upload_count - ?) WHERE id = ?", 'ii', [$cnt, $uid]);
        }

        json_exit(0, "已删除 {$affected} 张图片");
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
<title>图片管理 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>
html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}
.img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.img-card{background:var(--color-surface);border-radius:var(--radius-xl);overflow:hidden;box-shadow:var(--shadow-sm);transition:all .25s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}
.img-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-xl)}
.img-thumb{width:100%;height:120px;background:var(--color-bg-tertiary);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.img-thumb img{width:100%;height:100%;object-fit:cover}
.img-thumb .img-thumb-placeholder{font-size:48px;color:var(--color-text-muted)}
.img-info{padding:12px;flex:1;display:flex;flex-direction:column;gap:4px}
.img-info .img-name{font-size:13px;font-weight:600;color:var(--color-text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.img-info .img-meta{font-size:12px;color:var(--color-text-muted);display:flex;flex-wrap:wrap;gap:8px}
.img-info .img-meta span{display:inline-flex;align-items:center;gap:3px}
.img-actions{padding:8px 12px;border-top:1px solid var(--color-border);display:flex;gap:4px}
.img-actions .btn{flex:1;padding:6px;font-size:12px}
.api-badge{position:absolute;top:8px;left:8px;background:rgba(30,41,59,.8);color:#fff;font-size:11px;padding:2px 8px;border-radius:4px;backdrop-filter:blur(4px)}
.img-check{position:absolute;top:8px;right:8px;width:20px;height:20px;cursor:pointer;z-index:2;accent-color:var(--color-primary)}
.img-card.selected{outline:3px solid var(--color-primary);outline-offset:-3px}
</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('image-multiple-outline'); ?> 图片管理
  </div>

  <!-- 统计卡片 -->
  <div class="row g-2 mb-2">
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon blue"><?php echo icon('image-multiple'); ?></div>
        <div class="stat-content">
          <div class="stat-label">图片总数</div>
          <div class="stat-value" id="statTotal">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon cyan"><?php echo icon('filter'); ?></div>
        <div class="stat-content">
          <div class="stat-label">筛选结果</div>
          <div class="stat-value" id="statFiltered">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon green"><?php echo icon('account-multiple'); ?></div>
        <div class="stat-content">
          <div class="stat-label">有上传记录的用户</div>
          <div class="stat-value" id="statUsers">0</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="stat-card">
        <div class="stat-icon orange"><?php echo icon('database'); ?></div>
        <div class="stat-content">
          <div class="stat-label">总占用空间</div>
          <div class="stat-value" id="statTotalSize" style="font-size:18px;">0 B</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 筛选栏 -->
  <div class="card">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">用户</label>
          <select class="form-select" id="userFilter">
            <option value="0">全部用户</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">搜索文件名</label>
          <input type="text" class="form-control" id="searchInput" placeholder="输入文件名搜索" onkeydown="if(event.key==='Enter')loadImages(1)">
        </div>
        <div class="col-md-2">
          <label class="form-label">开始日期</label>
          <input type="date" class="form-control" id="dateFrom">
        </div>
        <div class="col-md-2">
          <label class="form-label">结束日期</label>
          <input type="date" class="form-control" id="dateTo">
        </div>
        <div class="col-md-1">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-primary w-100" onclick="loadImages(1, this)"><?php echo icon('magnify'); ?></button>
        </div>
        <div class="col-md-1">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-outline-secondary w-100" id="resetFilterBtn" onclick="resetFilter(this)"><?php echo icon('refresh'); ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- 图片网格 -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?php echo icon('image-multiple-outline'); ?> 图片列表</div>
      <div class="d-flex align-items-center gap-2">
        <div class="form-check" style="margin:0;">
          <input class="form-check-input" type="checkbox" id="checkAll" onclick="toggleAll(this)" title="全选当前页">
          <label class="form-check-label" for="checkAll" style="font-size:13px;">全选</label>
        </div>
        <div id="batchBar" class="batch-toolbar" style="display:none;">
          <span class="batch-info">已选 <strong id="selectedCount">0</strong> 项</span>
          <button type="button" class="btn btn-danger btn-sm" onclick="batchDeleteSelected()"><?php echo icon('delete-sweep'); ?> 批量删除</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()"><?php echo icon('close'); ?></button>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div id="imgGrid" class="img-grid">
        <div class="text-center text-muted py-5" style="grid-column:1/-1;"><span style="font-size:32px;"><?php echo icon('loading', 'icon-spin'); ?></span><div class="mt-2">加载中...</div></div>
      </div>
      <div id="imgPagination" class="d-flex justify-content-center py-3"></div>
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
var _currentPage = 1;
var _globalStats = {total_images: 0, total_size: 0, total_users: 0};

window.addEventListener('DOMContentLoaded', function(){
    loadGlobalStats();
    loadImages(1);

    // H2 修复：使用事件委托替代内联 onclick/onchange，避免字符串拼接导致 XSS
    // 图片卡片复选框切换（委托到 grid 容器）
    $('#imgGrid').on('change', '.img-check', function(){
        var id = parseInt($(this).data('id'), 10);
        if(!isNaN(id)) toggleRow(id, this.checked);
    });
    // 复制链接按钮
    $('#imgGrid').on('click', '.act-copy-url', function(){
        var url = $(this).data('url') || '';
        copyUrl(url);
    });
    // 删除图片按钮
    $('#imgGrid').on('click', '.act-delete-img', function(){
        var id = parseInt($(this).data('id'), 10);
        var filename = $(this).data('filename') || '';
        if(!isNaN(id)) deleteImage(id, filename);
    });
    // 分页链接（委托到分页容器）
    $('#imgPagination').on('click', '.page-link', function(e){
        e.preventDefault();
        var li = $(this).closest('.page-item');
        if(li.hasClass('disabled')) return;
        var page = parseInt($(this).data('page'), 10);
        if(!isNaN(page) && page > 0) loadImages(page);
    });
});

function loadGlobalStats(){
    $.ajax({
        url: 'images.php', type: 'POST', dataType: 'json',
        data: { action: 'stats' },
        success: function(res){
            if(res.code === 0){
                _globalStats = res.data;
                $('#statTotal').text(res.data.total_images);
                $('#statUsers').text(res.data.total_users);
                $('#statTotalSize').text(formatSize(res.data.total_size));
            }
        },
        error: function(){ toast('error', '加载统计数据失败，请刷新重试'); }
    });
}

function escHtml(str){
    if(str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// URL 协议白名单：仅放行 http/https/相对路径，拦截 javascript: 等危险协议
function safeUrl(url){
    var u = String(url || '').trim().toLowerCase();
    if(u === '' || u === '#') return url;
    if(/^(https?:|\/|\.\/|\.\.\/|#)/.test(u)) return url;
    return '';
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

function formatSize(bytes){
    if(bytes < 1024) return bytes + ' B';
    if(bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    if(bytes < 1073741824) return (bytes/1048576).toFixed(2) + ' MB';
    return (bytes/1073741824).toFixed(2) + ' GB';
}

function loadImages(page, triggerBtn){
    // 进入 loading 态：禁用触发按钮 + 替换为旋转图标
    var btnState = null;
    if(triggerBtn){
        btnState = { btn: triggerBtn, html: triggerBtn.innerHTML, disabled: triggerBtn.disabled };
        triggerBtn.disabled = true;
        triggerBtn.innerHTML = eeIcon('loading', 'icon-spin');
    }
    $.ajax({
        url: 'images.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'list',
            page: page,
            user_id: $('#userFilter').val(),
            search: $('#searchInput').val(),
            date_from: $('#dateFrom').val(),
            date_to: $('#dateTo').val()
        },
        success: function(res){
            if(res.code === 0){
                renderGrid(res.data);
                renderPagination(res.data);
                renderUserOptions(res.data);
                updateStats(res.data);
                _currentPage = res.data.page;
            } else {
                toast('error', res.msg);
            }
        },
        error: function(){
            toast('error', '网络错误，请重试');
        },
        complete: function(){
            // 恢复触发按钮原状
            if(btnState){
                btnState.btn.innerHTML = btnState.html;
                btnState.btn.disabled = btnState.disabled;
            }
        }
    });
}

function updateStats(data){
    $('#statFiltered').text(data.total);
}

function renderUserOptions(data){
    if(!data.users || data.users.length === 0) return;
    var sel = $('#userFilter');
    var currentVal = sel.val() || '0';
    // 保留第一个 option（全部用户），重建其余选项
    sel.find('option:not(:first)').remove();
    $.each(data.users, function(i, u){
        sel.append('<option value="'+u.user_id+'">'+escHtml(u.username)+'</option>');
    });
    // 恢复之前的选择
    sel.val(currentVal);
}

function renderGrid(data){
    var grid = $('#imgGrid');
    grid.empty();
    if(!data.images || data.images.length === 0){
        grid.append('<div class="text-center text-muted py-5" style="grid-column:1/-1;"><span style="font-size:64px;display:block;margin-bottom:8px;">'+eeIcon('image-off-outline')+'</span>暂无图片数据</div>');
        syncCheckAll();
        return;
    }
    $.each(data.images, function(i, img){
        var thumb = safeUrl(img.thumb_url ? img.thumb_url : img.url);
        var thumbHtml = thumb
            ? '<img class="img-thumb-img" src="'+escHtml(thumb)+'" loading="lazy">'
            : '<span class="img-thumb-placeholder">'+eeIcon('image-outline')+'</span>';

        var isChecked = _selectedIds.has(img.id);
        var checkedAttr = isChecked ? ' checked' : '';
        var selectedClass = isChecked ? ' selected' : '';
        // H2 修复：移除内联 onchange，改用 data-id + 事件委托
        var checkHtml = '<input type="checkbox" class="img-check" value="'+img.id+'"'+checkedAttr+' data-id="'+img.id+'" title="选择">';

        // H2 修复：移除内联 onclick，改用 data 属性 + 事件委托，避免字符串拼接导致 XSS
        var card = '<div class="img-card'+selectedClass+'">' +
            '<div class="img-thumb">' +
                thumbHtml +
                '<span class="api-badge">'+escHtml(img.api_type || 'unknown')+'</span>' +
                checkHtml +
            '</div>' +
            '<div class="img-info">' +
                '<div class="img-name" title="'+escHtml(img.filename)+'">'+escHtml(img.filename)+'</div>' +
                '<div class="img-meta">' +
                    '<span>'+eeIcon('account')+escHtml(img.username || '游客')+'</span>' +
                    '<span>'+eeIcon('file')+formatSize(img.size)+'</span>' +
                '</div>' +
                '<div class="img-meta">' +
                    '<span>'+eeIcon('clock-outline')+escHtml(img.created_at)+'</span>' +
                '</div>' +
                '<div class="img-meta">' +
                    '<span>'+eeIcon('ip')+escHtml(img.ip || '-')+'</span>' +
                '</div>' +
            '</div>' +
            '<div class="img-actions">' +
                '<a href="'+escHtml(safeUrl(img.url))+'" target="_blank" rel="noopener" class="btn btn-outline-primary" title="查看">'+eeIcon('eye-outline')+'</a>' +
                '<button type="button" class="btn btn-outline-info act-copy-url" data-url="'+escHtml(img.url)+'" title="复制链接">'+eeIcon('content-copy')+'</button>' +
                '<button type="button" class="btn btn-outline-danger act-delete-img" data-id="'+img.id+'" data-filename="'+escHtml(img.filename)+'" title="删除">'+eeIcon('delete')+'</button>' +
            '</div>' +
        '</div>';
        grid.append(card);
    });
    // 绑定图片加载失败处理
    grid.find('img.img-thumb-img').on('error', function(){
        $(this).replaceWith('<span class="img-thumb-placeholder">'+eeIcon('image-broken')+'</span>');
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
    // 同步卡片选中样式
    $('.img-check').each(function(){
        if(parseInt($(this).val(), 10) === id){
            $(this).closest('.img-card').toggleClass('selected', checked);
        }
    });
    updateBatchBar();
}

function toggleAll(master){
    var checked = master.checked;
    $('.img-check').each(function(){
        var id = parseInt($(this).val(), 10);
        if(isNaN(id)) return;
        if(checked){
            _selectedIds.add(id);
            this.checked = true;
            $(this).closest('.img-card').addClass('selected');
        } else {
            _selectedIds.delete(id);
            this.checked = false;
            $(this).closest('.img-card').removeClass('selected');
        }
    });
    updateBatchBar();
}

function syncCheckAll(){
    var visibleChecks = $('.img-check');
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
    $('.img-check').prop('checked', false);
    $('.img-card').removeClass('selected');
    $('#checkAll').prop('checked', false).prop('indeterminate', false);
    updateBatchBar();
}

function batchDeleteSelected(){
    if(_selectedIds.size === 0){ toast('warning', '请先选择要删除的图片'); return; }
    var ids = Array.from(_selectedIds);
    Swal.fire({
        title:'确认批量删除',
        html:'确定要删除选中的 <strong style="color:#ef4444;">'+ids.length+'</strong> 张图片吗？<br><span class="text-muted" style="font-size:13px;">该操作仅删除数据库记录，不可恢复！</span>',
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
                url:'images.php', type:'POST', dataType:'json',
                data:{ action:'delete_selected', ids:ids },
                success:function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        clearSelection();
                        loadGlobalStats();
                        loadImages(_currentPage);
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
    var box = $('#imgPagination');
    box.empty();
    if(data.totalPages <= 1) return;
    var nav = $('<nav><ul class="pagination mb-0"></ul></nav>');
    var ul = nav.find('ul');
    // H2 修复：移除内联 onclick，改用 data-page + 事件委托
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

function resetFilter(triggerBtn){
    $('#userFilter').val('0');
    $('#searchInput').val('');
    $('#dateFrom').val('');
    $('#dateTo').val('');
    loadImages(1, triggerBtn);
}

function copyUrl(url){
    var tmp = document.createElement('textarea');
    tmp.value = url;
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    try {
        document.execCommand('copy');
        toast('success', '链接已复制到剪贴板');
    } catch(e) {
        toast('error', '复制失败，请手动复制');
    }
    document.body.removeChild(tmp);
}

function deleteImage(id, filename){
    Swal.fire({
        title: '确认删除',
        html: '确定要删除图片 <strong>'+escHtml(filename)+'</strong> 吗？<br>该操作仅删除数据库记录，不可恢复！',
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
                url: 'images.php', type: 'POST', dataType: 'json',
                data: { action: 'delete', id: id },
                success: function(res){
                    if(res.code === 0){
                        toast('success', res.msg);
                        loadImages(_currentPage);
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
