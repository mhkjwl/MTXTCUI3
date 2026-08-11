<?php
/**
 * @file module.php
 * @description 导航分类/站点展示模块（遗留代码，当前由 header.php 直接引导）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require ('./inc/common.php');
require ('./inc/lang.php');
// 复用 common.php 已建立的数据库连接，避免前台每次请求建立两条连接
$db = $DB->link;
// § 5.4.3：禁止通过 GET 传递敏感参数（ID），仅使用公开 alias（slug）查询
// alias 是公开的 URL 友好别名，属非敏感筛选条件，允许 GET 传递
$alias = isset($_GET['alias']) ? $_GET['alias'] : '';

if($alias !== ''){
	$stmt = mysqli_prepare($db, 'SELECT * FROM eecms_sort WHERE alias = ?');
	mysqli_stmt_bind_param($stmt, 's', $alias);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row_sort = mysqli_fetch_array($result);
	mysqli_stmt_close($stmt);

	$stmt = mysqli_prepare($db, 'SELECT * FROM eecms_list WHERE alias = ?');
	mysqli_stmt_bind_param($stmt, 's', $alias);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row_list = mysqli_fetch_array($result);
	mysqli_stmt_close($stmt);
}else{
	$row_sort = false;
	$row_list = false;
}

$sortname = isset($row_list['sortname']) ? $row_list['sortname'] : '';
if($sortname !== ''){
	$stmt = mysqli_prepare($db, 'SELECT * FROM eecms_sort WHERE sortname = ?');
	mysqli_stmt_bind_param($stmt, 's', $sortname);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$rows_sort = mysqli_fetch_array($result);
	mysqli_stmt_close($stmt);
}else{
	$rows_sort = false;
}

// 统计分类/站点数量（纯常量 SQL，无用户输入，安全）
$cntsort = 0;
$cntlist = 0;
$res = mysqli_query($db, 'SELECT COUNT(*) FROM eecms_sort');
if($res){
	$row = mysqli_fetch_row($res);
	$cntsort = $row ? (int)$row[0] : 0;
}
$res = mysqli_query($db, 'SELECT COUNT(*) FROM eecms_list');
if($res){
	$row = mysqli_fetch_row($res);
	$cntlist = $row ? (int)$row[0] : 0;
}
?>

<?php
//输出所有导航（纯常量 SQL，无用户输入）
function nav(){
	global $db;
	$result = mysqli_query($db, "SELECT * FROM eecms_nav ORDER BY nid ASC");
	if(!$result) return;
	while($row = mysqli_fetch_array($result))
    {
?>
    <a target="_blank" href="<?php echo htmlspecialchars($row['url']);?>"
    class="item" style="opacity: 1;">
        <div class="content-wrap">
            <div class="img-wrap">
                <img src="<?php echo htmlspecialchars($row['icon']);?>" alt="">
            </div>
            <p class="app-name">
                <?php echo htmlspecialchars($row['name']);?>
            </p>
        </div>
    </a>
<?php } }?>

<?php
//输出所有分类（纯常量 SQL，无用户输入）
function websort(){
	global $db;
	$result = mysqli_query($db, "SELECT * FROM eecms_sort ORDER BY sid ASC");
	if(!$result) return;
	while($row = mysqli_fetch_array($result))
    {
?>
<li><a href="#<?php echo htmlspecialchars($row['sortname']);?>"><i class="<?php echo htmlspecialchars($row['icon']);?> fa-fw" aria-hidden="true"></i> <?php echo htmlspecialchars($row['sortname']);?></a></li>
<?php } }?>
