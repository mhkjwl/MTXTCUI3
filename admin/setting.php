<?php
/**
 * @file setting.php
 * @description 网站设置页面，管理站点名称、标题、联系邮箱、ICP备案号、SEO关键词描述及弹窗通知等基础信息
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

// 初始化配置（GET 首次访问时也需要读取）
$conf = array();
$rs = $DB->query("select * from eecms_config");
while($rs && ($row = $DB->fetch($rs))) {
    $conf[$row['name']] = $row['main'];
}

$toast_icon = ''; $toast_title = '';
if(isset($_POST['action'])) {
    if(!csrf_verify()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 1, 'msg' => '安全校验失败，请刷新页面后重试！']);
        exit;
    }
    // 字段白名单：只允许保存以下配置项，防止注入 admin_pwd 等敏感字段
    $allowedFields = ['name', 'title', 'email', 'icp', 'time', 'keywords', 'description', 'jieshao', 'Copyright'];
    foreach ($allowedFields as $name) {
        if(!isset($_POST[$name])) continue;
        $value = $_POST[$name];
        $ok = $DB->query_prepared("INSERT INTO eecms_config SET `name`=?, `main`=? ON DUPLICATE KEY UPDATE `main`=?", 'sss', [$name, $value, $value]);
        if($ok === false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 1, 'msg' => '配置项 '.$name.' 保存失败']);
            exit;
        }
    }
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $conf = array();
        foreach(pkg_safe_get_all($DB, "select * from eecms_config") as $row) {
            $conf[$row['name']] = $row['main'];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'msg' => '网站设置修改成功！']);
        exit;
    }
    $toast_icon = 'success'; $toast_title = '网站设置修改成功！';
    // 重新读取最新配置（覆盖表单前的旧值）
    $conf = array();
    $rs = $DB->query("select * from eecms_config");
    while($rs && ($row = $DB->fetch($rs))) {
        $conf[$row['name']] = $row['main'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh" data-theme="light">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('admin_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>网站设置 - <?php echo $lang->admin->title;?></title>
<link rel="stylesheet" href="style/css/admin.css?v=20260810e">
<style>html,body{height:100%;margin:0}body{overflow:auto}.admin-content{padding:16px 28px!important}</style>
<link rel="stylesheet" href="style/css/sweetalert2.min.css">
<link rel="stylesheet" href="style/css/ui3-dialog.css">
</head>
<body>
<?php echo icon_sprite(); ?>
<div class="admin-content">

  <div class="page-title">
    <?php echo icon('cog-outline'); ?> <?php echo $lang->admin->setting;?>
  </div>

  <div class="row">
    <div class="col-lg-12">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><?php echo icon('cog-outline'); ?> <?php echo $lang->admin->setting;?></div>
        </div>
        <div class="card-body">
          <form id="mainForm" method="post">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">网站名称</label><input value="<?php echo htmlspecialchars($conf['name'], ENT_QUOTES);?>" type="text" class="form-control" name="name"></div>
              <div class="col-md-6"><label class="form-label">网站标题</label><input value="<?php echo htmlspecialchars($conf['title'], ENT_QUOTES);?>" type="text" class="form-control" name="title"></div>
              <div class="col-md-6"><label class="form-label">联系邮箱</label><input value="<?php echo htmlspecialchars($conf['email'], ENT_QUOTES);?>" type="text" class="form-control" name="email" placeholder="选填，显示在前端快速指南卡片里"></div>
              <div class="col-md-6"><label class="form-label">ICP备案号</label><input value="<?php echo htmlspecialchars($conf['icp'], ENT_QUOTES);?>" type="text" class="form-control" name="icp"></div>
              <div class="col-md-6"><label class="form-label">警告</label><input value="<?php echo htmlspecialchars($conf['Copyright'], ENT_QUOTES);?>" type="text" class="form-control" name="Copyright" placeholder="以固定横幅显示在前端页面顶部，留空则不显示"></div>
              <div class="col-md-6"><label class="form-label">建站时间</label><input value="<?php echo htmlspecialchars($conf['time'], ENT_QUOTES);?>" type="date" class="form-control" name="time"></div>
              <div class="col-md-6"><label class="form-label">网站关键词</label><textarea rows="2" class="form-control" name="keywords"><?php echo htmlspecialchars($conf['keywords'], ENT_QUOTES, 'UTF-8');?></textarea></div>
              <div class="col-md-6"><label class="form-label">网站描述</label><textarea rows="2" class="form-control" name="description"><?php echo htmlspecialchars($conf['description'], ENT_QUOTES, 'UTF-8');?></textarea></div>
              <div class="col-12"><label class="form-label">弹窗通知</label><textarea rows="2" class="form-control" name="jieshao" placeholder="内容为空时前端不显示；有内容时用户首次打开网站弹出，关闭后不再弹出，直到内容修改后重新弹出"><?php echo htmlspecialchars($conf['jieshao'], ENT_QUOTES, 'UTF-8');?></textarea></div>
            </div>
            <div class="mt-4">
              <button type="button" class="btn btn-primary" onclick="doSave()"><?php echo icon('content-save'); ?> 保存设置</button>
            </div>
            <input type="hidden" name="action" value="1">
          </form>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="style/js/sweetalert2.min.js"></script>
<script src="style/js/ui3-dialog.js"></script>
<script src="style/js/cmodal.js"></script>
<script>
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

function doSave(){
    Swal.fire({
        title: '确认修改',
        text: '确定要保存当前网站设置吗？',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '确认保存',
        cancelButtonText: '取消',
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then(function(result){
        if(result.isConfirmed){
            var form = document.getElementById('mainForm');
            var formData = new FormData(form);
            fetch('setting.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': '<?php echo csrf_token();?>' },
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                toast(data.code === 0 ? 'success' : 'error', data.msg, 3000);
            })
            .catch(function() {
                toast('error', '网络错误，请重试', 3000);
            });
        }
    });
}
</script>
<?php if($toast_title): ?>
<script>
toast(<?php echo json_encode($toast_icon);?>, <?php echo json_encode($toast_title, JSON_UNESCAPED_UNICODE);?>, 3000);
</script>
<?php endif; ?>
</body>
</html>