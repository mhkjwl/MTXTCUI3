<?php
/**
 * @file access.php
 * @description 访问控制配置页面，设置未登录用户（访客）可使用的接口分组及是否强制隐藏本地上传入口
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

/**
 * 访问控制配置页面
 *
 * 配置项（存储于 eecms_config 表）：
 *   guest_group_id   - 未登录用户可用的接口分组ID（0=不限制，跟随后台启用的接口）
 *   guest_hide_local - 是否强制隐藏未登录用户的本地上传入口（1=隐藏，0=不隐藏）
 */

// json_exit 函数：统一 JSON 响应并终止
function json_exit($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== AJAX 保存配置 ==========
if(isset($_POST['action']) && $_POST['action'] === 'save') {
    if(!csrf_verify()) json_exit(1, '安全校验失败，请刷新页面后重试！');

    $guestGroupId  = intval($_POST['guest_group_id'] ?? 0);
    // checkbox 未勾选时不会随表单提交，需显式判断 isset
    $guestHideLocal = isset($_POST['guest_hide_local']) ? intval($_POST['guest_hide_local']) : 0;
    // 规范化：hide_local 只允许 0 / 1
    if($guestHideLocal !== 0 && $guestHideLocal !== 1) $guestHideLocal = 0;

    // 校验分组是否存在（0 表示不限制，跳过校验）
    if($guestGroupId > 0) {
        $grpExists = pkg_safe_get_row_prepared($DB, "SELECT id FROM eecms_api_groups WHERE id=?", 'i', [$guestGroupId]);
        if(!$grpExists) json_exit(1, '所选接口分组不存在，请刷新页面后重试！');
    }

    // 保存到 eecms_config 表（upsert 模式）
    $configs = [
        'guest_group_id'   => (string)$guestGroupId,
        'guest_hide_local' => (string)$guestHideLocal,
    ];
    foreach($configs as $key => $val) {
        // L9 修复：改用 INSERT ... ON DUPLICATE KEY UPDATE 消除查-插竞态
        $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?,`main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$key, $val, $val]);
        if($ok === false) json_exit(1, '配置保存失败，请重试！');
    }

    // 重新加载配置到 $conf（保证后续逻辑使用最新值）
    $confRows = pkg_safe_get_all($DB, "SELECT * FROM eecms_config");
    $conf = [];
    foreach($confRows as $row) {
        $conf[$row['name']] = $row['main'];
    }

    // 记录管理员操作日志
    if(function_exists('log_admin_action')) {
        log_admin_action($DB, 'access_config_save', 'config', 0, [
            'guest_group_id'   => $guestGroupId,
            'guest_hide_local' => $guestHideLocal,
        ]);
    }

    json_exit(0, '配置保存成功');
}

// ========== 加载当前配置 ==========
$guestGroupId   = isset($conf['guest_group_id']) ? (int)$conf['guest_group_id'] : 0;
$guestHideLocal = isset($conf['guest_hide_local']) ? $conf['guest_hide_local'] : '0';
$guestHideLocalChecked = ($guestHideLocal === '1' || $guestHideLocal === 1);

// ========== 查询所有接口分组 ==========
$groups = pkg_safe_get_all($DB, "SELECT * FROM eecms_api_groups g ORDER BY g.id ASC");

// ========== 统计各分组当前"已启用"的接口数量（与接口分组管理页、访客实际可用口径一致）==========
// 接口在「接口管理」中被关闭后，绑定记录仍保留在 eecms_api_group_items 中，
// 直接 COUNT(*) 会把已关闭的接口也算进去，需结合 eecms_config 的启用状态过滤
$allGroupItems = pkg_safe_get_all($DB, "SELECT group_id, api_type, is_s3, s3_id FROM eecms_api_group_items");
$enabledCountMap = [];
foreach($allGroupItems as $item) {
    $gid = (int)$item['group_id'];
    if(!isset($enabledCountMap[$gid])) $enabledCountMap[$gid] = 0;
    if((int)$item['is_s3'] === 1) {
        if(is_s3_config_enabled($conf, (int)$item['s3_id'])) $enabledCountMap[$gid]++;
    } else {
        if(is_api_enabled($conf, $item['api_type'])) $enabledCountMap[$gid]++;
    }
}
foreach($groups as &$g) {
    $g['items_count'] = isset($enabledCountMap[(int)$g['id']]) ? $enabledCountMap[(int)$g['id']] : 0;
}
unset($g);
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>访问控制 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('shield-lock-outline'); ?> 访问控制
  </div>

  <div class="row">
    <div class="col-lg-8">
      <!-- 当前配置状态 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('shield-check-outline'); ?> 当前配置状态</div>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <div class="d-flex align-items-center" style="gap:14px;padding:14px;border-radius:10px;background:#f8fafc;border:1px solid var(--color-border);">
                <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#3b82f6;"><?php echo icon('format-list-group'); ?></span>
                </div>
                <div>
                  <div style="font-size:12px;color:var(--color-text-muted);">访客可用接口分组</div>
                  <div style="font-size:15px;font-weight:600;color:var(--color-text-primary);margin-top:2px;" id="statusGroupName">
                    <?php if($guestGroupId > 0): ?>
                      <?php
                        $grpName = '';
                        foreach($groups as $g){ if((int)$g['id']===$guestGroupId){ $grpName = $g['name']; break; } }
                      ?>
                      <?php echo $grpName ? htmlspecialchars($grpName) : '分组(ID:'.$guestGroupId.')'; ?>
                    <?php else: ?>
                      不限制（跟随系统启用接口）
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="d-flex align-items-center" style="gap:14px;padding:14px;border-radius:10px;background:#f8fafc;border:1px solid var(--color-border);">
                <div style="width:44px;height:44px;border-radius:10px;background:<?php echo $guestHideLocalChecked?'#fef2f2':'#f0fdf4';?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:<?php echo $guestHideLocalChecked?'#ef4444':'#10b981';?>;"><?php echo icon($guestHideLocalChecked?'cloud-off-outline':'cloud-upload-outline'); ?></span>
                </div>
                <div>
                  <div style="font-size:12px;color:var(--color-text-muted);">访客本地上传</div>
                  <div style="font-size:15px;font-weight:600;color:var(--color-text-primary);margin-top:2px;" id="statusHideLocal">
                    <?php echo $guestHideLocalChecked ? '已强制隐藏' : '允许显示'; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 配置表单 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('cog-outline'); ?> 访问控制配置</div>
        </div>
        <div class="card-body">
          <div class="alert alert-info"><?php echo icon('information-outline'); ?> 配置未登录用户（访客）可使用的图床接口分组与本地上传权限，修改后点击保存即可生效。</div>

          <form id="accessConfigForm">
            <!-- 接口分组选择 -->
            <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
              <div class="d-flex align-items-start" style="gap:14px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#3b82f6;"><?php echo icon('format-list-group'); ?></span>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:600;color:#1e293b;">访客可用接口分组</div>
                  <div style="font-size:13px;color:#64748b;margin-top:2px;">选择未登录用户可使用的接口分组。选择「不限制」时访客可使用后台所有已启用的接口。</div>
                </div>
              </div>
              <div style="min-width:220px;">
                <select class="form-select" name="guest_group_id" id="selectGuestGroup" style="font-size:14px;">
                  <option value="0" <?php echo $guestGroupId===0?'selected':'';?>>不限制（跟随系统）</option>
                  <?php foreach($groups as $g): ?>
                    <option value="<?php echo (int)$g['id'];?>" <?php echo $guestGroupId===(int)$g['id']?'selected':'';?>><?php echo htmlspecialchars($g['name']);?>（<?php echo (int)$g['items_count'];?>个接口）</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- 本地上传隐藏开关 -->
            <div class="d-flex align-items-center justify-content-between py-3">
              <div class="d-flex align-items-start" style="gap:14px;">
                <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                  <span style="font-size:22px;color:#f59e0b;"><?php echo icon('cloud-off-outline'); ?></span>
                </div>
                <div>
                  <div style="font-size:15px;font-weight:600;color:#1e293b;">强制隐藏访客本地上传</div>
                  <div style="font-size:13px;color:#64748b;margin-top:2px;">开启后未登录用户将无法使用本地上传接口，即使该接口在所选分组中已启用。建议开启以节省服务器存储空间。</div>
                </div>
              </div>
              <div class="form-check form-switch" style="margin-bottom:0;">
                <input class="form-check-input" type="checkbox" name="guest_hide_local" value="1" id="switchGuestHideLocal" <?php echo $guestHideLocalChecked?'checked':'';?>>
                <label class="form-check-label" for="switchGuestHideLocal"></label>
              </div>
            </div>

            <!-- 保存按钮 -->
            <div class="mt-3 d-flex gap-2 align-items-center">
              <button type="button" class="btn btn-primary" onclick="saveConfig()"><?php echo icon('content-save'); ?> 保存配置</button>
            </div>
            <input type="hidden" name="action" value="save">
          </form>
        </div>
      </div>

      <!-- 接口分组列表 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('folder-multiple-outline'); ?> 接口分组列表（共 <?php echo count($groups);?> 个）</div>
        </div>
        <div class="card-body" style="padding:0;">
          <?php if(empty($groups)): ?>
          <div class="empty-state">
            <?php echo icon('folder-off-outline'); ?>
            <p>暂无接口分组</p>
            <p style="font-size:13px;">请先在「接口分组管理」中创建分组</p>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle" style="margin-bottom:0;">
              <thead>
                <tr style="background:#f8fafc;">
                  <th style="font-size:13px;font-weight:600;color:var(--color-text-muted);padding:10px 16px;width:60px;">ID</th>
                  <th style="font-size:13px;font-weight:600;color:var(--color-text-muted);padding:10px 16px;">分组名称</th>
                  <th style="font-size:13px;font-weight:600;color:var(--color-text-muted);padding:10px 16px;">描述</th>
                  <th style="font-size:13px;font-weight:600;color:var(--color-text-muted);padding:10px 16px;width:120px;">接口数量</th>
                  <th style="font-size:13px;font-weight:600;color:var(--color-text-muted);padding:10px 16px;width:110px;">当前状态</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($groups as $g): ?>
                <tr>
                  <td style="padding:10px 16px;font-size:13px;color:var(--color-text-muted);"><?php echo (int)$g['id'];?></td>
                  <td style="padding:10px 16px;font-size:14px;font-weight:600;color:var(--color-text-primary);"><?php echo htmlspecialchars($g['name']);?></td>
                  <td style="padding:10px 16px;font-size:13px;color:var(--color-text-muted);"><?php echo $g['description']?htmlspecialchars($g['description']):'<span class="text-muted">-</span>';?></td>
                  <td style="padding:10px 16px;">
                    <span class="badge bg-primary-subtle text-primary" style="font-size:12px;padding:4px 10px;border-radius:8px;"><?php echo icon('power-plug', 'me-1'); ?><?php echo (int)$g['items_count'];?> 个</span>
                  </td>
                  <td style="padding:10px 16px;">
                    <?php if($guestGroupId===(int)$g['id']): ?>
                      <span class="badge bg-success-subtle text-success" style="font-size:12px;padding:4px 10px;border-radius:8px;"><?php echo icon('check-circle', 'me-1'); ?>已选用</span>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:12px;">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <!-- 安全提示 -->
      <div class="card mb-4">
        <div class="card-header">
          <div class="card-title"><?php echo icon('alert-outline'); ?> 安全提示</div>
        </div>
        <div class="card-body">
          <div class="alert alert-warning mb-0" style="font-size:13px;line-height:1.8;">
            <p style="margin-bottom:8px;"><?php echo icon('shield-alert-outline'); ?> <strong>访客权限控制</strong>：未登录用户默认可使用后台所有已启用的接口，建议通过指定分组限制其可用范围。</p>
            <p style="margin-bottom:8px;"><?php echo icon('database-alert-outline'); ?> <strong>本地上传风险</strong>：本地上传会占用服务器存储空间，建议对访客强制隐藏本地上传，避免被恶意利用导致存储耗尽。</p>
            <p style="margin-bottom:8px;"><?php echo icon('account-key-outline'); ?> <strong>与上传设置联动</strong>：若在「注册设置」中开启了「上传需要登录」，则访客无法上传，此页面配置仅在允许游客上传时生效。</p>
            <p style="margin-bottom:0;"><?php echo icon('link-variant'); ?> <strong>分组管理</strong>：接口分组在「接口分组管理」页面创建与维护，此处仅选择访客可用的分组。</p>
          </div>
        </div>
      </div>

      <!-- 使用说明 -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><?php echo icon('help-circle-outline'); ?> 使用说明</div>
        </div>
        <div class="card-body">
          <div style="font-size:13px;color:var(--color-text-muted);line-height:1.9;">
            <p style="margin-bottom:6px;"><?php echo icon('chevron-right'); ?> <strong>不限制（跟随系统）</strong>：访客可使用后台所有已启用的图床接口。</p>
            <p style="margin-bottom:6px;"><?php echo icon('chevron-right'); ?> <strong>指定分组</strong>：访客仅可使用所选分组中包含的接口，适合限制访客使用特定图床。</p>
            <p style="margin-bottom:6px;"><?php echo icon('chevron-right'); ?> <strong>隐藏本地上传</strong>：无论分组是否包含本地上传，开启后访客均无法使用本地上传。</p>
            <p style="margin-bottom:0;"><?php echo icon('chevron-right'); ?> <strong>已登录用户</strong>：不受此页面配置影响，其权限由所属套餐等级决定。</p>
          </div>
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
var CSRF_TOKEN = '<?php echo csrf_token();?>';
// M5 修复：CSRF Token 统一通过 Header 传递，不再放 POST Body
$.ajaxSetup({ beforeSend: function(xhr){ xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } });

// 轻提示（toast）
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

// 保存配置
function saveConfig(){
    Swal.fire({
        title: '确认保存',
        text: '确定要保存当前访问控制配置吗？',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;

        var form = document.getElementById('accessConfigForm');
        var formData = new FormData(form);

        $.ajax({
            url: 'access.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(resp){
                if(resp.code === 0){
                    toast('success', resp.msg);
                    // 更新顶部状态显示
                    updateStatusDisplay();
                } else {
                    toast('error', resp.msg, 4000);
                }
            },
            error: function(){
                toast('error', '网络错误，请重试');
            }
        });
    });
}

// 更新当前配置状态显示（保存后同步）
function updateStatusDisplay(){
    var select = document.getElementById('selectGuestGroup');
    var groupId = parseInt(select.value, 10);
    var groupName = groupId > 0 ? select.options[select.selectedIndex].text : '不限制（跟随系统启用接口）';
    // 去掉分组名后缀的接口数量括号
    var nameOnly = groupId > 0 ? groupName.replace(/（\d+个接口）$/, '') : groupName;
    document.getElementById('statusGroupName').textContent = nameOnly;

    var hideLocal = document.getElementById('switchGuestHideLocal').checked;
    var statusEl = document.getElementById('statusHideLocal');
    statusEl.textContent = hideLocal ? '已强制隐藏' : '允许显示';
    // 更新图标容器颜色
    var iconBox = statusEl.closest('.d-flex').querySelector('div[style*="border-radius:10px"]');
    var iconSpan = iconBox.querySelector('span');
    if(hideLocal){
        iconBox.style.background = '#fef2f2';
        iconSpan.style.color = '#ef4444';
        iconSpan.innerHTML = eeIcon('cloud-off-outline');
    } else {
        iconBox.style.background = '#f0fdf4';
        iconSpan.style.color = '#10b981';
        iconSpan.innerHTML = eeIcon('cloud-upload-outline');
    }
}
</script>
</body>
</html>
