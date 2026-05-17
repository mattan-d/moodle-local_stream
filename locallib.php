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
 * local_stream locallib
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/message/lib.php');

/**
 * lib functions
 *
 * @package    local_stream
 * @category   admin
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_stream_help {

    /**
     * @var stdClass Configuration for the local_stream plugin
     */
    public $config;

    /**
     * @var cache Cache object for the streamdata
     */
    public $cache;

    /**
     * SharePoint listing: try OData $filter=createdDateTime ge … on children; auto-disabled if Graph returns an error.
     *
     * @var bool
     */
    private $teamsgraphuseserversidefilter = true;

    /**
     * Constant representing the Zoom platform.
     */
    public const PLATFORM_ZOOM = 0;

    /**
     * Constant representing the Webex platform.
     */
    public const PLATFORM_WEBEX = 1;

    /**
     * Constant representing the Teams platform.
     */
    public const PLATFORM_TEAMS = 2;

    /**
     * Constant representing the Unicko platform.
     */
    public const PLATFORM_UNICKO = 3;

    /**
     * Constant representing the queue status of a meeting.
     */
    public const MEETING_STATUS_QUEUE = 0;

    /**
     * Constant representing the process status of a meeting.
     */
    public const MEETING_STATUS_PROCESS = 1;

    /**
     * Constant representing the ready status of a meeting.
     */
    public const MEETING_STATUS_READY = 2;

    /**
     * Constant representing the deleted status of a meeting.
     */
    public const MEETING_STATUS_DELETED = 5;

    /**
     * Constant representing the invalid status of a meeting.
     */
    public const MEETING_STATUS_INVALID = 6;

    /**
     * Constant representing the archive status of a meeting.
     */
    public const MEETING_STATUS_ARCHIVE = 7;

    /**
     * Storage type for no download.
     *
     * @var int
     */
    public const STORAGE_NODOWNLOAD = 3;

    /**
     * Storage type for stream.
     *
     * @var int
     */
    public const STORAGE_STREAM = 4;

    /**
     * Get the human-readable platform name.
     *
     * @return string The platform name.
     */
    public function get_platform_name() {
        switch ($this->config->platform) {
            case self::PLATFORM_ZOOM:
                return 'Zoom';
            case self::PLATFORM_WEBEX:
                return 'Webex';
            case self::PLATFORM_TEAMS:
                return 'Microsoft Teams';
            case self::PLATFORM_UNICKO:
                return 'Unicko';
            default:
                return 'Unknown';
        }
    }

    /**
     * Constructor function.
     * Initializes the object by setting the 'config' property using the 'local_stream' configuration.
     */
    public function __construct() {
        $this->config = get_config('local_stream');
        $this->cache = cache::make('local_stream', 'streamdata');
    }

    /**
     * Get the meeting URL for a given recording.
     *
     * @param object $data Recording data.
     * @return mixed|string|null URL of the file or null if not found.
     */
    public function get_meeting($data) {
        global $DB;

        $meeting = $DB->get_record('local_stream_rec', ['id' => $data->id]);
        if ($meeting) {
            if ($meeting->streamid > 0) {
                return new moodle_url($this->config->streamurl . '/watch/' . $meeting->streamid);
            } else {
                $meeting->recordingdata = json_decode($meeting->recordingdata);

                // Zoom.
                if (isset($meeting->recordingdata->play_url)) {
                    return $meeting->recordingdata->play_url;
                }

                // Webex.
                if (isset($meeting->recordingdata->playbackUrl)) {
                    return $meeting->recordingdata->playbackUrl;
                }
            }
        }
    }

    /**
     * Encode UUID.
     *
     * @param string $uuid The UUID to encode.
     * @return string The encoded UUID.
     */
    public function encode_uuid($uuid) {
        return (substr($uuid, 0, 1) == '/' || strpos($uuid, '//')) ? urlencode(urlencode($uuid)) : $uuid;
    }

    /**
     * Get Zoom API token.
     *
     * @return string|null The access token if successful, otherwise null.
     */
    public function get_zoom_token() {

        $config = get_config('local_stream');

        $url = 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . $config->accountid;

        $options = [
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,
        ];

        $header = [
                'authorization: Basic ' . base64_encode($config->clientid . ':' . $config->clientsecret),
                'Content-Type: application/json',
        ];

        $curl = new \curl();
        $curl->setHeader($header);
        $jsonresult = $curl->post($url, null, $options);
        $response = json_decode($jsonresult);

        if (!isset($response->access_token)) {
            mtrace('Task: ' . $response->error);
            return false;
        }

        return $response->access_token ?? null;
    }

    /**
     * Get the Webex API token.
     *
     * This method retrieves the Webex API token from the configuration.
     *
     * @return string The Webex API token.
     */
    public function get_webex_token() {
        return $this->config->webexjwt;
    }

    /**
     * Calls the Zoom API with the provided parameters.
     *
     * @param string $url The API endpoint (e.g., 'users/me').
     * @param array $jsondata An associative array containing data to be sent in the request body.
     * @param string $method The HTTP method for the request (default is 'GET').
     * @param bool $getinfo Whether to retrieve cURL information (default is false).
     * @param bool $debug Whether to output errors to the debugging log (default is false).
     *
     * @return mixed Returns the decoded JSON response from the Zoom API.
     * @throws Exception If an error occurs during the API request.
     */
    public function call_zoom_api($url, $jsondata = [], $method = 'get', $getinfo = false, $debug = false) {

        static $jwt;
        if (!isset($jwt)) {
            $jwt = $this->get_zoom_token();
        }

        $options = [
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,
        ];

        $header = [
                'authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
        ];

        $curl = new \curl();
        $curl->setHeader($header);
        $body = (!empty($jsondata) && in_array($method, ['patch', 'post', 'put'])) ? json_encode($jsondata) : $jsondata;
        $jsonresult = $curl->$method('https://api.zoom.us/v2/' . $url, $body, $options);
        $response = json_decode($jsonresult);

        if ($response && !empty($response->message) && $debug) {
            mtrace('Error: ' . $response->message);
        }

        if ($getinfo) {
            return $curl->get_info();
        }

        return $response;
    }

    /**
     * Delete a Zoom cloud recording via Zoom API.
     * Used when "Delete Zoom recording after embedding" is set (e.g. 1, 3, 6, 12 hours).
     *
     * @param stdClass $meeting local_stream_rec record with meetingdata (json) and recordingid.
     * @return bool True if delete requested successfully (204), false otherwise.
     */
    public function delete_zoom_cloud_recording($meeting) {
        $meetingdata = is_string($meeting->meetingdata) ? json_decode($meeting->meetingdata) : $meeting->meetingdata;
        if (empty($meetingdata->uuid) || empty($meeting->recordingid)) {
            mtrace('delete_zoom_cloud_recording: missing uuid or recordingid for record #' . $meeting->id);
            return false;
        }
        $url = 'meetings/' . $this->encode_uuid($meetingdata->uuid) . '/recordings/' . $meeting->recordingid;
        $info = $this->call_zoom_api($url, [], 'delete', true, true);
        // Zoom returns 204 No Content on success.
        if (is_array($info) && isset($info['http_code']) && (int) $info['http_code'] === 204) {
            return true;
        }
        if (is_object($info) && !empty($info->message)) {
            mtrace('delete_zoom_cloud_recording: Zoom API error for record #' . $meeting->id . ': ' . $info->message);
        }
        return false;
    }

    /**
     * Parse Zoom storage string (e.g. "1 GB", "29 MB") to GB (float).
     *
     * @param string $s Value such as "1 GB" or "29 MB".
     * @return float|null GB value or null if unparseable.
     */
    public function parse_zoom_storage_string($s) {
        if (!is_string($s) || trim($s) === '') {
            return null;
        }
        $s = trim($s);
        if (preg_match('/^[\d.,]+$/', $s)) {
            return (float) str_replace(',', '.', $s);
        }
        if (preg_match('/^([\d.,]+)\s*(GB|MB|KB)$/i', $s, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            switch (strtoupper($m[2])) {
                case 'GB': return $num;
                case 'MB': return $num / 1024;
                case 'KB': return $num / (1024 * 1024);
            }
        }
        return null;
    }

    /**
     * Fetch Zoom account statistics (users count, licensed vs basic, licenses in account, storage usage).
     * Requires Zoom platform to be configured and scopes: user:read:admin, report:read:admin (for storage).
     * Total licenses in account may require billing/plan scope (e.g. account:read:admin or billing:read).
     *
     * @return stdClass { total_users, licensed_users, basic_users, total_licenses_in_account, storage_used_gb, storage_total_gb, error }
     */
    public function get_zoom_account_stats() {
        $result = (object) [
            'total_users' => 0,
            'licensed_users' => 0,
            'basic_users' => 0,
            'total_licenses_in_account' => null,
            'storage_used_gb' => null,
            'storage_total_gb' => null,
            'error' => null,
        ];
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            $result->error = 'not_zoom';
            return $result;
        }
        try {
            if (!empty($this->config->accountid)) {
                $plans = $this->call_zoom_api('accounts/' . $this->config->accountid . '/plans', [], 'get', false, true);
                if ($plans && empty($plans->message)) {
                    if (isset($plans->plans) && is_array($plans->plans)) {
                        foreach ($plans->plans as $plan) {
                            if (isset($plan->plan_user_count) && $plan->plan_user_count !== null) {
                                $result->total_licenses_in_account = (int) $plan->plan_user_count;
                                break;
                            }
                            if (isset($plan->hosts) && $plan->hosts !== null) {
                                $result->total_licenses_in_account = (int) $plan->hosts;
                                break;
                            }
                        }
                    }
                    if ($result->total_licenses_in_account === null && isset($plans->plan_user_count)) {
                        $result->total_licenses_in_account = (int) $plans->plan_user_count;
                    }
                }
            }
            $nexttoken = '';
            do {
                $url = 'users?page_size=300&status=active';
                if ($nexttoken !== '') {
                    $url .= '&next_page_token=' . urlencode($nexttoken);
                }
                $response = $this->call_zoom_api($url, [], 'get', false, true);
                if (empty($response->users) && empty($response->total_records)) {
                    if (!empty($response->message)) {
                        $result->error = $response->message;
                        return $result;
                    }
                    break;
                }
                foreach ($response->users as $user) {
                    $result->total_users++;
                    $type = isset($user->type) ? (int) $user->type : 1;
                    if ($type === 2) {
                        $result->licensed_users++;
                    } else {
                        $result->basic_users++;
                    }
                }
                $nexttoken = isset($response->next_page_token) ? $response->next_page_token : '';
            } while ($nexttoken !== '');

            $to = date('Y-m-d', strtotime('-1 day'));
            $from = date('Y-m-d', strtotime('-30 days'));
            $reporturl = 'report/cloud_recording?from=' . $from . '&to=' . $to;
            $report = $this->call_zoom_api($reporturl, [], 'get', false, true);
            if (!empty($report->message)) {
                $result->storage_used_gb = null;
            } else if (!empty($report->cloud_recording_storage)) {
                $last = end($report->cloud_recording_storage);
                $usedgb = null;
                if (isset($last->usage) && $last->usage !== '') {
                    $usedgb = $this->parse_zoom_storage_string($last->usage);
                }
                if ($usedgb === null && (isset($last->plan_usage) || isset($last->free_usage))) {
                    $usedgb = 0;
                    if (isset($last->plan_usage)) {
                        $p = $this->parse_zoom_storage_string($last->plan_usage);
                        if ($p !== null) {
                            $usedgb += $p;
                        }
                    }
                    if (isset($last->free_usage)) {
                        $f = $this->parse_zoom_storage_string($last->free_usage);
                        if ($f !== null) {
                            $usedgb += $f;
                        }
                    }
                }
                $result->storage_used_gb = $usedgb !== null ? round($usedgb, 2) : null;
            }
        } catch (\Exception $e) {
            $result->error = $e->getMessage();
        }
        return $result;
    }

    /**
     * Get Zoom user details (includes last_login_time, type). For revoke-inactive-license checks.
     *
     * @param string $userid Zoom user id or email.
     * @return stdClass|null User object or null on error.
     */
    public function get_zoom_user($userid) {
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            return null;
        }
        $response = $this->call_zoom_api('users/' . $userid, [], 'get', false, true);
        if (!empty($response->message) || empty($response->id)) {
            return null;
        }
        return $response;
    }

    /**
     * List Zoom groups.
     *
     * @return array|null Array of group objects or null on error (e.g. missing scope group:read:admin).
     */
    public function get_zoom_groups() {
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            return [];
        }
        $response = $this->call_zoom_api('groups?page_size=300', [], 'get', false, true);
        if ($response === null || !empty($response->message)) {
            return null;
        }
        return isset($response->groups) && is_array($response->groups) ? $response->groups : [];
    }

    /**
     * Get Zoom group members user IDs.
     *
     * @param string $groupid Zoom group id.
     * @return array|null Array of user ids in group, or null on error.
     */
    public function get_zoom_group_member_user_ids($groupid) {
        if ($this->config->platform != $this::PLATFORM_ZOOM || $groupid === '') {
            return [];
        }
        $userids = [];
        $nexttoken = '';
        do {
            $url = 'groups/' . $groupid . '/members?page_size=300';
            if ($nexttoken !== '') {
                $url .= '&next_page_token=' . urlencode($nexttoken);
            }
            $response = $this->call_zoom_api($url, [], 'get', false, true);
            if ($response === null || !empty($response->message)) {
                return null;
            }
            if (!empty($response->members) && is_array($response->members)) {
                foreach ($response->members as $m) {
                    if (!empty($m->id)) {
                        $userids[(string) $m->id] = true;
                    }
                }
            }
            $nexttoken = isset($response->next_page_token) ? $response->next_page_token : '';
        } while ($nexttoken !== '');

        return array_keys($userids);
    }

    /**
     * Get set of Zoom user ids that are currently in a live meeting (dashboard API).
     * Returns null if API fails (e.g. missing scope dashboard_meetings:read:admin).
     *
     * @return array|null Array of user ids in live meetings, or null on error.
     */
    public function get_zoom_live_meeting_participant_user_ids() {
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            return [];
        }
        $userids = [];
        try {
            $from = date('Y-m-d');
            $to = $from;
            $url = 'metrics/meetings?type=live&from=' . $from . '&to=' . $to . '&page_size=300';
            $response = $this->call_zoom_api($url, [], 'get', false, true);
            if (!empty($response->message)) {
                return null;
            }
            $meetings = isset($response->meetings) ? $response->meetings : [];
            foreach ($meetings as $meeting) {
                $meetingid = isset($meeting->uuid) ? $meeting->uuid : (isset($meeting->id) ? $meeting->id : null);
                if (!$meetingid) {
                    continue;
                }
                $participantsurl = 'metrics/meetings/' . $this->encode_uuid($meetingid) . '/participants?type=live&page_size=300';
                $parts = $this->call_zoom_api($participantsurl, [], 'get', false, true);
                if (!empty($parts->message) || empty($parts->participants)) {
                    continue;
                }
                foreach ($parts->participants as $p) {
                    $uid = isset($p->user_id) ? $p->user_id : (isset($p->id) ? $p->id : null);
                    if ($uid) {
                        $userids[$uid] = true;
                    }
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        return array_keys($userids);
    }

    /**
     * Get scheduled meetings for a user that start within the next N hours.
     *
     * @param string $userid Zoom user id or email.
     * @param int $hours Number of hours ahead to check.
     * @return array List of meetings (with start_time).
     */
    public function get_zoom_user_scheduled_meetings_next_hours($userid, $hours) {
        if ($this->config->platform != $this::PLATFORM_ZOOM || $hours < 1) {
            return [];
        }
        $response = $this->call_zoom_api('users/' . $userid . '/meetings?type=scheduled&page_size=100', [], 'get', false, true);
        if (!empty($response->message) || empty($response->meetings)) {
            return [];
        }
        $deadline = time() + ($hours * 3600);
        $upcoming = [];
        foreach ($response->meetings as $m) {
            if (empty($m->start_time)) {
                continue;
            }
            $start = is_numeric($m->start_time) ? (int) $m->start_time : strtotime($m->start_time);
            if ($start !== false && $start <= $deadline && $start >= time() - 300) {
                $upcoming[] = $m;
            }
        }
        return $upcoming;
    }

    /**
     * Revoke Zoom license: set user type to Basic (1). Requires user:write:admin.
     *
     * @param string $userid Zoom user id or email.
     * @return bool True on success.
     */
    public function revoke_zoom_user_license($userid) {
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            return false;
        }
        $response = $this->call_zoom_api('users/' . $userid, ['type' => 1], 'patch', false, true);
        if ($response === null || !empty($response->message)) {
            mtrace('revoke_zoom_user_license: ' . $response->message . ' for user ' . $userid);
            return false;
        }
        return true;
    }

    /**
     * Grant Zoom license: set user type to Licensed (2). Requires user:write:admin.
     *
     * @param string $userid Zoom user id or email.
     * @return bool True on success.
     */
    public function grant_zoom_user_license($userid) {
        if ($this->config->platform != $this::PLATFORM_ZOOM) {
            return false;
        }
        $response = $this->call_zoom_api('users/' . $userid, ['type' => 2], 'patch', false, true);
        if ($response === null || !empty($response->message)) {
            mtrace('grant_zoom_user_license: ' . ($response->message ?? 'unknown error') . ' for user ' . $userid);
            return false;
        }
        return true;
    }

    /** Minimum hours since last login to allow revoke. */
    const ZOOM_REVOKE_LAST_LOGIN_HOURS = 6;

    /**
     * Run revoke-inactive-license: find licensed Zoom users who meet all 3 conditions and set them to Basic.
     * Conditions: (1) last login >= 6h ago, (2) not in a live meeting, (3) no meeting in next 2 hours.
     *
     * @return array [ 'revoked' => int, 'skipped' => int, 'error' => string|null ]
     */
    public function run_revoke_inactive_zoom_licenses() {
        $result = ['revoked' => 0, 'skipped' => 0, 'error' => null];
        if ($this->config->platform != $this::PLATFORM_ZOOM || empty($this->config->zoom_revoke_inactive_license)) {
            return $result;
        }

        // Exclude users that belong to selected Zoom groups.
        $excludedusers = [];
        $excludedcsv = (string) ($this->config->zoom_revoke_excluded_groupids ?? '');
        $excludedgroupids = $excludedcsv !== '' ? array_filter(array_map('trim', explode(',', $excludedcsv))) : [];
        if (!empty($excludedgroupids)) {
            foreach ($excludedgroupids as $gid) {
                $members = $this->get_zoom_group_member_user_ids($gid);
                if ($members === null) {
                    $result['error'] = 'Could not fetch Zoom group members for exclusions; skipping revoke run.';
                    return $result;
                }
                foreach ($members as $uid) {
                    $excludedusers[(string) $uid] = true;
                }
            }
        }

        $sixhoursago = time() - (self::ZOOM_REVOKE_LAST_LOGIN_HOURS * 3600);
        $usersinmeeting = $this->get_zoom_live_meeting_participant_user_ids();
        if ($usersinmeeting === null) {
            $result['error'] = 'Could not fetch live meeting participants; skipping revoke run.';
            return $result;
        }
        $usersinmeeting = array_flip($usersinmeeting);
        $nexttoken = '';
        do {
            $url = 'users?page_size=300&status=active';
            if ($nexttoken !== '') {
                $url .= '&next_page_token=' . urlencode($nexttoken);
            }
            $response = $this->call_zoom_api($url, [], 'get', false, true);
            if (empty($response->users) && empty($response->total_records)) {
                if (!empty($response->message)) {
                    $result['error'] = $response->message;
                }
                break;
            }
            foreach ($response->users as $user) {
                if (isset($user->type) && (int) $user->type !== 2) {
                    continue;
                }
                $uid = $user->id;
                if (!empty($excludedusers) && isset($excludedusers[(string) $uid])) {
                    $result['skipped']++;
                    continue;
                }
                if (isset($usersinmeeting[$uid])) {
                    $result['skipped']++;
                    continue;
                }
                $detail = $this->get_zoom_user($uid);
                if (!$detail) {
                    $result['skipped']++;
                    continue;
                }
                $lastlogin = null;
                if (!empty($detail->last_login_time)) {
                    $lastlogin = strtotime($detail->last_login_time);
                }
                if ($lastlogin !== null && $lastlogin > $sixhoursago) {
                    $result['skipped']++;
                    continue;
                }
                $scheduled = $this->get_zoom_user_scheduled_meetings_next_hours($uid, 2);
                if (!empty($scheduled)) {
                    $result['skipped']++;
                    continue;
                }
                if ($this->revoke_zoom_user_license($uid)) {
                    $result['revoked']++;
                    mtrace('Revoked Zoom license for user: ' . ($detail->email ?? $uid));
                } else {
                    $result['skipped']++;
                }
            }
            $nexttoken = isset($response->next_page_token) ? $response->next_page_token : '';
        } while ($nexttoken !== '');

        return $result;
    }

    /**
     * Call the Webex API.
     *
     * This method sends a request to the Webex API and returns the response.
     *
     * @param string $url The API endpoint.
     * @param array $jsondata The data to send in JSON format (default is an empty array).
     * @param string $method The HTTP method to use (default is 'get').
     * @param bool $getinfo Whether to retrieve cURL request information (default is false).
     * @return object|array|null The response from the Webex API, or null if an error occurs.
     *
     * @throws \Exception If the cURL request fails.
     */
    public function call_webex_api($url, $jsondata = [], $method = 'get', $getinfo = false) {

        static $jwt;
        if (!isset($jwt)) {
            $jwt = $this->get_webex_token();
        }

        $options = [
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,
        ];

        $header = [
                'authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
        ];

        $curl = new \curl();
        $curl->setHeader($header);
        $jsonresult = $curl->$method('https://webexapis.com/v1/' . $url, $jsondata, $options);

        $headerresponse = $curl->getResponse();
        if (preg_match('/<([^>]+)>/', $headerresponse['link'], $matches)) {
            $response = json_decode($jsonresult);
            $response->next_page = $matches[1];
        } else {
            $response = json_decode($jsonresult);
        }

        if ($response->message) {
            mtrace('Error: ' . $response->message);
        }

        if ($getinfo) {
            return $curl->get_info();
        }

        return $response;
    }

    /**
     * Makes a request to the Unicko API.
     *
     * @param string $path The API endpoint path.
     * @param array $jsondata The data to send in the request (default empty array).
     * @param string $method The HTTP method to use (default 'get').
     * @param bool $getinfo Whether to return cURL info (default false).
     * @return mixed The API response or cURL info, depending on $getinfo.
     */
    public function call_unicko_api($path, $jsondata = [], $method = 'get', $getinfo = false) {

        $options = [
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,
        ];

        $header = [
                'authorization: Basic ' . base64_encode($this->config->unickokey . ':' . $this->config->unickosecret),
                'Content-Type: application/json',
        ];

        $curl = new \curl();
        $curl->setHeader($header);
        $jsonresult = $curl->$method('https://api.unicko.com/v1/' . $path, $jsondata, $options);

        $headerresponse = $curl->getResponse();
        if (preg_match('/<([^>]+)>/', $headerresponse['link'], $matches)) {
            $response = json_decode($jsonresult);
            $response->next_page = $matches[1];
        } else {
            $response = json_decode($jsonresult);
        }

        if ($response->message) {
            mtrace('Error: ' . $response->message);
        }

        if ($getinfo) {
            return $curl->get_info();
        }

        return $response;
    }

    /**
     * Fetch meetings data.
     *
     * @param stdClass $data The data object.
     * @param array $tmp Temporary array to accumulate meetings from each page (used in recursive calls).
     * @return array|null The meetings data if successful, otherwise null.
     */
    public function fetch_meetings($data, $tmp = []) {

        // Construct the URL with the next_page_token if it exists.
        $url = "/metrics/meetings/?page_size=300&type={$data->type}&from={$data->from}&to={$data->to}";
        if (!empty($data->next_page_token)) {
            $url .= "&next_page_token={$data->next_page_token}";
        }

        $object = $this->call_zoom_api($url);

        // Merge the current page of meetings into the accumulated list.
        if (isset($object->meetings)) {
            $tmp = array_merge($tmp, $object->meetings);
        }

        // Check if there's a next page, and recursively fetch it if so.
        if (!empty($object->next_page_token)) {
            $data->next_page_token = $object->next_page_token;
            return $this->fetch_meetings($data, $tmp);
        }

        // Return all accumulated meetings once pagination is complete.
        return !empty($tmp) ? $tmp : null;
    }

    /**
     * Index recordings based on data.
     *
     * @param stdClass $data The data object.
     * @return void
     */
    public function listing_zoom($data) {
        global $DB;

        $meetings = $this->fetch_meetings($data);
        if (!$meetings) {
            mtrace('Task: No Zoom meetings were found.');
            return;
        } else {
            $totalcount = count($meetings);
            mtrace('Task: Found ' . $totalcount . ' meetings');
        }

        $i = 0;
        foreach ($meetings as $meeting) {

            mtrace('Task: Checking meeting ' . $i . ' out of ' . $totalcount . ' #' . $meeting->id);
            $i++;

            if (!$meeting->has_recording) {
                continue;
            }

            $recordingsinstances =
                    $this->call_zoom_api('/meetings/' . $this->encode_uuid($meeting->uuid) . '/recordings');

            if (!isset($recordingsinstances->recording_files)) {
                continue;
            }

            foreach ($recordingsinstances->recording_files as $recording) {

                if ($exists = $DB->get_record('local_stream_rec',
                        ['meetingid' => $meeting->id, 'recordingid' => $recording->id])) {

                    mtrace('Task: Skipping recording #' . $recording->id . ' was previously saved and exists in the db.');
                    continue;
                }

                // Closed caption.
                if (strtolower($recording->file_type) == 'cc' || strtolower($recording->file_type) == 'transcript') {
                    if ($existcc = $DB->get_record('local_stream_cc',
                            ['meetingid' => $meeting->id, 'uuid' => $meeting->uuid])) {

                        mtrace('Task: Skipping closed caption recording #' . $recording->id .
                                ' was previously saved and exists in the db.');
                        continue;
                    } else {
                        $newcc = new stdClass();
                        $newcc->meetingid = $meeting->id;
                        $newcc->uuid = $meeting->uuid;
                        $newcc->downloadurl = $recording->download_url;
                        $newcc->timecreated = time();
                        $DB->insert_record('local_stream_cc', $newcc);

                        mtrace('Task: A new closed caption (CC) was found and saved in the database.');
                    }
                }

                if (strtolower($recording->file_type) != 'mp4') {
                    continue;
                }

                mtrace('Task: A new recording was found and saved in the database.');

                $newrecording = new stdClass();
                $newrecording->topic = $meeting->topic;
                $newrecording->email = strtolower($meeting->email);
                $newrecording->dept = $meeting->dept;
                $newrecording->starttime = $meeting->start_time;
                $newrecording->endtime = $meeting->end_time;
                $newrecording->duration = $meeting->duration;
                $newrecording->participants = $meeting->participants;
                $newrecording->meetingid = $meeting->id;
                $newrecording->recordingid = $recording->id;
                $newrecording->meetingdata = json_encode($meeting);
                $newrecording->recordingdata = json_encode($recording);
                $newrecording->timecreated = time();
                $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);

                // Publish immediately.
                if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
                    $newrecording->status = $this::MEETING_STATUS_READY;
                }

                $DB->insert_record('local_stream_rec', $newrecording);
            }
        }
    }

    /**
     * Index recordings based on data for WEBEX.
     *
     * @param stdClass $data The data object.
     * @return void
     */
    public function listing_webex($data) {

        global $DB;

        $meetings = $this->call_webex_api('admin/recordings?' . http_build_query($data, '?', '&'), null, 'get');

        mtrace('Welcome JWT: ' . $this->config->webexjwt);
        if (!$meetings->items) {
            mtrace('Task: No Webex meetings were found.');
            return;
        } else {
            $totalcount = count($meetings->items);
            mtrace('Task: Found ' . $totalcount . ' meetings');
        }

        $i = 0;
        foreach ($meetings->items as $meeting) {

            mtrace('Task: Checking meeting ' . $i . ' out of ' . $totalcount . ' #' . $meeting->id);
            $i++;

            $recording = $this->call_webex_api('recordings/' . $meeting->id . '?hostEmail=' . $meeting->hostEmail, null, 'get');
            if (!$recording->temporaryDirectDownloadLinks) {
                continue;
            }

            // Special adjustments for webex.
            $recording->file_size = $recording->sizeBytes;
            $meeting->explode = explode('_', $meeting->meetingId);
            $meeting->meetingId = end($meeting->explode);

            if ($exists = $DB->get_record('local_stream_rec',
                    ['meetingid' => $meeting->meetingId, 'recordingid' => $meeting->id])) {

                mtrace('Task: Skipping recording #' . $meeting->id . ' was previously saved and exists in the db.');
                continue;
            }

            if (strtolower($meeting->format) != 'mp4') {
                continue;
            }

            mtrace('Task: A new recording was found and saved in the database.');

            $newrecording = new stdClass();
            $newrecording->topic = $meeting->topic;
            $newrecording->email = strtolower($meeting->hostEmail);
            $newrecording->dept = $meeting->serviceType;
            $newrecording->starttime = $meeting->createTime;
            $newrecording->endtime = $meeting->timeRecorded;
            $newrecording->duration = $this->seconds_to_hms($meeting->durationSeconds);
            $newrecording->participants = 0;
            $newrecording->meetingid = $meeting->meetingId;
            $newrecording->recordingid = $meeting->id;
            $newrecording->meetingdata = json_encode($meeting);
            $newrecording->recordingdata = json_encode($recording);
            $newrecording->timecreated = time();
            $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);

            // Publish immediately.
            if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
                $newrecording->status = $this::MEETING_STATUS_READY;
            }

            $DB->insert_record('local_stream_rec', $newrecording);
        }

        // Next page.
        if ($meetings->next_page) {
            $parsed = parse_url($meetings->next_page);
            parse_str($parsed['query'], $data);
            $data = (object) $data;
            return $this->listing_webex($data);
        }
    }

    /**
     * Index recordings based on data for UNICKO.
     *
     * @param stdClass $data The data object.
     * @return void
     */
    public function listing_unicko($data) {

        global $DB;

        $meetings = $this->call_unicko_api('recordings?' . http_build_query($data, '?', '&'), null, 'get');
        if (!$meetings->items) {
            mtrace('Task: No Webex meetings were found.');
            return;
        } else {
            $totalcount = count($meetings->items);
            mtrace('Task: Found ' . $totalcount . ' meetings');
        }

        // Get the current time and the time for $data->days days ago.
        $currenttime = time();
        $daysago = strtotime('-' . ($data->days + 1) . ' days', $currenttime);
        $stop = false;

        $i = 0;
        foreach ($meetings->items as $meeting) {

            // Get the timestamp for the end time of the meeting.
            $meetingendtime = strtotime($meeting->end_time);
            $stop = ($meetingendtime < $daysago ? true : false);

            if ($stop) {
                continue;
            }

            mtrace('Task: Checking meeting ' . $i . ' out of ' . $totalcount . ' #' . $meeting->id);

            $details = $this->call_unicko_api('meetings/' . $meeting->meeting, null, 'get');
            if (isset($details) && isset($details->ext_id)) {
                $meeting->instanceid = $details->ext_id;
            }

            $i++;
            if ($exists = $DB->get_record('local_stream_rec',
                    ['meetingid' => $meeting->meeting, 'recordingid' => $meeting->id])) {

                $exists->starttime = $meeting->start_time;
                $exists->endtime = $meeting->end_time;
                $exists->meetingdata = json_encode($meeting);
                $exists->recordingdata = json_encode($meeting);

                $DB->update_record('local_stream_rec', $exists);
                mtrace('Task: Updating recording #' . $meeting->id . ' details in the db.');

                continue;
            }

            mtrace('Task: A new recording was found and saved in the database.');

            $newrecording = new stdClass();
            $newrecording->topic = $details->name;
            $newrecording->starttime = $meeting->start_time;
            $newrecording->endtime = $meeting->end_time;
            $newrecording->meetingid = $meeting->meeting;
            $newrecording->recordingid = $meeting->id;
            $newrecording->meetingdata = json_encode($meeting);
            $newrecording->recordingdata = json_encode($meeting);
            $newrecording->timecreated = time();
            $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);

            $module = $DB->get_record('modules', ['name' => 'lti']);
            if ($module && isset($meeting->instanceid)) {
                $cm = $DB->get_record('course_modules',
                        ['instance' => $meeting->instanceid, 'module' => $module->id]);

                if ($cm && isset($cm->course)) {
                    // Get the context of the course.
                    $context = context_course::instance($cm->course);
                    $teachers = get_role_users(3, $context); // 3 is the default role ID for teachers.

                    if (!empty($teachers)) {
                        foreach ($teachers as $teacher) {
                            $newrecording->email = $teacher->email; // Return the first teacher's user ID.
                        }
                    }
                }
            }

            // Publish immediately.
            if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
                $newrecording->status = $this::MEETING_STATUS_READY;
            }

            $DB->insert_record('local_stream_rec', $newrecording);
        }

        // Next page.
        if (isset($meetings->paging) && !$stop) {
            $parsed = parse_url($meetings->paging->next);
            parse_str($parsed['query'], $data);
            $data = (object) $data;

            return $this->listing_unicko($data);
        }
    }

    /**
     * Updates the recording information.
     *
     * @param int $id The ID of the recording to be updated.
     * @param bool|null $visible Whether the recording is visible or not (null if not to be updated).
     * @param int|bool $status The status of the meeting (false if not to be updated).
     *
     * @return bool True if the update is successful, false otherwise.
     * @throws coding_exception When there's an issue during the update process.
     */
    public function update_recording($id, $visible, $status = false) {
        global $DB;

        $task = new \local_stream\task\notifications();
        $meeting = $DB->get_record('local_stream_rec',
                ['id' => $id]);

        $cm = get_coursemodule_from_instance('stream', $meeting->moduleid);

        if ($meeting) {
            if ($status) {
                $meeting->status = $status;

                if ($status == $this::MEETING_STATUS_DELETED) {
                    $source = $DB->get_record('course_modules',
                            ['course' => $meeting->course, 'instance' => $meeting->moduleid]);
                    delete_mod_from_section($source->id, $source->section);
                    if ($cm) {
                        course_modinfo::purge_course_module_cache($meeting->course, $cm->id);
                    }
                }
            }

            if ($visible !== null) {
                $meeting->visible = $visible;
            }

            $DB->update_record('local_stream_rec', $meeting);
            if ($cm) {
                if ($visible !== null) {
                    $cm->visible = $visible;
                }

                if ($task && $cm->visible && $meeting->course) {
                    $coursecontext = \context_course::instance($meeting->course);
                    $users = get_enrolled_users($coursecontext);
                    foreach ($users as $user) {
                        $task->set_custom_data([
                                'userid' => $user->id,
                                'courseid' => $meeting->course,
                                'meetingid' => $meeting->id,
                                'date' => userdate(strtotime($meeting->starttime), '%d/%m/%Y'),
                                'time' => userdate(strtotime($meeting->starttime), '%H:%M'),
                                'topic' => $meeting->topic]);
                        \core\task\manager::queue_adhoc_task($task);
                    }
                }

                $cm->timemodified = time();
                $DB->update_record('course_modules', $cm);
                course_modinfo::purge_course_module_cache($meeting->course, $cm->id);
            }

            return true;
        }

        return false;
    }

    /**
     * Recovers a recording for a Zoom meeting.
     *
     * This function attempts to recover a recording associated with a specific meeting
     * by making a call to the Zoom API. The meeting and recording information are retrieved
     * from the 'local_stream_rec' table using the provided $id.
     *
     * @param int $id The ID of the recording to be recovered.
     * @return bool True if the recording was successfully recovered, false otherwise.
     */
    public function recover_recording($id) {
        global $DB;

        $meeting = $DB->get_record('local_stream_rec', ['id' => $id]);
        if ($meeting) {

            if (!isset($meeting->meetingdata)) {
                return false;
            }

            if ($this->config->platform == $this::PLATFORM_WEBEX) {
                $recover =
                        $this->call_webex_api('recordings/' . $meeting->recordingid . '?hostEmail=' . $meeting->email, null, 'get');
                if (isset($recover->temporaryDirectDownloadLinks)) {
                    $meeting->recordingdata = json_encode($recover);
                    $recover = [
                            'http_code' => 204,
                    ];
                } else {
                    $recover = [
                            'http_code' => 404,
                    ];
                }

            } else if ($this->config->platform == $this::PLATFORM_ZOOM) {
                // Recover meeting recordings.
                $recover =
                        $this->call_zoom_api('meetings/' . $meeting->meetingid . '/recordings/status', ['action' => 'recover'],
                                'put', true);
            } else if ($this->config->platform == $this::PLATFORM_UNICKO) {
                $recover = $this->call_unicko_api('meetings/' . $meeting->meeting, null, 'get');
                if (isset($recover) && isset($recover->ext_id)) {
                    $meeting->instanceid = $recover->ext_id;
                }

                $meeting->meetingdata = json_encode($meeting);
                $meeting->recordingdata = json_encode($meeting);
            }

            $meeting->streamid = 0;
            $meeting->status = 0;
            $meeting->embedded = 0;
            $meeting->tries = 0;
            $DB->update_record('local_stream_rec', $meeting);

            if ($meeting->course && $meeting->moduleid) {
                $source = $DB->get_record('course_modules',
                        ['course' => $meeting->course, 'instance' => $meeting->moduleid]);

                delete_mod_from_section($source->id, $source->section);
            }

            if ($recover['http_code'] == 204) {
                return true;
            } else {
                return $recover['message'];
            }
        } else {
            return false;
        }

        return true;
    }

    /**
     * Download a Zoom meeting recording.
     *
     * This method retrieves the download URL for a Zoom meeting recording and redirects the user to download it.
     *
     * @param int $id The ID of the Zoom meeting recording.
     * @return void This method redirects the user to download the recording or to the dashboard in case of an error.
     */
    public function download_recording($id) {
        global $DB;

        $recording = $DB->get_record('local_stream_rec', ['id' => $id]);
        if ($recording) {
            $urlparams = [];
            $urlparams['forcedownload'] = 1;
            $downloadurl = $this->get_meeting($recording);

            if ($downloadurl) {
                redirect(new moodle_url($downloadurl, $urlparams));
            } else {
                return false;
            }
        }
    }

    /**
     * Convert seconds to HH:MM:SS format.
     *
     * This method takes a number of seconds and converts it into a string representation in the format HH:MM:SS.
     *
     * @param int $seconds The number of seconds to convert.
     * @return string The formatted time string in HH:MM:SS.
     */
    public function seconds_to_hms($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingseconds = round($seconds % 60);

        // Add leading zeros if needed.
        $hours = $hours < 10 ? '0' . $hours : $hours;
        $minutes = $minutes < 10 ? '0' . $minutes : $minutes;
        $remainingseconds = $remainingseconds < 10 ? '0' . $remainingseconds : $remainingseconds;

        return $hours . ':' . $minutes . ':' . $remainingseconds;
    }

    /**
     * Remove embedded mod_stream. For collection_mode, deletes the whole activity and clears all recordings pointing at it.
     *
     * @param \stdClass $meeting local_stream_rec row (updated when not dry-run).
     * @param bool $dryrun If true, log only.
     * @param callable|null $log function(string $msg): void; default mtrace.
     * @return bool True when done (including nothing to remove).
     */
    public function remove_embedded_stream_activity($meeting, $dryrun = false, $log = null) {
        global $DB;

        $logfn = $log ?? function(string $msg): void {
            mtrace($msg);
        };

        if (empty($meeting->moduleid)) {
            return true;
        }

        $stream = $DB->get_record('stream', ['id' => (int) $meeting->moduleid]);
        if (!$stream) {
            if (!$dryrun) {
                $meeting->moduleid = 0;
                $meeting->embedded = 0;
                $meeting->embedded_at = 0;
                $DB->update_record('local_stream_rec', $meeting);
            }
            $logfn('Recording #' . $meeting->id . ': stream row missing; cleared embed flags.');
            return true;
        }

        $cm = get_coursemodule_from_instance('stream', $stream->id, $stream->course);

        if (!empty($stream->collection_mode)) {
            $affected = $DB->count_records('local_stream_rec', ['moduleid' => $stream->id]);
            if ($dryrun) {
                $logfn('[dry-run] Would delete collection mod_stream #' . $stream->id . ' (course ' . $stream->course .
                        ') and clear embed flags on ' . $affected . ' recording row(s).');
                return true;
            }
            if ($cm) {
                course_delete_module($cm->id);
            }
            $DB->execute(
                    'UPDATE {local_stream_rec} SET moduleid = 0, embedded = 0, embedded_at = 0 WHERE moduleid = :sid',
                    ['sid' => $stream->id]
            );
            $meeting->moduleid = 0;
            $meeting->embedded = 0;
            $meeting->embedded_at = 0;
            return true;
        }

        if ($dryrun) {
            $logfn('[dry-run] Would delete mod_stream instance #' . $stream->id . ' (course ' . $stream->course . ').');
        } else {
            if ($cm) {
                course_delete_module($cm->id);
            }
            $meeting->moduleid = 0;
            $meeting->embedded = 0;
            $meeting->embedded_at = 0;
            $DB->update_record('local_stream_rec', $meeting);
        }
        return true;
    }

    /**
     * Add a Zoom meeting module to a course.
     *
     * This method creates a new module in the specified course with information from the Zoom meeting.
     * For Zoom platform, it finds existing mod_stream instances with collection_mode=true and adds
     * the video to the collection.
     *
     * @param stdClass $meeting The Zoom meeting information.
     * @return int|bool The ID of the created module or false if the module creation fails.
     */
    public function add_module($meeting) {
        global $DB;

        $meeting->idnumber = 'meeting-' . $meeting->meetingid;

        // For Zoom platform, handle mod_stream collection mode
        if ($this->config->platform == $this::PLATFORM_ZOOM && $meeting->streamid) {
            // Find mod_stream instances with collection_mode=true in the specified course
            $streaminstances = $DB->get_records('stream', [
                    'course' => $meeting->course,
                    'collection_mode' => 1
            ]);

            if (!empty($streaminstances)) {
                // Use the first available stream instance (you can modify this logic as needed)
                $streaminstance = reset($streaminstances);

                // Add the new video ID to the existing collection
                $currentidentifiers = !empty($streaminstance->identifier) ? explode(',', $streaminstance->identifier) : [];
                $currentvideoorder = !empty($streaminstance->video_order) ? json_decode($streaminstance->video_order, true) : [];

                // Add new video ID if not already present
                if (!in_array($meeting->streamid, $currentidentifiers)) {
                    $currentidentifiers[] = $meeting->streamid;
                    $currentvideoorder[] = (string)$meeting->streamid;

                    // Update the stream instance
                    $streaminstance->identifier = implode(',', $currentidentifiers);
                    $streaminstance->video_order = json_encode($currentvideoorder);
                    $streaminstance->timemodified = time();

                    $DB->update_record('stream', $streaminstance);

                    // Return the existing stream instance ID
                    return (object)['id' => $streaminstance->id];
                }

                return (object)['id' => $streaminstance->id];
            }
        }

        // Original module creation logic for other platforms or when no collection mode instance exists
        $moduledata = new \stdClass();
        $moduledata->course = $meeting->course;
        $moduledata->modulename = 'stream';
        $moduledata->section = 0;
        $moduledata->idnumber = $meeting->idnumber;
        $moduledata->visible = ($this->config->hidefromstudents ? 0 : 1);
        $moduledata->contentformat = FORMAT_HTML;
        $moduledata->introeditor = [
                'text' => '',
                'format' => true,
        ];

        // For Zoom platform, check if this is the first mod_stream in the course and setting is enabled
        if ($this->config->platform == $this::PLATFORM_ZOOM && $this->config->defaultcollectionmode) {
            $existingstreams = $DB->count_records('stream', ['course' => $meeting->course]);
            if ($existingstreams == 0) {
                // This is the first mod_stream in the course, set collection_mode = 1
                $moduledata->collection_mode = 1;
            }
        }

        if (isset($meeting->recordingdata)) {
            $recordingdata = json_decode($meeting->recordingdata);
        }

        if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {

            // Webex.
            if ($this->config->platform == $this::PLATFORM_WEBEX) {
                $recordingurl = '<a target="_blank" href="' . $recordingdata->playbackUrl . '">לחץ/י כאן לצפייה בהקלטה</a>';
            }

            // Zoom.
            if ($this->config->platform == $this::PLATFORM_ZOOM) {
                $recordingurl = '<a target="_blank" href="' . $recordingdata->play_url . '">לחץ/י כאן לצפייה בהקלטה</a>';
            }

            $moduledata->introeditor['text'] .= $recordingurl;

            // Teams using onedrive url to display video without download.
            if ($this->config->platform == $this::PLATFORM_TEAMS) {
                $recordingurl = $this->get_meeting($meeting);
                $recordingurl = $recordingurl . '?web=1&csf=1';
                $moduledata->introeditor['text'] .= '<a href="' . $recordingurl . '">לחץ/י כאן לצפיה ישירה בהקלטה</a>';
            }

        } else {
            $moduledata->identifier = $meeting->streamid;
        }

        if ($this->config->hidetopic) {
            $meeting->topic = '';
        }

        if ($this->config->prefix) {
            $moduledata->name = $this->config->prefix . ' ' . $meeting->topic;
        } else {
            $moduledata->name = $meeting->topic;
        }

        if ($this->config->addrecordingtype && isset($recordingdata) && isset($recordingdata->recording_type)) {
            $moduledata->name .= ' ' . $this->convert_camel_case($recordingdata->recording_type);
        }

        if ($this->config->adddate) {
            $moduledata->name .= ' (' . userdate(strtotime($meeting->starttime)) . ')';
        }

        $moduledata->topic = $meeting->topic;

        return create_module($moduledata);
    }

    /**
     * Update a module based on the provided meeting information.
     *
     * This function creates or updates a 'page' module with information from the given meeting.
     *
     * @param \stdClass $meeting The meeting object containing information to update or create the module.
     * @return int|false The ID of the created or updated module on success, or false on failure.
     */
    public function update_module($meeting) {
        global $DB;

        if ($meeting->moduleid) {
            $cm = get_coursemodule_from_instance('stream', $meeting->moduleid);
            $stream = $DB->get_record('stream', ['id' => $meeting->moduleid]);

            if ($this->config->prefix) {
                $stream->name = $this->config->prefix . ' ' . $meeting->topic;
            } else {
                $stream->name = $meeting->topic;
            }

            if ($this->config->adddate) {
                $stream->name .= ' (' . userdate(strtotime($meeting->starttime)) . ')';
            }

            $DB->update_record('stream', $stream);

            course_modinfo::purge_course_module_cache($meeting->course, $cm->id);
        } else {
            return false;
        }
    }

    /**
     * Determines whether the current user has the capability to edit within the local stream plugin context.
     *
     * This method checks if the current user is assigned to the 'teacher' or 'editingteacher' role, or if the user
     * is a site administrator, granting them the capability to edit in the local Stream context.
     *
     * @return bool Returns true if the user has the capability to edit, and false otherwise.
     */
    public function has_capability_to_edit() {
        global $USER, $DB;

        $cache = cache::make('local_stream', 'usercapability');
        $capability = $cache->get('capability');

        if ($capability !== false) {
            return $capability;
        }

        $teacher = $DB->get_record('role', ['shortname' => 'teacher']);
        $editingteacher = $DB->get_record('role', ['shortname' => 'editingteacher']);

        $capability = false;
        if (user_has_role_assignment($USER->id, $teacher->id) ||
                user_has_role_assignment($USER->id, $editingteacher->id) ||
                is_siteadmin($USER)) {
            $capability = true;
        }

        $cache->set('capability', $capability);

        return $capability;
    }

    /**
     * Get the course IDs that the current user is enrolled in.
     *
     * This function retrieves the course IDs for the courses in which the current user is enrolled.
     *
     * @return array An array containing the course IDs that the current user is enrolled in.
     */
    public function get_user_my_courses() {
        static $courseids;

        $courseid = optional_param('course', 0, PARAM_INT);
        if (!isset($courseids)) {
            $courseids = [];

            if ($courseid && $courseid > 0) {
                $courseids[] = $courseid;
            } else {
                $courses = enrol_get_my_courses();
                foreach ($courses as $course) {
                    if ($course->id > 0) {
                        $courseids[] = $course->id;
                    }
                }
            }
        }

        return $courseids;
    }

    /**
     * Retrieves users associated with Zoom meetings.
     *
     * @return array An array of user data or options.
     */
    public function get_users() {
        global $DB;

        $cache = $this->cache->get('users');
        if ($cache) {
            $options = json_decode($cache);
            $options = (array) $options;
        } else {

            $options[0] = '';
            $meetings = $DB->get_records('local_stream_rec', [], 'email DESC', 'id, email');
            foreach ($meetings as $meeting) {

                $user = $DB->get_record('user', ['email' => $meeting->email]);

                if ($user) {
                    $options[$meeting->email] = fullname($user);
                } else {
                    $options[$meeting->email] = $meeting->email;
                }
            }

            $this->cache->set('users', json_encode($options));
        }

        return $options;
    }

    /**
     * Retrieves meetings based on specified parameters.
     *
     * This function queries the database for meetings based on the given parameters.
     *
     * @param array $params An associative array of parameters to filter meetings.
     * @param bool|int $count If true, returns the count of meetings; if false, returns meeting data.
     * @param int $page The page number for paginated results.
     * @return int|array Returns the count of meetings or an array of meeting data.
     */
    public function get_meetings($params, $count = false, $page = 0) {
        global $DB;

        static $data;

        $sql = '';
        $page = ($page * $this->config->recordingsperpage);

        if ($params['status']) {
            $sql .= ' status IN (' . implode(',', $params['status']) . ')';
        }

        // Filter for students only.
        if (!$this->has_capability_to_edit()) {
            $sql .= ' AND course IN (' . implode(',', $this->get_user_my_courses()) . ')';
            $params['visible'] = 1;
        }

        if (isset($params['starttime']) && $params['starttime']) {
            $sql .= ' AND starttime >= :starttime';
        }

        if (isset($params['endtime']) && $params['endtime']) {
            $sql .= ' AND endtime <= :endtime';
        }

        if (isset($params['topic']) && $params['topic']) {
            $sql .= ' AND ' . $DB->sql_like('topic', ':topic', false, false);
        }

        if (isset($params['meetingid']) && $params['meetingid']) {
            $sql .= ' AND ' . $DB->sql_like($DB->sql_cast_to_char('meetingid'), ':meetingid', false, false);
        }

        if (isset($params['email']) && $params['email'] && $this->has_capability_to_edit()) {
            $sql .= ' AND ' . $DB->sql_equal('email', ':email', true, false);
        }

        if (isset($params['visible']) && $params['visible']) {
            $params['visible'] = ($params['visible'] == 2 ? 0 : 1);
            $sql .= ' AND ' . $DB->sql_equal('visible', ':visible', true, false);
        }

        if (isset($params['course']) && $params['course']) {
            $sql .= ' AND ' . $DB->sql_equal('course', ':course', true, false);
        }

        if (isset($params['duration']) && $params['duration']) {
            $sql .= ' AND duration <= :duration';
        }

        if ($count) {
            return $DB->count_records_select('local_stream_rec', $sql, $params);;
        } else {
            if (!isset($data)) {
                $data = $DB->get_records_select('local_stream_rec', $sql, $params, 'id DESC', '*', $page,
                        $this->config->recordingsperpage);
            }

            return $data;
        }
    }

    /**
     * Retrieves a list of courses.
     *
     * If the $all parameter is true and the user is an admin, all courses are retrieved.
     * Otherwise, only the courses that the user is enrolled in are returned.
     * The results are cached to improve performance.
     *
     * @return array An array of course IDs and their full names.
     */
    public function get_courses() {
        global $USER;

        $courseid = optional_param('course', 0, PARAM_INT);

        // Attempt to get the courses from cache.
        if (is_siteadmin()) {
            $cache = $this->cache->get('admin_courses');
            if ($cache) {
                $courses = $cache;
            } else {
                $courses = get_courses();
                // Cache the fetched courses for admin.
                $this->cache->set('admin_courses', $courses);
            }
        } else {
            $cache = $this->cache->get('user_courses_' . $USER->id);
            if ($cache) {
                $courses = $cache;
            } else {
                $courses = enrol_get_my_courses();

                // Cache the fetched courses for the user.
                $this->cache->set('user_courses_' . $USER->id, $courses);
            }
        }

        $output = [];
        $output[0] = '';
        foreach ($courses as $course) {
            if (isset($course->fullname) && $course->fullname) {
                $output[$course->id] = $course->fullname;
            }
        }

        if (isset($output[$courseid])) {
            $first = [];
            $first[$courseid] = $output[$courseid];
            unset($output[$courseid]);
            unset($output[0]);
            $output = $first + $output;
        }

        return $output;
    }

    /**
     * Process actions related to Integration recordings.
     *
     * This function is used to handle actions such as deleting, hiding, downloading, and recovering Stream recordings.
     *
     * @param moodle_url $baseurl The base URL to redirect after processing the action.
     *
     * @return void
     */
    public function hooks($baseurl) {
        global $DB;

        $id = optional_param('id', 0, PARAM_INT);
        $visible = optional_param('visible', 0, PARAM_INT);
        $action = optional_param('action', 0, PARAM_TEXT);

        if ($action == 'delete' && $id) {
            if ($this->update_recording($id, null, $this::MEETING_STATUS_DELETED)) {
                redirect($baseurl, get_string('recordingdeleted', 'local_stream', $id));
            } else {
                redirect($baseurl, get_string('error', 'local_stream'));
            }
        } else if ($action == 'hide' && $id) {
            if ($this->update_recording($id, $visible)) {
                $stingname = ($visible == 1 ? 'recordingshow' : 'recordinghidden');
                redirect($baseurl, get_string($stingname, 'local_stream', $id));
            } else {
                redirect($baseurl, get_string('error', 'local_stream'));
            }
        } else if ($action == 'download' && $id) {
            $meeting = $DB->get_record('local_stream_rec', ['id' => $id], 'id,streamid');
            if (!empty($meeting->streamid)) {
                $this->stream_login('videos/edit/' . $meeting->streamid);
            }
            if (!$this->download_recording($id)) {
                redirect($baseurl, get_string('errordownload', 'local_stream', $id));
            }
        } else if ($action == 'recover' && $id) {
            if ($this->recover_recording($id)) {
                redirect($baseurl, get_string('recordingcycle', 'local_stream', $id));
            } else {
                redirect($baseurl, get_string('error', 'local_stream'));
            }
        }
    }

    /**
     * Retrieves an OAuth token for Microsoft Teams API requests.
     *
     * @return string|false The access token, or false on failure.
     */
    private function teams_get_token() {

        static $token;
        static $failed;
        if ($failed) {
            return false;
        }
        if ($token !== null) {
            return $token;
        }

        $url = 'https://login.microsoftonline.com/' . $this->config->teamstenantid . '/oauth2/v2.0/token';
        $data = [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->teamsclientid,
                'client_secret' => $this->config->teamsclientsecret,
                'scope' => 'https://graph.microsoft.com/.default',
        ];

        $options = [
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
        ];

        $curl = new \curl();
        $jsonresult = $curl->post($url, $data, $options);
        $response = json_decode($jsonresult);
        if (!$response || empty($response->access_token)) {
            mtrace('Teams: OAuth token request failed.');
            $failed = true;
            return false;
        }

        $token = (string) $response->access_token;
        return $token;
    }

    /**
     * GET Microsoft Graph (relative path beginning with /, or full https:// URL for @odata.nextLink).
     *
     * @param string $urlpath
     * @return array{0: string, 1: int} Response body and HTTP status code.
     */
    private function teams_graph_request_get($urlpath) {
        $tok = $this->teams_get_token();
        if ($tok === false || $tok === '') {
            return ['', 401];
        }
        $url = (strpos($urlpath, 'http') === 0) ? $urlpath : ('https://graph.microsoft.com/v1.0' . $urlpath);
        $headers = [
                'Authorization: Bearer ' . $tok,
                'Accept: application/json',
        ];
        $options = [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTPHEADER' => $headers,
        ];
        $curl = new \curl();
        $body = $curl->get($url, null, $options);
        $info = $curl->get_info();
        $code = isset($info['http_code']) ? (int) $info['http_code'] : 0;
        return [(string) $body, $code];
    }

    /**
     * Makes a request to the Microsoft Teams API (JSON response).
     *
     * @param string $path The API endpoint path (or full Graph URL).
     * @return mixed Decoded object, or null on failure.
     */
    private function teams_make_request($path) {

        list($jsonresult, $code) = $this->teams_graph_request_get($path);
        if ($code >= 400) {
            mtrace('Teams Graph error HTTP ' . $code . ' for ' . substr($path, 0, 160));
            return null;
        }
        return json_decode($jsonresult);
    }

    /**
     * Oldest createdDateTime (Unix ts) for items to include in listing, from admin "Days to listing".
     *
     * @return int
     */
    private function teams_listing_cutoff_timestamp() {
        $days = (int) $this->config->daystolisting;
        if ($days <= 0) {
            $midnight = strtotime('today midnight');
            return ($midnight !== false) ? $midnight : (time() - 86400);
        }
        return time() - ($days * 86400);
    }

    /**
     * Whether a drive item is new enough for the current listing window.
     *
     * @param stdClass $item Graph driveItem.
     * @param int $cutoff Unix timestamp.
     * @return bool
     */
    private function teams_item_created_not_before($item, $cutoff) {
        if (empty($item->createdDateTime)) {
            return false;
        }
        $ts = strtotime($item->createdDateTime);
        return $ts !== false && $ts >= $cutoff;
    }

    /**
     * Video file filter (mime video/* or .mp4), aligned with standalone Graph listing example.
     *
     * @param stdClass $item
     * @return bool
     */
    private function teams_sp_item_is_video_recording($item) {
        $mime = '';
        if (isset($item->file->mimeType)) {
            $mime = strtolower((string) $item->file->mimeType);
        }
        $name = strtolower((string) ($item->name ?? ''));
        if ($mime !== '' && strpos($mime, 'video/') === 0) {
            return true;
        }
        $len = strlen($name);
        if ($len >= 4 && substr($name, -4) === '.mp4') {
            return true;
        }
        return false;
    }

    /**
     * OData children URL for a folder; optional server-side createdDateTime filter.
     *
     * @param string $driveid
     * @param string $folderid Use "root" for drive root.
     * @param int $cutoff
     * @param bool $allowfilter
     * @return string Relative Graph path.
     */
    private function teams_sp_children_list_url($driveid, $folderid, $cutoff, $allowfilter) {
        $base = '/drives/' . rawurlencode($driveid) . '/items/' . rawurlencode($folderid) . '/children';
        if (!$allowfilter || !$this->teamsgraphuseserversidefilter) {
            return $base;
        }
        $cutoffiso = gmdate('Y-m-d\TH:i:s', $cutoff) . '.000Z';
        return $base . '?$filter=' . rawurlencode('createdDateTime ge ' . $cutoffiso);
    }

    /**
     * Walk one drive recursively and queue new video files (SharePoint / Teams recordings library pattern).
     *
     * @param string $driveid
     * @param string $folderid
     * @param string $logicalpath
     * @param string $drivename
     * @param int $cutoff
     * @return void
     */
    private function teams_sp_walk_drive($driveid, $folderid, $logicalpath, $drivename, $cutoff) {
        $next = $this->teams_sp_children_list_url($driveid, $folderid, $cutoff, true);
        while ($next !== '' && $next !== null) {
            list($raw, $code) = $this->teams_graph_request_get($next);
            if ($code >= 400 && $this->teamsgraphuseserversidefilter && strpos($next, '$filter=') !== false) {
                mtrace('Teams SharePoint: server-side $filter not supported; falling back to client-side date filter.');
                $this->teamsgraphuseserversidefilter = false;
                $next = $this->teams_sp_children_list_url($driveid, $folderid, $cutoff, false);
                continue;
            }
            if ($code >= 400) {
                mtrace('Teams SharePoint: list children HTTP ' . $code);
                break;
            }
            $decoded = json_decode($raw);
            if (!$decoded || empty($decoded->value)) {
                break;
            }
            foreach ($decoded->value as $item) {
                if (isset($item->folder)) {
                    $childid = isset($item->id) ? (string) $item->id : '';
                    if ($childid !== '') {
                        $subname = isset($item->name) ? (string) $item->name : '';
                        $subpath = $logicalpath === '' ? $subname : $logicalpath . '/' . $subname;
                        $this->teams_sp_walk_drive($driveid, $childid, $subpath, $drivename, $cutoff);
                    }
                    continue;
                }
                if (!$this->teams_item_created_not_before($item, $cutoff)) {
                    continue;
                }
                if (!$this->teams_sp_item_is_video_recording($item)) {
                    continue;
                }
                $this->teams_add_meeting($item, $driveid);
            }
            if (!empty($decoded->{'@odata.nextLink'})) {
                $next = $decoded->{'@odata.nextLink'};
            } else {
                $next = '';
            }
        }
    }

    /**
     * List recordings from a SharePoint site document libraries via Graph (same idea as teams_list_recordings example).
     *
     * @param string $sitesegment e.g. contoso.sharepoint.com:/sites/Recordings:
     * @return void
     */
    private function listing_teams_sharepoint_site($sitesegment) {
        $sitesegment = trim($sitesegment);
        if ($sitesegment === '') {
            return;
        }
        $this->teamsgraphuseserversidefilter = true;
        $cutoff = $this->teams_listing_cutoff_timestamp();
        $drivesresponse = $this->teams_make_request('/sites/' . $sitesegment . '/drives');
        if (!$drivesresponse || empty($drivesresponse->value)) {
            mtrace('Teams SharePoint: no drives returned (check teamssharepointsite value and Sites.Read.All / Files.Read.All).');
            return;
        }
        foreach ($drivesresponse->value as $drive) {
            $driveid = isset($drive->id) ? (string) $drive->id : '';
            $drivename = isset($drive->name) ? (string) $drive->name : '';
            if ($driveid === '') {
                continue;
            }
            mtrace('Teams SharePoint: scanning drive "' . $drivename . '" (' . $driveid . ')');
            $this->teams_sp_walk_drive($driveid, 'root', '', $drivename, $cutoff);
        }
    }

    /**
     * Fetch driveItem metadata including @microsoft.graph.downloadUrl when present.
     *
     * @param string|null $driveid When null, uses the user's default drive via /users/{email}/drive/items/...
     * @param string $owneremail
     * @param string $itemid
     * @return stdClass|null
     */
    private function teams_graph_drive_item_metadata($driveid, $owneremail, $itemid) {
        $select = 'id,name,size,createdDateTime,webUrl,file,@microsoft.graph.downloadUrl';
        $query = '$select=' . rawurlencode($select);
        if ($driveid !== null && $driveid !== '') {
            $path = '/drives/' . rawurlencode($driveid) . '/items/' . rawurlencode($itemid) . '?' . $query;
        } else {
            $path = '/users/' . rawurlencode($owneremail) . '/drive/items/' . rawurlencode($itemid) . '?' . $query;
        }
        $obj = $this->teams_make_request($path);
        if (!$obj || (!is_object($obj) && !is_array($obj))) {
            return null;
        }
        return is_object($obj) ? $obj : (object) $obj;
    }

    /**
     * Scan decoded JSON for any download URL string (Graph sometimes nests or renames the property).
     *
     * @param mixed $data
     * @return string
     */
    private function teams_graph_find_download_url($data) {
        $arr = json_decode(json_encode($data), true);
        if (!is_array($arr)) {
            return '';
        }
        $stack = [$arr];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($cur as $k => $v) {
                if (is_string($k) && is_string($v) && strpos($v, 'http') === 0) {
                    $kl = strtolower($k);
                    if ($kl === '@microsoft.graph.downloadurl' || strpos($kl, 'downloadurl') !== false) {
                        return $v;
                    }
                }
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }
        return '';
    }

    /**
     * Follow .../items/{id}/content redirect to obtain a time-limited file URL.
     *
     * @param string|null $driveid
     * @param string $owneremail
     * @param string $itemid
     * @return string
     */
    private function teams_fetch_content_redirect_download_url($driveid, $owneremail, $itemid) {
        $tok = $this->teams_get_token();
        if ($tok === false || $tok === '') {
            return '';
        }
        if ($driveid !== null && $driveid !== '') {
            $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveid) . '/items/' .
                    rawurlencode($itemid) . '/content';
        } else {
            $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($owneremail) . '/drive/items/' .
                    rawurlencode($itemid) . '/content';
        }
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $tok,
                        'Accept: */*',
                ],
                CURLOPT_CONNECTTIMEOUT => 25,
                CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return '';
        }
        $headerblock = '';
        $headersize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if ($headersize > 0) {
            $headerblock = substr((string) $response, 0, $headersize);
        }
        $redirect = '';
        if (defined('CURLINFO_REDIRECT_URL')) {
            $tmp = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if (is_string($tmp) && strpos($tmp, 'http') === 0) {
                $redirect = $tmp;
            }
        }
        curl_close($ch);
        if ($redirect !== '') {
            return $redirect;
        }
        if (preg_match('/^Location:\s*(.+)$/mi', $headerblock, $m)) {
            return trim($m[1], " \t\r\n\"'");
        }
        return '';
    }

    /**
     * Resolve a usable download URL for a drive item (pre-auth URL or /content redirect).
     *
     * @param string|null $driveid
     * @param string $owneremail
     * @param string $itemid
     * @param stdClass|null $detail Metadata from teams_graph_drive_item_metadata (optional).
     * @return string
     */
    private function teams_resolve_drive_item_download_url($driveid, $owneremail, $itemid, $detail) {
        $fromdetail = '';
        if ($detail) {
            $fromdetail = $this->teams_graph_find_download_url($detail);
        }
        if ($fromdetail !== '') {
            return $fromdetail;
        }
        return $this->teams_fetch_content_redirect_download_url($driveid, $owneremail, $itemid);
    }

    /**
     * Refresh Graph pre-authenticated download URL before Stream ingest (URLs expire).
     *
     * @param stdClass $meeting Row from local_stream_rec (updated in DB when a new URL is found).
     * @return void
     */
    public function teams_refresh_recording_download_url($meeting) {
        global $DB;
        if (empty($meeting->recordingdata) || empty($meeting->email)) {
            return;
        }
        $rd = json_decode($meeting->recordingdata);
        if (!$rd || empty($rd->fileid)) {
            return;
        }
        $fileid = (string) $rd->fileid;
        $driveid = isset($rd->driveid) ? (string) $rd->driveid : '';
        $drivearg = ($driveid !== '') ? $driveid : null;
        $detail = $this->teams_graph_drive_item_metadata($drivearg, (string) $meeting->email, $fileid);
        $download = $this->teams_resolve_drive_item_download_url($drivearg, (string) $meeting->email, $fileid, $detail);
        if ($download === '') {
            mtrace('Teams: could not refresh download URL for recording #' . $meeting->id);
            return;
        }
        $rd->download_url = $download;
        if ($detail && !empty($detail->webUrl)) {
            $rd->play_url = $detail->webUrl;
        }
        $meeting->recordingdata = json_encode($rd);
        $DB->update_record('local_stream_rec', $meeting);
    }

    /**
     * Retrieves the owners of Microsoft Teams groups.
     *
     * @return array The list of owner emails.
     */
    private function teams_groups_owners() {
        $groups = $this->teams_make_request('/groups');
        if (!$groups || empty($groups->value)) {
            mtrace('Teams: could not list groups (missing token or Group.Read.All / etc.).');
            return [];
        }

        $tmpgroups = [];
        $tmpowners = [];
        $tmp = [];

        foreach ($groups->value as $group) {
            $tmpowners[$group->id] = $this->teams_make_request('/groups/' . $group->id . '/owners');
            if (empty($tmpowners[$group->id]->value)) {
                continue;
            }
            foreach ($tmpowners[$group->id]->value as $owner) {
                if (empty($owner->mail) || strpos($owner->mail, 'moodle@') === false) {
                    mtrace('Skipping Group ID: ' . $group->id);
                    continue;
                }
                $tmpgroups[] = $group->id;
            }
        }

        $userlistfiler = explode("\n", trim($this->config->teamsusersfilter ?? ''));

        foreach ($tmpgroups as $tmpgroup) {
            if (empty($tmpowners[$tmpgroup]->value)) {
                continue;
            }
            foreach ($tmpowners[$tmpgroup]->value as $owner) {
                if (empty($owner->mail)) {
                    continue;
                }

                $allow = false;
                foreach ($userlistfiler as $username) {
                    $username = trim($username);
                    if ($username !== '' && strpos($owner->mail, $username) !== false) {
                        $allow = true;
                        break;
                    }
                }

                if (!in_array($owner->mail, $tmp) && $allow) {
                    mtrace('Group ID: ' . $tmpgroup . ' Owner: ' . $owner->mail);
                    $tmp[] = $owner->mail;
                }
            }
        }

        return $tmp;
    }

    /**
     * Recursively retrieves files from a user's Microsoft Teams drive.
     *
     * @param string $owneremail The owner's email address.
     * @param string $id The ID of the drive item.
     * @return array The list of video files.
     */
    private function teams_get_files_recursive($owneremail, $id) {
        $allfiles = [];
        $nextlink = '/users/' . rawurlencode($owneremail) . '/drive/items/' . rawurlencode($id) . '/children';

        // Iterate through subfolders and make recursive calls.
        while ($nextlink) {
            $response = $this->teams_make_request($nextlink);
            if (!$response || !isset($response->value)) {
                break;
            }

            foreach ($response->value as $file) {
                $allfiles[] = $file;
                if (isset($file->folder) && $file->folder->childCount > 0) {
                    $subfolderfiles = $this->teams_get_files_recursive($owneremail, $file->id);
                    $allfiles = array_merge($allfiles, $subfolderfiles);
                }
            }

            // Check for next page.
            $nextlink = isset($response->{'@odata.nextLink'}) ?
                    str_replace('https://graph.microsoft.com/v1.0', '', $response->{'@odata.nextLink'}) : null;
        }

        // Filter files of type 'video/mp4'.
        $filteredfiles = array_filter($allfiles, function($file) {
            return isset($file->file) && $file->file->mimeType === 'video/mp4';
        });

        return array_values($filteredfiles);
    }

    /**
     * Retrieves video files for a specific owner from Microsoft Teams.
     *
     * @param string $owneremail The owner's email address.
     */
    private function teams_get_owner_files($owneremail) {

        $root = $this->teams_make_request('/users/' . rawurlencode($owneremail) . '/drive/root/children');
        if (!$root || empty($root->value)) {
            return;
        }
        $cutoff = $this->teams_listing_cutoff_timestamp();
        foreach ($root->value as $dir) {
            mtrace('Checking folder: ' . $dir->name);
            $files = $this->teams_get_files_recursive($owneremail, $dir->id);
            foreach ($files as $file) {
                if ($this->teams_item_created_not_before($file, $cutoff)) {
                    mtrace('Meeting is within listing window (days to listing / today).');
                    $this->teams_add_meeting($file, null);
                }
            }
        }
    }

    /**
     * Parses course id and section name from a Teams recording file name.
     *
     * Expected prefix: "קורס {id}, {section name}, ..." e.g.
     * "קורס 736, מבוגר עיניים אאג 3, הנושא ...-הקלטת פגישה.mp4"
     *
     * @param string $data Recording display name / topic.
     * @return array{courseid: int, sectionname: string}
     */
    public function teams_course_data($data) {
        $data = trim((string) $data);
        $data = preg_replace('/\.(mp4|mov|webm|mkv|m4v)$/iu', '', $data);

        $courseid = -1;
        $sectionname = '';

        if (preg_match('/^קורס\s+(\d+)\s*,\s*(.*?)(?:\s*,|$)/u', $data, $matches)) {
            $courseid = (int) $matches[1];
            $sectionname = trim($matches[2]);
        } else {
            $parts = explode(',', $data);
            if (isset($parts[0]) && mb_strpos($parts[0], 'קורס') !== false) {
                $digits = preg_replace('/\D/u', '', $parts[0]);
                if ($digits !== '') {
                    $courseid = (int) $digits;
                }
                if (isset($parts[1])) {
                    $sectionname = trim($parts[1]);
                }
            }
        }

        if ($courseid <= 0) {
            $courseid = -1;
        }

        return [
                'courseid' => $courseid,
                'sectionname' => $sectionname,
        ];
    }

    /**
     * Adds a meeting to the database (Teams / Graph drive item).
     *
     * @param object $data Graph driveItem from listing.
     * @param string|null $driveid When set (SharePoint site drives), stored for download URL refresh.
     * @return bool True if the meeting was added, false otherwise.
     */
    public function teams_add_meeting($data, $driveid = null) {
        global $DB;

        if (empty($data->id)) {
            return false;
        }

        $drivearg = ($driveid !== null && $driveid !== '') ? $driveid : null;
        $itemid = (string) $data->id;
        $dedupekey = 't_' . substr(sha1(($drivearg ?? '') . '|' . $itemid), 0, 40);

        if ($DB->get_record('local_stream_rec', ['recordingid' => $dedupekey])) {
            mtrace('Skip Meeting: ' . $itemid);
            return false;
        }

        // fileid is XMLDB text — get_record() conditions are rejected when debugging is on; use sql_compare_text.
        $filewhere = $DB->sql_compare_text('fileid', 4000) . ' = :teamsfileid';
        if ($DB->record_exists_select('local_stream_rec', $filewhere, ['teamsfileid' => $itemid])) {
            mtrace('Skip Meeting (fileid): ' . $itemid);
            return false;
        }

        $email = '';
        if (isset($data->createdBy->user->email)) {
            $email = strtolower((string) $data->createdBy->user->email);
        } else if (isset($data->createdBy->user->userPrincipalName)) {
            $email = strtolower((string) $data->createdBy->user->userPrincipalName);
        }
        if ($email === '' || strpos($email, '@') === false) {
            mtrace('Skip Meeting (no owner email): ' . $itemid);
            return false;
        }

        $detail = $this->teams_graph_drive_item_metadata($drivearg, $email, $itemid);
        $download = $this->teams_graph_find_download_url($data);
        if ($download === '' && $detail) {
            $download = $this->teams_graph_find_download_url($detail);
        }
        if ($download === '') {
            $download = $this->teams_resolve_drive_item_download_url($drivearg, $email, $itemid, $detail);
        }
        if ($download === '') {
            mtrace('Skip Meeting (no download URL): ' . $itemid);
            return false;
        }

        // Rich Teams meeting object (OneDrive) vs plain SharePoint file.
        if (isset($data->source->threadId, $data->media->recordingStartDateTime, $data->video->duration)) {
            $start = new DateTime($data->media->recordingStartDateTime);
            $start = $start->format("Y-m-d\TH:i:s\Z");
            $duration = $this->seconds_to_hms($data->video->duration / 1000);
            $meetingid = strtotime($data->createdDateTime);
            $topic = $data->name;
        } else {
            $created = isset($data->createdDateTime) ? strtotime($data->createdDateTime) : false;
            $start = ($created !== false) ? gmdate('Y-m-d\TH:i:s\Z', $created) : gmdate('Y-m-d\TH:i:s\Z');
            $duration = '00:00:00';
            if (isset($data->video->duration)) {
                $duration = $this->seconds_to_hms(((int) $data->video->duration) / 1000);
            }
            $topic = isset($data->name) ? (string) $data->name : 'Recording';
            $meetingid = ($created !== false) ? $created : time();
        }

        $currdate = gmdate('Y-m-d\TH:i:s\Z');
        $newrecording = new stdClass();
        $newrecording->topic = $topic;
        $coursedata = $this->teams_course_data($topic);
        if ($coursedata['courseid'] > 0 && $DB->record_exists('course', ['id' => $coursedata['courseid']])) {
            $newrecording->course = $coursedata['courseid'];
        }
        $newrecording->recordingid = $dedupekey;
        $newrecording->meetingid = (int) $meetingid;
        $newrecording->email = $email;
        $newrecording->timecreated = time();
        $newrecording->duration = $duration;
        $newrecording->endtime = $currdate;
        $newrecording->embedded = 0;
        $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);

        $rd = [
                'download_url' => $download,
                'fileid' => $itemid,
                'file_size' => isset($data->size) ? (int) $data->size : 0,
                'play_url' => isset($data->webUrl) ? (string) $data->webUrl : '',
        ];
        if ($drivearg !== null) {
            $rd['driveid'] = $drivearg;
        }
        $newrecording->recordingdata = json_encode($rd);
        $newrecording->starttime = $start;
        $newrecording->fileid = $itemid;
        $newrecording->meetingdata = json_encode(['graphItemId' => $itemid, 'driveId' => $drivearg]);

        // Publish immediately.
        if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
            $newrecording->status = $this::MEETING_STATUS_READY;
        }

        $DB->insert_record('local_stream_rec', $newrecording);

        mtrace('Added Meeting: ' . $itemid);

        return true;
    }

    /**
     * Lists Microsoft Teams recordings.
     */
    public function listing_teams() {

        $site = isset($this->config->teamssharepointsite) ? trim((string) $this->config->teamssharepointsite) : '';
        if ($site !== '') {
            mtrace('Teams: listing from SharePoint site (Graph drives).');
            $this->listing_teams_sharepoint_site($site);
            return;
        }

        foreach ($this->teams_groups_owners() as $owner) {
            mtrace('Checking Recording for: ' . $owner);
            $this->teams_get_owner_files($owner);
        }
    }

    /**
     * Uploads a video stream to a given URL.
     *
     * @param array $data The data to upload.
     * @return mixed The stream ID if successful, false otherwise.
     */
    public function upload_stream($data) {

        $url = $this->config->streamurl . '/webservice/api/v1';

        $headers = [
                'Authorization: Bearer ' . $this->config->streamkey,
                'Accept: application/json',
        ];

        $options = [
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
                'CURLOPT_HTTPHEADER' => $headers,
        ];

        $curl = new \curl();
        $jsonresult = $curl->post($url, $data, $options);

        $response = json_decode($jsonresult);
        if (isset($response->streamid)) {
            mtrace('Task: Stream ID: ' . $response->streamid);
            return $response->streamid;
        } else {
            mtrace('Task: error can\'t upload video to stream [' . json_encode($response) . ']');
            return false;
        }
    }

    /**
     * Retrieves the category tree for a given category ID.
     *
     * @param int $categoryid The category ID.
     * @param array $tree The category tree (default empty array).
     * @return string The category tree in JSON format.
     */
    public function get_category_tree($categoryid, $tree = []) {
        global $DB;

        $tmp = $DB->get_record('course_categories', ['id' => $categoryid]);
        if ($tmp) {
            $tree[] = $tmp->name;
            return $this->get_category_tree($tmp->parent, $tree);
        }

        return json_encode($tree);
    }

    /**
     * Fetches the sesskey for the current logged-in user and redirects them to the Stream URL.
     *
     * This method sends the user's information via a POST request to the Stream API
     * to retrieve a sesskey. If the sesskey is valid, the user is redirected to the
     * configured Stream URL with the sesskey as a parameter. Otherwise, a coding exception
     * is thrown.
     *
     * @param string $redirect The redirect URL.
     *
     * @throws coding_exception If the sesskey is not valid.
     */
    public function stream_login($redirect = '') {
        global $DB, $USER;

        $url = $this->config->streamurl . '/webservice/api/v4';
        $headers = [
                'Authorization: Bearer ' . $this->config->streamkey,
                'Accept: application/json',
        ];

        $options = [
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
                'CURLOPT_HTTPHEADER' => $headers,
        ];

        $user = $DB->get_record('user', ['id' => $USER->id]);
        $user = (array) $user;

        $curl = new \curl();
        $response = $curl->post($url, $user, $options);
        $response = json_decode($response);

        if ($response->sesskey) {
            redirect(new moodle_url($this->config->streamurl, ['sesskey' => $response->sesskey, 'redirect' => $redirect]));
        } else {
            throw new coding_exception('sesskey not valid.');
        }
    }

    /**
     * Converts a snake_case string to CamelCase.
     *
     * This function takes a string formatted in snake_case (with underscores)
     * and converts it to CamelCase by removing the underscores and capitalizing
     * the first letter of each subsequent word.
     *
     * @param string $string The input string in snake_case format.
     * @return string The converted string in CamelCase format.
     */
    public function convert_camel_case($string) {

        // Replace underscores with spaces.
        $stringwithspaces = str_replace('_', ' ', $string);

        // Capitalize the first letter of the string.
        $readablestring = ucfirst($stringwithspaces);

        return $readablestring;
    }

    /**
     * Call Stream user-videos API (one batch).
     *
     * @param string $email User email on Stream (required by API).
     * @param int $limit 1–100
     * @param int $offset Pagination offset
     * @return array{error:bool,message:string,videos:array,total?:int}
     */
    public function call_user_videos_api(string $email, int $limit = 50, int $offset = 0): array {
        $streamurl = rtrim((string) ($this->config->streamurl ?? ''), '/');
        $streamkey = (string) ($this->config->streamkey ?? '');
        if ($streamurl === '' || $streamkey === '') {
            return ['error' => true, 'message' => 'missingconfig', 'videos' => [], 'total' => 0];
        }
        if (trim($email) === '') {
            return ['error' => true, 'message' => 'noemail', 'videos' => [], 'total' => 0];
        }

        $limit = min(100, max(1, $limit));
        $offset = max(0, $offset);
        $endpoint = $streamurl . '/webservice/api/user-videos';

        $headers = [
                'Authorization: Bearer ' . $streamkey,
                'Accept: application/json',
        ];
        $options = [
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTPHEADER' => $headers,
        ];

        $curl = new \curl();
        $raw = $curl->post($endpoint, [
                'email' => $email,
                'limit' => $limit,
                'offset' => $offset,
        ], $options);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['error' => true, 'message' => 'invalidjson', 'videos' => [], 'total' => 0];
        }
        if (!empty($decoded['error'])) {
            $msg = trim((string) ($decoded['message'] ?? ''));
            return ['error' => true, 'message' => $msg !== '' ? $msg : 'apierror', 'videos' => [], 'total' => 0];
        }

        $videos = $decoded['videos'] ?? [];
        if (!is_array($videos)) {
            $videos = [];
        }
        $total = isset($decoded['total']) ? (int) $decoded['total'] : count($videos);

        return [
                'error' => false,
                'message' => (string) ($decoded['message'] ?? ''),
                'videos' => $videos,
                'total' => $total,
        ];
    }

    /**
     * Fetch all batches from user-videos and keep only entries with subtitles (has_subtitles).
     *
     * @param string $email Moodle user email sent to Stream
     * @return array{error:bool,message:string,videos:array<int,array>}
     */
    public function collect_user_subtitled_videos(string $email): array {
        $subtitled = [];
        $offset = 0;
        $batch = 100;
        $maxiterations = 100;

        for ($i = 0; $i < $maxiterations; $i++) {
            $resp = $this->call_user_videos_api($email, $batch, $offset);
            if ($resp['error']) {
                return ['error' => true, 'message' => $resp['message'], 'videos' => []];
            }
            foreach ($resp['videos'] as $v) {
                if (is_array($v) && !empty($v['has_subtitles'])) {
                    $subtitled[] = $v;
                }
            }
            if (count($resp['videos']) < $batch) {
                break;
            }
            $offset += $batch;
        }

        return ['error' => false, 'message' => '', 'videos' => $subtitled];
    }

    /**
     * Generate study questions from video subtitles via Stream API.
     *
     * @param int $videoid Stream video id
     * @param int $count Number of questions (1–20)
     * @param string $qtypekey moodle-like key: multichoice | shortanswer | truefalse
     * @return array{error:bool,message?:string,payload?:array}
     */
    public function call_video_subtitle_questions_api(int $videoid, int $count, string $qtypekey): array {
        $streamurl = rtrim((string) ($this->config->streamurl ?? ''), '/');
        $streamkey = (string) ($this->config->streamkey ?? '');
        if ($streamurl === '' || $streamkey === '') {
            return ['error' => true, 'message' => 'missingconfig'];
        }
        if ($videoid < 1) {
            return ['error' => true, 'message' => 'invalidvideoid'];
        }

        $count = min(20, max(1, $count));

        $map = [
                'multichoice' => 'multichoice',
                'shortanswer' => 'short_answer',
                'truefalse' => 'true_false',
        ];
        $questiontype = $map[$qtypekey] ?? 'multichoice';

        $endpoint = $streamurl . '/webservice/api/video-subtitle-questions';

        $headers = [
                'Authorization: Bearer ' . $streamkey,
                'Accept: application/json',
        ];
        $options = [
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTPHEADER' => $headers,
        ];

        $curl = new \curl();
        $raw = $curl->post($endpoint, [
                'videoid' => $videoid,
                'count' => $count,
                'question_type' => $questiontype,
        ], $options);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['error' => true, 'message' => 'invalidjson'];
        }
        if (!empty($decoded['error'])) {
            $msg = trim((string) ($decoded['message'] ?? ''));
            return ['error' => true, 'message' => $msg !== '' ? $msg : 'apierror'];
        }

        return ['error' => false, 'payload' => $decoded];
    }
}
