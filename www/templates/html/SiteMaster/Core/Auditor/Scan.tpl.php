<?php
$previous_scan = $context->getPreviousScan();
$pages = $context->getPages();
?>

<section class="scan">
    <header>
        <h2>Scan: <?php echo date("n-j-y g:i a", strtotime($context->start_time)); ?></h2>
        <div class="sub-info">
            Status: <?php echo $context->status;?>
        </div>
    </header>
    <div class="wdn-grid-set dashboard-metrics">
        <div class="bp1-wdn-col-one-third">
                <div class="visual-island gpa">
                    <span class="dashboard-value"><?php echo $context->gpa ?></span>
                    <span class="dashboard-metric">GPA</span>
                </div>
        </div>
        <div class="bp1-wdn-col-one-third">
            <div class="visual-island">
                <span class="dashboard-value date"><?php echo $context->getABSNumberOfChanges() ?></span>
                <span class="dashboard-metric">Changes</span>
            </div>
        </div>
        <div class="bp1-wdn-col-one-third">
            <div class="visual-island">
                <span class="dashboard-value date"><?php echo $pages->count() ?></span>
                <span class="dashboard-metric">Pages</span>
            </div>
        </div>
    </div>
</section>
