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
 * Replace dead Stream-server links with the Teams playback link.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts local_stream_rec rows that were uploaded to Stream into Teams URL activities.
 */
class teams_stream_convert {

    /** @var \local_stream_help */
    private $help;

    /** @var int */
    private $onlycourse;

    /** @var int */
    private $limit;

    /** @var bool */
    private $dryrun;

    /** @var array<string, int> */
    private $counts = [
            'checked' => 0,
            'converted' => 0,
            'skipped' => 0,
            'failed' => 0,
    ];

    /**
     * @param \local_stream_help $help
     * @param int $courseid Limit to one course, or 0 for all.
     * @param int $limit Maximum rows, or 0 for all.
     * @param bool $dryrun When true, courses and recording rows are not changed.
     */
    public function __construct(\local_stream_help $help, int $courseid, int $limit, bool $dryrun) {
        $this->help = $help;
        $this->onlycourse = $courseid;
        $this->limit = $limit;
        $this->dryrun = $dryrun;
    }

    /**
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        mtrace('Stream to Teams conversion' . ($this->dryrun ? ' (dry-run)' : '') . '.');
        if ($this->onlycourse > 0) {
            mtrace('Limited to course #' . $this->onlycourse . '.');
        }

        $select = 'streamid > 0 AND status NOT IN (:deleted, :invalid, :archive)';
        $params = [
                'deleted' => \local_stream_help::MEETING_STATUS_DELETED,
                'invalid' => \local_stream_help::MEETING_STATUS_INVALID,
                'archive' => \local_stream_help::MEETING_STATUS_ARCHIVE,
        ];
        if ($this->onlycourse > 0) {
            $select .= ' AND (course = :course OR course = 0)';
            $params['course'] = $this->onlycourse;
        }
        $limit = $this->limit > 0 ? $this->limit : 0;
        $records = $DB->get_records_select('local_stream_rec', $select, $params, 'id ASC', '*', 0, $limit);

        foreach ($records as $rec) {
            $this->convert_recording($rec);
        }

        mtrace('Stream to Teams conversion finished.'
                . ' Checked: ' . $this->counts['checked']
                . ', converted: ' . $this->counts['converted']
                . ', skipped: ' . $this->counts['skipped']
                . ', failed: ' . $this->counts['failed'] . '.');
    }

    /**
     * @param \stdClass $rec
     * @return void
     */
    private function convert_recording(\stdClass $rec): void {
        global $DB;

        $this->counts['checked']++;
        $streamid = (int) $rec->streamid;
        if (!$this->dryrun && !empty($rec->fileid)) {
            $this->help->teams_refresh_recording_download_url($rec);
            $fresh = $DB->get_record('local_stream_rec', ['id' => $rec->id]);
            if ($fresh) {
                $rec = $fresh;
            }
        }

        $playurl = $this->teams_play_url($rec);
        if ($playurl === '') {
            $this->skip($rec, 'no Teams playback link on the recording');
            return;
        }

        $activities = $this->stream_activities($rec);
        $courses = [];
        foreach ($activities as $activity) {
            $courses[(int) $activity->course] = true;
        }
        if ((int) $rec->course > 0) {
            $courses[(int) $rec->course] = true;
        }
        if ($this->onlycourse > 0) {
            $courses = isset($courses[$this->onlycourse]) ? [$this->onlycourse => true] : [];
            if (!$courses) {
                $this->skip($rec, 'not in course #' . $this->onlycourse);
                return;
            }
        }
        if (!$courses && (int) $rec->course <= 0) {
            if ($this->dryrun) {
                mtrace('[dry-run] #' . $rec->id . ': would drop Stream id ' . $streamid
                        . ' and keep the Teams link. No course activity to replace.');
            } else {
                $this->save_row($rec, 0, 0, (int) $rec->embedded === 1 ? 0 : (int) $rec->embedded);
                mtrace('#' . $rec->id . ': Stream id cleared. Teams link kept. No course activity was attached.');
            }
            $this->counts['converted']++;
            return;
        }

        $primaryurl = 0;
        $primarycourse = (int) $rec->course > 0 ? (int) $rec->course : (int) array_key_first($courses);
        foreach (array_keys($courses) as $courseid) {
            $courseactivities = array_filter($activities, static function(\stdClass $activity) use ($courseid): bool {
                return (int) $activity->course === (int) $courseid;
            });
            $urlid = $this->ensure_url($rec, (int) $courseid, $playurl, $courseactivities);
            if ($urlid === null) {
                $this->counts['failed']++;
                return;
            }
            if (!$this->remove_stream_activities($streamid, $courseactivities)) {
                $this->counts['failed']++;
                return;
            }
            if ((int) $courseid === $primarycourse || $primaryurl === 0) {
                $primaryurl = $urlid;
                $primarycourse = (int) $courseid;
            }
        }

        if ($this->dryrun) {
            mtrace('[dry-run] #' . $rec->id . ': would convert Stream id ' . $streamid
                    . ' to Teams URL #' . $primaryurl . ' in course #' . $primarycourse . '.');
        } else {
            $this->save_row($rec, $primarycourse, $primaryurl, 1);
            mtrace('#' . $rec->id . ': converted Stream id ' . $streamid
                    . ' to Teams URL #' . $primaryurl . ' in course #' . $primarycourse . '.');
        }
        $this->counts['converted']++;
    }

    /**
     * @param \stdClass $rec
     * @return string
     */
    private function teams_play_url(\stdClass $rec): string {
        $data = $rec->recordingdata ?? '';
        if (is_string($data)) {
            $data = json_decode($data);
        }
        $url = (is_object($data) && !empty($data->play_url)) ? (string) $data->play_url : '';
        if ($url === '' || stripos($url, '/watch/') !== false) {
            return '';
        }
        $streamurl = (string) ($this->help->config->streamurl ?? '');
        $streamhost = parse_url($streamurl, PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        if ($streamhost && $host && strcasecmp((string) $streamhost, (string) $host) === 0) {
            return '';
        }
        if (stripos($url, 'web=1') !== false) {
            return $url;
        }
        $join = strpos($url, '?') === false ? '?' : '&';
        return $url . $join . 'web=1&csf=1';
    }

    /**
     * @param \stdClass $rec
     * @return array<int, \stdClass>
     */
    private function stream_activities(\stdClass $rec): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('stream') || (int) $rec->streamid <= 0) {
            return [];
        }
        $found = [];
        $courseid = (int) $rec->course;
        if (!empty($rec->moduleid)) {
            $params = ['id' => $rec->moduleid];
            if ($courseid > 0) {
                $params['course'] = $courseid;
            }
            $owned = $DB->get_record('stream', $params);
            if ($owned && $this->references_stream($owned, (int) $rec->streamid)) {
                $found[(int) $owned->id] = $owned;
            }
        }

        if ($courseid > 0) {
            $candidates = $DB->get_records('stream', ['course' => $courseid]);
        } else {
            $like = $DB->sql_like('identifier', ':ident', false);
            $candidates = $DB->get_records_select(
                    'stream',
                    $like,
                    ['ident' => '%' . $DB->sql_like_escape((string) (int) $rec->streamid) . '%']
            );
        }
        foreach ($candidates as $candidate) {
            if ($this->references_stream($candidate, (int) $rec->streamid)) {
                $found[(int) $candidate->id] = $candidate;
            }
        }
        return $found;
    }

    /**
     * @param \stdClass $activity
     * @param int $streamid
     * @return bool
     */
    private function references_stream(\stdClass $activity, int $streamid): bool {
        $wanted = (string) $streamid;
        foreach ($this->identifier_ids($activity->identifier ?? '') as $id) {
            if ($id === $wanted) {
                return true;
            }
        }
        $text = (string) ($activity->intro ?? '') . ' ' . (string) ($activity->identifier ?? '');
        return (bool) preg_match('#/watch/' . preg_quote($wanted, '#') . '([^\d]|$)#', $text);
    }

    /**
     * @param \stdClass $rec
     * @param int $courseid
     * @param string $playurl
     * @param array<int, \stdClass> $activities
     * @return int|null URL instance id, or null on failure.
     */
    private function ensure_url(\stdClass $rec, int $courseid, string $playurl, array $activities): ?int {
        global $CFG, $DB;

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            mtrace('#' . $rec->id . ': course #' . $courseid . ' does not exist.');
            return null;
        }
        $existing = $this->find_url($rec, $courseid, $playurl);
        if ($existing) {
            if (!$this->dryrun
                    && teams_link_sync::normalize_link((string) $existing->externalurl)
                        !== teams_link_sync::normalize_link($playurl)) {
                $existing->externalurl = $playurl;
                $existing->timemodified = time();
                $DB->update_record('url', $existing);
                rebuild_course_cache($courseid, true);
            }
            return (int) $existing->id;
        }
        if ($this->dryrun) {
            mtrace('[dry-run] #' . $rec->id . ': would add a Teams URL in course #' . $courseid . '.');
            return 0;
        }

        $template = $activities ? reset($activities) : null;
        $cm = $template ? $this->stream_cm($template) : null;
        $sectionnum = 0;
        if ($cm) {
            $section = $DB->get_record('course_sections', ['id' => $cm->section], 'id, section');
            if ($section) {
                $sectionnum = (int) $section->section;
            }
        }
        $name = $template && !empty($template->name) ? (string) $template->name : (string) $rec->topic;
        $visible = $cm ? (int) $cm->visible : (int) $rec->visible;

        require_once($CFG->libdir . '/resourcelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $module = new \stdClass();
        $module->course = $courseid;
        $module->modulename = 'url';
        $module->section = $sectionnum;
        $module->idnumber = $cm && $cm->idnumber !== '' ? $cm->idnumber : ('meeting-' . $rec->meetingid);
        $module->visible = $visible;
        $module->externalurl = $playurl;
        $module->display = RESOURCELIB_DISPLAY_OPEN;
        $module->introeditor = [
                'text' => '',
                'format' => FORMAT_HTML,
        ];
        $module->name = $name !== '' ? $name : 'Recording';

        try {
            $created = create_module($module);
        } catch (\Exception $e) {
            mtrace('#' . $rec->id . ': failed to create Teams URL in course #' . $courseid . ': ' . $e->getMessage());
            return null;
        }
        $instanceid = !empty($created->instance) ? (int) $created->instance : (int) $created->id;
        if ($instanceid <= 0) {
            mtrace('#' . $rec->id . ': Teams URL in course #' . $courseid . ' was not created.');
            return null;
        }
        return $instanceid;
    }

    /**
     * @param \stdClass $rec
     * @param int $courseid
     * @param string $playurl
     * @return \stdClass|null
     */
    private function find_url(\stdClass $rec, int $courseid, string $playurl): ?\stdClass {
        global $DB;

        if (!$DB->get_manager()->table_exists('url')) {
            return null;
        }
        $this->help->apply_embed_preferences_for_email($rec->email ?? '');
        $prefix = (string) ($this->help->config->prefix ?? '');
        $norm = teams_link_sync::normalize_link($playurl);
        $streamid = (int) $rec->streamid;
        foreach ($DB->get_records('url', ['course' => $courseid]) as $url) {
            $external = (string) $url->externalurl;
            if ($norm !== '' && teams_link_sync::normalize_link($external) === $norm) {
                return $url;
            }
            if ($streamid > 0 && preg_match('#/watch/' . $streamid . '([^\d]|$)#', $external)) {
                return $url;
            }
            if (preg_match('#/watch/\d+#', $external)) {
                continue;
            }
            if (teams_link_sync::same_recording_name((string) $url->name, (string) $rec->topic, $prefix)) {
                return $url;
            }
        }
        return null;
    }

    /**
     * @param int $streamid
     * @param array<int, \stdClass> $activities
     * @return bool
     */
    private function remove_stream_activities(int $streamid, array $activities): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        foreach ($activities as $activity) {
            $ids = $this->identifier_ids($activity->identifier ?? '');
            $keep = array_values(array_filter($ids, static function(string $id) use ($streamid): bool {
                return $id !== (string) $streamid;
            }));
            $cm = $this->stream_cm($activity);
            if ($keep) {
                if ($this->dryrun) {
                    mtrace('[dry-run] Would remove Stream id ' . $streamid . ' from activity #' . $activity->id . '.');
                    continue;
                }
                $activity->identifier = implode(',', $keep);
                if (!empty($activity->video_order)) {
                    $order = json_decode($activity->video_order, true);
                    if (is_array($order)) {
                        $order = array_values(array_filter($order, static function($id) use ($streamid): bool {
                            return (string) $id !== (string) $streamid;
                        }));
                        $activity->video_order = json_encode($order);
                    }
                }
                $DB->update_record('stream', $activity);
                rebuild_course_cache($activity->course, true);
                continue;
            }
            if (!$cm) {
                continue;
            }
            if ($this->dryrun) {
                mtrace('[dry-run] Would delete Stream activity #' . $activity->id . ' in course #' . $activity->course . '.');
                continue;
            }
            try {
                course_delete_module($cm->id);
            } catch (\Exception $e) {
                mtrace('Failed to delete Stream activity #' . $activity->id . ': ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * @param \stdClass $activity
     * @return \stdClass|null
     */
    private function stream_cm(\stdClass $activity): ?\stdClass {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'stream'], 'id');
        if (!$module) {
            return null;
        }
        $cm = $DB->get_record('course_modules', [
                'course' => $activity->course,
                'module' => $module->id,
                'instance' => $activity->id,
        ]);
        return $cm ?: null;
    }

    /**
     * @param \stdClass $rec
     * @param int $courseid
     * @param int $urlid
     * @param int $embedded
     * @return void
     */
    private function save_row(\stdClass $rec, int $courseid, int $urlid, int $embedded): void {
        global $DB;

        $update = new \stdClass();
        $update->id = $rec->id;
        $update->streamid = 0;
        $update->status = \local_stream_help::MEETING_STATUS_READY;
        $update->embedded = $embedded;
        if ($courseid > 0) {
            $update->course = $courseid;
        }
        if ($urlid > 0) {
            $update->moduleid = $urlid;
            $update->embedded = 1;
        }
        $DB->update_record('local_stream_rec', $update);
    }

    /**
     * @param string $identifier
     * @return string[]
     */
    private function identifier_ids($identifier): array {
        $identifier = trim((string) $identifier);
        if ($identifier === '' || $identifier === 'auto_collection' || $identifier === 'external') {
            return [];
        }
        $ids = array_map('trim', explode(',', $identifier));
        $ids = array_filter($ids, static function(string $id): bool {
            return $id !== '' && $id !== 'auto_collection' && $id !== 'external';
        });
        return array_values($ids);
    }

    /**
     * @param \stdClass $rec
     * @param string $reason
     * @return void
     */
    private function skip(\stdClass $rec, string $reason): void {
        $this->counts['skipped']++;
        mtrace('#' . $rec->id . ': skipped, ' . $reason . '.');
    }
}
