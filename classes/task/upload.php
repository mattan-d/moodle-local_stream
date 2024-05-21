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
 * local_stream download recordings.
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/stream/locallib.php');

/**
 * scheduled_task functions
 *
 * @package    local_stream
 * @category   admin
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */
class upload extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('downloadrecordings', 'local_stream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $help = new \local_stream_help();

        global $DB, $CFG;

        $task = new \local_stream\task\notifications();
        $meetings = $DB->get_records_list('local_stream_rec', 'status', [$help::MEETING_STATUS_PROCESS,]);
        if (!$meetings) {
            mtrace('Task: No meetings were found.');
            return true;
        } else {
            $totalcount = count($meetings);
            mtrace('Task: Found ' . $totalcount . ' meetings');
        }

        foreach ($meetings as $meeting) {

            if ($meeting->tries > 2 && $meeting->status == $help::MEETING_STATUS_PROCESS) {
                $meeting->status = $help::MEETING_STATUS_INVALID;
                $DB->update_record('local_stream_rec', $meeting);

                mtrace('Task: File corrupted #' . $meeting->id);
                continue;
            }

            $recordingdata = json_decode($meeting->recordingdata);

            // Webex.
            if ($help->config->platform == $help::PLATFORM_WEBEX) {
                $recordingdata->download_url = $recordingdata->temporaryDirectDownloadLinks->recordingDownloadLink;
            }

            // Zoom.
            if ($help->config->platform == $help::PLATFORM_ZOOM) {
                if ($zoom = $DB->get_record('zoom', ['meeting_id' => $meeting->meetingid])) {
                    if ($zoom->course && $course = get_course($zoom->course)) {

                        $course->tags = $help->get_category_tree($course->category);
                        $course->page = new moodle_url('/course/view.php', ['id' => $course->id]);
                        $meeting->description =
                                'Recorded from Zoom:' . "\n\n" . $course->fullname . "\n" . $course->page . "\n";
                    }
                }
            }

            // Stream Upload.
            $videoid = $help->upload_stream([
                    'topic' => $meeting->topic,
                    'email' => $meeting->email,
                    'description' => $meeting->description,
                    'downloadurl' => $recordingdata->download_url,
                    'tags' => $course->tags,
                    'category' => $help->config->streamcategoryid,
            ]);

            if ($videoid) {
                $meeting->streamid = $videoid;
                $meeting->status = $help::MEETING_STATUS_READY;
                $DB->update_record('local_stream_rec', $meeting);

                mtrace('Task: Video #' . $videoid . ' file successfully uploaded to Stream.');
            }

            $user = $DB->get_record('user', ['email' => $meeting->email]);
            if ($task && $user) {
                $task->set_custom_data([
                        'userid' => $user->id,
                        'courseid' => SITEID,
                        'meetingid' => $meeting->id,
                        'date' => userdate(strtotime($meeting->starttime), '%d/%m/%Y'),
                        'time' => userdate(strtotime($meeting->starttime), '%H:%M'),
                        'topic' => $meeting->topic]);
                \core\task\manager::queue_adhoc_task($task);
            }
        }

        return true;
    }
}
