<?php
/**
 * @file 404.php
 * @description 404 页面不存在错误页，返回 404 状态码并渲染提示界面
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

http_response_code(404);
require (dirname(__FILE__) . '/header.php');
$siteName404 = isset($conf['name']) ? $conf['name'] : '图床';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 - 页面不存在 - <?php echo htmlspecialchars($siteName404);?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, 'Segoe UI', 'Microsoft YaHei', sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 40px 20px;
}
.text--line { font-size: 120px; fill: none; stroke: #e74c3c; stroke-width: 2; stroke-dasharray: 500; animation: dash 4s ease-in-out forwards; }
.text--line2 { font-size: 30px; fill: #999; }
.text-copy { fill: none; stroke: #e74c3c; stroke-width: 1; stroke-dasharray: 500; animation: dash 4s ease-in-out forwards; opacity: 0.3; }
.g-ants { animation: fadeIn 1s ease-in-out; }
@keyframes dash {
    from { stroke-dashoffset: 500; }
    to { stroke-dashoffset: 0; }
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.back-home {
    margin-top: 24px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 24px;
    background: #6d4aff;
    color: #fff;
    text-decoration: none;
    border-radius: 10px;
    font-size: 14px;
    transition: all .25s;
}
.back-home:hover { background: #5a3ae0; transform: translateY(-1px); }
</style>
</head>
<body>

<div class="main">
    <svg viewBox="0 -40 600 320" style="max-width:480px;width:100%;">
        <symbol id="s-text">
            <text text-anchor="middle" x="50%" y="40%" class="text--line">404</text>
            <text text-anchor="middle" x="50%" y="60%" class="text--line2">Not Found</text>
        </symbol>
        <g class="g-ants">
            <use xlink:href="#s-text" class="text-copy"></use>
            <use xlink:href="#s-text" class="text-copy"></use>
            <use xlink:href="#s-text" class="text-copy"></use>
            <use xlink:href="#s-text" class="text-copy"></use>
            <use xlink:href="#s-text" class="text-copy"></use>
        </g>
    </svg>
    <a class="back-home" href="/">返回首页</a>
</div>

<?php require (dirname(__FILE__) . '/footer.php'); ?>
</body>
</html>
