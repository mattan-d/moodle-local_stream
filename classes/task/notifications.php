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
 * Task
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
require_once($CFG->dirroot . '/message/lib.php');

/**
 * scheduled_task functions
 *
 * @package    local_stream
 * @category   admin
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */
class notifications extends \core\task\adhoc_task {

    /**
     * Execute.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $user = $DB->get_record('user', ['id' => $data->userid]);
        if ($user) {

            $message = new \core\message\message();
            $message->courseid = $data->courseid;
            $message->component = 'local_stream';
            $message->name = 'uploaded';
            $message->userfrom = \core_user::get_noreply_user(); // If the message is 'from' a specific user you can set them here.
            $message->userto = $user;
            $message->subject = get_string('messagenewvideosubject', 'local_stream', $data->topic);
            $message->fullmessage = get_string('messagenewvideocontent', 'local_stream', $data);
            $message->fullmessageformat = FORMAT_MARKDOWN;
            $message->fullmessagehtml = get_string('messagenewvideocontent', 'local_stream', $data);
            $message->notification = 1; // Because this is a notification generated from Moodle, not a user-to-user message.
            $message->contexturl =
                    (new \moodle_url('/local/stream/index.php'))->out(false); // A relevant URL for the notification.
            $message->contexturlname =
                    get_string('dashboard',
                            'local_stream'); // Link title explaining where users get to for the contexturl.

            if (message_send($message)) {
                mtrace('notification successfully sent to user #' . $user->id);
            } else {
                mtrace('failed to send notification.');
            }
        }

        return true;
    }
}
