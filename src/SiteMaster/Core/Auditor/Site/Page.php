<?php
namespace SiteMaster\Core\Auditor\Site;

use DB\Record;
use SiteMaster\Core\Auditor\Metric\Mark;
use SiteMaster\Core\Registry\Site\Member;
use SiteMaster\Core\Registry\Site;
use SiteMaster\Core\Auditor\Downloader\HTMLOnly;
use SiteMaster\Core\Auditor\Logger\Scheduler;
use SiteMaster\Core\Auditor\Logger\Metrics;
use SiteMaster\Core\Auditor\Scan;
use SiteMaster\Core\Util;
use SiteMaster\Core\HTTPConnectionException;
use SiteMaster\Core\RuntimeException;

class Page extends Record
{
    public $id;                    //int required
    public $scans_id;              //fk for scans.id NOT NULL
    public $sites_id;              //fk for sites_id NOT NULL
    public $uri;                   //URI VARCHAR(256) NOT NULL
    public $status;                //ENUM('CREATED', 'QUEUED', 'RUNNING', 'COMPLETE', 'ERROR') NOT NULL default='CREATED'
    public $scan_type;             //ENUM('USER', 'AUTO') NOT NULL default='AUTO'
    public $grade;                 //DOUBLE(2,2) NOT NULL default=0
    public $priority;              //INT NOT NULL default=300, the priority for this job.  0 is the most urgent
    public $date_created;          //DATETIME NOT NULL, the date that this record was created
    public $start_time;            //DATETIME NOT NULL
    public $end_time;              //DATETIME
    public $title;                 //VARCHAR(256)
    public $letter_grade;          //VARCHAR(2)
    public $error;                 //VARCHAR(256)

    const STATUS_CREATED  = 'CREATED';
    const STATUS_QUEUED   = 'QUEUED';
    const STATUS_RUNNING  = 'RUNNING';
    const STATUS_COMPLETE = 'COMPLETE';
    const STATUS_ERROR    = 'ERROR';

    const SCAN_TYPE_USER = 'USER';
    const SCAN_TYPE_AUTO = 'AUTO';

    const PRI_AUTO_SITE_SCAN = 400;
    const PRI_AUTO_PAGE_SCAN = 300;
    const PRI_USER_SITE_SCAN = 200;
    const PRI_USER_PAGE_SCAN = 100;

    public function keys()
    {
        return array('id');
    }

    public static function getTable()
    {
        return 'scanned_page';
    }

    /**
     * Get a page by its scan id and uri
     *
     * @param $scans_id
     * @param $uri
     * @internal param $base_url
     * @return bool|Page
     */
    public static function getByScanIDAndURI($scans_id, $uri)
    {
        return self::getByAnyField(__CLASS__, 'uri', $uri, 'scans_id=' . (int)$scans_id);
    }

    /**
     * Get the previous page scan for the current uri
     * 
     * @return bool|Page
     */
    public function getPreviousScan()
    {
        $db = Util::getDB();
        
        $sql = "SELECT *
                FROM scanned_page
                WHERE uri = '" . $db->escape_string($this->uri) . "'
                    AND id != " . ($this->id) . "
                ORDER BY id DESC
                LIMIT 1";

        if (!$result = $db->query($sql)) {
            return false;
        }

        $data = $result->fetch_assoc();
        $object = new self();
        $object->synchronizeWithArray($data);
        return $object;
    }

    /**
     * Get the marks for a given metric
     * 
     * @param int $metrics_id the metric id to get marks for
     * @return Page\Marks\AllForPageMetric
     */
    public function getMarks($metrics_id)
    {
        return new Page\Marks\AllForPageMetric(array(
            'scanned_page_id' => $this->id,
            'metrics_id' => $metrics_id
        ));
    }

    /**
     * Create a new page
     *
     * @param $scans_id
     * @param $sites_id
     * @param $uri
     * @param array $fields
     * @return bool|Page
     */
    public static function createNewPage($scans_id, $sites_id, $uri, array $fields = array())
    {
        $page = new self();
        $page->status        = self::STATUS_CREATED;
        $page->scan_type     = self::SCAN_TYPE_AUTO;
        $page->grade         = 0;
        $page->priority      = self::PRI_AUTO_SITE_SCAN;
        $page->date_created  = Util::epochToDateTime();
        
        $page->synchronizeWithArray($fields);
        $page->scans_id = $scans_id;
        $page->sites_id = $sites_id;
        $page->uri      = $uri;

        if (!$page->insert()) {
            return false;
        }

        return $page;
    }

    /**
     * @return false|Scan
     */
    public function getScan()
    {
        return Scan::getByID($this->scans_id);
    }

    /**
     * @return false|\SiteMaster\Core\Registry\Site
     */
    public function getSite()
    {
        return Site::getByID($this->sites_id);
    }

    /**
     * Schedule a scan of this page.
     *
     * @return bool Tru on success
     */
    public function scheduleScan()
    {
        if ($this->status != self::STATUS_CREATED) {
            //The scan already finished.  Don't scan again.
            return false;
        }
        
        $site = $this->getSite();
        
        $priority_site = self::PRI_AUTO_SITE_SCAN;
        $priority_page = self::PRI_AUTO_PAGE_SCAN;
        
        if ($this->scan_type == 'USER') {
            $priority_site = self::PRI_USER_SITE_SCAN;
            $priority_page = self::PRI_USER_PAGE_SCAN;
        }
        
        //Set the priority
        $priority = $priority_page;
        if ($this->uri == $site->base_url) {
            $priority = $priority_site;
        }
        
        $this->markAsQueued($priority);
    }
    
    public function scan()
    {
        if ($this->status != self::STATUS_QUEUED) {
            //Looks like it has already been scanned (or has yet to be scheduled).  Don't continue.
            return false;
        }

        $this->markAsRunning();
        
        $scan = $this->getScan();
        $site = $this->getSite();
        
        $spider = new \Spider(
            new HTMLOnly(),
            new \Spider_Parser()
        );
        
        $spider->addUriFilter('\\SiteMaster\\Core\\Auditor\\Filter\\FileExtension');
        $spider->addLogger(new Scheduler($spider, $scan, $site));
        $spider->addLogger(new Metrics($spider, $scan, $site, $this));

        try {
            $spider->processPage($this->uri, 1);
        } catch (HTTPConnectionException $e) {
            //Couldn't get the page, so don't process it.
            return $this->delete();
        }
        
        $this->markAsComplete();
    }

    /**
     * Mark this page as queued
     */
    public function markAsQueued($priority)
    {
        $this->status   = self::STATUS_QUEUED;
        $this->priority = $priority;
        $this->save();
    }

    /**
     * Mark this page as running
     */
    public function markAsRunning()
    {
        $scan = $this->getScan();
        if ($scan->status != Scan::STATUS_RUNNING) {
            $scan->markAsRunning();
        }
        
        $this->start_time = Util::epochToDateTime();
        $this->status     = self::STATUS_RUNNING;
        $this->save();
    }

    /**
     * Mark this page as complete
     */
    public function markAsComplete()
    {
        $this->end_time = Util::epochToDateTime();
        $this->status   = self::STATUS_COMPLETE;
        $this->save();
        
        $scan = $this->getScan();

        //Figure out we the site scan is finished.
        if (!$scan->getNextQueuedPage()) {
            //Could not find any more queued pages to scan.  The scan must be finished.
            $scan->markAsComplete();
        }
    }

    /**
     * Mark this page as an error
     *
     * @param string $error
     */
    public function markAsError($error = 'unknown')
    {
        $this->end_time = Util::epochToDateTime();
        $this->status   = self::STATUS_ERROR;
        $this->save();
    }

    /**
     * Create a page mark
     * 
     * @param Mark $mark The Mark object
     * @param array $fields Array of fields such as context, line, col
     * @return bool|Page\Mark
     */
    public function addMark(Mark $mark, array $fields = array())
    {
        return Page\Mark::createNewPageMark($mark->id, $this->id, $mark->point_deduction, $fields);
    }
}
