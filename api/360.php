<?php
/**
 * @file api/360.php
 * @description 360图床上传适配器（dev.360.cn）
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
    
    // 检查360 Cookie是否已配置
    $cookieValue = isset($conf['api_360_cookie']) ? $conf['api_360_cookie'] : '';
    if(empty($cookieValue) || $cookieValue == '网站信息') {
        $result = array(
            "code" => 201,
            "msg" => "请先在后台API接口设置中填写360 Cookie后再上传图片！"
        );
        echo json_encode($result);
        exit;
    }
     
    // 获取文件
    $file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
    if(empty($file)) {
        $result = array(
            "code" => 201,
            "msg" => "未收到上传文件！"
        );
        echo json_encode($result);
        exit;
    }
     
    // 获取文件后缀名
    $extension = pathinfo($file, PATHINFO_EXTENSION);
    
    // 上传目录
    $uploadDirectory = 'upload';
    
    // 确保上传目录存在
    if(!is_dir($uploadDirectory)) {
        @mkdir($uploadDirectory, 0755, true);
    }
    
    // 重命名
    $newfile = uniqid() . '.' . $extension;
     
    // 允许上传的文件
    $fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
    $allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png"];
    
    if (in_array($fileType, $allowedTypes)) {
        
        // 允许上传的文件大小（10MB）
        if($_FILES["file"]["size"] <= 10485760) {
            
            // 可以上传
            move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDirectory. "/" . $newfile);
            
            // 当前文件在服务器的路径
            $filepath = realpath(dirname(__FILE__)) . "/" . $uploadDirectory . "/" . $newfile;
            
            // 将文件上传至指定服务器
            $ch = curl_init();
            
            // 指定服务器
            curl_setopt($ch, CURLOPT_URL, 'https://dev.360.cn/mod3/upload/img/');
            curl_setopt($ch, CURLOPT_POST, true);
            
            // 设置时区为中国标准时间
            date_default_timezone_set('Asia/Shanghai');

            // 使用date()函数生成日期字符串
            $lastModifiedDate = date('D M d Y H:i:s \G\M\TO (T)', time());
            
            // 参数
            $data = array(
                'id' => 'WU_FILE_0',
                'name' => $file,
                'lastModifiedDate' => $lastModifiedDate,
                'size' => $_FILES["file"]["size"],
                'Filedata' => new CURLFile($filepath, '', $file)
            );
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setup_ssl($ch);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // 登录地址：https://dev.360.cn/mod3/developer/index/submittype/1
            // 登录后按F12打开开发者工具，点击【console】或【控制台】粘贴【document.cookie】回车后就会弹出cookie
            // stripslashes还原：因为daddslashes会addslashes存入，MySQL读取时自动去反斜杠，所以这里不需要stripslashes
            $headers = array();
            $headers[] = "Cookie: " . $cookieValue;

            $headers[] = "Host: dev.360.cn";
            $headers[] = "Origin: https://dev.360.cn";
            $headers[] = "Pragma: no-cache";
            $headers[] = "Referer: https://dev.360.cn/mod3/developer/index/submittype/1";
            
            // 设置请求头
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
            // 发起请求
            $uploadimg = curl_exec($ch);
            
            // 关闭请求
            unset($ch);
            
            // 解析请求结果
            $imgData = json_decode($uploadimg, true);
            $imgUrl = isset($imgData['data']['url']) ? $imgData['data']['url'] : '';
            
            // 验证是否为合法的URL
            if(filter_var($imgUrl, FILTER_VALIDATE_URL)) {
                
                // 上传结果
                $result = array(
                    "code" => 200,
                    "msg" => "上传成功！",
                    "path" => $imgUrl
                );
            }else {
                
                // 上传结果
                $result = array(
                    "code" => 201,
                    "msg" => "图片上传失败！可能是登录失效，可前往Url：https://dev.360.cn/mod3/developer/index/submittype/1 登录后再试！",
                    "debug" => $uploadimg
                );
            }
            
            // 删除临时文件
            @unlink($filepath);
            
        }else {
            
            // 文件大小超出限制
            $result = array(
                "code" => 201,
                "msg" => "文件大小超出限制！最大只能上传10MB的文件！"
            );
        }
    } else {
        
        // 文件类型无效
        $result = array(
            "code" => 201,
            "msg" => "只允许上传gif、jpeg、jpg、png格式的图片文件！"
        );
    }
    
    // 输出JSON
    echo json_encode($result);
    
?>
