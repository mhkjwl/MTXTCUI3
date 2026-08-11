<?php
/**
 * @file love.php
 * @description 点赞功能处理，基于 IP 去重并更新点赞计数
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

	@header('Content-Type: text/html; charset=UTF-8');
	require ('./common.php');
	// 复用 common.php 已建立的数据库连接
	$db = $DB->link;

	mysqli_query($db, "set names utf8mb4");
	$ip = isset($_SERVER["REMOTE_ADDR"]) ? mysqli_real_escape_string($db, $_SERVER["REMOTE_ADDR"]) : '';//获取点赞者ip
	$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

	if(!isset($id) || empty($id)) exit;

	$sql_ip = mysqli_query($db, "select love_ip from eecms_love where love_id = '$id' and love_ip = '$ip'"); //获取点赞记录
	$count = mysqli_num_rows($sql_ip);
	if($count == 0){ //如果没有记录
	   	$sql = "update eecms_list set love=love+1 where id='$id'"; //更新点赞数量
	    mysqli_query($db, $sql);
	    $sql_in = "insert into eecms_love (love_id,love_ip) values ('$id','$ip')"; //记录点赞id以及ip
	    mysqli_query($db, $sql_in);
	    $result = mysqli_query($db, "select love from eecms_list where id='$id'");
	    $row = mysqli_fetch_array($result);
	    $love = $row['love']; //获取点赞数量
	    echo "<span class=\"love-active\"><i class=\"fa fa-heart fa-fw\" aria-hidden=\"true\"></i> " . intval($love) . "</span>";
	}else{
	    echo "<span class=\"love-active\"><i class=\"fa fa-heart fa-fw\" aria-hidden=\"true\"></i> 赞过了</span>";
	}
?>