<?php
use SiteMaster\Core\Config;

ini_set('display_errors', true);

//Initialize all settings and autoloaders
require_once(__DIR__ . "/../init.php");

$config_file = __DIR__ . '/../config.inc.php';
$last_modified = filemtime($config_file);

if (!isset($argv[1])) {
    echo "usage: php daemon.php id-of-daemon" . PHP_EOL;
    exit();
}

$daemon_name = $argv[1];

/**
 * TODO:
 * 1. [done] apply an ID to each daemon 'default', 'two', etc. As passed by a command line argument `php daemon.php this-is-the-id`
 * 2. [done] add a `daemon_name` to the scanned_page table
 * 3. [done] Running() should only look at jobs with the same daemon ID (only one worker at a time)
 * 4. [done] Queued() should not return any pages in which there is another page being scanned for the same site (to respect craw time limits)
 * 5. The generated headless files should be renamed to the current daemon worker to prevent concurrency issues
 */

//There should only be one worker running at a time.  If there are running jobs, remove them and try again...
$running = new SiteMaster\Core\Auditor\Site\Pages\Running([
    'daemon_name' => $daemon_name
]);

foreach ($running as $page) {
    //Log it
    SiteMaster\Core\Util::log(Monolog\Logger::NOTICE, 'Re-scheduling running job: pages.id=' . $page->id);
    
    if ($page->tries >= 3) {
        //Give up, and mark it as an error
        $page->markAsError();
        continue;
    }
    
    $page->rescheduleScan();
}

//Regenerate the compiled headless script to account for changes
$headless_runner = new \SiteMaster\Core\Auditor\HeadlessRunner();
$headless_runner->deleteCompiledScript();

$total_incomplete = 0;
$total_checked    = 0;
$current_site     = false;
while (true) {
    clearstatcache(false, $config_file);
    //Check the last modified time to see if we need to load a new config
    if (filemtime($config_file) > $last_modified) {
        SiteMaster\Core\Util::log(Monolog\Logger::NOTICE, 'stopping daemon due to a change in config.inc.php');
        exit(10);
    }
    
    //Get the queue
    $running = new SiteMaster\Core\Auditor\Site\Pages\Running();
    $running_scan_ids = [];
    foreach ($running as $page) {
        $running_scan_ids[] = $page->scans_id;
    }
    
    $queue = new SiteMaster\Core\Auditor\Site\Pages\Queued([
        'not_in_scans' => $running_scan_ids
    ]);
    
    if (!$queue->count()) {
        //Reset the cached robots.txt
        \Spider_Filter_RobotsTxt::$robotstxt = array();
        
        //Sleep for 10 seconds
        sleep(10);
        
        //Check again.
        continue;
    }

    if ($total_checked >= Config::get('RESTART_INTERVAL')) {
        /**
         * Do a routine restart of the daemon after a few pages have been scanned.  Metrics and plugins can cause problems over, and restarting the daemon script can solve those problems.
         */
        SiteMaster\Core\Util::log(Monolog\Logger::NOTICE, 'doing a routine restart of the daemon after ' . Config::get('RESTART_INTERVAL') . ' pages');
        exit(12);
    }
    
    /**
     * @var $page \SiteMaster\Core\Auditor\Site\Page
     */
    $queue->rewind();
    $page = $queue->current();
    $scan = $page->getScan();
    $site = $scan->getSite();
    
    if ($page->status == \SiteMaster\Core\Auditor\Site\Page::STATUS_RUNNING) {
        //sanity check. This page should not already be running
        continue;
    }
    
    if (($current_site !== false) && ($current_site != $scan->sites_id)) {
        //Restart the daemon before scanning a new site. (to help prevent errors and clear any variables such as robots.txt)
        SiteMaster\Core\Util::log(Monolog\Logger::NOTICE, 'Restarting before scanning a different site.');
        exit(13);
    }
    
    $current_site = $scan->sites_id;
    $total_checked++;

    echo date("Y-m-d H:i:s"). " - scanning page.id=" . $page->id . PHP_EOL;
    if (!$page->scan($daemon_name)) {
        //Some sort of issue with scanning...
        continue;
    }
    
    //The page might have been removed after the scan, so we need to check for that.
    if (!$page = \SiteMaster\Core\Auditor\Site\Page::getById($page->id)) {
        sleep(1);
        continue;
    }
    
    //Check if we might need to restart the daemon due to metric problems.
    if ($page->letter_grade == \SiteMaster\Core\Auditor\GradingHelper::GRADE_INCOMPLETE) {
        $total_incomplete++;

        if ($total_incomplete >= Config::get('INCOMPLETE_LIMIT')) {
            SiteMaster\Core\Util::log(Monolog\Logger::WARNING, 'stopping daemon due due to ' . Config::get('INCOMPLETE_LIMIT') . ' incomplete page scans in a row for ' . $site->base_url);
            exit(12);
        }
    } else {
        //All is fine.
        $total_incomplete = 0;
    }
    
    //Check if there might have been some errors
    $scan->reload();
    if ($scan->start_time == $scan->end_time) {
        SiteMaster\Core\Util::log(Monolog\Logger::WARNING, 'attempting to restart daemon due to a possible error (start and end times are the same)',
            array(
                'sites.id' => $page->sites_id,
                'scans.id' => $page->scans_id,
            )
        );
        exit(11);
    }
    
    sleep(1);
}
