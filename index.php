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

$page = optional_param('page', 0, PARAM_INT);
$courseid = optional_param('course', null, PARAM_INT);
$action = optional_param('action', null, PARAM_TEXT);
$redirect = optional_param('redirect', null, PARAM_TEXT);

$context = context_system::instance();
$PAGE->set_context($context);

require_capability('local/stream:manage', $context);

$help = new local_stream_help();

if ($action == 'sso') {
    $help->stream_login($redirect);
}

if ($courseid) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course) {
        $context = context_course::instance($course->id);
        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $baseurl = new moodle_url('/local/stream/index.php', ['course' => $course->id]);
    }
} else {
    $baseurl = new moodle_url('/local/stream/index.php');
}

$strtitle = get_string('browselist', 'local_stream');
$PAGE->set_url($baseurl);
$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);
$PAGE->set_pagelayout('admin');
$PAGE->requires->js_call_amd('local_stream/init');

$help->hooks($baseurl);

$fs = get_file_storage();
$form = new local_stream_form();

$basefilter = [];
$basefilter['status'] = [$help::MEETING_STATUS_READY];

if (!isset($SESSION->meetings_filtering)) {
    $SESSION->meetings_filtering = [];
    $SESSION->meetings_filtering = $basefilter;
}

if ($form->is_cancelled()) {
    $SESSION->meetings_filtering = $basefilter;
    $baseurl = new moodle_url('/local/stream/index.php');
    redirect($baseurl);
}

if ($courseid) {
    $SESSION->meetings_filtering['course'] = $courseid;
}

if (($search = $form->get_data()) && confirm_sesskey()) {

    if ($search->starttime > 0) {
        $SESSION->meetings_filtering['starttime'] = userdate($search->starttime, '%Y-%m-%dT%H:%M:00Z', null, false);
    }

    if ($search->endtime > 0) {
        $SESSION->meetings_filtering['endtime'] = userdate($search->endtime, '%Y-%m-%dT%H:%M:00Z', null, false);
    }

    if ($search->meeting > 0) {
        $SESSION->meetings_filtering['meetingid'] = '%' . $search->meeting . '%';
    }

    if (isset($search->email) && $search->email) {
        $SESSION->meetings_filtering['email'] = $search->email;
    }

    if (isset($search->topic) && $search->topic) {
        $SESSION->meetings_filtering['topic'] = '%' . $search->topic . '%';
    }

    if (isset($search->course) && $search->course) {
        $SESSION->meetings_filtering['course'] = $search->course;
    }

    if (isset($search->visible) && $search->visible) {
        $SESSION->meetings_filtering['visible'] = $search->visible;
    }

    if (isset($search->duration) && $search->duration) {
        $interval = new DateInterval('PT' . $search->duration . 'S');
        $SESSION->meetings_filtering['duration'] = $interval->format('%H:%I:%S');
    }
}

$perpage = $help->config->recordingsperpage;
if (!is_siteadmin($USER)) {
    $user = $DB->get_record('user', ['id' => $USER->id]);
    if ($user) {
        $basefilter['email'] = $user->email;
        $SESSION->meetings_filtering['email'] = $user->email;
        $data = $help->get_meetings($basefilter, false, $page);
        $recordingscount = $help->get_meetings($basefilter, true);
    }
} else {
    $data = $help->get_meetings($SESSION->meetings_filtering, false, $page);
    $recordingscount = $help->get_meetings($basefilter, true);
}

$data = json_decode(json_encode($data), true);;

if (isset($SESSION->meetings_filtering) || isset($courseid)) {
    if ($SESSION->meetings_filtering != $basefilter) {
        $searchcount = $help->get_meetings($SESSION->meetings_filtering, true);
    }
}

// Create an HTML table with the sample data for the current page.
$table = new html_table();
if ($help->has_capability_to_edit()) {
    $table->head =
            ['#', get_string('status'), get_string('course', 'local_stream'),
                    get_string('meeting', 'local_stream'),
                    get_string('starttime', 'local_stream'),
                    get_string('topic', 'local_stream'),
                    get_string('user'),
                    get_string('duration', 'local_stream'),
                    get_string('file_size', 'local_stream'),
                    get_string('participants', 'local_stream'),
                    get_string('views', 'local_stream'),
                    get_string('visible'),
                    get_string('options'),
            ];
} else {
    $table->head =
            ['#', get_string('status'), get_string('course', 'local_stream'),
                    get_string('meeting', 'local_stream'),
                    get_string('starttime', 'local_stream'),
                    get_string('topic', 'local_stream'),
                    get_string('duration', 'local_stream'),
                    get_string('options'),
            ];
}

foreach ($data as $row) {

    $icons = [
            'progressbar' => new pix_icon('i/progressbar', get_string('progress', 'local_stream')),
            'preview' => new pix_icon('t/preview', get_string('preview'), 'moodle', ['class' => 'mr-2']),
            'hide' => new pix_icon('t/hide', get_string('hide'), 'moodle', ['class' => 'mr-2']),
            'show' => new pix_icon('t/show', get_string('show'), 'moodle', ['class' => 'mr-2']),
            'course' => new pix_icon('i/course', get_string('course'), 'moodle', ['class' => 'mr-2']),
            'delete' => new pix_icon('t/delete', get_string('delete'), 'moodle', ['class' => 'mr-2']),
            'restore' => new pix_icon('t/restore', get_string('restore'), 'moodle', ['class' => 'mr-2']),
            'download' => new pix_icon('t/download', get_string('download'), 'moodle', ['class' => 'mr-2']),
            'warning' => new pix_icon('i/warning', get_string('warning')),
            'invalid' => new pix_icon('i/invalid', get_string('invalid', 'local_stream')),
            'valid' => new pix_icon('i/valid', get_string('valid', 'local_stream')),
            'risk_dataloss' => new pix_icon('i/risk_dataloss', get_string('delete')),
    ];

    $file = new stdClass();
    $file->id = $row['id'];

    $recordingurl = $help->get_meeting($file);
    $recordingdata = json_decode($row['recordingdata']);
    $meetingdata = json_decode($row['meetingdata']);

    $buttons = '';
    $menuitemclass = 'dropdown-item align-items-center d-flex flex-row-reverse justify-content-end';

    if ($recordingurl) {
        $icon = $icons['preview'];
        $buttons .= $OUTPUT->action_icon($recordingurl, $icon, null,
                ['class' => $menuitemclass . ' preview-mode'], true);
    }

    // Visible button.
    if ($row['visible']) {
        $icon = $icons['hide'];
        $row['visible'] = 0;
    } else {
        $icon = $icons['show'];
        $row['visible'] = 1;
    }
    $visiblebtn = $OUTPUT->action_icon(new moodle_url('/local/stream/index.php',
            ['action' => 'hide', 'id' => $row['id'], 'visible' => $row['visible']]), $icon,
            new confirm_action(get_string('areyousure', 'local_stream')));

    if ($help->has_capability_to_edit()) {
        $icon = $icons['course'];
        $buttons .= $OUTPUT->action_icon(new moodle_url('/local/stream/embed.php',
                ['id' => $row['id']]),
                $icon, null,
                ['class' => $menuitemclass], true);

        $icon = $icons['delete'];
        $buttons .= $OUTPUT->action_icon(new moodle_url('/local/stream/index.php',
                ['action' => 'delete', 'id' => $row['id']]),
                $icon,
                new confirm_action(get_string('areyousure', 'local_stream')),
                ['class' => $menuitemclass], true);

        if (isset($row['meetingdata'])) {
            $icon = $icons['restore'];
            $buttons .= $OUTPUT->action_icon(new moodle_url('/local/stream/index.php',
                    ['action' => 'recover', 'id' => $row['id']]), $icon,
                    new confirm_action(get_string('areyousure', 'local_stream')),
                    ['class' => $menuitemclass], true);
        }

        $icon = $icons['download'];
        $buttons .= $OUTPUT->action_icon(new moodle_url('/local/stream/index.php',
                ['action' => 'download', 'id' => $row['id']]), $icon, null,
                ['class' => $menuitemclass], true);
    }

    $coursepage = '-';
    if ($row['course']) {
        $course = $DB->get_record('course', ['id' => $row['course']]);
        if ($course) {
            $coursepage = html_writer::link(new moodle_url('/course/view.php', ['id' => $row['course']]),
                    $course->fullname);
        }
    }

    if ($row['status'] == $help::MEETING_STATUS_INVALID) {
        $statusicon =
                $OUTPUT->action_icon(new moodle_url('/local/stream/meeting.php', ['id' => $row['id'], 'sesskey' => sesskey()]),
                        $icons['warning']);
    } else if ($row['status'] == $help::MEETING_STATUS_DELETED) {
        $statusicon = $OUTPUT->action_icon(new moodle_url('/local/stream/meeting.php',
                ['id' => $row['id'], 'sesskey' => sesskey()]), $icons['risk_dataloss']);
    } else if ($row['status'] == $help::MEETING_STATUS_READY) {
        $statusicon = $OUTPUT->action_icon(new moodle_url('/local/stream/meeting.php',
                ['id' => $row['id'], 'sesskey' => sesskey()]), $icons['valid']);
    } else if ($row['status'] == $help::MEETING_STATUS_PROCESS) {
        $statusicon = $OUTPUT->action_icon(new moodle_url('/local/stream/meeting.php',
                ['id' => $row['id'], 'sesskey' => sesskey()]), $icons['progressbar']);
    } else {
        $statusicon = $OUTPUT->action_icon(new moodle_url('/local/stream/meeting.php',
                ['id' => $row['id'], 'sesskey' => sesskey()]), $icons['invalid']);
    }

    $buttons = '<div class="btn-group" role="group">' .
            '<button id="btnGroupDrop" type="button" class="btn btn-secondary dropdown-toggle ' .
            'advanced-menu" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . get_string('choose') . '
    </button>
    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="btnGroupDrop">
      ' . $buttons . '
    </div>
  </div>';

    // Reference to zoom report.
    if ($row['participants']) {
        $zoom = $DB->get_record('zoom', ['meeting_id' => $row['meetingid']]);
        if ($zoom) {
            $cm = get_coursemodule_from_instance('zoom', $zoom->id);
            if ($cm) {
                $row['participants'] = html_writer::link(new moodle_url('/mod/zoom/participants.php',
                        ['id' => $cm->id, 'uuid' => $meetingdata->uuid]),
                        $row['participants']);
            }
        }
    }

    $inplace = [];
    $inplace['topic'] = new \core\output\inplace_editable(
            'local_stream',
            'topic',
            $row['id'],
            $help->has_capability_to_edit(),
            format_string($row['topic']),
            $row['topic'],
            get_string('sectionheadingedit', 'quiz', $row['topic']),
            get_string('sectionheadingedit', 'quiz', $row['topic'])
    );

    $row['topic'] = $OUTPUT->render($inplace['topic']);
    $row['starttime'] = userdate(strtotime($row['starttime']));

    $usersaccount = $DB->get_record('user', ['email' => $row['email']]);
    if ($usersaccount) {
        $row['email'] =
                html_writer::link(new moodle_url('/user/profile.php', ['id' => $usersaccount->id]),
                        fullname($usersaccount));
    }

    if ($help->has_capability_to_edit()) {
        $table->data[] = [
                $row['id'],
                $statusicon,
                $coursepage,
                html_writer::link(new moodle_url('meeting.php', ['id' => $row['id'], 'sesskey' => sesskey()]),
                        $row['meetingid']),
                $row['starttime'],
                $row['topic'],
                $row['email'],
                $row['duration'],
                number_format(($recordingdata->file_size / (1024 * 1024)), 2) . 'MB',
                $row['participants'],
                $row['views'],
                $visiblebtn,
                $buttons,
        ];
    } else {
        $table->data[] = [
                $row['id'],
                $statusicon,
                $coursepage,
                $row['meetingid'],
                $row['starttime'],
                $row['topic'],
                $row['duration'],
                $buttons,
        ];
    }
}

echo $OUTPUT->header();

if (isset($searchcount)) {
    echo $OUTPUT->heading($searchcount . ' / ' . $recordingscount . ' ' .
            get_string('recordings', 'local_stream'), 2);
    $recordingscount = $searchcount;
} else {
    echo $OUTPUT->heading($recordingscount . ' ' . get_string('recordings', 'local_stream'), 2);
}

$basepagingurl = ($courseid ? 'index.php?courseid=' . $courseid : 'index.php');
echo $OUTPUT->paging_bar($recordingscount, $page, $perpage, $basepagingurl);

$form->display();
echo html_writer::table($table);
echo $OUTPUT->paging_bar($recordingscount, $page, $perpage, $basepagingurl);
echo $OUTPUT->footer();
