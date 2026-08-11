<?php
/**
 * @file sae.php
 * @description SAE（新浪云）环境数据库连接配置
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/*数据库配置*/
$dbconfig=array(
	'host' => SAE_MYSQL_HOST_M, //数据库服务器
	'port' => SAE_MYSQL_PORT, //数据库端口
	'user' => SAE_MYSQL_USER, //数据库用户名
	'pwd' => SAE_MYSQL_PASS, //数据库密码
	'dbname' => SAE_MYSQL_DB, //数据库名
	'dbqz' => 'wjob' //数据表前缀
);
?>