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
 * Teacher embedding preferences.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');

use local_stream\embed_preferences;
use local_stream\form\embed_preferences_form;

$userid = optional_param('userid', $USER->id, PARAM_INT);

[$user, $course] = useredit_setup_preference_page($userid, SITEID);

if (!embed_preferences::can_edit_user((int) $user->id)) {
    throw new moodle_exception('cannotedituserpreferences', 'error');
}

$url = new moodle_url('/local/stream/preferences.php', ['userid' => $user->id]);
$PAGE->set_url($url);
$PAGE->navbar->includesettingsbase = true;

$title = get_string('embedpreferences', 'local_stream');
$PAGE->set_title($title);
$PAGE->set_heading(fullname($user));

$mform = new embed_preferences_form($url);
$mform->set_data(embed_preferences::form_defaults((int) $user->id));

$redirect = new moodle_url('/user/preferences.php', ['userid' => $user->id]);
if ($mform->is_cancelled()) {
    redirect($redirect);
} else if ($data = $mform->get_data()) {
    embed_preferences::save((int) $user->id, $data);
    redirect($redirect, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$mform->display();
echo $OUTPUT->footer();
