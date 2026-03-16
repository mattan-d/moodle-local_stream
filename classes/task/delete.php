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
class delete extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('deleterecordings', 'local_stream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $help = new \local_stream_help();
        $meetings = $DB->get_records('local_stream_rec', ['status' => $help::MEETING_STATUS_DELETED], null);

        foreach ($meetings as $meeting) {
            mtrace($meeting->topic);
            $meeting->status = $help::MEETING_STATUS_ARCHIVE;
            $DB->update_record('local_stream_rec', $meeting);
        }

        // Zoom: delete recording from Zoom cloud X hours after embedding (if configured).
        if ($help->config->platform == $help::PLATFORM_ZOOM && !empty($help->config->zoom_delete_after_hours)) {
            $hours = (int) $help->config->zoom_delete_after_hours;
            $deadline = time() - ($hours * 3600);
            $meetings = $DB->get_records_sql(
                "SELECT * FROM {local_stream_rec}
                 WHERE embedded = 1 AND embedded_at > 0 AND embedded_at <= ?
                 AND zoom_cloud_deleted = 0",
                [$deadline]
            );
            foreach ($meetings as $meeting) {
                if ($help->delete_zoom_cloud_recording($meeting)) {
                    $meeting->zoom_cloud_deleted = 1;
                    $DB->update_record('local_stream_rec', $meeting);
                    mtrace('Zoom cloud recording #' . $meeting->id . ' deleted from Zoom.');
                }
            }
        }

        // Unicko.
        if ($help->config->platform == $help::PLATFORM_UNICKO && $help->config->daystocleanup > 0) {

            // Calculate the timestamp for days ago.
            $daysago = time() - ($help->config->daystocleanup * 24 * 60 * 60);
            $meetings = $DB->get_records_select('local_stream_rec',
                    'status = ' . $help::MEETING_STATUS_READY . ' AND timecreated < ' . $daysago);

            if ($meetings) {
                mtrace('Total videos to be deleted: ' . count($meetings));
            } else {
                mtrace('No videos older than ' . $help->config->daystocleanup . ' days found.');
            }

            foreach ($meetings as $meeting) {
                if (isset($meeting->recordingid)) {
                    $delete = $help->call_unicko_api('recordings/' . $meeting->recordingid, null, 'delete');
                    mtrace('The video with ID #' . $meeting->id . ' deleted successfully.');
                }
            }

        }

        return true;
    }
}
