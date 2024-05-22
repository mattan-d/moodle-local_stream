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
 * Provider Class.
 *
 * @package   local_stream
 * @copyright  2024 mattandor <mattan@centricapp.co.il>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\privacy;

use core_privacy\local\request\userlist;

/**
 * Class provider.
 *
 * @copyright  2024 mattandor <mattan@centricapp.co.il>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {

    /**
     * Returns metadata.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('local_stream_rec', [
                'email' => 'privacy:metadata:local_stream_rec:email',
        ], 'privacy:metadata:local_stream_rec');

        $collection->add_external_location_link('stream', [
                'email' => 'privacy:metadata:stream:email',
                'fullname' => 'privacy:metadata:stream:fullname',
        ], 'privacy:metadata:stream');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid($userid): contextlist {
        $params = [
                'userid' => $userid,
        ];

        $sql = "SELECT DISTINCT lsc.id
                FROM {local_stream_rec} lsc
                JOIN {user} u ON lsc.email = u.email
                WHERE u.id =  :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
    }

    /**
     * Export all stream data from each specified userid and context.
     *
     * @param int $userid The user to export.
     * @param \context $context The context to export.
     * @param array $subcontext The subcontext within the context to export this information to.
     * @param array $linkarray The weird and wonderful link array used to display information for a specific item
     */
    public static function export_stream_user_data(int $userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;

        if (empty($userid)) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        $meetings = $DB->get_records('local_stream_rec', ['email' => $user->email]);
        foreach ($meetings as $meeting) {
            $contextdata = helper::get_context_data($context, $user);
            // Merge with module data and write it.
            $contextdata = (object) array_merge((array) $contextdata, (array) $meeting);
            writer::with_context($context)->export_data([], $contextdata);
            // Write generic module intro files.
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_stream_for_context(\context $context) {
        global $DB;
        if (empty($context)) {
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }

        // Delete all meetings.
        $DB->delete_records('local_stream_rec', ['cm' => $context->instanceid]);
    }

    /**
     * Delete all user information for the provided user and context.
     *
     * @param int $userid The user to delete
     * @param \context $context The context to refine the deletion.
     */
    public static function delete_stream_for_user(int $userid, \context $context) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        $DB->delete_records('local_stream_rec', ['email' => $user->email]);
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT email
                FROM {local_stream_rec}
                WHERE moduleid = :cm";
        $params = ['cm' => $context->instanceid];
        $userlist->add_from_sql('email', $sql, $params);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }
        // Prepare SQL to gather all completed IDs.
        $userids = $userlist->get_userids();
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $users = $DB->get_records_select('user', "id $insql", $inparams);
        foreach ($users as $user) {
            $DB->delete_records('local_stream_rec', ['email' => $user->email]);
        }
    }
}
