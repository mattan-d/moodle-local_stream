<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Pick a subtitled Stream video, then generate questions via Stream API (subtitle-based).
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once(__DIR__ . '/classes/form/question_from_video_form.php');

use local_stream\form\question_from_video_form;
use local_stream\question_bank_import;

$courseid = required_param('course', PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$course = get_course($courseid);
require_login($course);

$coursecontext = context_course::instance($course->id);
require_capability('moodle/question:add', $coursecontext);

if ($cmid) {
    $cm = get_coursemodule_from_id(null, $cmid, $courseid, false, MUST_EXIST);
    if ($cm->modname !== 'quiz') {
        throw new moodle_exception('invalidcoursemodule');
    }
}

$PAGE->set_course($course);
$PAGE->set_context($coursecontext);

$strtitle = get_string('aicreatequestionsmenu', 'local_stream');
$PAGE->set_title($strtitle);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$PAGE->requires->css('/local/stream/styles_question_from_video.css');

$perpage = 10;

if (trim((string) ($USER->email ?? '')) === '') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($strtitle);
    echo $OUTPUT->notification(get_string('questionfromstreamnoemail', 'local_stream'), 'warning');
    echo local_stream_question_from_video_footer_back($returnurl, $courseid, $cmid);
    echo $OUTPUT->footer();
    exit;
}

$help = new local_stream_help();

$formcountdefault = optional_param('count', 5, PARAM_INT);
$formcountdefault = min(20, max(1, (int) $formcountdefault));
$formqtypedefault = optional_param('qtype', 'multichoice', PARAM_RAW_TRIMMED);
if (!in_array($formqtypedefault, ['multichoice', 'shortanswer', 'truefalse'], true)) {
    $formqtypedefault = 'multichoice';
}

$result = $help->collect_user_subtitled_videos(clean_param($USER->email, PARAM_EMAIL));

if ($result['error']) {
    $code = $result['message'];
    if ($code === 'missingconfig') {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($strtitle);
        echo $OUTPUT->notification(get_string('questionfromstreammissingconfig', 'local_stream'), 'warning');
        echo local_stream_question_from_video_footer_back($returnurl, $courseid, $cmid);
        echo $OUTPUT->footer();
        exit;
    }
    if ($code === 'noemail') {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($strtitle);
        echo $OUTPUT->notification(get_string('questionfromstreamnoemail', 'local_stream'), 'warning');
        echo local_stream_question_from_video_footer_back($returnurl, $courseid, $cmid);
        echo $OUTPUT->footer();
        exit;
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading($strtitle);
    echo $OUTPUT->notification(
            get_string('questionfromstreamapierror', 'local_stream', s($code)),
            'error'
    );
    echo local_stream_question_from_video_footer_back($returnurl, $courseid, $cmid);
    echo $OUTPUT->footer();
    exit;
}

$allvideos = $result['videos'];
$totalvideosall = count($allvideos);

$search = optional_param('q', '', PARAM_RAW_TRIMMED);
if ($search === '') {
    $search = optional_param('searchq', '', PARAM_RAW_TRIMMED);
}
if (strlen($search) > 200) {
    $search = substr($search, 0, 200);
}
$filteredvideos = local_stream_filter_videos_by_search($allvideos, $search);
$total = count($filteredvideos);

$params = ['course' => $courseid];
if ($cmid) {
    $params['cmid'] = $cmid;
}
if ($returnurl !== '') {
    $params['returnurl'] = $returnurl;
}
if ($search !== '') {
    $params['q'] = $search;
}
$pageurl = new moodle_url('/local/stream/question_from_video.php', $params);

$page = optional_param('page', 0, PARAM_INT);
$page = max(0, $page);
$maxpage = $total > 0 ? (int) floor(($total - 1) / $perpage) : 0;
if ($page > $maxpage) {
    $page = $maxpage;
}
$slice = array_slice($filteredvideos, $page * $perpage, $perpage);

$urlwithpage = new moodle_url($pageurl, ['page' => $page]);
$PAGE->set_url($urlwithpage);

$formaction = new moodle_url($pageurl, ['page' => $page]);

$formcustomdata = [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'returnurl' => $returnurl,
        'page' => $page,
        'defaultcount' => $formcountdefault,
        'defaultqtype' => $formqtypedefault,
        'searchquery' => $search,
];

$mform = new question_from_video_form($formaction->out(false), $formcustomdata);

$generation_result = null;
$formsubmitteddata = null;
$notify_video_required = false;

if ($fromform = $mform->get_data()) {
    $formsubmitteddata = $fromform;
    $postvideoid = (int) $fromform->videoid;
    $formcount = min(20, max(1, (int) $fromform->count));
    $formqtype = (string) $fromform->qtype;
    if (!in_array($formqtype, ['multichoice', 'shortanswer', 'truefalse'], true)) {
        $formqtype = 'multichoice';
    }

    if (!has_capability('moodle/question:managecategory', $coursecontext)) {
        $generation_result = [
                'error' => true,
                'message' => get_string('questionfromstreamnocatcap', 'local_stream'),
        ];
    } else {
        $generation_result = $help->call_video_subtitle_questions_api($postvideoid, $formcount, $formqtype);
        if ($generation_result['error']) {
            $generation_result['message'] = local_stream_question_from_video_map_api_error($generation_result['message']);
        } else {
            $videotitle = local_stream_find_video_title($allvideos, $postvideoid);
            $payload = $generation_result['payload'] ?? [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $import = question_bank_import::create_category_and_import(
                    $coursecontext,
                    $payload,
                    $videotitle,
                    $postvideoid,
                    $formqtype
            );
            if ($import['error'] !== '') {
                $generation_result['error'] = true;
                $generation_result['message'] = local_stream_question_from_video_map_import_error($import['error']);
            } else if (!empty($import['category']) && !empty($import['questionids'])) {
                $redir = local_stream_question_bank_edit_url(
                        $courseid,
                        $cmid,
                        $import['category'],
                        $import['questionids']
                );
                redirect($redir);
            } else {
                $generation_result = [
                        'error' => true,
                        'message' => get_string('questionfromstreamsavefailed', 'local_stream', ''),
                ];
            }
        }
    }
} else if ($mform->is_submitted()) {
    $submitdata = $mform->get_submitted_data();
    if ($submitdata && (int) ($submitdata->videoid ?? 0) < 1) {
        $notify_video_required = true;
    }
}

if ($generation_result !== null && !empty($generation_result['error']) && $formsubmitteddata !== null) {
    $mform->set_data($formsubmitteddata);
}

$PAGE->requires->js_init_code(<<<'JS'
(function() {
  var root = document.querySelector('.local-stream-qfv-wrap');
  var hidden = document.querySelector('.local-stream-qfv-genform input[name="videoid"]');
  function sync(val) {
    if (hidden) { hidden.value = val; }
  }
  if (!root || !hidden) { return; }
  root.querySelectorAll('.local-stream-qfv-card').forEach(function(btn) {
    btn.addEventListener('click', function() {
      root.querySelectorAll('.local-stream-qfv-card').forEach(function(b) { b.classList.remove('selected'); });
      btn.classList.add('selected');
      sync(btn.getAttribute('data-video-id') || '0');
    });
  });
})();
JS
);

echo $OUTPUT->header();

echo $OUTPUT->heading($strtitle);

if ($notify_video_required) {
    echo $OUTPUT->notification(get_string('questionfromstreampickvideorequired', 'local_stream'), 'warning');
}

if ($generation_result !== null && $generation_result['error']) {
    echo $OUTPUT->notification($generation_result['message'], 'error');
}

echo html_writer::div(get_string('questionfromvideopickvideo', 'local_stream'), 'local-stream-qfv-picker-help text-muted');

if ($totalvideosall === 0) {
    echo $OUTPUT->notification(get_string('questionfromstreamnovideos', 'local_stream'), 'info');
} else {
    echo html_writer::start_div('card local-stream-qfv-search-panel mb-4 border-0 shadow-sm');
    echo html_writer::start_div('card-body p-3 p-md-4');

    echo html_writer::div(
            get_string('questionfromvideosearchheading', 'local_stream'),
            'local-stream-qfv-search-heading'
    );

    $searchformattrs = [
            'method' => 'get',
            'action' => (new moodle_url('/local/stream/question_from_video.php'))->out(false),
            'class' => 'local-stream-qfv-search',
            'role' => 'search',
            'aria-label' => get_string('questionfromvideosearchheading', 'local_stream'),
    ];
    echo html_writer::start_tag('form', $searchformattrs);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'course', 'value' => $courseid]);
    if ($cmid) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
    }
    if ($returnurl !== '') {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnurl', 'value' => $returnurl]);
    }

    echo html_writer::start_div('local-stream-qfv-search-controls');

    echo html_writer::start_div('input-group input-group-lg local-stream-qfv-search-field');
    echo html_writer::span(
            $OUTPUT->pix_icon('i/search', '', 'core'),
            'input-group-text local-stream-qfv-search-prefix',
            ['aria-hidden' => 'true']
    );
    echo html_writer::empty_tag('input', [
            'type' => 'search',
            'name' => 'q',
            'id' => 'local-stream-qfv-search-q',
            'value' => s($search),
            'class' => 'form-control',
            'placeholder' => get_string('questionfromvideosearchplaceholder', 'local_stream'),
            'maxlength' => '200',
            'autocomplete' => 'off',
            'enterkeyhint' => 'search',
            'aria-label' => get_string('questionfromvideosearchlabel', 'local_stream'),
    ]);
    echo html_writer::tag(
            'button',
            get_string('questionfromvideosearchsubmit', 'local_stream'),
            [
                    'type' => 'submit',
                    'class' => 'btn btn-primary px-3',
            ]
    );
    echo html_writer::end_div();

    if ($search !== '') {
        $clearparams = ['course' => $courseid];
        if ($cmid) {
            $clearparams['cmid'] = $cmid;
        }
        if ($returnurl !== '') {
            $clearparams['returnurl'] = $returnurl;
        }
        $clearurl = new moodle_url('/local/stream/question_from_video.php', $clearparams);
        echo html_writer::link($clearurl, get_string('questionfromvideosearchclear', 'local_stream'), [
                'class' => 'btn btn-outline-secondary local-stream-qfv-search-clear',
                'title' => get_string('questionfromvideosearchclear', 'local_stream'),
        ]);
    }

    echo html_writer::end_div();

    if ($search !== '') {
        echo html_writer::div(
                get_string(
                        'questionfromvideosearchactive',
                        'local_stream',
                        (object) ['q' => s($search)]
                ),
                'local-stream-qfv-search-active mt-2'
        );
    }

    echo html_writer::end_tag('form');

    echo html_writer::end_div();
    echo html_writer::end_div();

    if ($total === 0) {
        echo $OUTPUT->notification(get_string('questionfromvideosearchnoresults', 'local_stream'), 'info');
    }
}

if ($totalvideosall > 0 && $total > 0) {
    $from = $page * $perpage + 1;
    $to = min(($page + 1) * $perpage, $total);
    echo html_writer::div(
            get_string('questionfromstreampageinfo', 'local_stream', (object) ['from' => $from, 'to' => $to, 'total' => $total]),
            'small text-muted mb-2'
    );

    echo html_writer::start_div('local-stream-qfv-wrap');
    echo html_writer::start_div('local-stream-qfv-grid');

    foreach ($slice as $v) {
        if (!is_array($v)) {
            continue;
        }
        $vid = (int) ($v['id'] ?? 0);
        if ($vid < 1) {
            continue;
        }
        $rawtitle = trim((string) ($v['title'] ?? ''));
        $title = $rawtitle !== '' ? s($rawtitle) : get_string('questionfromstreamuntitled', 'local_stream');
        $thumb = isset($v['thumbnail']) ? clean_param($v['thumbnail'], PARAM_URL) : '';
        $duration = isset($v['duration']) ? s($v['duration']) : '';

        $attrs = [
                'type' => 'button',
                'class' => 'local-stream-qfv-card',
                'data-video-id' => (string) $vid,
        ];
        echo html_writer::start_tag('button', $attrs);
        if ($thumb !== '') {
            echo html_writer::empty_tag('img', ['src' => $thumb, 'alt' => '']);
        }
        echo html_writer::div($title, 'local-stream-qfv-card-title');
        if ($duration !== '') {
            echo html_writer::div($duration, 'local-stream-qfv-card-meta');
        }
        echo html_writer::end_tag('button');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl, 'page');

    echo html_writer::tag('h4', get_string('questionfromvideosectiongenerate', 'local_stream'), ['class' => 'mt-4']);

    echo html_writer::start_div('local-stream-qfv-genform');
    $mform->display();
    echo html_writer::end_div();
}

echo local_stream_question_from_video_footer_back($returnurl, $courseid, $cmid);

echo $OUTPUT->footer();

/**
 * Filter videos by title / id substring (case-insensitive UTF-8).
 *
 * @param array $videos Raw video rows from Stream.
 * @param string $query Search text.
 * @return array<int,array>
 */
function local_stream_filter_videos_by_search(array $videos, string $query): array {
    $query = trim($query);
    if ($query === '') {
        return $videos;
    }

    $out = [];
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        $title = (string) ($v['title'] ?? '');
        $id = (string) ($v['id'] ?? '');
        $match = false;
        if ($title !== '' && mb_stripos($title, $query, 0, 'UTF-8') !== false) {
            $match = true;
        }
        if (!$match && $id !== '' && (mb_stripos($id, $query, 0, 'UTF-8') !== false || $query === $id)) {
            $match = true;
        }
        if ($match) {
            $out[] = $v;
        }
    }

    return $out;
}

/**
 * Find Stream video title from collected list.
 *
 * @param array $videos Videos from collect_user_subtitled_videos.
 * @param int $videoid Stream video id.
 * @return string Trimmed title or ''.
 */
function local_stream_find_video_title(array $videos, int $videoid): string {
    foreach ($videos as $v) {
        if (!is_array($v)) {
            continue;
        }
        if ((int) ($v['id'] ?? 0) === $videoid) {
            return trim((string) ($v['title'] ?? ''));
        }
    }
    return '';
}

/**
 * URL for course or activity question bank filtered to one category (after saving).
 *
 * @param int $courseid Moodle course id.
 * @param int $cmid Course-module id or 0.
 * @param stdClass $category Category object with ->id and ->contextid (question_categories).
 * @param int[] $savedquestionids Moodle question ids that were saved (last becomes lastchanged).
 * @return moodle_url
 */
function local_stream_question_bank_edit_url(
        int $courseid,
        int $cmid,
        stdClass $category,
        array $savedquestionids
): moodle_url {
    $params = $cmid ? ['cmid' => $cmid] : ['courseid' => $courseid];
    $url = new moodle_url('/question/edit.php', $params);
    $pair = (int) $category->id . ',' . (int) $category->contextid;
    $url->param('cat', $pair);
    $url->param('category', $pair);
    if (!empty($savedquestionids)) {
        $url->param('lastchanged', end($savedquestionids));
    }
    return $url;
}

/**
 * Present import failures to the teacher.
 *
 * @param string $token Error token or raw exception message.
 * @return string Localised or safe text.
 */
function local_stream_question_from_video_map_import_error(string $token): string {
    $tok = trim($token);
    $known = [
            'noquestions' => get_string('questionfromstreamimportnone', 'local_stream'),
            'mismatchtype' => get_string('questionfromstreamimporttype', 'local_stream'),
            'invalidqtype' => get_string('questionfromstreamimporttype', 'local_stream'),
            'exception' => get_string('questionfromstreamsavefailed', 'local_stream', ''),
    ];
    if (isset($known[$tok])) {
        return $known[$tok];
    }
    // Raw exception message from save — append after colon for support visibility.
    $detail = $tok !== '' ? ': ' . s($tok) : '';
    return get_string('questionfromstreamsavefailed', 'local_stream', $detail);
}

/**
 * Map API / internal error tokens to display strings.
 *
 * @param string $message
 * @return string
 */
function local_stream_question_from_video_map_api_error(string $message): string {
    $map = [
            'missingconfig' => get_string('questionfromstreammissingconfig', 'local_stream'),
            'invalidjson' => get_string('questionfromstreaminvalidresponse', 'local_stream'),
            'invalidvideoid' => get_string('questionfromstreaminvalidvideoid', 'local_stream'),
            'apierror' => get_string('questionfromstreamgeneratefailed', 'local_stream'),
    ];
    if (isset($map[$message])) {
        return $map[$message];
    }
    return $message !== '' ? $message : get_string('questionfromstreamgeneratefailed', 'local_stream');
}

/**
 * Back link block for this page.
 *
 * @param string $returnurl
 * @param int $courseid
 * @param int $cmid
 * @return string HTML
 */
function local_stream_question_from_video_footer_back(string $returnurl, int $courseid, int $cmid): string {
    $back = null;
    if ($returnurl !== '') {
        $back = new moodle_url($returnurl);
    } else if ($cmid) {
        $back = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
    } else {
        $back = new moodle_url('/course/view.php', ['id' => $courseid]);
    }
    return html_writer::div(html_writer::link($back, get_string('back')), 'mt-3');
}
