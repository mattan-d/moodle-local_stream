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
 * Count Zoom cloud recordings for one account.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Walks Zoom users and cloud recordings and prints a quantity report.
 */
class zoom_recordings_report {

    /** @var string */
    private $accountid;

    /** @var string */
    private $clientid;

    /** @var string */
    private $clientsecret;

    /** @var string */
    private $from;

    /** @var string */
    private $to;

    /** @var bool */
    private $includetrash;

    /** @var string */
    private $token = '';

    /**
     * @param string $accountid Zoom account ID.
     * @param string $clientid Server-to-Server OAuth client ID.
     * @param string $clientsecret Server-to-Server OAuth client secret.
     * @param string $from YYYY-MM-DD.
     * @param string $to YYYY-MM-DD.
     * @param bool $includetrash Also count recordings in the Zoom trash.
     */
    public function __construct(
            string $accountid,
            string $clientid,
            string $clientsecret,
            string $from,
            string $to,
            bool $includetrash
    ) {
        $this->accountid = $accountid;
        $this->clientid = $clientid;
        $this->clientsecret = $clientsecret;
        $this->from = $from;
        $this->to = $to;
        $this->includetrash = $includetrash;
    }

    /**
     * @return void
     */
    public function execute(): void {
        $this->token = $this->fetch_token();
        $users = $this->list_users();
        $ranges = self::month_ranges($this->from, $this->to);

        $meetings = [];
        $files = 0;
        $mp4 = 0;
        $bytes = 0;
        $bymonth = [];
        $byuser = [];

        $totalusers = count($users);
        $index = 0;
        foreach ($users as $user) {
            $index++;
            $email = (string) ($user->email ?? $user->id);
            mtrace('בודק משתמש ' . $index . '/' . $totalusers . ': ' . $email);
            $byuser[$email] = ['meetings' => 0, 'files' => 0, 'mp4' => 0, 'bytes' => 0];
            foreach ($ranges as $range) {
                $pages = $this->list_user_recordings((string) $user->id, $range[0], $range[1], false);
                if ($this->includetrash) {
                    $pages = array_merge($pages, $this->list_user_recordings((string) $user->id, $range[0], $range[1], true));
                }
                foreach ($pages as $meeting) {
                    $uuid = (string) ($meeting->uuid ?? '');
                    if ($uuid === '' || isset($meetings[$uuid])) {
                        continue;
                    }
                    $meetings[$uuid] = true;
                    $month = substr((string) ($meeting->start_time ?? $range[0]), 0, 7);
                    if (!isset($bymonth[$month])) {
                        $bymonth[$month] = ['meetings' => 0, 'files' => 0, 'mp4' => 0, 'bytes' => 0];
                    }
                    $bymonth[$month]['meetings']++;
                    $byuser[$email]['meetings']++;
                    $recordingfiles = $meeting->recording_files ?? [];
                    if (!is_array($recordingfiles)) {
                        continue;
                    }
                    foreach ($recordingfiles as $file) {
                        $files++;
                        $bymonth[$month]['files']++;
                        $byuser[$email]['files']++;
                        $size = (int) ($file->file_size ?? 0);
                        $bytes += $size;
                        $bymonth[$month]['bytes'] += $size;
                        $byuser[$email]['bytes'] += $size;
                        $type = strtoupper((string) ($file->file_type ?? ''));
                        if ($type === 'MP4') {
                            $mp4++;
                            $bymonth[$month]['mp4']++;
                            $byuser[$email]['mp4']++;
                        }
                    }
                }
            }
            if ($byuser[$email]['meetings'] === 0) {
                unset($byuser[$email]);
            }
        }

        ksort($bymonth);
        uasort($byuser, static function(array $a, array $b): int {
            return $b['files'] <=> $a['files'];
        });

        $this->print_report(count($users), count($meetings), $files, $mp4, $bytes, $bymonth, $byuser);
    }

    /**
     * @param string $from
     * @param string $to
     * @return array<int, array{0:string,1:string}>
     */
    public static function month_ranges(string $from, string $to): array {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $ranges = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $monthend = $cursor->modify('last day of this month');
            if ($monthend > $end) {
                $monthend = $end;
            }
            $ranges[] = [$cursor->format('Y-m-d'), $monthend->format('Y-m-d')];
            $cursor = $monthend->modify('+1 day');
        }
        return $ranges;
    }

    /**
     * @return string
     */
    private function fetch_token(): string {
        $url = 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode($this->accountid);
        $curl = new \curl();
        $curl->setHeader([
                'authorization: Basic ' . base64_encode($this->clientid . ':' . $this->clientsecret),
                'Content-Type: application/json',
        ]);
        $body = $curl->post($url, null, ['RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 30]);
        $response = json_decode($body);
        if (empty($response->access_token)) {
            $error = is_object($response) && !empty($response->reason) ? $response->reason : 'token request failed';
            if (is_object($response) && !empty($response->error)) {
                $error = (string) $response->error;
            }
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $error);
        }
        return (string) $response->access_token;
    }

    /**
     * @return \stdClass[]
     */
    private function list_users(): array {
        $users = [];
        foreach (['active', 'inactive'] as $status) {
            $token = '';
            do {
                $url = 'https://api.zoom.us/v2/users?page_size=300&status=' . $status;
                if ($token !== '') {
                    $url .= '&next_page_token=' . rawurlencode($token);
                }
                $response = $this->get_json($url);
                foreach ($response->users ?? [] as $user) {
                    if (!empty($user->id)) {
                        $users[(string) $user->id] = $user;
                    }
                }
                $token = (string) ($response->next_page_token ?? '');
            } while ($token !== '');
        }
        return array_values($users);
    }

    /**
     * @param string $userid
     * @param string $from
     * @param string $to
     * @param bool $trash
     * @return \stdClass[]
     */
    private function list_user_recordings(string $userid, string $from, string $to, bool $trash): array {
        $meetings = [];
        $token = '';
        do {
            $url = 'https://api.zoom.us/v2/users/' . rawurlencode($userid) . '/recordings'
                    . '?page_size=300&from=' . $from . '&to=' . $to;
            if ($trash) {
                $url .= '&trash=true';
            }
            if ($token !== '') {
                $url .= '&next_page_token=' . rawurlencode($token);
            }
            $response = $this->get_json($url);
            foreach ($response->meetings ?? [] as $meeting) {
                $meetings[] = $meeting;
            }
            $token = (string) ($response->next_page_token ?? '');
        } while ($token !== '');
        return $meetings;
    }

    /**
     * @param string $url
     * @return \stdClass
     */
    private function get_json(string $url): \stdClass {
        $attempts = 0;
        while ($attempts < 4) {
            $attempts++;
            $curl = new \curl();
            $curl->setHeader([
                    'authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
            ]);
            $body = $curl->get($url, [], ['RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 60]);
            $info = $curl->get_info();
            $code = (int) ($info['http_code'] ?? 0);
            if ($code === 429) {
                sleep(2 * $attempts);
                continue;
            }
            $response = json_decode($body);
            if ($code >= 400 || !is_object($response)) {
                $message = is_object($response) && !empty($response->message) ? (string) $response->message : 'HTTP ' . $code;
                throw new \moodle_exception('generalexceptionmessage', 'error', '', $message);
            }
            return $response;
        }
        throw new \moodle_exception('generalexceptionmessage', 'error', '', 'HTTP 429');
    }

    /**
     * @param int $users
     * @param int $meetings
     * @param int $files
     * @param int $mp4
     * @param int $bytes
     * @param array<string, array{meetings:int,files:int,mp4:int,bytes:int}> $bymonth
     * @param array<string, array{meetings:int,files:int,mp4:int,bytes:int}> $byuser
     * @return void
     */
    private function print_report(
            int $users,
            int $meetings,
            int $files,
            int $mp4,
            int $bytes,
            array $bymonth,
            array $byuser
    ): void {
        mtrace('');
        mtrace('דוח הקלטות Zoom');
        mtrace('חשבון: ' . $this->accountid);
        mtrace('טווח: ' . $this->from . ' עד ' . $this->to
                . ($this->includetrash ? ' (כולל פח)' : ' (בלי פח)'));
        mtrace('משתמשים שנבדקו: ' . $users);
        mtrace('פגישות עם הקלטות: ' . $meetings);
        mtrace('קבצי הקלטה: ' . $files);
        mtrace('מתוכם MP4: ' . $mp4);
        mtrace('נפח כולל: ' . self::format_bytes($bytes));
        mtrace('');
        mtrace('לפי חודש');
        foreach ($bymonth as $month => $row) {
            mtrace($month
                    . '  פגישות ' . $row['meetings']
                    . '  קבצים ' . $row['files']
                    . '  MP4 ' . $row['mp4']
                    . '  ' . self::format_bytes($row['bytes']));
        }
        mtrace('');
        mtrace('לפי מארח');
        foreach ($byuser as $email => $row) {
            mtrace($email
                    . '  פגישות ' . $row['meetings']
                    . '  קבצים ' . $row['files']
                    . '  MP4 ' . $row['mp4']
                    . '  ' . self::format_bytes($row['bytes']));
        }
    }

    /**
     * @param int $bytes
     * @return string
     */
    public static function format_bytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return round($value, $value >= 100 ? 0 : 1) . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }
}
