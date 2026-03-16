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
 * Revoke Zoom license from inactive users (last login >= 6h, not in meeting, no meeting in 2h).
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/stream/locallib.php');

/**
 * Scheduled task: revoke Zoom license from users who meet inactivity conditions.
 */
class revoke_zoom_license extends \core\task\scheduled_task {

    /**
     * Task name for admin.
     *
     * @return string
     */
    public function get_name() {
        return get_string('revoke_zoom_license_task', 'local_stream');
    }

    /**
     * Execute: run revoke-inactive-license logic for Zoom when setting is enabled.
     */
    public function execute() {
        $help = new \local_stream_help();
        $result = $help->run_revoke_inactive_zoom_licenses();
        if (!empty($result['error'])) {
            mtrace('Revoke Zoom license task: ' . $result['error']);
        }
        if (isset($result['revoked']) && $result['revoked'] > 0) {
            mtrace('Revoked Zoom license for ' . $result['revoked'] . ' user(s).');
        }
        return true;
    }
}
