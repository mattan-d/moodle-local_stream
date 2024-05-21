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
 *  Dashboard page
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

$id = required_param('id', PARAM_INT);

$context = context_system::instance();
require_capability('local/stream:manage', $context);

$help = new local_stream_help();
$baseurl = new moodle_url('/local/stream/embed.php');
$dashboardurl = new moodle_url('/local/stream/index.php');

$PAGE->set_url($baseurl);

$strtitle = get_string('embeddedrecordings', 'local_stream');

$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);
$PAGE->set_pagelayout('admin');

$meeting = $DB->get_record('local_stream_rec', ['id' => $id]);

$form = new local_stream_embed_form();
$form->set_data($meeting);

if ($form->is_cancelled()) {
    redirect($dashboardurl);
} else if ($data = $form->get_data()) {

    $meeting->course = $data->course;
    $page = $help->add_module($meeting);

    $newmeeting = new stdClass();
    $newmeeting->id = $meeting->id;
    $newmeeting->course = $meeting->course;
    $newmeeting->moduleid = $page->id;
    $newmeeting->embedded = 1;

    $DB->update_record('local_stream_rec', $newmeeting);

    if (isset($data->saveanddisplay)) {
        redirect(new moodle_url('/course/view.php', ['id' => $meeting->course]),
                get_string('recordingcycle', 'local_stream', $meeting->id));
    } else {
        redirect($dashboardurl, get_string('recordingcycle', 'local_stream', $meeting->id));
    }
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
