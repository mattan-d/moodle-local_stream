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
 * Site admin report: usage of “questions from lesson recordings”.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_stream\form\question_gen_report_filter_form;
use local_stream\question_gen_report;

require_login(0, false);
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_stream_question_gen_report');

$defaultto = usergetmidnight(time());
$defaultfrom = $defaultto - (89 * DAYSECS);

$mform = new question_gen_report_filter_form();

$datefrom = $defaultfrom;
$dateto = $defaultto + DAYSECS - 1;

if ($fromform = $mform->get_data()) {
    $datefrom = (int) $fromform->datefrom;
    $dateto = (int) $fromform->dateto + DAYSECS - 1;
} else if ($mform->is_submitted()) {
    $datefrom = optional_param('datefrom', $defaultfrom, PARAM_INT);
    $dateto = optional_param('dateto', $defaultto, PARAM_INT) + DAYSECS - 1;
}

if ($datefrom > $dateto) {
    $tmp = $datefrom;
    $datefrom = $dateto;
    $dateto = $tmp;
}

$report = new question_gen_report($datefrom, $dateto);
$summary = $report->summary();
$bytype = $report->by_question_type();
$bycourse = $report->by_course();

$PAGE->set_title(get_string('qgenreporttitle', 'local_stream'));
$PAGE->set_heading(get_string('qgenreporttitle', 'local_stream'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('qgenreporttitle', 'local_stream'));

$mform->set_data((object) [
        'datefrom' => usergetmidnight($datefrom),
        'dateto' => usergetmidnight($dateto),
]);
$mform->display();

echo html_writer::tag('p', get_string('qgenreportperiod', 'local_stream', (object) [
        'from' => userdate($datefrom, get_string('strftimedatefullshort', 'langconfig')),
        'to' => userdate($dateto, get_string('strftimedatefullshort', 'langconfig')),
]), ['class' => 'text-muted mb-4']);

$summarytable = new html_table();
$summarytable->attributes['class'] = 'generaltable w-auto mb-4';
$summarytable->head = [
        get_string('qgenreportmetric', 'local_stream'),
        get_string('qgenreportvalue', 'local_stream'),
];
$summarytable->data = [
        [get_string('qgenreportcourses', 'local_stream'), $summary->courses],
        [get_string('qgenreportquestions', 'local_stream'), $summary->questions],
        [get_string('qgenreportgenerations', 'local_stream'), $summary->sessions],
];
echo html_writer::tag('h3', get_string('qgenreportsummary', 'local_stream'));
echo html_writer::table($summarytable);

echo html_writer::tag('h3', get_string('qgenreportbytype', 'local_stream'));
if (!$bytype) {
    echo $OUTPUT->notification(get_string('qgenreportnodata', 'local_stream'), 'info', false);
} else {
    $typetable = new html_table();
    $typetable->attributes['class'] = 'generaltable w-auto mb-4';
    $typetable->head = [
            get_string('questionfromvideoqtypelabel', 'local_stream'),
            get_string('qgenreportquestions', 'local_stream'),
            get_string('qgenreportgenerations', 'local_stream'),
    ];
    foreach ($bytype as $row) {
        $typetable->data[] = [
                question_gen_report::qtype_label((string) $row->qtype),
                (int) $row->questions,
                (int) $row->sessions,
        ];
    }
    echo html_writer::table($typetable);
}

echo html_writer::tag('h3', get_string('qgenreportbycourse', 'local_stream'));
if (!$bycourse) {
    echo $OUTPUT->notification(get_string('qgenreportnodata', 'local_stream'), 'info', false);
} else {
    $coursetable = new html_table();
    $coursetable->attributes['class'] = 'generaltable w-auto';
    $coursetable->head = [
            get_string('course'),
            get_string('qgenreportquestions', 'local_stream'),
            get_string('qgenreportgenerations', 'local_stream'),
            get_string('qgenreportusers', 'local_stream'),
    ];
    foreach ($bycourse as $row) {
        $courseid = (int) $row->courseid;
        $coursename = $courseid;
        try {
            $course = get_course($courseid);
            $coursename = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $courseid]),
                    format_string($course->fullname)
            );
        } catch (Exception $e) {
            $coursename = get_string('qgenreportcoursedeleted', 'local_stream', $courseid);
        }
        $coursetable->data[] = [
                $coursename,
                (int) $row->questions,
                (int) $row->sessions,
                (int) $row->users,
        ];
    }
    echo html_writer::table($coursetable);
}

$backurl = new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']);
echo html_writer::div($OUTPUT->single_button($backurl, get_string('back'), 'get'), 'mt-4');

echo $OUTPUT->footer();
