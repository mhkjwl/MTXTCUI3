<?php
/**
 * @file submit.php
 * @description 站点提交处理，含 CSRF 校验与数据入库
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

@header('Content-Type: text/html; charset=UTF-8');
require ('./common.php');
// 复用 common.php 已建立的数据库连接，避免冗余连接
$db = $DB->link;

if(isset($_POST['submit'])){
    // CSRF 校验
    if(!function_exists('csrf_verify') || !csrf_verify()){
        $type = 'error'; $msg = '安全校验失败，请刷新页面后重试！'; $action = 'history.go(-1)';
    } else {
    mysqli_query($db, "set names utf8mb4");
    $name = isset($_POST['name']) ? mysqli_real_escape_string($db, $_POST['name']) : '';
    $img = isset($_POST['img']) ? mysqli_real_escape_string($db, $_POST['img']) : '';
    $sortname = isset($_POST['sortname']) ? mysqli_real_escape_string($db, $_POST['sortname']) : '';
    $url = isset($_POST['url']) ? mysqli_real_escape_string($db, $_POST['url']) : '';
    $introduce = isset($_POST['introduce']) ? mysqli_real_escape_string($db, $_POST['introduce']) : '';

    $sql = "select * from eecms_list where url ='$url'";
    $result = mysqli_query($db, $sql);
    $count_list = mysqli_num_rows($result);
    $sql = "select * from eecms_apply where url ='$url'";
    $result = mysqli_query($db, $sql);
    $count_apply = mysqli_num_rows($result);

    if($count_list != 0){
        $type = 'warning'; $msg = '该站点已经存在，请勿重复提交！'; $action = 'history.go(-1)';
    }elseif($count_apply != 0){
        $type = 'warning'; $msg = '该站点已提交过，请勿重复提交！'; $action = 'history.go(-1)';
    }else{
        $sql = "insert into eecms_apply (name,img,sortname,url,introduce) values('$name','$img','$sortname','$url','$introduce')";
        mysqli_query($db, $sql);
        $type = 'success'; $msg = '提交成功，请耐心等待审核！'; $action = "location='../index.php'";
    }
    }
} else {
    $type = 'info'; $msg = ''; $action = "location='../index.php'";
}

// 统一Toastr通知输出
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css">
</head>
<body>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.js"></script>
<script>
toastr.options = {
    positionClass: 'toast-top-center',
    closeButton: true,
    progressBar: true,
    timeOut: 2000
};
<?php if(!empty($msg)): ?>
toastr.<?php echo $type; ?>(<?php echo json_encode($msg, JSON_UNESCAPED_UNICODE); ?>);
<?php endif; ?>
setTimeout(function(){ <?php echo $action; ?>; }, 1500);
</script>
</body>
</html>