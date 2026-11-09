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
 * Reset every teacher's embedding preferences back to the site settings.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_stream\embed_preferences;

admin_externalpage_setup('local_stream_reset_embed_preferences');

$confirm = optional_param('confirm', 0, PARAM_BOOL);
$url = new moodle_url('/local/stream/reset_embed_preferences.php');
$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']);
$PAGE->set_url($url);

$count = embed_preferences::count_users_with_preferences();

if ($confirm && confirm_sesskey()) {
    $reset = embed_preferences::reset_all();
    redirect($returnurl, get_string('resetembedpreferences_done', 'local_stream', $reset),
            null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resetembedpreferences', 'local_stream'));

if ($count === 0) {
    echo $OUTPUT->notification(get_string('resetembedpreferences_none', 'local_stream'),
            \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button($returnurl);
} else {
    $confirmstring = ($count === 1)
            ? get_string('resetembedpreferences_confirm_one', 'local_stream')
            : get_string('resetembedpreferences_confirm', 'local_stream', $count);
    echo $OUTPUT->confirm(
            $confirmstring,
            new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
            $returnurl
    );
}

echo $OUTPUT->footer();
