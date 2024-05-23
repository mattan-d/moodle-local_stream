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
 * local_stream index recordings.
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
class listing extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('indexrecordings', 'local_stream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $help = new \local_stream_help();

        // Only 1 time running.
        if ($help->config->platform == $help::PLATFORM_TEAMS) { // Teams.
            $help->listing_teams();
        } else {

            $days = $help->config->daystolisting;
            for ($i = $days; $i >= 0; $i--) {
                $today = date('Y-m-d', strtotime('-' . $i . ' day', time()));

                $data = new \stdClass();
                $data->from = $today;
                $data->to = $today;

                mtrace(json_encode($data));

                if ($help->config->platform == $help::PLATFORM_ZOOM) { // Zoom.
                    $data->page = 1;
                    $data->size = 300;
                    $data->type = 'past';
                    $help->listing_zoom($data);
                } else if ($help->config->platform == $help::PLATFORM_WEBEX) { // Webex.
                    $data->from = $data->from . 'T00:00:00';
                    $data->to = $data->to . 'T23:59:00';
                    $data->max = 100;
                    $data->order = 'desc';
                    $help->listing_webex($data);
                } else if ($help->config->platform == $help::PLATFORM_UNICKO) { // Unicko.
                    $data->page_size = 100;
                    $data->order = 'desc';
                    $data->inpage = 1;
                    $data->maxpages = $days;
                    $help->listing_unicko($data);
                    break;
                }
            }

        }

        return true;
    }
}
