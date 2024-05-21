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
 *  Meeting Details
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_sesskey();

$id = required_param('id', PARAM_INT);

$context = context_system::instance();
require_capability('local/stream:manage', $context);

$help = new local_stream_help();
$baseurl = new moodle_url('/local/stream/index.php');
$PAGE->set_url($baseurl);
$strtitle = get_string('meeting', 'local_stream');

$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);
$PAGE->set_pagelayout('admin');

$fs = get_file_storage();
$context = context_system::instance();
$meetingform = new local_stream_meeting_form();

$data = $DB->get_record('local_stream_rec', array('id' => $id));

echo $OUTPUT->header();
$meetingform->set_data($data);
$meetingform->display();
echo $OUTPUT->footer();
