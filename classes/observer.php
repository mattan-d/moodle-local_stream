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
 * Event observers for local_stream.
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/stream/locallib.php');

class observer {

    /** User preference key to ensure one-time processing. */
    const PREF_ZOOM_AUTOLICENSE_DONE = 'local_stream_zoom_autolicense_done';

    /**
     * On first login: if user is teacher/editingteacher and not licensed in Zoom, grant license once.
     *
     * @param \core\event\user_loggedin $event
     * @return void
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB;

        $help = new \local_stream_help();
        if ($help->config->platform != $help::PLATFORM_ZOOM) {
            return;
        }
        if (empty($help->config->zoom_auto_license_teachers_first_login)) {
            return;
        }

        $userid = (int) $event->userid;
        if ($userid <= 0) {
            return;
        }
        if (get_user_preferences(self::PREF_ZOOM_AUTOLICENSE_DONE, 0, $userid)) {
            return;
        }

        // Only apply to users that have a teacher or editingteacher role assignment.
        $roles = $DB->get_records_list('role', 'shortname', ['teacher', 'editingteacher'], '', 'id, shortname');
        if (!$roles) {
            return;
        }
        $roleids = array_keys($roles);

        list($insql, $inparams) = $DB->get_in_or_equal($roleids);
        $sql = "SELECT 1
                  FROM {role_assignments}
                 WHERE userid = ?
                   AND roleid {$insql}";
        $params = array_merge([$userid], $inparams);
        $hasrole = $DB->record_exists_sql($sql, $params);
        if (!$hasrole) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id,email', IGNORE_MISSING);
        if (!$user || empty($user->email)) {
            return;
        }

        // Check Zoom user by email; if already licensed, mark done. Otherwise, attempt to grant license.
        $zoomuser = $help->get_zoom_user($user->email);
        if ($zoomuser && isset($zoomuser->type) && (int) $zoomuser->type === 2) {
            set_user_preference(self::PREF_ZOOM_AUTOLICENSE_DONE, 1, $userid);
            return;
        }

        if ($help->grant_zoom_user_license($user->email)) {
            set_user_preference(self::PREF_ZOOM_AUTOLICENSE_DONE, 1, $userid);
        }
    }
}

