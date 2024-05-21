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
        global $DB, $USER;

        $help = new \local_stream_help();
        $task = new \local_stream\task\notifications();
        $meetings =
                $DB->get_records('local_stream_rec', ['embedded' => 0, 'status' => $help::MEETING_STATUS_READY],
                        'timecreated DESC', '*', '0',
                        '100');

        if (!$meetings) {
            mtrace('There are no recordings to be embedded.');
            return true;
        }

        if ($help->config->platform == $help::PLATFORM_ZOOM) {
            $module = $DB->get_record('modules', ['name' => 'zoom']);

        } else if ($help->config->platform == $help::PLATFORM_TEAMS) {
            $module = $DB->get_record('modules', ['name' => 'msteams']);
        }

        foreach ($meetings as $meeting) {

            if ($help->config->platform == $help::PLATFORM_ZOOM) {
                $platform = $DB->get_record('zoom', ['meeting_id' => $meeting->meetingid]);
            } else if ($help->config->platform == $help::PLATFORM_TEAMS) {

                // Define a regular expression pattern to match the ID.
                $pattern = '/^.*:meeting_([A-Za-z0-9]+)@thread\.v2$/';
                // Use preg_match to find matches.
                if (preg_match($pattern, $meeting->recordingid, $matches)) {
                    // Will contain the first set of parentheses, which is the ID.
                    $tmpmeetingid = $matches[1];
                    mtrace('checking meeting: ' . $meeting->recordingid);
                    $likesql = $DB->sql_like('externalurl', ':externalurl');
                    $platform = $DB->get_record_sql(
                            "SELECT id, course FROM {msteams} WHERE {$likesql}",
                            [
                                    'externalurl' => '%' . $tmpmeetingid . '%',
                            ]
                    );
                }

                $details = $help->teams_course_data($meeting->topic);
                if ($details['courseid'] > 0) {
                    $platform = new \stdClass();
                    $platform->course = $details['courseid'];
                }

            } else {
                $platform = false;
            }

            if (!$platform) {
                if ($meeting->course) {
                    $page = $help->add_module($meeting);
                    $meeting->moduleid = $page->id;
                    $meeting->embedded = 1;
                    mtrace('NO-PLATFORM: The video with ID #' . $meeting->id . ' was embedded in course #' . $meeting->course .
                            '.');
                } else {
                    $meeting->embedded = 2;
                    mtrace('meeting not found.');
                }
            } else {
                $meeting->course = $platform->course;
                if (!$course = $DB->get_record('course', ['id' => $meeting->course])) {

                    $meeting->embedded = 2;
                    $DB->update_record('local_stream_rec', $meeting);

                    mtrace('The video with ID #' . $meeting->id . ' not found in course #' . $meeting->course . '.');
                    continue;
                }

                if ($page = $help->add_module($meeting)) {

                    $source = $DB->get_record('course_modules',
                            ['course' => $platform->course, 'instance' => $page->id]);
                    $destination = $DB->get_record('course_modules',
                            ['course' => $platform->course, 'module' => $module->id, 'instance' => $platform->id]);

                    $section = $DB->get_record('course_sections',
                            ['course' => $platform->course, 'id' => $destination->section]);

                    // Hack for TEAMS.
                    if (isset($details['sectionname']) && $details['sectionname']) {
                        $section = $DB->get_record('course_sections',
                                ['course' => $platform->course, 'name' => $details['sectionname']]);

                        if ($section) {
                            moveto_module($source, $section);
                        }
                    } else {

                        if ($destination) {
                            moveto_module($source, $section, $destination);
                        }

                        // Move page under Zoom meeting.
                        if ($help->config->embedorder == '1') {
                            moveto_module($destination, $section, $source);
                        }
                    }

                    $meeting->embedded = 1;
                    $meeting->course = $platform->course;
                    $meeting->moduleid = $page->id;
                    mtrace('The video with ID #' . $meeting->id . ' was embedded in course #' . $platform->course . '.');
                }
            }

            $DB->update_record('local_stream_rec', $meeting);

            if ($task && $meeting->course && $meeting->visible) {

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
        }

        return true;
    }
}
