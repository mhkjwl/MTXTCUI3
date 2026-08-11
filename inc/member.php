<?php
/**
 * @file member.php
 * @description 管理员登录态校验，解析 admin_token cookie 并验证会话
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined('IN_CRONLITE'))exit();

$clientip=isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

// 兼容PHP8：确保 $password_hash 已定义
if(!isset($password_hash)) $password_hash = '';

if(isset($_COOKIE["admin_token"]))
{
	$token=authcode(daddslashes($_COOKIE['admin_token']), 'DECODE', SYS_KEY);
	$parts = explode("\t", $token);
	$user = isset($parts[0]) ? $parts[0] : '';
	$sid  = isset($parts[1]) ? $parts[1] : '';
	// M2 修复：会话令牌改用 hash_hmac('sha256')，与 login.php 同步
	// 旧版用 md5($user.$stored.$password_hash) 违反"MD5/SHA1 禁止"硬约束
	$session=hash_hmac('sha256', $conf['admin_user'].$conf['admin_pwd'], SYS_KEY);
	if(hash_equals($session, $sid)) {
		$islogin=1;
	}
}
?>