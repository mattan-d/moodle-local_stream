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

    /** @var array<int, array<int, array{table:string,id:int,text:string,identifier:string}>> */
    private $coursetexts = [];

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
        require_once($CFG->dirroot . '/mod/stream/lib.php');

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
        if (!$streammodule) {
            mtrace('mod_stream is not installed; course activities cannot be created.');
        }

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

        $serverurl = $this->server_url($rec);
        $norm = self::normalize_link($serverurl);
        $fileid = trim((string) ($rec->fileid ?? ''));
        $streamid = (int) ($rec->streamid ?? 0);

        $owned = $this->owned_stream($rec, $courseid);
        if ($owned && $this->stream_matches($owned, $norm, $fileid, $streamid)) {
            $this->mark_embedded($rec, $courseid, (int) $owned->id, 'already matches course activity');
            return;
        }

        if ($owned) {
            if ($this->update_stream_link($owned, $serverurl, $streamid)) {
                $this->mark_embedded($rec, $courseid, (int) $owned->id, 'updated the course link', true);
            } else {
                $this->skip($rec, 'course activity #' . $owned->id . ' has no playback link to update');
            }
            return;
        }

        $hit = $this->find_course_hit($courseid, $norm, $fileid, $streamid);
        if ($hit) {
            $moduleid = $hit['table'] === 'stream' ? (int) $hit['id'] : 0;
            $where = $hit['table'] . ' #' . $hit['id'];
            $this->mark_embedded($rec, $courseid, $moduleid, 'link already present in ' . $where);
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
        if (!$streammodule) {
            $this->fail($rec, 'mod_stream is not installed');
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
            return;
        }

        $after = $DB->get_record('local_stream_rec', ['id' => $rec->id], 'id, embedded', MUST_EXIST);
        if ((int) $after->embedded === 1) {
            $this->counts['embedded']++;
        } else {
            $this->counts['failed']++;
        }
    }

    /**
     * @param \stdClass $rec
     * @return int
     */
    private function resolve_course(\stdClass $rec): int {
        global $DB;

        if (!empty($rec->moduleid)) {
            $stream = $DB->get_record('stream', ['id' => $rec->moduleid], 'id, course');
            if ($stream && $DB->record_exists('course', ['id' => $stream->course])) {
                return (int) $stream->course;
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
     * @param int $courseid
     * @return \stdClass|null
     */
    private function owned_stream(\stdClass $rec, int $courseid): ?\stdClass {
        global $DB;

        if (empty($rec->moduleid)) {
            return null;
        }
        $stream = $DB->get_record('stream', ['id' => $rec->moduleid, 'course' => $courseid]);
        return $stream ?: null;
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
     * @return array{table:string,id:int,text:string,identifier:string}|null
     */
    private function find_course_hit(int $courseid, string $norm, string $fileid, int $streamid): ?array {
        $fallback = null;
        foreach ($this->course_texts($courseid) as $item) {
            if (!$this->item_matches($item, $norm, $fileid, $streamid)) {
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
     * @return bool
     */
    private function item_matches(array $item, string $norm, string $fileid, int $streamid): bool {
        if ($streamid > 0 && $item['table'] === 'stream'
                && in_array((string) $streamid, stream_parse_identifiers($item['identifier']), true)) {
            return true;
        }
        if ($norm !== '' && self::text_has_url($item['text'], $norm)) {
            return true;
        }
        if (strlen($fileid) >= 12 && stripos($item['text'], $fileid) !== false) {
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
                if (!$replaced) {
                    $anchor = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">לחץ/י כאן לצפיה ישירה בהקלטה</a>';
                    $intro = trim($intro) === '' ? $anchor : ($intro . '<br>' . $anchor);
                }
                $stream->intro = $intro;
                $stream->introformat = FORMAT_HTML;
                $changed = true;
            }
        }

        if ($streamid > 0 && !in_array((string) $streamid, stream_parse_identifiers($stream->identifier ?? ''), true)) {
            $ids = stream_parse_identifiers($stream->identifier ?? '');
            $ids[] = (string) $streamid;
            $stream->identifier = implode(',', $ids);
            $stream->video_order = stream_sync_video_order($stream->identifier, $stream->video_order ?? '[]');
            $changed = true;
        }

        if (!$changed) {
            return false;
        }
        if ($this->dryrun) {
            mtrace('[dry-run] Would update stream activity #' . $stream->id . ' in course #' . $stream->course . '.');
            return true;
        }

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
     * @return array<int, array{table:string,id:int,text:string,identifier:string}>
     */
    private function course_texts(int $courseid): array {
        global $DB;

        if (isset($this->coursetexts[$courseid])) {
            return $this->coursetexts[$courseid];
        }

        $items = [];
        if ($DB->get_manager()->table_exists('stream')) {
            foreach ($DB->get_records('stream', ['course' => $courseid], '', 'id, intro, identifier') as $row) {
                $items[] = [
                        'table' => 'stream',
                        'id' => (int) $row->id,
                        'text' => (string) ($row->intro ?? ''),
                        'identifier' => (string) ($row->identifier ?? ''),
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
                || strpos($url, '/watch/') !== false;
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
