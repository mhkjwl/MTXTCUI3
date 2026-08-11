<?php
/**
 * @file lang.php
 * @description 多语言文案定义（前台、后台、通用）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

$lang = new stdClass(); // 初始化$lang为一个对象
$lang->index = new stdClass(); // 初始化$lang->index为一个对象
$lang->admin = new stdClass(); // 初始化$lang->admin为一个对象
$lang->all = new stdClass(); // 初始化$lang->all为一个对象

	//前台--开始

	$lang->index->index = '首页';
	$lang->index->about = '关于我们';
    $lang->index->search = '搜索结果';
    $lang->index->apply = '站点提交';
    $lang->index->nofound = '404';

	//前台--结束


	//后台--开始

	$lang->admin->login = '后台登录';
	$lang->admin->title = '管理中心';
	$lang->admin->footer = '牧皇网络. All Rights Reserved.';

	$lang->admin->index = '后台首页';
	$lang->admin->set = '系统设置';
	$lang->admin->setting = '网站信息';
	$lang->admin->user = '账号信息';
	$lang->admin->upimg = '上传图片';
	$lang->admin->logout = '退出登录';

	$lang->admin->favicon = 'favicon图标';
	$lang->admin->api = 'API接口设置';

	//后台--结束


	//综合--开始

	$lang->all->name = 'Eecms管理系统';
	$lang->all->edition = 'V1.0';

	//综合--结束

?>