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
 * Aggregated usage report for AI question generation from recordings.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_gen_report {

    /** @var int */
    private $from;

    /** @var int */
    private $to;

    /**
     * @param int $from Unix timestamp (inclusive), 0 = no lower bound.
     * @param int $to Unix timestamp (inclusive), 0 = no upper bound.
     */
    public function __construct(int $from, int $to) {
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * SQL fragment and params for the date window.
     *
     * @return array{0:string,1:array}
     */
    private function date_where(): array {
        $sql = '1=1';
        $params = [];
        if ($this->from > 0) {
            $sql .= ' AND timecreated >= :timefrom';
            $params['timefrom'] = $this->from;
        }
        if ($this->to > 0) {
            $sql .= ' AND timecreated <= :timeto';
            $params['timeto'] = $this->to;
        }
        return [$sql, $params];
    }

    /**
     * @return object{courses:int,questions:int,sessions:int}
     */
    public function summary(): object {
        global $DB;

        [$where, $params] = $this->date_where();
        $row = $DB->get_record_sql(
                "SELECT COUNT(DISTINCT courseid) AS courses,
                        COALESCE(SUM(questioncount), 0) AS questions,
                        COUNT(id) AS sessions
                   FROM {local_stream_qgen_log}
                  WHERE {$where}",
                $params
        );

        return (object) [
                'courses' => (int) ($row->courses ?? 0),
                'questions' => (int) ($row->questions ?? 0),
                'sessions' => (int) ($row->sessions ?? 0),
        ];
    }

    /**
     * @return array<int,object> qtype => row with qtype, questions, sessions
     */
    public function by_question_type(): array {
        global $DB;

        [$where, $params] = $this->date_where();
        $records = $DB->get_records_sql(
                "SELECT qtype,
                        SUM(questioncount) AS questions,
                        COUNT(id) AS sessions
                   FROM {local_stream_qgen_log}
                  WHERE {$where}
               GROUP BY qtype
               ORDER BY questions DESC",
                $params
        );

        return $records ?: [];
    }

    /**
     * @return array<int,object> courseid => row
     */
    public function by_course(): array {
        global $DB;

        [$where, $params] = $this->date_where();
        $records = $DB->get_records_sql(
                "SELECT courseid,
                        SUM(questioncount) AS questions,
                        COUNT(id) AS sessions,
                        COUNT(DISTINCT userid) AS users
                   FROM {local_stream_qgen_log}
                  WHERE {$where}
               GROUP BY courseid
               ORDER BY questions DESC",
                $params
        );

        return $records ?: [];
    }

    /**
     * Human-readable label for a stored qtype key.
     *
     * @param string $qtype
     * @return string
     */
    public static function qtype_label(string $qtype): string {
        switch ($qtype) {
            case 'multichoice':
                return get_string('questionfromvideoqtype_multichoice', 'local_stream');
            case 'shortanswer':
                return get_string('questionfromvideoqtype_shortanswer', 'local_stream');
            case 'truefalse':
                return get_string('questionfromvideoqtype_truefalse', 'local_stream');
            default:
                return $qtype;
        }
    }
}
