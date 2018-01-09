<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin is used to access localvideo videos
 *
 * @since Moodle 2.0
 * @package    repository_localvideo
 * @copyright  2016 OpenApp {@link http://openapp.co.il}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * repository_localvideo class
 *
 * @since Moodle 2.0
 * @package    repository_localvideo
 * @copyright  2016 OpenApp {@link http://openapp.co.il}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class repository_localvideo extends repository {
    /** @var int maximum number of thumbs per page */
    const localvideo_THUMBS_PER_PAGE = 27;

    /**
     * API key for using the localvideo Data API.
     * @var mixed
     */
    private $video_site;
	private $streaming;

     /**
     * localvideo plugin constructor
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);

        $this->video_site = $this->get_option('video_site');
		$this->streaming = $this->get_option('streaming');

        // Without an API key, don't show this repo to users as its useless without it.
        //if (empty($this->video_site)) {
        //    $this->disabled = true;
        //}
    }

    /**
     * Save videosite and streaming in config table.
     * @param array $options
     * @return boolean
     */
    public function set_option($options = array()) {
        if (!empty($options['video_site'])) {
            set_config('video_site', trim($options['video_site']), 'localvideo');
        }
        if (!empty($options['streaming'])) {
            set_config('streaming', trim($options['streaming']), 'localvideo');
        }		
        unset($options['video_site']);
		unset($options['streaming']);
        return parent::set_option($options);
    }

    /**
     * Get data from config table.
     *
     * @param string $config
     * @return mixed
     */
    public function get_option($config = '') {
        if ($config === 'video_site') {
            return trim(get_config('localvideo', 'video_site'));
        } elseif ($config === 'streaming') {
            return trim(get_config('localvideo', 'streaming'));
        } else {
            $options['video_site'] = trim(get_config('localvideo', 'video_site'));
			$options['streaming'] = trim(get_config('localvideo', 'streaming'));
        }
        return parent::get_option($config);
    }

    public function check_login() {
        return !empty($this->keyword);
    }

    /**
     * Return search results
     * @param string $search_text
     * @return array
     */
    public function search($search_text, $page = 0) {
        global $SESSION;
        $sort = optional_param('localvideo_sort', '', PARAM_TEXT);
        $mode = optional_param('localvideo_mode', '', PARAM_TEXT);
        $sess_keyword = 'localvideo_'.$this->id.'_keyword';
        $sess_sort = 'localvideo_'.$this->id.'_sort';

        // This is the request of another page for the last search, retrieve the cached keyword and sort
        if ($page && !$search_text && isset($SESSION->{$sess_keyword})) {
            $search_text = $SESSION->{$sess_keyword};
        }
        if ($page && !$sort && isset($SESSION->{$sess_sort})) {
            $sort = $SESSION->{$sess_sort};
        }
        if (!$sort) {
            $sort = 'relevance'; // default
        }

        // Save this search in session
        $SESSION->{$sess_keyword} = $search_text;
        $SESSION->{$sess_sort} = $sort;

        $this->keyword = $search_text;
        $ret  = array();
        $ret['nologin'] = true;
        $ret['page'] = (int)$page;
        if ($ret['page'] < 1) {
            $ret['page'] = 1;
        }
        $start = ($ret['page'] - 1) * self::localvideo_THUMBS_PER_PAGE + 1;
        $max = self::localvideo_THUMBS_PER_PAGE;
        $ret['list'] = $this->_get_collection($search_text, $start, $max, $sort, $mode);
        $ret['norefresh'] = true;
        $ret['nosearch'] = true;
        // If the number of results is smaller than $max, it means we reached the last page.
        $ret['pages'] = (count($ret['list']) < $max) ? $ret['page'] : -1;
        return $ret;
    }

    /**
     * Private method to get localvideo search results
     * @param string $keyword
     * @param int $start
     * @param int $max max results
     * @param string $sort
     * @param string $mode
     * @throws moodle_exception If the google API returns an error.
     * @return array
     */
    private function _get_collection($keyword, $start, $max, $sort, $mode) {
        global $SESSION,$USER,$DB,$CFG;

        // The new API doesn't use "page" numbers for browsing through results.
        // It uses a prev and next token in each set that you need to use to
        // request the next page of results.
        $sesspagetoken = 'localvideo_'.$this->id.'_nextpagetoken';
        $pagetoken = '';
        if ($start > 1 && isset($SESSION->{$sesspagetoken})) {
            $pagetoken = $SESSION->{$sesspagetoken};
        }

        $list = array();
        $error = null;

        $params = array('action' => 'search', 'subject' => $keyword);
        
        list($context, $course, $cm) = get_context_info_array($this->context->id);
        $courseid = is_object($course) ? $course->id : SITEID;
//        $vodlist = $DB->get_records('local_video_directory',array());
    	if (is_siteadmin($USER)) {
        	$vodlist = $DB->get_records_sql('SELECT v.*, ' . $DB->sql_concat_join("' '", array("firstname", "lastname")) .
        	                                ' AS name FROM {local_video_directory} v
                	                        LEFT JOIN {user} u on v.owner_id = u.id
						WHERE ' . $DB->sql_like('orig_filename', ':keyword') ,array('keyword'=>'%'.$DB->sql_like_escape($keyword).'%'));
    	} else {
        	$vodlist = $DB->get_records_sql('SELECT v.*, ' . $DB->sql_concat_join("' '", array("firstname", "lastname")) .
                                                ' AS name FROM {local_video_directory} v
                        	                LEFT JOIN {user} u on v.owner_id = u.id WHERE owner_id =' . $USER->id .
                                	        ' OR (private IS NULL OR private = 0)
						AND ' . $DB->sql_like('orig_filename', ':keyword') ,array('keyword'=>'%'.$DB->sql_like_escape($keyword).'%'));
    	}

	$videosettings = get_config('local_video_directory');

//get right thumbnail
        foreach ($vodlist as $voditem) {
                    $thumbdata = explode('-', $voditem->thumb);
                    $thumbid = $thumbdata[0];
                    $thumbseconds = isset($thumbdata[1]) ? "&second=$thumbdata[1]" : '';

		
						
                $list[] = array(
                    
                    'shorttitle' => $voditem->orig_filename,
                    'thumbnail_title' => $voditem->orig_filename,
                    'title' => $voditem->filename, // This is a hack so we accept this file by extension.
                    'description' => $voditem->orig_filename,
                    'thumbnail' => $CFG->wwwroot."/local/video_directory/thumb.php?id=" . $voditem->id . $thumbseconds . "&mini=1",
                    'thumbnail_width' => 150, //(int)$thumb->width,
                    'thumbnail_height' => 100, //(int)$thumb->height,
                    'size' => $voditem->size,
                    'date' => $voditem->timemodified,
                    'author' => $voditem->name,
                    'source' => $videosettings->streaming . "/" . $voditem->filename,
                );
         }
	return $list;

}


    /**
     * localvideo plugin doesn't support global search
     */
    public function global_search() {
        return false;
    }

    public function get_listing($path='', $page = '') {
        return array();
    }

    /**
     * Generate search form
     */
    public function print_login($ajax = true) {
        $ret = array();

        $search = new stdClass();
        $search->type = 'text';
        $search->id   = 'localvideo_search';
        $search->name = 's';
        $search->label = get_string('search', 'repository_localvideo').': ';


        $mode = new stdClass();
        $mode->type = 'select';
        $mode->options = array(
            (object)array(
                'value' => 'description',
                'label' => get_string('searchindescription', 'repository_localvideo')
            ),
            (object)array(
                'value' => 'tags',
                'label' => get_string('searchintags', 'repository_localvideo')
            )
        );
        $mode->id = 'localvideo_mode';
        $mode->name = 'localvideo_mode';
        $mode->label = get_string('mode', 'repository_localvideo').': ';

        $sort = new stdClass();
        $sort->type = 'select';
        $sort->options = array(
            (object)array(
                'value' => 'relevance',
                'label' => get_string('sortrelevance', 'repository_localvideo')
            ),
            (object)array(
                'value' => 'date',
                'label' => get_string('sortpublished', 'repository_localvideo')
            ),
            (object)array(
                'value' => 'rating',
                'label' => get_string('sortrating', 'repository_localvideo')
            ),
            (object)array(
                'value' => 'viewCount',
                'label' => get_string('sortviewcount', 'repository_localvideo')
            )
        );
        $sort->id = 'localvideo_sort';
        $sort->name = 'localvideo_sort';
        $sort->label = get_string('sortby', 'repository_localvideo').': ';



        $ret['login'] = array($search, $mode, $sort);
        $ret['login_btn_label'] = get_string('search');
        $ret['login_btn_action'] = 'search';
        $ret['allowcaching'] = true; // indicates that login form can be cached in filepicker.js
        return $ret;
    }

    /**
     * file types supported by localvideo plugin
     * @return array
     */
    public function supported_filetypes() {
        return array('video', 'video/mp4');
    }

    /**
     * localvideo plugin only return external links
     * @return int
     */
    public function supported_returntypes() {
        return FILE_EXTERNAL;
    }

    /**
     * Is this repository accessing private data?
     *
     * @return bool
     */
    public function contains_private_data() {
        return false;
    }

    /**
     * Add plugin settings input to Moodle form.
     * @param object $mform
     * @param string $classname
     */
    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);
        $video_site = get_config('localvideo', 'video_site');
		$streaming = get_config('localvideo', 'streaming');
        if (empty($video_site)) {
            $video_site = 'URL of YOUR VIDEO SITE';
        }

        $mform->addElement('text', 'video_site', get_string('video_site', 'repository_localvideo'), array('value' => $video_site, 'size' => '40'));
        $mform->setType('video_site', PARAM_RAW_TRIMMED);
        $mform->addRule('video_site', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'streaming', get_string('streaming', 'repository_localvideo'), array('value' => $streaming, 'size' => '40'));
        $mform->setType('streaming', PARAM_RAW_TRIMMED);
        $mform->addRule('streaming', null, null , null, 'client');


        $mform->addElement('static', null, '',  get_string('information', 'repository_localvideo'));
    }

    /**
     * Names of the plugin settings
     * @return array
     */
    public static function get_type_option_names() {
        return array('video_site','streaming','pluginname');
    }
}
