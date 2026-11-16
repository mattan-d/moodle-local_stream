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
 * Compare Teams playback links on the server with links already in the course.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Index Teams recordings for a day window and make each course link match the server.
 */
class teams_link_sync {

    /** @var \local_stream_help */
    private $help;

    /** @var int */
    private $days;

    /** @var int Course filter, or 0 for every course. */
    private $onlycourse;

    /** @var bool */
    private $dryrun;

    /** @var int */
    private $cutoff;

    /** @var array<int, array<int, array{table:string,id:int,text:string,identifier:string,name:string}>> */
    private $coursetexts = [];

    /** @var array<string, int> Recordings already placed in this run, keyed by course and identity. */
    private $placed = [];

    /** @var array<string, int> */
    private $counts = [
            'checked' => 0,
            'insync' => 0,
            'embedded' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'failed' => 0,
    ];

    /**
     * @param \local_stream_help $help
     * @param int $days How many days back to sync. 0 means since local midnight.
     * @param int $courseid Limit to one course, or 0 for all.
     * @param bool $dryrun When true, course activities are not created or edited.
     */
    public function __construct(\local_stream_help $help, int $days, int $courseid, bool $dryrun) {
        $this->help = $help;
        $this->days = $days;
        $this->onlycourse = $courseid;
        $this->dryrun = $dryrun;
        $this->cutoff = self::cutoff_timestamp($days);
    }

    /**
     * Unix timestamp of the oldest recording included in the window.
     *
     * @param int $days
     * @return int
     */
    public static function cutoff_timestamp(int $days): int {
        if ($days <= 0) {
            $midnight = strtotime('today midnight');
            return ($midnight !== false) ? $midnight : (time() - 86400);
        }
        return time() - ($days * 86400);
    }

    /**
     * List Teams files, then embed missing links and refresh course links that drifted.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $label = $this->days > 0 ? ('last ' . $this->days . ' day(s)') : 'since today midnight';
        mtrace('Teams link sync: ' . $label . ($this->dryrun ? ' (dry-run)' : '') . '.');
        if ($this->onlycourse > 0) {
            mtrace('Limited to course #' . $this->onlycourse . '.');
        }

        $this->help->teamslistingdays = $this->days;
        mtrace('Indexing Teams recordings from the server...');
        $this->help->listing_teams();

        $like = $DB->sql_like('recordingid', ':rectype', false, false);
        $iso = gmdate('Y-m-d\TH:i:s\Z', $this->cutoff);
        $select = $like . ' AND (starttime >= :iso OR timecreated >= :cut)
                       AND status NOT IN (:deleted, :invalid, :archive)';
        $params = [
                'rectype' => 't\_%',
                'iso' => $iso,
                'cut' => $this->cutoff,
                'deleted' => \local_stream_help::MEETING_STATUS_DELETED,
                'invalid' => \local_stream_help::MEETING_STATUS_INVALID,
                'archive' => \local_stream_help::MEETING_STATUS_ARCHIVE,
        ];
        $recordings = $DB->get_records_select('local_stream_rec', $select, $params, 'id ASC');

        $streammodule = $DB->get_record('modules', ['name' => 'stream']);
        $platformmodule = $DB->get_record('modules', ['name' => 'msteams']);

        foreach ($recordings as $rec) {
            if (!$this->in_window($rec)) {
                continue;
            }
            $this->sync_recording($rec, $streammodule ?: null, $platformmodule ?: null);
        }

        mtrace('Teams link sync finished.'
                . ' Checked: ' . $this->counts['checked']
                . ', already in sync: ' . $this->counts['insync']
                . ', embedded: ' . $this->counts['embedded']
                . ', links updated: ' . $this->counts['updated']
                . ', marked existing: ' . $this->counts['linked']
                . ', skipped: ' . $this->counts['skipped']
                . ', failed: ' . $this->counts['failed'] . '.');
    }

    /**
     * @param \stdClass $rec
     * @param \stdClass|null $streammodule
     * @param \stdClass|null $platformmodule
     * @return void
     */
    private function sync_recording(\stdClass $rec, $streammodule, $platformmodule): void {
        global $DB;

        $courseid = $this->resolve_course($rec);
        if ($this->onlycourse > 0 && $courseid !== $this->onlycourse) {
            return;
        }

        $this->counts['checked']++;
        if ((int) $rec->embedded === 5) {
            $this->skip($rec, 'grouped duplicate, left as-is');
            return;
        }
        if ($courseid <= 0) {
            $this->skip($rec, 'no course in the recording name or row');
            return;
        }

        $rec->course = $courseid;

        if (!$this->dryrun && empty($rec->streamid) && !empty($rec->fileid)) {
            $this->help->teams_refresh_recording_download_url($rec);
            $fresh = $DB->get_record('local_stream_rec', ['id' => $rec->id]);
            if ($fresh) {
                $rec = $fresh;
                $rec->course = $courseid;
            }
        }

        $this->help->apply_embed_preferences_for_email($rec->email ?? '');
        $serverurl = $this->server_url($rec);
        $norm = self::normalize_link($serverurl);
        $fileid = trim((string) ($rec->fileid ?? ''));
        $streamid = (int) ($rec->streamid ?? 0);
        $topic = (string) ($rec->topic ?? '');

        $already = $this->placed_instance($courseid, $rec, $norm);
        if ($already !== null) {
            $this->mark_embedded($rec, $courseid, $already, 'duplicate of a recording already placed in this run');
            return;
        }

        $existing = self::find_existing_stream($this->help, $rec);
        if ($existing) {
            $this->reuse_stream($rec, $existing, $serverurl, $norm, $fileid, $streamid, $courseid);
            return;
        }

        $hit = $this->find_course_hit($courseid, $norm, $fileid, $streamid, $topic);
        if ($hit) {
            if (($hit['table'] === 'url' || $hit['table'] === 'stream')
                    && $DB->get_manager()->table_exists($hit['table'])) {
                $activity = $DB->get_record($hit['table'], ['id' => $hit['id']]);
                if ($activity) {
                    $activity->modname = $hit['table'];
                    $this->reuse_stream($rec, $activity, $serverurl, $norm, $fileid, $streamid, $courseid);
                    return;
                }
            }
            $where = $hit['table'] . ' #' . $hit['id'];
            $this->mark_embedded($rec, $courseid, 0, 'link already present in ' . $where);
            $this->remember_placed($courseid, $rec, $norm, 0);
            return;
        }

        if (!$this->can_embed($rec, $serverurl, $streamid)) {
            $ready = (int) $rec->status === \local_stream_help::MEETING_STATUS_READY;
            $reason = $ready
                    ? 'no playback link on the server yet'
                    : 'not ready to embed (status ' . (int) $rec->status . ')';
            $this->skip($rec, $reason);
            return;
        }
        mtrace('#' . $rec->id . ': embedding into course #' . $courseid
                . ($this->dryrun ? ' (dry-run)' : '') . '.');
        embed_recording_helper::process_single(
                $this->help,
                $rec,
                $streammodule,
                $platformmodule,
                $this->dryrun,
                false
        );
        unset($this->coursetexts[$courseid]);

        if ($this->dryrun) {
            $this->counts['embedded']++;
            $this->remember_placed($courseid, $rec, $norm, 0);
            return;
        }

        $after = $DB->get_record('local_stream_rec', ['id' => $rec->id], 'id, embedded, moduleid', MUST_EXIST);
        if ((int) $after->embedded === 1) {
            $this->counts['embedded']++;
            $this->remember_placed($courseid, $rec, $norm, (int) $after->moduleid);
        } else {
            $this->counts['failed']++;
        }
    }

    /**
     * Keep a single course activity for this recording and point it at the server link.
     *
     * @param \stdClass $rec
     * @param \stdClass $stream
     * @param string $serverurl
     * @param string $norm
     * @param string $fileid
     * @param int $streamid
     * @param int $courseid
     * @return void
     */
    private function reuse_stream(
            \stdClass $rec,
            \stdClass $stream,
            string $serverurl,
            string $norm,
            string $fileid,
            int $streamid,
            int $courseid
    ): void {
        $updated = false;
        $modname = $stream->modname ?? 'stream';
        if ($modname === 'url') {
            $updated = $this->update_url_link($stream, $serverurl);
        } else if (!$this->stream_matches($stream, $norm, $fileid, $streamid)) {
            $updated = $this->update_stream_link($stream, $serverurl, $streamid);
        }
        $reason = $updated ? 'updated the existing course activity' : 'already in course, no second activity';
        $this->mark_embedded($rec, $courseid, (int) $stream->id, $reason, $updated);
        $this->remember_placed($courseid, $rec, $norm, (int) $stream->id);
    }

    /**
     * @param \stdClass $rec
     * @return int
     */
    private function resolve_course(\stdClass $rec): int {
        global $DB;

        if (!empty($rec->moduleid)) {
            $linked = self::linked_activity($this->help, (int) $rec->moduleid, (int) $rec->course);
            if ($linked && $DB->record_exists('course', ['id' => $linked->course])) {
                return (int) $linked->course;
            }
        }

        $parsed = $this->help->teams_course_data($rec->topic ?? '');
        $courseid = (int) ($parsed['courseid'] ?? -1);
        if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
            return $courseid;
        }

        $courseid = (int) $rec->course;
        if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
            return $courseid;
        }

        return 0;
    }

    /**
     * @param \stdClass $rec
     * @return string
     */
    private function server_url(\stdClass $rec): string {
        $url = $this->help->get_meeting($rec);
        if ($url instanceof \moodle_url) {
            return $url->out(false);
        }
        return is_string($url) ? $url : '';
    }

    /**
     * @param \stdClass $rec
     * @param string $serverurl
     * @param int $streamid
     * @return bool
     */
    private function can_embed(\stdClass $rec, string $serverurl, int $streamid): bool {
        if ((int) $rec->status !== \local_stream_help::MEETING_STATUS_READY) {
            return false;
        }
        if ($streamid > 0) {
            return true;
        }
        $nodownload = (int) $this->help->config->storage === \local_stream_help::STORAGE_NODOWNLOAD;
        return $nodownload && $serverurl !== '';
    }

    /**
     * @param int $courseid
     * @param string $norm
     * @param string $fileid
     * @param int $streamid
     * @param string $topic
     * @return array{table:string,id:int,text:string,identifier:string,name:string}|null
     */
    private function find_course_hit(int $courseid, string $norm, string $fileid, int $streamid, string $topic): ?array {
        $fallback = null;
        foreach ($this->course_texts($courseid) as $item) {
            if (!$this->item_matches($item, $norm, $fileid, $streamid, $topic)) {
                continue;
            }
            if ($item['table'] === 'stream') {
                return $item;
            }
            if ($fallback === null) {
                $fallback = $item;
            }
        }
        return $fallback;
    }

    /**
     * @param array{table:string,id:int,text:string,identifier:string} $item
     * @param string $norm
     * @param string $fileid
     * @param int $streamid
     * @param string $topic
     * @return bool
     */
    private function item_matches(array $item, string $norm, string $fileid, int $streamid, string $topic = ''): bool {
        if ($streamid > 0 && $item['table'] === 'stream'
                && in_array((string) $streamid, self::video_ids($item['identifier']), true)) {
            return true;
        }
        if ($norm !== '' && self::text_has_url($item['text'], $norm)) {
            return true;
        }
        if (strlen($fileid) >= 12 && stripos($item['text'], $fileid) !== false) {
            return true;
        }
        $prefix = (string) ($this->help->config->prefix ?? '');
        if ($item['table'] === 'stream' && self::same_recording_name($item['name'] ?? '', $topic, $prefix)) {
            return true;
        }
        if ($topic !== '' && self::text_has_filename($item['text'], $topic)) {
            return true;
        }
        return false;
    }

    /**
     * @param \stdClass $stream
     * @param string $norm
     * @param string $fileid
     * @param int $streamid
     * @return bool
     */
    private function stream_matches(\stdClass $stream, string $norm, string $fileid, int $streamid): bool {
        $item = [
                'table' => 'stream',
                'id' => (int) $stream->id,
                'text' => (string) ($stream->intro ?? ''),
                'identifier' => (string) ($stream->identifier ?? ''),
        ];
        return $this->item_matches($item, $norm, $fileid, $streamid);
    }

    /**
     * Point an existing stream activity at the current server link.
     *
     * @param \stdClass $stream
     * @param string $serverurl
     * @param int $streamid
     * @return bool True when the activity now carries the server link.
     */
    private function update_stream_link(\stdClass $stream, string $serverurl, int $streamid): bool {
        global $DB;

        $changed = false;
        $intro = (string) ($stream->intro ?? '');

        if ($serverurl !== '') {
            $href = $this->playback_href($serverurl);
            if (!self::text_has_url($intro, self::normalize_link($serverurl))) {
                list($intro, $replaced) = self::replace_first_recording_url($intro, $href);
                // A second anchor would show the recording twice. Replace the existing one, or write the first.
                if (!$replaced && !self::text_has_recording_url($intro)) {
                    $anchor = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">לחץ/י כאן לצפיה ישירה בהקלטה</a>';
                    $intro = trim($intro) === '' ? $anchor : ($intro . '<br>' . $anchor);
                    $replaced = true;
                }
                if ($replaced) {
                    $stream->intro = $intro;
                    $stream->introformat = FORMAT_HTML;
                    $changed = true;
                }
            }
        }

        if ($streamid > 0 && $DB->get_manager()->field_exists('stream', 'identifier')) {
            $ids = array_values(array_filter(
                    self::video_ids($stream->identifier ?? ''),
                    static function(string $id): bool {
                        return $id !== 'external';
                    }
            ));
            // Leave an activity that already plays a different video. Adding the id would show two recordings.
            if ($ids === []) {
                $stream->identifier = (string) $streamid;
                $order = json_encode([(string) $streamid]);
                if (function_exists('stream_sync_video_order')) {
                    $order = stream_sync_video_order($stream->identifier, $stream->video_order ?? '[]');
                }
                $stream->video_order = $order;
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }
        if ($this->dryrun) {
            mtrace('[dry-run] Would update stream activity #' . $stream->id . ' in course #' . $stream->course . '.');
            return true;
        }

        unset($stream->modname);
        $DB->update_record('stream', $stream);
        rebuild_course_cache($stream->course, true);
        unset($this->coursetexts[(int) $stream->course]);
        mtrace('Updated stream activity #' . $stream->id . ' in course #' . $stream->course . '.');
        return true;
    }

    /**
     * @param \stdClass $rec
     * @param int $courseid
     * @param int $moduleid Stream instance id, or 0 when the link lives outside mod_stream.
     * @param string $reason
     * @param bool $updated
     * @return void
     */
    private function mark_embedded(\stdClass $rec, int $courseid, int $moduleid, string $reason, bool $updated = false): void {
        global $DB;

        $needsrow = (int) $rec->embedded !== 1
                || (int) $rec->course !== $courseid
                || ($moduleid > 0 && (int) $rec->moduleid !== $moduleid);

        if ($updated) {
            $this->counts['updated']++;
        } else if (!$needsrow) {
            $this->counts['insync']++;
        } else {
            $this->counts['linked']++;
        }

        mtrace('#' . $rec->id . ': ' . $reason . ' (course #' . $courseid . ').');
        if (!$needsrow || $this->dryrun) {
            if ($needsrow && $this->dryrun) {
                mtrace('[dry-run] Would mark recording #' . $rec->id . ' as embedded.');
            }
            return;
        }

        $update = new \stdClass();
        $update->id = $rec->id;
        $update->embedded = 1;
        $update->course = $courseid;
        if ($moduleid > 0) {
            $update->moduleid = $moduleid;
        }
        $DB->update_record('local_stream_rec', $update);
    }

    /**
     * @param int $courseid
     * @return array<int, array{table:string,id:int,text:string,identifier:string,name:string}>
     */
    private function course_texts(int $courseid): array {
        global $DB;

        if (isset($this->coursetexts[$courseid])) {
            return $this->coursetexts[$courseid];
        }

        $items = [];
        if ($DB->get_manager()->table_exists('stream')) {
            foreach ($DB->get_records('stream', ['course' => $courseid], '', 'id, name, intro, identifier') as $row) {
                $items[] = [
                        'table' => 'stream',
                        'id' => (int) $row->id,
                        'text' => (string) ($row->intro ?? ''),
                        'identifier' => (string) ($row->identifier ?? ''),
                        'name' => (string) ($row->name ?? ''),
                ];
            }
        }
        foreach (['label' => 'intro', 'page' => 'intro, content', 'url' => 'intro, externalurl'] as $table => $fields) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            foreach ($DB->get_records($table, ['course' => $courseid], '', 'id, ' . $fields) as $row) {
                $text = '';
                foreach (explode(', ', $fields) as $field) {
                    $text .= ' ' . ($row->{$field} ?? '');
                }
                $items[] = [
                        'table' => $table,
                        'id' => (int) $row->id,
                        'text' => $text,
                        'identifier' => '',
                        'name' => '',
                ];
            }
        }
        if ($DB->get_manager()->table_exists('course_sections')) {
            foreach ($DB->get_records('course_sections', ['course' => $courseid], '', 'id, summary') as $row) {
                $items[] = [
                        'table' => 'course_sections',
                        'id' => (int) $row->id,
                        'text' => (string) ($row->summary ?? ''),
                        'identifier' => '',
                        'name' => '',
                ];
            }
        }

        $this->coursetexts[$courseid] = $items;
        return $items;
    }

    /**
     * @param \stdClass $rec
     * @return bool
     */
    private function in_window(\stdClass $rec): bool {
        $ts = strtotime((string) $rec->starttime);
        if ($ts !== false && $ts > 0) {
            return $ts >= $this->cutoff;
        }
        return (int) $rec->timecreated >= $this->cutoff;
    }

    /**
     * @param string $url
     * @return string Host plus decoded path, without query or fragment.
     */
    public static function normalize_link(string $url): string {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }
        $path = isset($parts['path']) ? rawurldecode($parts['path']) : '';
        return strtolower($parts['host'] . $path);
    }

    /**
     * @param string $text
     * @param string $norm
     * @return bool
     */
    private static function text_has_url(string $text, string $norm): bool {
        if ($norm === '') {
            return false;
        }
        foreach (self::extract_urls($text) as $url) {
            if (self::normalize_link($url) === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $text
     * @return string[]
     */
    private static function extract_urls(string $text): array {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        if (!preg_match_all('#https?://[^\s"\'<>]+#i', $text, $matches)) {
            return [];
        }
        $urls = [];
        foreach ($matches[0] as $url) {
            $urls[] = rtrim($url, ').,;]');
        }
        return $urls;
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function is_recording_url(string $url): bool {
        $url = strtolower($url);
        return strpos($url, 'sharepoint') !== false
                || strpos($url, '1drv.ms') !== false
                || strpos($url, 'onedrive') !== false
                || strpos($url, '/watch/') !== false
                || strpos($url, 'teams.microsoft') !== false
                || strpos($url, 'microsoftstream') !== false
                || strpos($url, 'stream.microsoft') !== false;
    }

    /**
     * SharePoint links open in the browser. Stream watch URLs stay unchanged.
     *
     * @param string $url
     * @return string
     */
    private function playback_href(string $url): string {
        if ($url === '' || stripos($url, '/watch/') !== false || stripos($url, 'web=1') !== false) {
            return $url;
        }
        $join = strpos($url, '?') === false ? '?' : '&';
        return $url . $join . 'web=1&csf=1';
    }

    /**
     * @param string $html
     * @param string $newurl
     * @return array{0:string,1:bool}
     */
    private static function replace_first_recording_url(string $html, string $newurl): array {
        $found = false;
        $updated = preg_replace_callback(
                '#https?://[^\s"\'<>]+#i',
                function(array $match) use ($newurl, &$found): string {
                    if ($found) {
                        return $match[0];
                    }
                    $current = rtrim(html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5), ').,;]');
                    if (!self::is_recording_url($current)) {
                        return $match[0];
                    }
                    $found = true;
                    return htmlspecialchars($newurl, ENT_QUOTES);
                },
                $html
        );
        return [$updated ?? $html, $found];
    }

    /**
     * Course activity already showing this Teams recording.
     *
     * URL activities are the normal placement. An older mod_stream activity is reused when one exists,
     * so a second activity is not created. mod_stream does not have to be installed.
     *
     * @param \local_stream_help $help
     * @param \stdClass $rec
     * @return \stdClass|null Row with modname url or stream.
     */
    public static function find_existing_stream(\local_stream_help $help, \stdClass $rec): ?\stdClass {
        global $DB;

        $help->apply_embed_preferences_for_email($rec->email ?? '');
        $courseid = (int) ($rec->course ?? 0);
        if ($courseid <= 0) {
            $parsed = $help->teams_course_data($rec->topic ?? '');
            $courseid = (int) ($parsed['courseid'] ?? 0);
            if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
                return null;
            }
        }

        if (!empty($rec->moduleid)) {
            $owned = self::linked_activity($help, (int) $rec->moduleid, $courseid);
            if ($owned) {
                return $owned;
            }
        }

        $fileid = trim((string) ($rec->fileid ?? ''));
        $streamid = (int) ($rec->streamid ?? 0);
        $serverurl = $help->get_meeting($rec);
        if ($serverurl instanceof \moodle_url) {
            $serverurl = $serverurl->out(false);
        }
        $serverurl = is_string($serverurl) ? $serverurl : '';
        $norm = self::normalize_link($serverurl);
        $topic = (string) ($rec->topic ?? '');
        $prefix = (string) ($help->config->prefix ?? '');

        $matches = [];
        foreach (['url', 'stream'] as $modname) {
            if (!$DB->get_manager()->table_exists($modname)) {
                continue;
            }
            $rows = $DB->get_records($modname, ['course' => $courseid]);
            foreach ($rows as $row) {
                if (!self::activity_is_recording($row, $modname, $norm, $fileid, $streamid, $topic, $prefix)) {
                    continue;
                }
                $row->modname = $modname;
                $matches[] = $row;
            }
        }

        if (strlen($fileid) >= 12) {
            $where = $DB->sql_compare_text('fileid', 4000) . ' = :fileid AND course = :course AND moduleid > 0';
            $params = ['fileid' => $fileid, 'course' => $courseid];
            if (!empty($rec->id)) {
                $where .= ' AND id <> :id';
                $params['id'] = (int) $rec->id;
            }
            $siblings = $DB->get_records_select('local_stream_rec', $where, $params, 'id ASC', 'id, moduleid, course');
            foreach ($siblings as $sibling) {
                $linked = self::linked_activity($help, (int) $sibling->moduleid, $courseid);
                if ($linked) {
                    $matches[] = $linked;
                }
            }
        }

        if (!$matches) {
            return null;
        }
        $unique = [];
        foreach ($matches as $row) {
            $unique[($row->modname ?? '') . ':' . $row->id] = $row;
        }
        $matches = array_values($unique);
        usort($matches, static function(\stdClass $a, \stdClass $b): int {
            $aname = $a->modname ?? '';
            $bname = $b->modname ?? '';
            if ($aname !== $bname) {
                return $aname === 'url' ? -1 : 1;
            }
            return (int) $a->id <=> (int) $b->id;
        });
        $first = $matches[0];
        if (count($matches) > 1) {
            mtrace('Teams: recording #' . (int) ($rec->id ?? 0) . ' matches ' . count($matches)
                    . ' activities in course #' . $courseid . '; reusing ' . $first->modname . ' #' . $first->id . '.');
        }
        return $first;
    }

    /**
     * @param \local_stream_help $help
     * @param int $instanceid
     * @param int $courseid
     * @return \stdClass|null
     */
    private static function linked_activity(\local_stream_help $help, int $instanceid, int $courseid): ?\stdClass {
        global $DB;

        $mods = (int) $help->config->platform === \local_stream_help::PLATFORM_TEAMS
                ? ['url', 'stream'] : ['stream', 'url'];
        foreach ($mods as $modname) {
            if (!$DB->get_manager()->table_exists($modname)) {
                continue;
            }
            $params = ['id' => $instanceid];
            if ($courseid > 0) {
                $params['course'] = $courseid;
            }
            $row = $DB->get_record($modname, $params);
            if (!$row) {
                continue;
            }
            $module = $DB->get_record('modules', ['name' => $modname], 'id');
            if (!$module) {
                continue;
            }
            $cm = $DB->get_record('course_modules', [
                    'course' => $row->course,
                    'module' => $module->id,
                    'instance' => $row->id,
            ]);
            if (!$cm) {
                continue;
            }
            $row->modname = $modname;
            return $row;
        }
        return null;
    }

    /**
     * @param \stdClass $row
     * @param string $modname
     * @param string $norm
     * @param string $fileid
     * @param int $streamid
     * @param string $topic
     * @param string $prefix
     * @return bool
     */
    private static function activity_is_recording(
            \stdClass $row,
            string $modname,
            string $norm,
            string $fileid,
            int $streamid,
            string $topic,
            string $prefix
    ): bool {
        $intro = (string) ($row->intro ?? '');
        $external = (string) ($row->externalurl ?? '');
        if ($modname === 'url' && $norm !== '' && self::normalize_link($external) === $norm) {
            return true;
        }
        if ($streamid > 0 && $modname === 'stream'
                && in_array((string) $streamid, self::video_ids($row->identifier ?? ''), true)) {
            return true;
        }
        $text = $intro . ' ' . $external;
        if ($norm !== '' && self::text_has_url($text, $norm)) {
            return true;
        }
        if (strlen($fileid) >= 12 && stripos($text, $fileid) !== false) {
            return true;
        }
        if (self::same_recording_name((string) ($row->name ?? ''), $topic, $prefix)) {
            return true;
        }
        return self::text_has_filename($text, $topic);
    }

    /**
     * @param string $identifier
     * @return string[]
     */
    private static function video_ids($identifier): array {
        $identifier = trim((string) $identifier);
        if ($identifier === '' || $identifier === 'auto_collection') {
            return [];
        }
        $ids = array_map('trim', explode(',', $identifier));
        $ids = array_filter($ids, static function(string $id): bool {
            return $id !== '' && $id !== 'auto_collection';
        });
        return array_values($ids);
    }

    /**
     * @param \stdClass $url
     * @param string $serverurl
     * @return bool
     */
    private function update_url_link(\stdClass $url, string $serverurl): bool {
        global $DB;

        $href = $this->playback_href($serverurl);
        if ($href === '' || self::normalize_link((string) ($url->externalurl ?? '')) === self::normalize_link($serverurl)) {
            return false;
        }
        if ($this->dryrun) {
            mtrace('[dry-run] Would update URL activity #' . $url->id . ' in course #' . $url->course . '.');
            return true;
        }
        unset($url->modname);
        $url->externalurl = $href;
        $url->timemodified = time();
        $DB->update_record('url', $url);
        rebuild_course_cache($url->course, true);
        unset($this->coursetexts[(int) $url->course]);
        mtrace('Updated URL activity #' . $url->id . ' in course #' . $url->course . '.');
        return true;
    }

    /**
     * Exact activity-name match for a Teams file, ignoring the embed prefix and a trailing date.
     *
     * Generic titles are ignored so two different meetings with the same short name stay separate.
     *
     * @param string $activityname
     * @param string $topic Recording file name.
     * @param string $prefix Site or teacher embed prefix.
     * @return bool
     */
    public static function same_recording_name(string $activityname, string $topic, string $prefix = ''): bool {
        $topic = self::canonical_recording_name($topic, '');
        if ($topic === '' || mb_strlen($topic) < 12) {
            return false;
        }
        if (mb_strlen($topic) < 24 && mb_strpos($topic, 'קורס') === false) {
            return false;
        }
        $name = self::canonical_recording_name($activityname, $prefix);
        if ($name === $topic) {
            return true;
        }
        // The embedder appends one " (date)" after the file name. Do not strip parentheses that belong to the title.
        $withoutdate = preg_replace('/\s*\([^)]{1,80}\)\s*$/u', '', $name);
        $withoutdate = trim((string) preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', $withoutdate));
        return $withoutdate !== $name && $withoutdate === $topic;
    }

    /**
     * Activity title reduced to the recording file name.
     *
     * @param string $name
     * @param string $prefix Embed prefix to remove from the front.
     * @return string
     */
    private static function canonical_recording_name(string $name, string $prefix): string {
        $name = self::norm_name($name);
        $prefix = self::norm_name($prefix);
        if ($prefix !== '' && mb_strpos($name, $prefix . ' ') === 0) {
            $name = trim(mb_substr($name, mb_strlen($prefix)));
        }
        $name = preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', $name);
        return trim($name);
    }

    /**
     * @param string $name
     * @return string
     */
    private static function norm_name(string $name): string {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
        $name = preg_replace('/\s+/u', ' ', trim($name));
        return mb_strtolower($name);
    }

    /**
     * @param string $text
     * @param string $topic
     * @return bool
     */
    private static function text_has_filename(string $text, string $topic): bool {
        $wanted = self::norm_name(preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', $topic));
        if ($wanted === '' || mb_strlen($wanted) < 12) {
            return false;
        }
        if (mb_strlen($wanted) < 24 && mb_strpos($wanted, 'קורס') === false) {
            return false;
        }
        foreach (self::extract_urls($text) as $url) {
            $parts = parse_url($url);
            $base = isset($parts['path']) ? basename(rawurldecode($parts['path'])) : '';
            $base = self::norm_name(preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', $base));
            if ($base !== '' && $base === $wanted) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $text
     * @return bool
     */
    private static function text_has_recording_url(string $text): bool {
        foreach (self::extract_urls($text) as $url) {
            if (self::is_recording_url($url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $courseid
     * @param \stdClass $rec
     * @param string $norm
     * @return int|null Stream instance already used in this run, or null when this recording is new.
     */
    private function placed_instance(int $courseid, \stdClass $rec, string $norm): ?int {
        foreach ($this->identity_keys($courseid, $rec, $norm) as $key) {
            if (array_key_exists($key, $this->placed)) {
                return (int) $this->placed[$key];
            }
        }
        return null;
    }

    /**
     * @param int $courseid
     * @param \stdClass $rec
     * @param string $norm
     * @param int $streaminstance
     * @return void
     */
    private function remember_placed(int $courseid, \stdClass $rec, string $norm, int $streaminstance): void {
        foreach ($this->identity_keys($courseid, $rec, $norm) as $key) {
            if (!array_key_exists($key, $this->placed)) {
                $this->placed[$key] = $streaminstance;
            }
        }
    }

    /**
     * @param int $courseid
     * @param \stdClass $rec
     * @param string $norm
     * @return string[]
     */
    private function identity_keys(int $courseid, \stdClass $rec, string $norm): array {
        $keys = [];
        $fileid = trim((string) ($rec->fileid ?? ''));
        if (strlen($fileid) >= 12) {
            $keys[] = $courseid . '|file|' . $fileid;
        }
        if ($norm !== '') {
            $keys[] = $courseid . '|url|' . $norm;
        }
        if ((int) ($rec->streamid ?? 0) > 0) {
            $keys[] = $courseid . '|stream|' . (int) $rec->streamid;
        }
        $topic = self::norm_name(preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', (string) ($rec->topic ?? '')));
        if ($topic !== '' && (mb_strlen($topic) >= 24 || mb_strpos($topic, 'קורס') !== false) && mb_strlen($topic) >= 12) {
            $keys[] = $courseid . '|name|' . $topic;
        }
        return $keys;
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

    /**
     * @param \stdClass $rec
     * @param string $reason
     * @return void
     */
    private function fail(\stdClass $rec, string $reason): void {
        $this->counts['failed']++;
        mtrace('#' . $rec->id . ': failed, ' . $reason . '.');
    }
}
