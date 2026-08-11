<?php
/**
 * @file header.php
 * @description 公共引导文件，加载核心类库 inc/common.php 并初始化运行环境
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

ini_set('display_errors', 0);
// 直接加载核心引导（原经 module.php 中转，module.php 为原导航 CMS 遗留死代码，已移除）
require (dirname(__FILE__) . '/inc/common.php');
