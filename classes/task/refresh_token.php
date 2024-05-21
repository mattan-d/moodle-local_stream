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
class refresh_token extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('refreshtoken', 'local_stream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $help = new \local_stream_help();

        if ($help->config->platform == $help::PLATFORM_WEBEX) {

            $jsonresult = shell_exec("curl -X POST https://webexapis.com/v1/access_token \
     -H 'Content-Type: application/x-www-form-urlencoded' \
     -d 'grant_type=refresh_token' \
     -d 'client_id=" . $help->config->webexclientid . "' \
     -d 'client_secret=" . $help->config->webexclientsecret . "' \
     -d 'refresh_token=" . $help->config->webexrefreshtoken . "'
");
            $response = json_decode($jsonresult);

            if (!isset($response->access_token)) {
                mtrace('Task: ' . $response->error);
                return false;
            } else {
                set_config('webexjwt', $response->access_token, 'local_stream');
                mtrace('Task: refresh token expires in: ' . $response->refresh_token_expires_in);
            }
        }
    }
}
