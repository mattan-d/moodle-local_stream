<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Generate questions from Stream video subtitles — generate section.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form: question count, type, hidden video/context fields.
 */
class question_from_video_form extends \moodleform {

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $cd = $this->_customdata ?? [];

        $courseid = (int) ($cd['courseid'] ?? 0);
        $cmid = (int) ($cd['cmid'] ?? 0);
        $page = (int) ($cd['page'] ?? 0);
        $returnurl = (string) ($cd['returnurl'] ?? '');
        $searchquery = (string) ($cd['searchquery'] ?? '');

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_INT);
        $mform->setDefault('course', $courseid);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->setDefault('cmid', $cmid);

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_RAW_TRIMMED);
        $mform->setDefault('returnurl', $returnurl);

        $mform->addElement('hidden', 'page');
        $mform->setType('page', PARAM_INT);
        $mform->setDefault('page', $page);

        $mform->addElement('hidden', 'searchq');
        $mform->setType('searchq', PARAM_RAW_TRIMMED);
        $mform->setDefault('searchq', $searchquery);

        $mform->addElement('hidden', 'videoid');
        $mform->setType('videoid', PARAM_INT);
        $mform->setDefault('videoid', 0);

        $mform->addElement(
                'static',
                'videoselection_help',
                '',
                get_string('questionfromstreamformvideohint', 'local_stream')
        );

        $counts = [];
        for ($i = 1; $i <= 20; $i++) {
            $counts[$i] = (string) $i;
        }

        $defaultcount = (int) ($cd['defaultcount'] ?? 5);
        $defaultcount = min(20, max(1, $defaultcount));

        $mform->addElement(
                'select',
                'count',
                get_string('questionfromvideoquestioncount', 'local_stream'),
                $counts
        );
        $mform->setType('count', PARAM_INT);
        $mform->setDefault('count', $defaultcount);

        $qtypes = [
                'multichoice' => get_string('pluginname', 'qtype_multichoice'),
                'shortanswer' => get_string('pluginname', 'qtype_shortanswer'),
                'truefalse' => get_string('pluginname', 'qtype_truefalse'),
        ];

        $defaultqtype = (string) ($cd['defaultqtype'] ?? 'multichoice');
        if (!isset($qtypes[$defaultqtype])) {
            $defaultqtype = 'multichoice';
        }

        $mform->addElement(
                'select',
                'qtype',
                get_string('questionfromvideoqtypelabel', 'local_stream'),
                $qtypes
        );
        $mform->setType('qtype', PARAM_ALPHA);
        $mform->setDefault('qtype', $defaultqtype);

        $this->add_action_buttons(false, get_string('questionfromstreamgenerate', 'local_stream'));
    }

    /**
     * Extra validation: require a selected video (hidden updated by JS).
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $videoid = isset($data['videoid']) ? (int) $data['videoid'] : 0;
        if ($videoid < 1) {
            // Attach to visible static element so the message is shown (hidden fields often hide errors).
            $errors['videoselection_help'] = get_string('questionfromstreampickvideorequired', 'local_stream');
        }
        return $errors;
    }
}
