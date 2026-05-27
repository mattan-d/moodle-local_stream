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

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Logs successful “questions from recording” imports for admin reports.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_gen_logger {

    /**
     * Record one successful generation batch.
     *
     * @param int $courseid
     * @param int $cmid Quiz course-module id (0 if none).
     * @param int $userid Moodle user who generated.
     * @param int $videoid Stream video id.
     * @param string $videotitle
     * @param string $qtype multichoice | shortanswer | truefalse
     * @param int $questioncount Number of questions saved.
     * @param int $categoryid Question category id.
     * @return void
     */
    public static function log_success(
            int $courseid,
            int $cmid,
            int $userid,
            int $videoid,
            string $videotitle,
            string $qtype,
            int $questioncount,
            int $categoryid
    ): void {
        global $DB;

        if ($courseid < 1 || $userid < 1 || $questioncount < 1) {
            return;
        }

        if (!in_array($qtype, ['multichoice', 'shortanswer', 'truefalse'], true)) {
            $qtype = 'multichoice';
        }

        $record = (object) [
                'courseid' => $courseid,
                'cmid' => max(0, $cmid),
                'userid' => $userid,
                'videoid' => max(0, $videoid),
                'videotitle' => shorten_text($videotitle, 255),
                'qtype' => $qtype,
                'questioncount' => $questioncount,
                'categoryid' => max(0, $categoryid),
                'timecreated' => time(),
        ];

        $DB->insert_record('local_stream_qgen_log', $record);
    }
}
