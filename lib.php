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
 * Lib
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/formslib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Meeting form
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_stream_meeting_form extends moodleform {

    /**
     * Define the form elements for the plugin settings.
     */
    public function definition() {

        $mform =& $this->_form;

        $mform->addElement('header', 'meeting', get_string('meeting', 'local_stream'));
        $mform->addElement('static', 'topic', get_string('topic', 'local_stream'), 'readonly');
        $mform->addElement('static', 'email', get_string('email', 'local_stream'), 'readonly');
        $mform->addElement('static', 'dept', get_string('department', 'local_stream'), 'readonly');
        $mform->addElement('static', 'starttime', get_string('starttime', 'local_stream'), 'readonly');
        $mform->addElement('static', 'endtime', get_string('endtime', 'local_stream'), 'readonly');
        $mform->addElement('static', 'duration', get_string('duration', 'local_stream'), 'readonly');
        $mform->addElement('static', 'participants', get_string('participants', 'local_stream'), 'readonly');
        $mform->addElement('textarea', 'embeddingcode', get_string('embeddingsettings', 'local_stream'),
                'readonly wrap="virtual" rows="5" cols="5"');

        $mform->addElement('header', 'extrafields', get_string('extrafields', 'local_stream'));
        $mform->addElement('static', 'extra_uuid', get_string('uuid', 'local_stream'), 'readonly');
        $mform->addElement('static', 'extra_host', get_string('host', 'local_stream'), 'readonly');
        $mform->addElement('static', 'extra_user_type', get_string('user_type', 'local_stream'), 'readonly');
        $mform->addElement('static', 'extra_has_screen_share', get_string('has_screen_share', 'local_stream'),
                'readonly');
        $mform->addElement('static', 'extra_audio_quality', get_string('audio_quality', 'local_stream'), 'readonly');
        $mform->addElement('static', 'extra_video_quality', get_string('video_quality', 'local_stream'), 'readonly');

        $mform->addElement('header', 'recording', get_string('recording', 'local_stream'));
        $mform->addElement('static', 'recording_type', get_string('recording_type', 'local_stream'), 'readonly');
        $mform->addElement('static', 'recording_start', get_string('starttime', 'local_stream'), 'readonly');
        $mform->addElement('static', 'recording_end', get_string('endtime', 'local_stream'), 'readonly');
        $mform->addElement('static', 'file_extension', get_string('file_extension', 'local_stream'), 'readonly');
        $mform->addElement('static', 'file_size', get_string('file_size', 'local_stream'), 'readonly');
        $mform->addElement('static', 'status', get_string('status', 'local_stream'), 'readonly');

        $mform->addElement('cancel', 'cancel', get_string('back'));
        $mform->closeHeaderBefore('cancel');
    }

    /**
     * Set the data to be eventually rendered.
     *
     * @param object $data
     */
    public function set_data($data) {
        global $DB, $help, $id;

        $meeting = $DB->get_record('local_stream_rec', ['id' => $id]);

        $recordingurl = $help->get_meeting($meeting);
        $data->embeddingcode .= '<video controls="true" controlsList="nodownload" oncontextmenu="return false;"><source src="' .
                $recordingurl . '">' . $recordingurl .
                '</video>';

        foreach (json_decode($data->recordingdata) as $key => $value) {
            $data->$key = $value;
        }

        foreach (json_decode($data->meetingdata) as $key => $value) {
            $key = 'extra_' . $key;
            $data->$key = $value;
        }

        parent::set_data($data);
    }
}

/**
 * Filters form
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_stream_form extends moodleform {

    /**
     * Define the form elements for the plugin settings.
     */
    public function definition() {
        global $USER;

        $help = new \local_stream_help();

        $mform =& $this->_form;

        $mform->addElement('header', 'filters', get_string('filters', 'local_stream'));

        $mform->addElement('text', 'topic', get_string('topic', 'local_stream'), 'size=40');
        $mform->setType('topic', PARAM_TEXT);

        $courses = $help->get_courses();
        $options = [
                'multiple' => false,
                'showsuggestions' => true,
        ];

        $mform->addElement('autocomplete', 'course', get_string('course'), $courses, $options);

        if (is_siteadmin($USER)) {
            $mform->addElement('autocomplete', 'email', get_string('user'), $help->get_users(),
                    ['multiple' => false, 'showsuggestions' => true]);
            $mform->setAdvanced('email');

        }

        $mform->addElement('text', 'meeting', get_string('meetingid', 'local_stream'));
        $mform->setDefault('meeting', '');
        $mform->setType('meeting', PARAM_TEXT);
        $mform->setAdvanced('meeting');

        if ($help->has_capability_to_edit()) {
            $mform->addElement('select', 'visible', get_string('visible'),
                    [0 => get_string('all'), 1 => get_string('show'), 2 => get_string('hide')]);
            $mform->setAdvanced('visible');

            $mform->addElement('select', 'duration', get_string('duration', 'local_stream'),
                    [0 => get_string('all'), 60 => 60 . ' ' . get_string('seconds'), 300 => 5 . ' ' . get_string('minutes')]);
            $mform->setAdvanced('duration');
        }

        $mform->addElement('date_time_selector', 'starttime', get_string('starttime', 'local_stream'));
        $mform->addHelpButton('starttime', 'startdate');
        $date = (new \DateTime())->setTimestamp(usergetmidnight(time()));
        $date->modify('-1 year');
        $mform->setDefault('starttime', $date->getTimestamp());
        $mform->setAdvanced('starttime');

        $mform->addElement('date_time_selector', 'endtime', get_string('endtime', 'local_stream'));
        $mform->setAdvanced('endtime');

        $this->add_action_buttons(true, get_string('search'));
    }
}

/**
 * Embed form
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_stream_embed_form extends moodleform {

    /**
     * Define the form elements for the plugin settings.
     */
    public function definition() {
        global $DB, $USER;

        $help = new \local_stream_help();
        $mform =& $this->_form;

        $mform->addElement('header', 'embedvideo', get_string('embeddedrecordings', 'local_stream'));

        $mform->addElement('hidden', 'id');
        $mform->addElement('static', 'topic', get_string('topic', 'local_stream'), ['size' => 120]);
        $mform->addElement('static', 'duration', get_string('duration', 'local_stream'), ['size' => 120]);

        if (isset($mform->participant) && $mform->participant) {
            $mform->addElement('static', 'participants', get_string('participants', 'local_stream'),
                    ['size' => 120]);
        }

        $courses = $help->get_courses();
        $options = [
                'multiple' => false,
        ];

        $mform->addElement('autocomplete', 'course', get_string('course'), $courses, $options);

        $buttonarray = [];
        $classarray = ['class' => 'form-submit'];

        $buttonarray[] = &$mform->createElement('submit', 'saveandreturn', get_string('savechangesandreturn'), $classarray);
        $buttonarray[] = &$mform->createElement('submit', 'saveanddisplay', get_string('savechangesanddisplay'), $classarray);
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}

/**
 * Update an inplace editable field in the local Stream plugin.
 *
 * This function updates the specified field (itemtype) with a new value for the given item ID (itemid)
 * in the 'local_stream_rec' table.
 *
 * @param string $itemtype The type of item to be updated (e.g., 'topic').
 * @param int $itemid The ID of the item to be updated.
 * @param mixed $newvalue The new value to be set for the specified item.
 * @return \core\output\inplace_editable|null Returns an inplace_editable object if the update is successful,
 *                                             or null if the update fails.
 */
function local_stream_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB;

    $help = new \local_stream_help();

    $inplace = $DB->get_record('local_stream_rec', ['id' => $itemid]);
    if ($inplace) {
        $inplace->$itemtype = $newvalue;

        if ($inplace->moduleid) {
            $help->update_module($inplace);
        }

        $DB->update_record('local_stream_rec', $inplace);
    }

    return new \core\output\inplace_editable(
            'local_stream',
            'topic',
            $itemid,
            $help->has_capability_to_edit(),
            format_string($newvalue),
            $newvalue,
            get_string('sectionheadingedit', 'quiz', format_string($newvalue)),
            get_string('sectionheadingedit', 'quiz', format_string($newvalue))
    );
}

/**
 * Extend the Moodle navigation menu for Stream.
 *
 * This function is called by Moodle to extend the navigation menu and add Stream dashboard.
 *
 * @param navigation_node $navigation The navigation tree to extend.
 *
 * @return void
 */
function local_stream_extend_navigation($navigation) {
    global $USER, $PAGE, $DB;

    if (empty($USER->id)) {
        return;
    }

    // Check the current page context.
    $context = context::instance_by_id($PAGE->context->id);
    $isvalidcontext =
            ($context instanceof context_course || $context instanceof context_module || $context instanceof context_user) ? true :
                    false;
    if (!$isvalidcontext) {
        return;
    }

    // If the context if a module then get the parent context.
    $coursecontext = null;
    if ($context instanceof context_module) {
        $coursecontext = $context->get_course_context();
    } else if ($context instanceof context_course) {
        $coursecontext = $context;
    }

    if (!has_capability('local/stream:view', context_system::instance())) {
        return;
    }

    $label = get_string('coursedashboard', 'local_stream');
    $icon = new pix_icon('icon', $label, 'local_stream');

    if (isset($coursecontext->id) && $coursecontext->id) {
        $link = new moodle_url('/local/stream', ['course' => $coursecontext->instanceid]);
    } else {
        $link = new moodle_url('/local/stream');

        $nodehome = $navigation->get('home');
        if (isnodeempty($nodehome)) {
            $nodehome->add($label, $link, navigation_node::NODETYPE_LEAF, $label, 'stream-home', $icon);
            $nodehome->showinflatnavigation = true;
        }
    }

    $currentcoursenode = $navigation->find('currentcourse', $navigation::TYPE_ROOTNODE);
    if (isnodeempty($currentcoursenode)) {
        // We have a 'current course' node, add the link to it.
        $currentcoursenode->add($label, $link, navigation_node::NODETYPE_LEAF, $label, 'stream-currentcourse', $icon);
    }

    $mycoursesnode = $navigation->find('mycourses', $navigation::TYPE_ROOTNODE);
    if (isnodeempty($mycoursesnode)) {
        $currentcourseinmycourses = $mycoursesnode->find($coursecontext->instanceid, navigation_node::TYPE_COURSE);
        if ($currentcourseinmycourses) {
            // We found the current course in 'my courses' node, add the link to it.
            $currentcourseinmycourses->add($label, $link, navigation_node::NODETYPE_LEAF, $label,
                    'stream-mycourses', $icon);
        }
    }

    $coursesnode = $navigation->find('courses', $navigation::TYPE_ROOTNODE);
    if (isnodeempty($coursesnode)) {
        $currentcourseincourses = $coursesnode->find($coursecontext->instanceid, navigation_node::TYPE_COURSE);
        if ($currentcourseincourses) {
            // We found the current course in the 'courses' node, add the link to it.
            $currentcourseincourses->add($label, $link, navigation_node::NODETYPE_LEAF, $label,
                    'stream-allcourses', $icon);
        }
    }
}

/**
 * Check if a navigation node is not empty.
 *
 * This function checks if the provided navigation node is not false and has children.
 *
 * @param navigation_node|false $node The navigation node to check.
 *
 * @return bool Returns true if the node is not false and has children, otherwise false.
 */
function isnodeempty($node) {
    return $node !== false && $node->has_children();
}
