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
 * Zoom account statistics (users, licenses, storage). Shown only when platform is Zoom.
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

admin_externalpage_setup('local_stream_zoom_stats');

$help = new local_stream_help();

if ($help->config->platform != $help::PLATFORM_ZOOM) {
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']),
        get_string('zoom_stats_zoom_only', 'local_stream'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$stats = $help->get_zoom_account_stats();

$PAGE->set_title(get_string('zoom_account_stats', 'local_stream'));
$PAGE->set_heading(get_string('zoom_account_stats', 'local_stream'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('zoom_account_stats', 'local_stream'));

if (!empty($stats->error)) {
    if ($stats->error === 'not_zoom') {
        echo $OUTPUT->notification(get_string('zoom_stats_zoom_only', 'local_stream'), 'warning');
    } else {
        echo $OUTPUT->notification(get_string('zoom_stats_error', 'local_stream') . ' ' . s($stats->error), 'error');
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('zoom_stats_metric', 'local_stream'), get_string('zoom_stats_value', 'local_stream')];
$table->data = [
    [get_string('zoom_stats_total_users', 'local_stream'), $stats->total_users],
    [get_string('zoom_stats_licensed_users', 'local_stream'), $stats->licensed_users],
    [get_string('zoom_stats_basic_users', 'local_stream'), $stats->basic_users],
];

if (isset($stats->total_licenses_in_account) && $stats->total_licenses_in_account !== null) {
    $table->data[] = [
        get_string('zoom_stats_total_licenses', 'local_stream'),
        $stats->total_licenses_in_account,
    ];
} else {
    $table->data[] = [
        get_string('zoom_stats_total_licenses', 'local_stream'),
        get_string('zoom_stats_storage_na', 'local_stream'),
    ];
}

if ($stats->storage_used_gb !== null) {
    $table->data[] = [
        get_string('zoom_stats_storage_used', 'local_stream'),
        get_string('zoom_stats_storage_gb', 'local_stream', ['gb' => $stats->storage_used_gb]),
    ];
} else {
    $table->data[] = [
        get_string('zoom_stats_storage_used', 'local_stream'),
        get_string('zoom_stats_storage_na', 'local_stream'),
    ];
}

echo html_writer::table($table);

$backurl = new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']);
echo $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo $OUTPUT->footer();
