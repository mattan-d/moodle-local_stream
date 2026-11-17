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
 * Embed ready recordings into courses whose idnumber appears in the meeting name.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Match a meeting-name code such as 31-2-31212 to course.idnumber and embed the recording.
 */
class zoom_course_embed {

    /** @var \local_stream_help */
    private $help;

    /** @var bool */
    private $collection;

    /** @var bool */
    private $dryrun;

    /** @var int */
    private $limit;

    /**
     * @param \local_stream_help $help
     * @param bool $collection One playlist activity per course.
     * @param bool $dryrun
     * @param int $limit 0 means no limit.
     */
    public function __construct(\local_stream_help $help, bool $collection, bool $dryrun, int $limit) {
        $this->help = $help;
        $this->collection = $collection;
        $this->dryrun = $dryrun;
        $this->limit = $limit;
    }

    /**
     * Codes in a meeting name. Example: 31-2-31212.
     *
     * @param string $topic
     * @return string[]
     */
    public static function course_codes(string $topic): array {
        if (!preg_match_all('/(?<![0-9])([0-9]+-[0-9]+-[0-9]+)(?![0-9])/', $topic, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    /**
     * @return void
     */
    public function execute(): void {
        global $DB;

        $courses = $this->courses_by_idnumber();
        $records = $DB->get_records(
                'local_stream_rec',
                [
                        'embedded' => 0,
                        'status' => \local_stream_help::MEETING_STATUS_READY,
                ],
                'timecreated ASC',
                '*',
                0,
                $this->limit > 0 ? $this->limit : 0
        );

        $embedded = 0;
        $skipped = 0;
        foreach ($records as $meeting) {
            $codes = self::course_codes((string) ($meeting->topic ?? ''));
            if (!$codes) {
                mtrace('דילוג על הקלטה #' . $meeting->id . ': אין מזהה קורס בשם המפגש');
                $skipped++;
                continue;
            }
            $targets = [];
            foreach ($codes as $code) {
                if (!empty($courses[$code])) {
                    foreach ($courses[$code] as $course) {
                        $targets[$course->id] = $course;
                    }
                }
            }
            if (!$targets) {
                mtrace('דילוג על הקלטה #' . $meeting->id . ': לא נמצא קורס עבור '
                        . implode(', ', $codes));
                $skipped++;
                continue;
            }
            $placed = false;
            foreach ($targets as $course) {
                if ($this->embed_in_course($meeting, $course)) {
                    $placed = true;
                }
            }
            if ($placed) {
                $embedded++;
            } else {
                $skipped++;
            }
        }

        mtrace('שובצו: ' . $embedded . '  דולגו: ' . $skipped);
    }

    /**
     * @return array<string, \stdClass[]>
     */
    private function courses_by_idnumber(): array {
        global $DB;
        $courses = $DB->get_records_select('course', 'id > 1 AND idnumber <> :empty', ['empty' => ''], '', 'id, idnumber, shortname, fullname');
        $map = [];
        foreach ($courses as $course) {
            $code = trim((string) $course->idnumber);
            if ($code === '') {
                continue;
            }
            $map[$code][] = $course;
        }
        return $map;
    }

    /**
     * @param \stdClass $meeting
     * @param \stdClass $course
     * @return bool
     */
    private function embed_in_course(\stdClass $meeting, \stdClass $course): bool {
        global $DB;

        $streamid = (string) ($meeting->streamid ?? '');
        $existing = $this->existing_instance((int) $course->id, $streamid);
        if ($existing > 0) {
            mtrace('הקלטה #' . $meeting->id . ' כבר בקורס #' . $course->id
                    . ' (' . $course->idnumber . ')');
            if (!$this->dryrun) {
                $this->mark_embedded($meeting, (int) $course->id, $existing);
            }
            return true;
        }

        $mode = $this->collection ? 'במצב אוסף' : 'כפעילות נפרדת';
        mtrace('שיבוץ הקלטה #' . $meeting->id . ' בקורס #' . $course->id
                . ' (' . $course->idnumber . ') ' . $mode);
        if ($this->dryrun) {
            return true;
        }

        $originalcourse = $meeting->course;
        $meeting->course = $course->id;
        $this->help->zoomembedcollection = $this->collection;
        $page = $this->help->add_module($meeting);
        $meeting->course = $originalcourse;
        if (!$page || empty($page->id)) {
            mtrace('נכשל שיבוץ הקלטה #' . $meeting->id . ' בקורס #' . $course->id);
            return false;
        }

        if ($this->collection) {
            $this->name_new_collection((int) $page->id, $course);
        }
        $this->mark_embedded($meeting, (int) $course->id, (int) $page->id);
        return true;
    }

    /**
     * @param int $courseid
     * @param string $streamid
     * @return int Stream instance id, or 0 when the recording is not in the course.
     */
    private function existing_instance(int $courseid, string $streamid): int {
        global $DB;
        if ($streamid === '' || $streamid === '0' || !$DB->get_manager()->table_exists('stream')) {
            return 0;
        }
        $streams = $DB->get_records('stream', ['course' => $courseid], '', 'id, identifier');
        foreach ($streams as $stream) {
            $ids = array_map('trim', explode(',', (string) $stream->identifier));
            if (in_array($streamid, $ids, true)) {
                return (int) $stream->id;
            }
        }
        return 0;
    }

    /**
     * The first video creates the playlist. Keep the course name instead of that meeting title.
     *
     * @param int $instanceid
     * @param \stdClass $course
     * @return void
     */
    private function name_new_collection(int $instanceid, \stdClass $course): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('stream')) {
            return;
        }
        $stream = $DB->get_record('stream', ['id' => $instanceid, 'course' => $course->id]);
        if (!$stream || empty($stream->collection_mode)) {
            return;
        }
        $ids = array_filter(array_map('trim', explode(',', (string) $stream->identifier)));
        if (count($ids) !== 1) {
            return;
        }
        $name = trim((string) ($course->shortname ?: $course->idnumber));
        if ($name === '') {
            return;
        }
        $stream->name = $name;
        $stream->timemodified = time();
        $DB->update_record('stream', $stream);
    }

    /**
     * @param \stdClass $meeting
     * @param int $courseid
     * @param int $moduleid
     * @return void
     */
    private function mark_embedded(\stdClass $meeting, int $courseid, int $moduleid): void {
        global $DB;
        $meeting->course = $courseid;
        if ($moduleid > 0) {
            $meeting->moduleid = $moduleid;
        }
        $meeting->embedded = 1;
        $meeting->embedded_at = time();
        $DB->update_record('local_stream_rec', $meeting);
    }
}
