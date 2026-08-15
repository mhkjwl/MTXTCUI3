<?php
/**
 * @file s3.php
 * @description S3兼容存储配置页面，采用UI3卡片网格布局管理S3存储节点，支持新增/编辑/删除/启用切换
 * @author AI
 * @version 1.3.1-dev
 * @date 2026-08-15
 */
declare(strict_types=1);

ini_set("display_errors", 0);
require('../inc/lang.php');
require('../inc/common.php');
require('../inc/icon.php');
if(!isset($islogin) || $islogin!=1) exit('<script>parent.location.href="login.php";</script>');

// 从数据库加载 S3 存储配置（解密 secret_key）
$s3Configs = array();
if(isset($conf['s3_storage_configs']) && !empty($conf['s3_storage_configs'])) {
    $decoded = json_decode($conf['s3_storage_configs'], true);
    if(is_array($decoded)) {
        foreach($decoded as &$cfg) {
            if(isset($cfg['secret_key'])) {
                $cfg['secret_key'] = ct_decrypt($cfg['secret_key']);
            }
        }
        unset($cfg);
        $s3Configs = $decoded;
    }
}

/**
 * 持久化 S3 配置到数据库（加密 secret_key 后写入）
 */
function persistS3Configs(array $configs, $db): bool {
    $toSave = array_map(function($cfg) {
        if(isset($cfg['secret_key'])) {
            $cfg['secret_key'] = ct_encrypt($cfg['secret_key']);
        }
        return $cfg;
    }, array_values($configs));
    $json = json_encode($toSave, JSON_UNESCAPED_UNICODE);
    return $db->query_prepared(
        "INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?",
        'sss', ['s3_storage_configs', $json, $json]
    ) !== false;
}

// 处理 AJAX 操作
if(isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if(!csrf_verify()) {
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试！']);
        exit;
    }

    $act = $_POST['action'];

    // ---- list ----
    if($act === 'list') {
        $out = array();
        foreach($s3Configs as $i => $cfg) {
            if(isset($cfg['deleted']) && $cfg['deleted'] === '1') continue;
            $out[] = array(
                'id'          => (int)$i,
                'name'        => isset($cfg['name']) ? $cfg['name'] : '',
                'bucket'      => isset($cfg['bucket']) ? $cfg['bucket'] : '',
                'endpoint'    => isset($cfg['endpoint']) ? $cfg['endpoint'] : '',
                'region'      => isset($cfg['region']) ? $cfg['region'] : '',
                'domain'      => isset($cfg['domain']) ? $cfg['domain'] : '',
                'path_style'  => isset($cfg['path_style']) ? $cfg['path_style'] : '0',
                'verify_ssl'  => isset($cfg['verify_ssl']) ? $cfg['verify_ssl'] : '0',
                'path_prefix' => isset($cfg['path_prefix']) ? $cfg['path_prefix'] : '',
                'enabled'     => isset($cfg['enabled']) ? $cfg['enabled'] : '0',
                'max_size'    => isset($cfg['max_size']) ? $cfg['max_size'] : '10',
            );
        }
        echo json_encode(['code' => 0, 'data' => $out]);
        exit;
    }

    // ---- get ----
    if($act === 'get' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if(!isset($s3Configs[$id]) || (isset($s3Configs[$id]['deleted']) && $s3Configs[$id]['deleted'] === '1')) {
            echo json_encode(['code' => 1, 'msg' => '配置不存在！']);
            exit;
        }
        $cfg = $s3Configs[$id];
        $cfg['secret_key'] = '__KEEP_EXISTING__';
        $ak = isset($cfg['access_key']) ? $cfg['access_key'] : '';
        $cfg['access_key'] = strlen($ak) > 4 ? str_repeat('•', strlen($ak) - 4) . substr($ak, -4) : '••••';
        $cfg['id'] = (int)$id;
        echo json_encode(['code' => 0, 'data' => $cfg]);
        exit;
    }

    // ---- toggle_enable ----
    if($act === 'toggle_enable' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if(!isset($s3Configs[$id]) || (isset($s3Configs[$id]['deleted']) && $s3Configs[$id]['deleted'] === '1')) {
            echo json_encode(['code' => 1, 'msg' => '配置不存在！']);
            exit;
        }
        $cur = isset($s3Configs[$id]['enabled']) ? $s3Configs[$id]['enabled'] : '0';
        $new = ($cur === '1') ? '0' : '1';
        $s3Configs[$id]['enabled'] = $new;
        if(!persistS3Configs($s3Configs, $DB)) {
            echo json_encode(['code' => 1, 'msg' => '状态切换失败，请重试！']);
            exit;
        }
        echo json_encode(['code' => 0, 'msg' => ($new==='1'?'S3 存储已启用':'S3 存储已禁用'), 'enabled' => $new]);
        exit;
    }

    // ---- delete ----
    if($act === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if(!isset($s3Configs[$id]) || (isset($s3Configs[$id]['deleted']) && $s3Configs[$id]['deleted'] === '1')) {
            echo json_encode(['code' => 1, 'msg' => '配置不存在！']);
            exit;
        }
        $s3Configs[$id]['deleted'] = '1';
        $s3Configs[$id]['enabled'] = '0';
        if(!persistS3Configs($s3Configs, $DB)) {
            echo json_encode(['code' => 1, 'msg' => '配置删除失败，请重试！']);
            exit;
        }
        echo json_encode(['code' => 0, 'msg' => 'S3 存储配置已删除！']);
        exit;
    }

    // ---- save ----
    if($act === 'save') {
        $editId = isset($_POST['edit_id']) ? $_POST['edit_id'] : '';
        $entry = array(
            'name'       => isset($_POST['s3_name']) ? trim($_POST['s3_name']) : '',
            'endpoint'   => isset($_POST['s3_endpoint']) ? trim($_POST['s3_endpoint']) : '',
            'access_key' => isset($_POST['s3_access_key']) ? trim($_POST['s3_access_key']) : '',
            'secret_key' => isset($_POST['s3_secret_key']) ? trim($_POST['s3_secret_key']) : '',
            'bucket'     => isset($_POST['s3_bucket']) ? trim($_POST['s3_bucket']) : '',
            'region'     => isset($_POST['s3_region']) ? trim($_POST['s3_region']) : '',
            'path_style' => isset($_POST['s3_path_style']) ? '1' : '0',
            'verify_ssl' => isset($_POST['s3_verify_ssl']) ? '1' : '0',
            'path_prefix'=> isset($_POST['s3_path_prefix']) ? trim($_POST['s3_path_prefix']) : '',
            'domain'     => isset($_POST['s3_domain']) ? trim($_POST['s3_domain']) : '',
            'max_size'   => isset($_POST['s3_max_size']) ? trim($_POST['s3_max_size']) : '10',
            'enabled'    => isset($_POST['s3_enabled']) ? '1' : '0',
        );

        $errors = array();
        if(empty($entry['name'])) $errors[] = '显示名称不能为空';
        if(empty($entry['endpoint'])) $errors[] = 'Endpoint 不能为空';
        if(empty($entry['access_key'])) $errors[] = 'Access Key 不能为空';
        if(empty($entry['secret_key'])) $errors[] = 'Secret Key 不能为空';
        if(empty($entry['bucket'])) $errors[] = 'Bucket 不能为空';
        if(empty($entry['region'])) $errors[] = 'Region 不能为空';

        if(!empty($errors)) {
            echo json_encode(['code' => 1, 'msg' => implode('；', $errors)]);
            exit;
        }

        if($entry['secret_key'] === '__KEEP_EXISTING__' && $editId !== '' && isset($s3Configs[(int)$editId])) {
            $entry['secret_key'] = $s3Configs[(int)$editId]['secret_key'];
        }
        if($editId !== '' && isset($s3Configs[(int)$editId]) && strpos($entry['access_key'], '•') !== false) {
            $entry['access_key'] = $s3Configs[(int)$editId]['access_key'];
        }

        if($editId !== '' && isset($s3Configs[(int)$editId]) && !(isset($s3Configs[(int)$editId]['deleted']) && $s3Configs[(int)$editId]['deleted'] === '1')) {
            $s3Configs[(int)$editId] = $entry;
        } else {
            $entry['enabled'] = '0'; // 新增默认禁用
            $s3Configs[] = $entry;
        }

        if(!persistS3Configs($s3Configs, $DB)) {
            echo json_encode(['code' => 1, 'msg' => '配置保存失败，请重试！']);
            exit;
        }
        echo json_encode(['code' => 0, 'msg' => 'S3 存储配置已保存！']);
        exit;
    }

    echo json_encode(['code' => 1, 'msg' => '未知操作！']);
    exit;
}

// 渐变色轮播
$gradColors = array('grad-violet', 'grad-sky', 'grad-amber', 'grad-rose', 'grad-emerald');
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>S3 存储设置 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260815a">
<style>
html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}

/* 卡片内行操作按钮 */
.api-card-foot .icon-btn-sm {
  width: 30px; height: 30px; border-radius: 8px;
  display: inline-grid; place-items: center;
  border: 1px solid var(--border);
  background: var(--surface); color: var(--muted);
  cursor: pointer; transition: all .2s;
}
.api-card-foot .icon-btn-sm:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
.api-card-foot .icon-btn-sm.danger:hover { border-color: var(--rose); color: var(--rose); background: rgba(239,68,119,.08); }
.api-card-foot .icon-btn-sm svg { width: 15px; height: 15px; }

/* 空状态 */
.s3-empty { grid-column: 1 / -1; text-align: center; padding: 48px 20px; color: var(--muted); }
.s3-empty svg { width: 56px; height: 56px; opacity: .4; margin-bottom: 12px; }

/* 弹窗表单 */
.edit-form .form-label{font-size:13px;margin-bottom:8px;display:block}
.edit-form .alert{font-size:13px;margin-bottom:14px}
.s3-required{color:var(--rose)}
#s3EditModal .modal-body .row{margin-bottom:16px}
#s3EditModal .modal-body .row:last-child{margin-bottom:0}
#s3EditModal .modal-body .form-label{margin-bottom:8px}

/* 参考表 */
.s3-ref-table td{font-size:13px}
.s3-ref-table code{font-size:11px}
</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <!-- ===== 页面头部 ===== -->
  <div class="page-head">
    <div>
      <h2>S3 存储设置</h2>
      <p class="muted">管理所有 S3 兼容存储节点，支持热切换与自定义域名</p>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" onclick="openS3Modal()">
        <?php echo icon('plus'); ?> 新增配置
      </button>
    </div>
  </div>

  <!-- ===== S3 存储卡片网格 ===== -->
  <div class="grid grid-3 mt-16" id="s3CardGrid">
    <div class="s3-empty"><?php echo icon('loading', 'icon-spin'); ?><p>加载中...</p></div>
  </div>

  <!-- ===== 常见 S3 兼容服务参考 ===== -->
  <div class="card mt-24">
    <div class="card-header">
      <div class="card-title"><?php echo icon('information-outline'); ?> 常见 S3 兼容服务参考</div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered small mb-0 s3-ref-table">
          <thead>
            <tr><th>服务商</th><th>Endpoint 示例</th><th>Region</th></tr>
          </thead>
          <tbody>
            <tr><td>AWS S3</td><td><code>https://s3.amazonaws.com</code></td><td>us-east-1</td></tr>
            <tr><td>Cloudflare R2</td><td><code>https://&lt;account_id&gt;.r2.cloudflarestorage.com</code></td><td>auto</td></tr>
            <tr><td>阿里云 OSS</td><td><code>https://oss-cn-hangzhou.aliyuncs.com</code></td><td>oss-cn-hangzhou</td></tr>
            <tr><td>腾讯云 COS</td><td><code>https://cos.ap-guangzhou.myqcloud.com</code></td><td>ap-guangzhou</td></tr>
            <tr><td>七牛云 Kodo</td><td><code>https://s3.cn-north-1.qiniucs.com</code></td><td>cn-north-1</td></tr>
            <tr><td>华为云 OBS</td><td><code>https://obs.cn-north-4.myhuaweicloud.com</code></td><td>cn-north-4</td></tr>
            <tr><td>Backblaze B2</td><td><code>https://s3.us-west-004.backblazeb2.com</code></td><td>us-west-004</td></tr>
            <tr><td>MinIO（自建）</td><td><code>https://minio.example.com</code></td><td>us-east-1</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- ========== 新增/编辑 S3 配置弹窗 ========== -->
<div class="modal fade" id="s3EditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="s3ModalTitle"><?php echo icon('plus-circle'); ?> 新增 S3 配置</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div id="s3EditAlert" class="alert alert-info" style="display:none;"><?php echo icon('information-outline'); ?> <span id="s3EditText"></span></div>
        <form id="s3Form" class="edit-form">
          <div class="mb-3">
            <label class="form-label"><span class="s3-required">*</span> 显示名称</label>
            <input type="text" class="form-control" name="s3_name" id="f_name" placeholder="例如：阿里云 OSS、腾讯云 COS、Cloudflare R2" required>
            <small class="text-muted">用于后台识别，不影响上传</small>
          </div>

          <div class="mb-3">
            <label class="form-label"><span class="s3-required">*</span> Endpoint</label>
            <input type="text" class="form-control" name="s3_endpoint" id="f_endpoint" placeholder="例如：https://s3.amazonaws.com 或 https://oss-cn-hangzhou.aliyuncs.com" required>
            <small class="text-muted">S3 兼容服务的接入点地址</small>
          </div>

          <div class="row">
            <div class="col-md-6">
              <label class="form-label"><span class="s3-required">*</span> Access Key</label>
              <input type="text" class="form-control" name="s3_access_key" id="f_access_key" placeholder="Access Key" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><span class="s3-required">*</span> Secret Key</label>
              <input type="password" class="form-control" name="s3_secret_key" id="f_secret_key" placeholder="Secret Key" required>
              <small class="text-muted" id="f_secret_hint" style="display:none;">密码字段，留空则保持原值不变</small>
            </div>
          </div>

          <div class="row">
            <div class="col-md-6">
              <label class="form-label"><span class="s3-required">*</span> Bucket</label>
              <input type="text" class="form-control" name="s3_bucket" id="f_bucket" placeholder="Bucket 名称" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><span class="s3-required">*</span> Region</label>
              <input type="text" class="form-control" name="s3_region" id="f_region" placeholder="例如：us-east-1、oss-cn-hangzhou" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">自定义域名（可选）</label>
            <input type="text" class="form-control" name="s3_domain" id="f_domain" placeholder="例如：https://cdn.example.com（留空则使用默认 S3 域名）">
            <small class="text-muted">用于替换默认的 S3 访问域名，实现 CDN 加速或自定义域名访问</small>
          </div>

          <div class="mb-3">
            <label class="form-label">存储路径前缀（可选）</label>
            <input type="text" class="form-control" name="s3_path_prefix" id="f_path_prefix" placeholder="例如：images（留空则上传到 Bucket 根目录）">
            <small class="text-muted">上传文件的存储目录前缀，文件直接存入该目录，不会再按日期创建子目录</small>
          </div>

          <div class="mb-3">
            <label class="form-label">单文件大小限制 (MB)</label>
            <input type="number" step="0.1" min="0.1" class="form-control" name="s3_max_size" id="f_max_size" placeholder="10" style="max-width:200px;">
          </div>

          <div class="mb-3">
            <label class="form-label">存储选项</label>
            <div class="d-flex flex-wrap gap-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="s3_path_style" id="f_path_style" value="1">
                <label class="form-check-label" for="f_path_style">Path-Style</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="s3_verify_ssl" id="f_verify_ssl" value="1" checked>
                <label class="form-check-label" for="f_verify_ssl">校验 SSL</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="s3_enabled" id="f_enabled" value="1">
                <label class="form-check-label" for="f_enabled">启用</label>
              </div>
            </div>
            <small class="text-muted d-block mt-2">Path-Style：开启后使用 <code>{endpoint}/{bucket}/{key}</code>。SSL 校验：自建 S3 自签名证书可关闭。启用：开启后该存储可被前端上传接口调度。</small>
          </div>

          <input type="hidden" name="action" value="save">
          <input type="hidden" name="edit_id" id="f_edit_id" value="">
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo icon('close'); ?> 取消</button>
        <button type="button" class="btn btn-primary" onclick="doSave()"><?php echo icon('content-save'); ?> <span id="s3SaveBtnText">新增配置</span></button>
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
$.ajaxSetup({ beforeSend: function(xhr){ xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } });
var s3Modal;
var _s3List = []; // 当前列表缓存
var GRADS = <?php echo json_encode($gradColors);?>;

function escHtml(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function toast(type, msg){
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

// 渲染卡片网格
function renderCards(list){
    var grid = $('#s3CardGrid');
    grid.empty();
    _s3List = list || [];

    if(!_s3List.length){
        grid.append('<div class="s3-empty">'+eeIcon('cloud-off-outline')+'<p>暂无 S3 存储配置</p></div>');
    }

    _s3List.forEach(function(cfg, idx){
        var enabled = cfg.enabled === '1';
        var name = escHtml(cfg.name);
        var bucket = escHtml(cfg.bucket);
        var region = escHtml(cfg.region || '');
        var endpoint = escHtml(cfg.endpoint);
        var initChar = name.charAt(0).toUpperCase();
        var grad = GRADS[idx % GRADS.length];

        // 构建标签
        var tags = '';
        if(cfg.path_style === '1') tags += '<span class="chip chip-outline">Path-Style</span>';
        if(cfg.verify_ssl === '1') tags += '<span class="chip chip-outline">SSL</span>';
        else tags += '<span class="chip chip-outline">无 SSL</span>';
        if(cfg.domain) tags += '<span class="chip chip-outline">CDN</span>';
        if(cfg.max_size && cfg.max_size !== '10') tags += '<span class="chip chip-outline">'+escHtml(cfg.max_size)+'MB</span>';

        var card = '<article class="api-card" data-id="'+cfg.id+'">' +
            '<header class="api-card-head">' +
                '<div class="api-logo '+grad+'">'+initChar+'</div>' +
                '<div>' +
                    '<h4>'+name+' <span class="chip '+(enabled?'chip-emerald':'chip-rose')+'">'+(enabled?'运行中':'已禁用')+'</span></h4>' +
                    '<p class="muted">'+region+' · '+bucket+'</p>' +
                '</div>' +
                '<label class="switch">' +
                    '<input type="checkbox" class="s3-toggle"'+(enabled?' checked':'')+' data-id="'+cfg.id+'">' +
                    '<i></i>' +
                '</label>' +
            '</header>' +
            '<div class="api-metrics">' +
                '<div><b>'+bucket+'</b><span>存储桶</span></div>' +
                '<div><b>'+region+'</b><span>区域</span></div>' +
                '<div><b>'+(cfg.max_size||'10')+' MB</b><span>大小限制</span></div>' +
            '</div>' +
            '<footer class="api-card-foot">' +
                tags +
                '<span class="icon-btn-sm act-edit" data-id="'+cfg.id+'" title="编辑">'+eeIcon('pencil')+'</span>' +
                '<span class="icon-btn-sm danger act-delete" data-id="'+cfg.id+'" data-name="'+name+'" title="删除">'+eeIcon('delete')+'</span>' +
            '</footer>' +
            '</article>';

        grid.append(card);
    });

    // 新增卡片
    grid.append(
        '<article class="api-card api-card-add" onclick="openS3Modal()">' +
            '<div class="add-inner">' +
                '<div class="add-icon">'+eeIcon('plus')+'</div>' +
                '<div class="add-text">' +
                    '<h4>接入新存储</h4>' +
                    '<p class="muted">支持 S3 兼容协议 / 自定义域名</p>' +
                '</div>' +
            '</div>' +
        '</article>'
    );

    syncAddCardSize();
}

// 同步新增卡片高度：宽度由网格自动拉伸（与同列存储卡片天然等宽），无需 JS 干预；
// 高度与第一张存储卡片保持一致，避免单独成行时高度不匹配
function syncAddCardSize(){
    var firstCard = $('#s3CardGrid .api-card:not(.api-card-add)').first();
    var addCard = $('#s3CardGrid .api-card-add');
    if(!addCard.length) return;
    if(firstCard.length){
        addCard.css('min-height', firstCard.outerHeight() + 'px');
    } else {
        // 无存储卡片时，新增卡片恢复默认高度
        addCard.css('min-height', '');
    }
}

// 加载列表
function loadS3Configs(){
    $.ajax({
        url:'', type:'POST', data:{action:'list'}, dataType:'json',
        success:function(resp){
            if(resp.code !== 0){
                $('#s3CardGrid').html('<div class="s3-empty">'+eeIcon('alert-circle-outline')+'<p>'+escHtml(resp.msg||'加载失败')+'</p></div>');
                return;
            }
            renderCards(resp.data || []);
        },
        error:function(){
            $('#s3CardGrid').html('<div class="s3-empty">'+eeIcon('alert-circle-outline')+'<p>网络错误，请刷新页面重试</p></div>');
        }
    });
}

// 切换启用/禁用
function toggleEnable(id){
    var cfg = _s3List.find(function(c){ return c.id == id; });
    var cur = cfg ? cfg.enabled === '1' : false;
    var name = cfg ? cfg.name : '';
    var verb = cur ? '禁用' : '启用';

    Swal.fire({
        title:'确认操作',
        text:'确定要'+verb+'「'+name+'」吗？',
        icon:'question',
        showCancelButton:true,
        confirmButtonText:'确认'+verb,
        cancelButtonText:'取消',
        confirmButtonColor: cur ? '#ef4444' : '#10b981',
        cancelButtonColor:'#94a3b8',
        reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST',
            data:{action:'toggle_enable', id:id},
            dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    if(cfg) cfg.enabled = resp.enabled;
                    toast('success', resp.msg);
                    renderCards(_s3List);
                } else {
                    toast('error', resp.msg);
                }
            },
            error:function(){ toast('error', '操作失败，请重试'); }
        });
    });
}

// 打开弹窗
function openS3Modal(id){
    var form = document.getElementById('s3Form');
    form.reset();
    $('#f_edit_id').val('');
    $('#f_secret_key').val('');
    $('#f_path_style').prop('checked', false);
    $('#f_verify_ssl').prop('checked', true);
    $('#f_enabled').prop('checked', false);
    $('#f_secret_hint').hide();
    $('#s3EditAlert').hide();

    if(typeof id !== 'undefined' && id !== null && id !== ''){
        $('#s3ModalTitle').html(eeIcon('pencil')+' 编辑 S3 配置');
        $('#s3SaveBtnText').text('保存修改');
        $.ajax({
            url:'', type:'POST', data:{action:'get', id:id}, dataType:'json',
            success:function(resp){
                if(resp.code !== 0){ toast('error', resp.msg || '配置不存在'); return; }
                var d = resp.data;
                $('#f_edit_id').val(d.id);
                $('#f_name').val(d.name || '');
                $('#f_endpoint').val(d.endpoint || '');
                $('#f_access_key').val(d.access_key || '');
                $('#f_secret_key').val('__KEEP_EXISTING__');
                $('#f_bucket').val(d.bucket || '');
                $('#f_region').val(d.region || '');
                $('#f_domain').val(d.domain || '');
                $('#f_path_prefix').val(d.path_prefix || '');
                $('#f_max_size').val(d.max_size || '10');
                $('#f_path_style').prop('checked', d.path_style === '1');
                $('#f_verify_ssl').prop('checked', d.verify_ssl === '1');
                $('#f_enabled').prop('checked', d.enabled === '1');
                $('#f_secret_hint').show();
                $('#s3EditText').html('正在编辑：<strong>'+escHtml(d.name)+'</strong> —— 密码留空则保持原值不变。');
                $('#s3EditAlert').show();
            },
            error:function(){ toast('error', '加载配置失败，请重试'); }
        });
    } else {
        $('#s3ModalTitle').html(eeIcon('plus-circle')+' 新增 S3 配置');
        $('#s3SaveBtnText').text('新增配置');
    }
    s3Modal.show();
}

// 保存
function doSave(){
    var form = document.getElementById('s3Form');
    var name = form.s3_name.value.trim();
    var endpoint = form.s3_endpoint.value.trim();
    var accessKey = form.s3_access_key.value.trim();
    var secretKey = form.s3_secret_key.value.trim();
    var bucket = form.s3_bucket.value.trim();
    var region = form.s3_region.value.trim();

    if(!name){ toast('warning', '请输入显示名称'); return; }
    if(!endpoint){ toast('warning', '请输入 Endpoint'); return; }
    if(!accessKey){ toast('warning', '请输入 Access Key'); return; }
    if(!secretKey){ toast('warning', '请输入 Secret Key'); return; }
    if(!bucket){ toast('warning', '请输入 Bucket 名称'); return; }
    if(!region){ toast('warning', '请输入 Region'); return; }

    Swal.fire({
        title:'确认保存', text:'确定要保存当前 S3 存储配置吗？',
        icon:'question', showCancelButton:true,
        confirmButtonText:'确认保存', cancelButtonText:'取消',
        confirmButtonColor:'#6366f1', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        var formData = new FormData(form);
        $.ajax({
            url:'', type:'POST', data:formData, processData:false, contentType:false, dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    s3Modal.hide();
                    toast('success', resp.msg || '保存成功！');
                    loadS3Configs();
                } else {
                    toast('error', resp.msg || '保存失败');
                }
            },
            error:function(){ toast('error', '保存失败，请重试！'); }
        });
    });
}

// 删除
function deleteConfig(id, name){
    Swal.fire({
        title:'确认删除',
        text:'确定要删除 S3 存储配置「' + name + '」吗？此操作不可恢复！',
        icon:'warning', showCancelButton:true,
        confirmButtonText:'确认删除', cancelButtonText:'取消',
        confirmButtonColor:'#ef4444', cancelButtonColor:'#94a3b8', reverseButtons:true
    }).then(function(result){
        if(!result.isConfirmed) return;
        $.ajax({
            url:'', type:'POST', data:{action:'delete', id:id}, dataType:'json',
            success:function(resp){
                if(resp.code === 0){
                    toast('success', resp.msg || '删除成功！');
                    loadS3Configs();
                } else {
                    toast('error', resp.msg || '删除失败！');
                }
            },
            error:function(){ toast('error', '删除失败，请重试！'); }
        });
    });
}

// 页面就绪
document.addEventListener('DOMContentLoaded', function(){
    var modalEl = document.getElementById('s3EditModal');
    s3Modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);

    // 事件委托：启用/禁用开关
    $(document).on('change', '.s3-toggle', function(){
        var id = $(this).data('id');
        if(typeof id !== 'undefined') toggleEnable(id);
    });

    // 事件委托：编辑
    $(document).on('click', '.act-edit', function(e){
        e.stopPropagation();
        var id = $(this).data('id');
        if(typeof id !== 'undefined') openS3Modal(id);
    });

    // 事件委托：删除
    $(document).on('click', '.act-delete', function(e){
        e.stopPropagation();
        var id = $(this).data('id');
        var name = $(this).data('name') || '';
        if(typeof id !== 'undefined') deleteConfig(id, name);
    });

    // 窗口缩放时同步新增卡片高度
    var resizeTimer;
    $(window).on('resize', function(){
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function(){
            requestAnimationFrame(syncAddCardSize);
        }, 100);
    });

    // 侧栏折叠等容器尺寸变化不触发 window resize，用 ResizeObserver 兜底同步
    var s3GridEl = document.getElementById('s3CardGrid');
    if(window.ResizeObserver && s3GridEl){
        new ResizeObserver(function(){ syncAddCardSize(); }).observe(s3GridEl);
    }

    loadS3Configs();
});
</script>
</body>
</html>
