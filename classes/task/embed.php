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
 * local_stream embedded recordings.
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/stream/locallib.php');
require_once($CFG->dirroot . '/local/stream/classes/embed_recording_helper.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/externallib.php');

/**
 * scheduled_task functions
 *
 * @package    local_stream
 * @category   admin
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */
class embed extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('embeddedrecordings', 'local_stream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $help = new \local_stream_help();
        $meetings =
                $DB->get_records('local_stream_rec', ['embedded' => 0, 'status' => $help::MEETING_STATUS_READY],
                        'timecreated DESC', '*', '0',
                        '100');

        // Type-base grouping.
        if ($help->config->basedgrouping) {
            $uniquemeetings = [];
            $uuids = [];
            foreach ($meetings as $meeting) {
                $meetingdata = json_decode($meeting->meetingdata, true);
                if (isset($meetingdata['uuid']) && !in_array($meetingdata['uuid'], $uuids)) {
                    $uuids[] = $meetingdata['uuid'];
                    $uniquemeetings[] = $meeting;
                } else {
                    $meeting->embedded = 5;
                    $DB->update_record('local_stream_rec', $meeting);
                }
            }

            $meetings = $uniquemeetings;
        }

        if (!$meetings) {
            mtrace('There are no recordings to be embedded.');
            return true;
        }

        $module = null;
        if ($help->config->platform == $help::PLATFORM_ZOOM) {
            $module = $DB->get_record('modules', ['name' => 'zoom']);
        } else if ($help->config->platform == $help::PLATFORM_TEAMS) {
            $module = $DB->get_record('modules', ['name' => 'msteams']);
        } else if ($help->config->platform == $help::PLATFORM_UNICKO) {
            $module = $DB->get_record('modules', ['name' => 'lti']);
        }
        $streammodule = $DB->get_record('modules', ['name' => 'stream']);
        if (!$streammodule) {
            mtrace('mod_stream module type not found; cannot embed recordings.');
            return true;
        }

        foreach ($meetings as $meeting) {
            \local_stream\embed_recording_helper::process_single(
                    $help,
                    $meeting,
                    $streammodule,
                    $module,
                    false,
                    true,
                    null
            );
        }

        return true;
    }
}
