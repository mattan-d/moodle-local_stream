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
 * local_stream install
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Perform the post-install procedures.
 */
function xmldb_local_stream_install() {
    global $DB;

    $dbman = $DB->get_manager();

    $table = new xmldb_table('local_zoom_integration_rec');
    if ($dbman->table_exists($table)) {

        $configs = get_config('local_zoom_integration');
        $configs = (array) $configs;
        foreach ($configs as $key => $value) {
            set_config($key, $value, 'local_stream');
        }

        $meetings = $DB->get_records('local_zoom_integration_rec', []);
        foreach ($meetings as $meeting) {

            if ($DB->get_record('local_stream_rec', ['recordingid' => $meeting->recordingid])) {
                continue;
            }

            unset($meeting->id);
            $DB->insert_record('local_stream_rec', $meeting);
        }
    }
}
