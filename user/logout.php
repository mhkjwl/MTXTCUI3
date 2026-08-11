<?php
/**
 * @file logout.php
 * @description 用户登出，清除登录凭证并跳转到登录页
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 用户登出
 * 清除登录 cookie 并跳转到登录页
 */
require('../inc/common.php');

user_logout();

header('Location: login.php');
exit;
