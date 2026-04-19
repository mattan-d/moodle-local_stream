<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Save Stream-generated JSON questions into the Moodle question bank under a video category (reused per video).
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

use core\context;
use core\exception\moodle_exception;
use core_question\category_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Question bank import helpers for Stream subtitle questions API payloads.
 */
class question_bank_import {

    /** API payload question types -> Moodle qtype keys used to validate responses. */
    private const TYPE_MAP = [
            'multichoice' => 'multiple_choice',
            'shortanswer' => 'short_answer',
            'truefalse' => 'true_false',
    ];

    /**
     * Get or create a child category for this Stream video (stable idnumber) and import all questions.
     *
     * @param \core\context $bankcontext Course context for the question bank.
     * @param array $payload Decoded JSON from Stream (expects questions[]).
     * @param string $videotitle Human-readable title for naming.
     * @param int $videoid Stream video id for naming / idnumber.
     * @param string $expectedqtype One of multichoice | shortanswer | truefalse (must match API batch).
     * @return array{error:string,category:?stdClass,questionids:int[]} category has id + contextid fields used for redirects.
     */
    public static function create_category_and_import(
            context $bankcontext,
            array $payload,
            string $videotitle,
            int $videoid,
            string $expectedqtype
    ): array {
        global $CFG, $DB;

        $out = [
                'error' => '',
                'category' => null,
                'questionids' => [],
        ];

        if (empty(self::TYPE_MAP[$expectedqtype])) {
            $out['error'] = 'invalidqtype';
            return $out;
        }

        $expectedapitype = self::TYPE_MAP[$expectedqtype];
        $questions = $payload['questions'] ?? [];
        if (!is_array($questions) || count($questions) < 1) {
            $out['error'] = 'noquestions';
            return $out;
        }

        foreach ($questions as $q) {
            if (!is_array($q) || (($q['type'] ?? '') !== $expectedapitype)) {
                $out['error'] = 'mismatchtype';
                return $out;
            }
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            $defaultcat = question_make_default_categories([$bankcontext]);
            if (!$defaultcat) {
                throw new moodle_exception('nocategory', 'question');
            }

            $name = shorten_text(
                    get_string(
                            'questionfromstreambankcategoryname',
                            'local_stream',
                            (object) [
                                    'title' => $videotitle !== ''
                                            ? $videotitle
                                            : get_string('questionfromstreamuntitled', 'local_stream'),
                                    'videoid' => $videoid,
                            ]
                    ),
                    255
            );
            $stableid = 'stream_vid_' . $videoid;

            $parent = $defaultcat->id . ',' . $defaultcat->contextid;
            $existing = $DB->get_record(
                    'question_categories',
                    ['contextid' => $bankcontext->id, 'idnumber' => $stableid],
                    'id,contextid',
                    IGNORE_MISSING
            );

            if ($existing) {
                $newcat = $existing;
            } else {
                $mgr = new category_manager();
                $newcatid = $mgr->add_category($parent, $name, '', FORMAT_HTML, $stableid);
                $newcat = $DB->get_record('question_categories', ['id' => $newcatid], 'id,contextid', MUST_EXIST);
            }

            \question_bank::load_question_definition_classes($expectedqtype);

            $index = 0;
            foreach ($questions as $raw) {
                $index++;
                $questionobj = self::build_new_question_skeleton($expectedqtype);
                $fromform = self::payload_item_to_form(
                        $expectedqtype,
                        $raw,
                        $newcat->id . ',' . $newcat->contextid,
                        $index
                );
                $qtype = \question_bank::get_qtype($expectedqtype);
                $saved = $qtype->save_question($questionobj, $fromform);
                $out['questionids'][] = (int) $saved->id;
            }

            $transaction->allow_commit();

            $cat = new \stdClass();
            $cat->id = (int) $newcat->id;
            $cat->contextid = (int) $newcat->contextid;
            $out['category'] = $cat;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            $out['error'] = $e->getMessage();
            if ($out['error'] === '') {
                $out['error'] = 'exception';
            }
        }

        return $out;
    }

    /**
     * @param string $qtype multichoice|shortanswer|truefalse
     */
    private static function build_new_question_skeleton(string $qtype): \stdClass {
        $q = new \stdClass();
        $q->qtype = $qtype;
        return $q;
    }

    /**
     * Build editing-form shaped object for save_question().
     *
     * @param array $item Single question from API payload.
     */
    private static function payload_item_to_form(
            string $qtype,
            array $item,
            string $categorypair,
            int $position
    ): \stdClass {
        switch ($qtype) {
            case 'multichoice':
                return self::form_multichoice($item, $categorypair, $position);
            case 'shortanswer':
                return self::form_shortanswer($item, $categorypair, $position);
            case 'truefalse':
                return self::form_truefalse($item, $categorypair, $position);
            default:
                throw new moodle_exception('unsupportedqtype');
        }
    }

    private static function form_multichoice(
            array $item,
            string $categorypair,
            int $position
    ): \stdClass {
        $stem = trim((string) ($item['question'] ?? ''));
        $options = $item['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        $cleanopts = [];
        foreach ($options as $opt) {
            $t = trim((string) $opt);
            if ($t !== '') {
                $cleanopts[] = $t;
            }
        }
        if (count($cleanopts) < 2) {
            throw new moodle_exception('notenoughanswers', 'qtype_multichoice', '', 2);
        }

        $correctindex = isset($item['correct_index']) ? (int) $item['correct_index'] : -1;
        if ($correctindex < 0 || $correctindex >= count($cleanopts)) {
            throw new moodle_exception('invalidparameter', 'core');
        }

        $n = count($cleanopts);
        $slots = $n + 1;

        $f = new \stdClass();
        $f->category = $categorypair;
        $f->name = shorten_text($stem !== '' ? $stem : ('Q' . $position), 100);
        $f->questiontext = ['text' => $stem !== '' ? $stem : get_string('question'), 'format' => FORMAT_HTML];
        $f->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $f->defaultmark = 1;
        $f->penalty = 0.3333333;
        $f->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $f->versionid = 0;
        $f->version = 1;
        $f->questionbankentryid = 0;
        $f->noanswers = $slots;
        $f->layout = 0;
        $f->shuffleanswers = 0;
        $f->answernumbering = 'abc';
        $f->showstandardinstruction = 0;
        $f->single = '1';
        $f->correctfeedback = [
                'text' => get_string('correctfeedbackdefault', 'question'),
                'format' => FORMAT_HTML,
        ];
        $f->partiallycorrectfeedback = [
                'text' => get_string('partiallycorrectfeedbackdefault', 'question'),
                'format' => FORMAT_HTML,
        ];
        $f->incorrectfeedback = [
                'text' => get_string('incorrectfeedbackdefault', 'question'),
                'format' => FORMAT_HTML,
        ];
        $f->shownumcorrect = 1;

        $fraction = [];
        $answer = [];
        $feedback = [];
        for ($i = 0; $i < $slots; $i++) {
            if ($i < $n) {
                $fraction[] = ($i === $correctindex) ? '1.0' : '0.0';
                $answer[$i] = ['text' => $cleanopts[$i], 'format' => FORMAT_PLAIN];
                $feedback[$i] = ['text' => '', 'format' => FORMAT_HTML];
            } else {
                $fraction[] = '0.0';
                $answer[$i] = ['text' => '', 'format' => FORMAT_PLAIN];
                $feedback[$i] = ['text' => '', 'format' => FORMAT_HTML];
            }
        }
        $f->fraction = $fraction;
        $f->answer = $answer;
        $f->feedback = $feedback;

        return $f;
    }

    private static function form_shortanswer(array $item, string $categorypair, int $position): \stdClass {
        $stem = trim((string) ($item['question'] ?? ''));
        $key = trim((string) ($item['answer_key'] ?? ''));
        if ($key === '') {
            throw new moodle_exception('invalidparameter', 'core');
        }

        $f = new \stdClass();
        $f->category = $categorypair;
        $f->name = shorten_text($stem !== '' ? $stem : ('Q' . $position), 100);
        $f->questiontext = ['text' => $stem !== '' ? $stem : get_string('question'), 'format' => FORMAT_HTML];
        $f->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $f->defaultmark = 1;
        $f->penalty = 1;
        $f->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $f->versionid = 0;
        $f->version = 1;
        $f->questionbankentryid = 0;
        $f->usecase = false;
        $f->answer = [$key, '*'];
        $f->fraction = ['1.0', '0.0'];
        $f->feedback = [
                ['text' => '', 'format' => FORMAT_HTML],
                ['text' => '', 'format' => FORMAT_HTML],
        ];

        return $f;
    }

    private static function form_truefalse(array $item, string $categorypair, int $position): \stdClass {
        $stem = trim((string) ($item['question'] ?? ''));
        if (!array_key_exists('correct_answer', $item)) {
            throw new moodle_exception('invalidparameter', 'core');
        }
        $correct = (bool) $item['correct_answer'];

        $f = new \stdClass();
        $f->category = $categorypair;
        $f->name = shorten_text($stem !== '' ? $stem : ('Q' . $position), 100);
        $f->questiontext = ['text' => $stem !== '' ? $stem : get_string('question'), 'format' => FORMAT_HTML];
        $f->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $f->defaultmark = 1;
        $f->penalty = 1;
        $f->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $f->versionid = 0;
        $f->version = 1;
        $f->questionbankentryid = 0;
        $f->correctanswer = $correct ? '1' : '0';
        $f->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
        $f->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
        $f->showstandardinstruction = 0;

        return $f;
    }
}
