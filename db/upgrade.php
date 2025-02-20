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
 * local_stream upgrade
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade between versions.
 *
 * @param int $oldversion Version we are starting from.
 * @return bool    True on success, false on failure.
 */
function xmldb_local_stream_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024060700) {
        $table = new xmldb_table('local_stream_rec');
        $field = new xmldb_field('email', XMLDB_TYPE_CHAR, '64', null, null, null, null);

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024060700, 'local', 'stream');
    }

    if ($oldversion < 2025022000) {
        // Define table local_stream_cc
        $table = new xmldb_table('local_stream_cc');

        // Adding fields
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('meetingid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('uuid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('downloadurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']); // Primary key should be on 'id'
        $table->add_key('uuid_unique', XMLDB_KEY_UNIQUE, ['uuid']); // Ensuring uuid is unique

        // Create table if it does not exist
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025022000, 'local', 'stream');
    }

    return true;
}
