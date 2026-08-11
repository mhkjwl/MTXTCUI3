<?php
/**
 * @file api/local.php
 * @description 本地存储上传适配器（服务器本地落盘）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
// 编码
header("Content-type:application/json");

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
$tmpName = isset($_FILES["file"]["tmp_name"]) ? $_FILES["file"]["tmp_name"] : '';
if(empty($file) || empty($tmpName) || !is_uploaded_file($tmpName)) {
    echo json_encode(array("code" => 201, "msg" => "未收到上传文件！"));
    exit;
}

// 上传目录
$uploadDirectory = 'upload';
if(!is_dir($uploadDirectory)) {
    @mkdir($uploadDirectory, 0755, true);
}

// 允许的图片真实类型 => 安全后缀（服务端强制，不信任客户端文件名/MIME）
$allowedImage = array(
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
    IMAGETYPE_BMP  => 'bmp',
);

// 服务端二进制校验：必须是真实图片
$imageInfo = @getimagesize($tmpName);
if($imageInfo === false || !isset($imageInfo[2]) || !isset($allowedImage[$imageInfo[2]])) {
    echo json_encode(array("code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的真实图片文件！"));
    exit;
}

// 文件大小限制（10MB）
if($_FILES["file"]["size"] > 10485760) {
    echo json_encode(array("code" => 201, "msg" => "文件大小超出限制！最大只能上传10MB的文件！"));
    exit;
}

// 用服务端判定的安全后缀重命名，杜绝 .php 等可执行后缀
$safeExt = $allowedImage[$imageInfo[2]];
$newfile = uniqid() . '.' . $safeExt;

if(move_uploaded_file($tmpName, $uploadDirectory . "/" . $newfile)) {
    // 构建访问路径
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $path = $protocol . $host . '/api/' . $uploadDirectory . '/' . $newfile;

    $result = array(
        "code" => 200,
        "msg" => "上传成功！",
        "path" => $path
    );
} else {
    $result = array(
        "code" => 201,
        "msg" => "图片保存失败！请检查「api/upload」目录权限是否为777！"
    );
}

// 输出JSON
echo json_encode($result);
?>