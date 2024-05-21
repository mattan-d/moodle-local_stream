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

        $meeting = $DB->get_record('local_stream_rec', array('id' => $data->id));
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

        $options = array(
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,

        );

        $header = array(
                'authorization: Basic ' . base64_encode($config->clientid . ':' . $config->clientsecret),
                'Content-Type: application/json',
        );

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
    public function call_zoom_api($url, $jsondata = array(), $method = 'get', $getinfo = false, $debug = false) {

        static $jwt;
        if (!isset($jwt)) {
            $jwt = $this->get_zoom_token();
        }

        $options = array(
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30
        );

        $header = array(
                'authorization: Bearer ' . $jwt,
                'Content-Type: application/json'
        );

        $curl = new \curl();
        $curl->setHeader($header);
        $jsonresult = $curl->$method('https://api.zoom.us/v2/' . $url, $jsondata, $options);
        $response = json_decode($jsonresult);

        if ($response->message && $debug) {
            mtrace('Error: ' . $response->message);
        }

        if ($getinfo) {
            return $curl->get_info();
        }

        return $response;
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
    public function call_webex_api($url, $jsondata = array(), $method = 'get', $getinfo = false) {

        static $jwt;
        if (!isset($jwt)) {
            $jwt = $this->get_webex_token();
        }

        $options = array(
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30
        );

        $header = array(
                'authorization: Bearer ' . $jwt,
                'Content-Type: application/json'
        );

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
    public function call_unicko_api($path, $jsondata = array(), $method = 'get', $getinfo = false) {

        $options = array(
                'RETURNTRANSFER' => true,
                'CURLOPT_MAXREDIRS' => 10,
                'CURLOPT_TIMEOUT' => 30,
        );

        $header = array(
                'authorization: Basic ' . base64_encode($this->config->unickokey . ':' . $this->config->unickosecret),
                'Content-Type: application/json',
        );

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
     * @return array|null The meetings data if successful, otherwise null.
     */
    public function fetch_meetings($data) {
        $url = "/metrics/meetings/?page_size={$data->size}&type={$data->type}&from={$data->from}&to={$data->to}";
        $object = $this->call_zoom_api($url);

        return isset($object->meetings) ? $object->meetings : null;
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
                        array('meetingid' => $meeting->id, 'recordingid' => $recording->id))) {

                    mtrace('Task: Skipping recording #' . $recording->id . ' was previously saved and exists in the db.');
                    continue;
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
                    array('meetingid' => $meeting->meetingId, 'recordingid' => $meeting->id))) {

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

        $i = 0;
        foreach ($meetings->items as $meeting) {

            mtrace('Task: Checking meeting ' . $i . ' out of ' . $totalcount . ' #' . $meeting->id);
            $i++;

            if ($exists = $DB->get_record('local_stream_rec',
                    array('meetingid' => $meeting->id, 'recordingid' => $meeting->meeting))) {

                mtrace('Task: Skipping recording #' . $meeting->id . ' was previously saved and exists in the db.');
                continue;
            }

            mtrace('Task: A new recording was found and saved in the database.');

            $newrecording = new stdClass();
            $newrecording->topic = $meeting->meeting;
            $newrecording->starttime = $meeting->start_time;
            $newrecording->endtime = $meeting->end_time;
            $newrecording->meetingid = $meeting->id;
            $newrecording->recordingid = $meeting->meeting;
            $newrecording->meetingdata = json_encode($meeting);
            $newrecording->recordingdata = json_encode($meeting);
            $newrecording->timecreated = time();
            $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);

            // Publish immediately.
            if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
                $newrecording->status = $this::MEETING_STATUS_READY;
            }

            $DB->insert_record('local_stream_rec', $newrecording);
        }

        // Next page.
        if ($meetings->paging) {
            $parsed = parse_url($meetings->next);
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
                array('id' => $id));

        $cm = get_coursemodule_from_instance('page', $meeting->moduleid);

        if ($meeting) {
            if ($status) {
                $meeting->status = $status;

                if ($status == $this::MEETING_STATUS_DELETED) {
                    $source = $DB->get_record('course_modules',
                            array('course' => $meeting->course, 'instance' => $meeting->moduleid));
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

        $meeting = $DB->get_record('local_stream_rec', array('id' => $id));
        if ($meeting) {

            if (!isset($meeting->meetingdata)) {
                return false;
            }

            if ($this->config->platform == $this::PLATFORM_WEBEX) {
                $recover =
                        $this->call_webex_api('recordings/' . $meeting->recordingid . '?hostEmail=' . $meeting->email, null, 'get');
                if (isset($recover->temporaryDirectDownloadLinks)) {
                    $meeting->recordingdata = json_encode($recover);
                    $recover = array(
                            'http_code' => 204
                    );
                } else {
                    $recover = array(
                            'http_code' => 404
                    );
                }

            } else if ($this->config->platform == $this::PLATFORM_ZOOM) {
                // Recover meeting recordings.
                $recover =
                        $this->call_zoom_api('meetings/' . $meeting->meetingid . '/recordings/status', array('action' => 'recover'),
                                'put', true);
            }

            $meeting->status = 0;
            $meeting->embedded = 0;
            $meeting->tries = 0;
            $DB->update_record('local_stream_rec', $meeting);

            if ($meeting->course && $meeting->moduleid) {
                $source = $DB->get_record('course_modules',
                        array('course' => $meeting->course, 'instance' => $meeting->moduleid));

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

        $recording = $DB->get_record('local_stream_rec', array('id' => $id));
        if ($recording) {
            $urlparams = array();
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
     * Add a Zoom meeting module to a course.
     *
     * This method creates a new module in the specified course with information from the Zoom meeting.
     *
     * @param stdClass $meeting The Zoom meeting information.
     * @return int|bool The ID of the created module or false if the module creation fails.
     */
    public function add_module($meeting) {

        $meeting->idnumber = 'meeting-' . $meeting->meetingid;

        $moduledata = new \stdClass();
        $moduledata->course = $meeting->course;
        $moduledata->modulename = 'page';
        $moduledata->section = 0;
        $moduledata->idnumber = $meeting->idnumber;
        $moduledata->visible = ($this->config->hidefromstudents ? 0 : 1);
        $moduledata->contentformat = FORMAT_HTML;
        $moduledata->introeditor = array(
                'text' => true,
                'format' => true
        );

        if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {

            $recordingdata = json_decode($meeting->recordingdata);

            // Webex.
            if ($this->config->platform == $this::PLATFORM_WEBEX) {
                $recordingurl = '<a target="_blank" href="' . $recordingdata->playbackUrl . '">לחץ/י כאן לצפייה בהקלטה</a>';
            }

            // Zoom.
            if ($this->config->platform == $this::PLATFORM_ZOOM) {
                $recordingurl = '<a target="_blank" href="' . $recordingdata->play_url . '">לחץ/י כאן לצפייה בהקלטה</a>';
            }

            $moduledata->content .= $recordingurl;

            // Teams using onedrive url to display video without download.
            if ($this->config->platform == $this::PLATFORM_TEAMS) {
                $recordingurl = $this->get_meeting($meeting);
                $recordingurl = $recordingurl . '?web=1&csf=1';
                $moduledata->content .= '<a href="' . $recordingurl . '">לחץ/י כאן לצפיה ישירה בהקלטה</a>';
            }

        } else {
            $moduledata->content = 'This video was recorded by ' . $meeting->email . ' <hr> ';
            $moduledata->content .= $this->config->streamurl . '/watch/' . $meeting->streamid;
        }

        if ($this->config->prefix) {
            $moduledata->name = $this->config->prefix . ' ' . $meeting->topic;
        } else {
            $moduledata->name = $meeting->topic;
        }

        if ($this->config->adddate) {
            $moduledata->name .= ' (' . userdate(strtotime($meeting->starttime)) . ')';
        }

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
            $cm = get_coursemodule_from_instance('page', $meeting->moduleid);
            $page = $DB->get_record('page', ['id' => $meeting->moduleid]);

            if ($this->config->prefix) {
                $page->name = $this->config->prefix . ' ' . $meeting->topic;
            } else {
                $page->name = $meeting->topic;
            }

            if ($this->config->adddate) {
                $page->name .= ' (' . userdate(strtotime($meeting->starttime)) . ')';
            }

            $DB->update_record('page', $page);

            course_modinfo::purge_course_module_cache($meeting->course, $cm->id);
        } else {
            return false;
        }
    }

    /**
     * Check if the current user has the capability to edit in the local Stream context.
     *
     * This function checks if the current user has either the 'teacher' or 'editingteacher' role, or if the user
     * is a site administrator, granting them the capability to edit in the local Stream context.
     *
     * @return bool Returns true if the user has the capability to edit, and false otherwise.
     */
    public function has_capability_to_edit() {
        global $USER, $DB, $SESSION;

        static $capability;

        if (isset($SESSION->usercapability)) {
            return $SESSION->usercapability;
        }

        if (!isset($capability)) {
            $teacher = $DB->get_record('role', array('shortname' => 'teacher'));
            $editingteacher = $DB->get_record('role', array('shortname' => 'editingteacher'));

            $capability = false;
            if (user_has_role_assignment($USER->id, $teacher->id) || user_has_role_assignment($USER->id, $editingteacher->id) ||
                    is_siteadmin($USER)) {
                $capability = true;
            }

            if (!isset($SESSION->usercapability)) {
                $SESSION->usercapability = $capability;
            }
        }

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

            if ($courseid) {
                $courseids[] = $courseid;
            } else {
                $courses = enrol_get_my_courses();
                foreach ($courses as $course) {
                    $courseids[] = $course->id;
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
        global $DB, $CFG;

        static $data;

        $sql = '';
        $page = ($page * $this->config->recordingsperpage);

        if ($params['status']) {
            $sql .= ' status IN (' . implode(',', $params['status']) . ')';
        }

        // Filter for students only.
        if (!$this->has_capability_to_edit()) {
            $sql .= ' AND course IN (' . implode(',', $this->get_user_my_courses()) . ')';
            unset($params['email']);
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
            $sql .= ' AND ' . $DB->sql_like('meetingid', ':meetingid', false, false);
        }

        if (isset($params['email']) && $params['email']) {
            $sql .= ' AND ' . $DB->sql_equal('email', ':email', false, false);
        }

        if (isset($params['visible']) && $params['visible']) {
            $params['visible'] = ($params['visible'] == 2 ? 0 : 1);
            $sql .= ' AND ' . $DB->sql_equal('visible', ':visible', false, false);
        }

        if (isset($params['course']) && $params['course']) {
            $sql .= ' AND ' . $DB->sql_equal('course', ':course', false, false);
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
     * @param bool $all Whether to retrieve all courses or only the user's courses.
     * @return array An array of course IDs and their full names.
     */
    public function get_courses($all = false) {
        global $DB;

        if ($all && is_siteadmin()) {
            $cache = $this->cache->get('admin_courses_list');
            if ($cache) {
                $output = $cache;
            } else {
                $courses = get_courses();
                $output = [];
                foreach ($courses as $course) {
                    if ($course->fullname) {
                        $output[$course->id] = $course->fullname;
                    }
                }

                $output = $this->cache->set('admin_courses_list', $output);
            }

            return $output;
        }

        $courseid = optional_param('course', 0, PARAM_INT);

        if (is_siteadmin()) {
            $cache = $this->cache->get('admin_courses');
            if ($cache) {
                $courses = $cache;
            } else {
                $courses =
                        $DB->get_records('local_stream_rec', ['status' => $this::MEETING_STATUS_READY], null,
                                'course');

                $courses = $this->cache->set('admin_courses', $courses);
            }
        } else {
            $courses = enrol_get_my_courses();
        }

        $output = [];
        $output[0] = '';
        foreach ($courses as $course) {
            if (!isset($course->fullname)) {
                $course = $DB->get_record('course', ['id' => $course->course], 'id ,fullname');
            }

            if ($course->fullname) {
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
     * @return string The access token.
     */
    private function teams_get_token() {

        static $response;
        if (!isset($response)) {
            $url = 'https://login.microsoftonline.com/' . $this->config->teamstenantid . '/oauth2/v2.0/token';
            $data = [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->teamsclientid,
                    'client_secret' => $this->config->teamsclientsecret,
                    'scope' => 'https://graph.microsoft.com/.default',
            ];

            $options = array(
                    'CURLOPT_POST' => true,
                    'CURLOPT_RETURNTRANSFER' => true,

            );

            $curl = new \curl();
            $jsonresult = $curl->post($url, $data, $options);
            $response = json_decode($jsonresult);
        }

        return $response->access_token;
    }

    /**
     * Makes a request to the Microsoft Teams API.
     *
     * @param string $path The API endpoint path.
     * @return mixed The response from the API.
     */
    private function teams_make_request($path) {

        $headers = [
                'Authorization: Bearer ' . $this->teams_get_token(),
                'Accept: application/json',
        ];

        $options = array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTPHEADER' => $headers,
        );

        $curl = new \curl();
        $jsonresult = $curl->get('https://graph.microsoft.com/v1.0' . $path, null, $options);
        $response = json_decode($jsonresult);

        return $response;
    }

    /**
     * Retrieves the owners of Microsoft Teams groups.
     *
     * @return array The list of owner emails.
     */
    private function teams_groups_owners() {
        $groups = $this->teams_make_request('/groups');

        $tmpgroups = [];
        $tmpowners = [];
        $tmp = [];

        foreach ($groups->value as $group) {
            $tmpowners[$group->id] = $this->teams_make_request('/groups/' . $group->id . '/owners');
            foreach ($tmpowners[$group->id]->value as $owner) {
                if (strpos($owner->mail, 'moodle@') === false) {
                    mtrace('Skipping Group ID: ' . $group->id);
                    continue;
                }
                $tmpgroups[] = $group->id;
            }
        }

        $allownames = [
                'geriatria',
                'hasava',
                'oncologia',
                'gastro',
                'nituah',
                'nefrologia',
                'psich',
                'hybrid',
                'class',
                'tipuln',
        ];

        // Filter only moolde courses, only user contain "hasava".
        foreach ($tmpgroups as $tmpgroup) {
            foreach ($tmpowners[$tmpgroup]->value as $owner) {

                $allow = false;
                foreach ($allownames as $name) {
                    if (strpos($owner->mail, $name) !== false) {
                        $allow = true;
                        break;
                    }
                }

                if (!in_array($owner->mail, $tmp) && $allow) {
                    mtrace('Group ID: ' . $group->id . ' Owner: ' . $owner->mail);
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
        $files = $this->teams_make_request('/users/' . $owneremail . '/drive/items/' . $id . '/children');

        // Iterate through subfolders and make recursive calls.
        $allfiles = (array) $files->value;
        foreach ($allfiles as $file) {
            if ($file->folder->childCount > 0) {
                $subfolderfiles = $this->teams_get_files_recursive($owneremail, $file->id);
                $allfiles = array_merge($allfiles, $subfolderfiles);
            }
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

        $root = $this->teams_make_request('/users/' . $owneremail . '/drive/root/children');
        foreach ($root->value as $dir) {
            mtrace('Checking folder: ' . $dir->name);
            $files = $this->teams_get_files_recursive($owneremail, $dir->id);
            foreach ($files as $file) {
                $giventimestamp = strtotime($file->createdDateTime);
                $currenttimestamp = time();
                $onedayinseconds = (3 * 24 * 60 * 60); // 3 days.

                if (($currenttimestamp - $giventimestamp) <= $onedayinseconds) {
                    mtrace('Meeting is within the last 24 hours.');
                    $this->teams_add_meeting($file);
                }
            }
        }
    }

    /**
     * Parses course data from a given string.
     *
     * @param string $data The course data string.
     * @return array The parsed course ID and section name.
     */
    public function teams_course_data($data) {
        $parts = explode(',', $data);
        if (strpos($parts[0], 'קורס') !== false) {
            $courseid = trim($parts[0]);
            $courseid = preg_replace('/[^0-9]/', '', $courseid);
        } else {
            $courseid = -1;
        }

        $sectionname = trim($parts[1]);

        return [
                'courseid' => $courseid,
                'sectionname' => $sectionname,
        ];
    }

    /**
     * Adds a meeting to the database.
     *
     * @param object $data The meeting data.
     * @return bool True if the meeting was added, false otherwise.
     */
    public function teams_add_meeting($data) {
        global $DB;

        if (!isset($data->source->threadId)) {
            return;
        }

        if ($DB->get_record('local_stream_rec', ['fileid' => $data->id])) {
            mtrace('Skip Meeting: ' . $data->id);
            return;
        }

        $start = new DateTime($data->media->recordingStartDateTime);
        $start = $start->format("Y-m-d\TH:i:s\Z");

        $currdate = gmdate('Y-m-d\TH:i:s\Z');
        $newrecording = new stdClass();
        $newrecording->topic = $data->name;
        $newrecording->recordingid = uniqid();
        $newrecording->meetingid = strtotime($data->createdDateTime);
        $newrecording->email = $data->createdBy->user->email;
        $newrecording->timecreated = time();
        $newrecording->duration = $this->seconds_to_hms($data->video->duration / 1000);
        $newrecording->endtime = $currdate;
        $newrecording->embedded = 0;
        $newrecording->visible = ($this->config->hidefromstudents ? 0 : 1);
        $newrecording->recordingdata =
                json_encode(array('fileid' => $data->id, 'file_size' => $data->size, 'play_url' => $data->webUrl));
        $newrecording->starttime = $start;
        $newrecording->fileid = $data->id;

        // Publish immediately.
        if ($this->config->storage == $this::STORAGE_NODOWNLOAD) {
            $newrecording->status = $this::MEETING_STATUS_READY;
        }

        $DB->insert_record('local_stream_rec', $newrecording);

        mtrace('Added Meeting: ' . $data->id);

        return true;
    }

    /**
     * Lists Microsoft Teams recordings.
     */
    public function listing_teams() {

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
        global $DB;

        $url = $this->config->streamurl . '/webservice/api.php';

        $headers = array(
                'Authorization: Bearer ' . $this->config->streamkey,
        );

        $options = array(
                'CURLOPT_POST' => true,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_HTTPHEADER' => $headers,
        );

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
}
