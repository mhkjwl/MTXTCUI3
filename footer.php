<?php
/**
 * @file footer.php
 * @description 页面底部公共模板，展示版权信息与站点运行时长
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);
?>
<div class="container">
    <footer style="text-align: center; padding: 20px 0; color: #666; font-size: 13px;">
        <div class="footer-content">
            <?php if (!empty($conf['Copyright'])): ?>
            <div class="copyright">
                &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($conf['Copyright']); ?> 版权所有
            </div>
            <?php endif; ?>

            <?php if (!empty($conf['time'])): ?>
            <?php
                $startTime = strtotime($conf['time']);
                $now = time();
                $diff = $now - $startTime;
                if ($diff > 0) {
                    $years = floor($diff / (365 * 24 * 3600));
                    $remaining = $diff % (365 * 24 * 3600);
                    $months = floor($remaining / (30 * 24 * 3600));
                    $remaining = $remaining % (30 * 24 * 3600);
                    $days = floor($remaining / (24 * 3600));
                    $runTime = '';
                    if ($years > 0) $runTime .= $years . '年';
                    if ($months > 0) $runTime .= $months . '个月';
                    if ($days > 0) $runTime .= $days . '天';
                    if ($runTime === '') $runTime = '1天';
                } else {
                    $runTime = '0天';
                }
            ?>
            <div class="runtime" style="margin: 5px 0;">
                <i class="mdi mdi-clock-outline"></i> 已稳定运营 <strong style="color: #409eff;"><?php echo $runTime; ?></strong>
            </div>
            <?php endif; ?>

            <?php if (!empty($conf['icp'])): ?>
            <div class="icp" style="margin: 5px 0;">
                <a target="_blank" href="https://beian.miit.gov.cn/" style="text-decoration:none; color: #999;" rel="noopener">
                    <?php echo htmlspecialchars($conf['icp']); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </footer>
</div>
