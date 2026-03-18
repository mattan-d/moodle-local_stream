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
 * Zoom group exclusions for revoke-inactive-license mechanism.
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

admin_externalpage_setup('local_stream_zoom_group_exclusions');

$help = new local_stream_help();

if ($help->config->platform != $help::PLATFORM_ZOOM) {
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']),
        get_string('zoom_stats_zoom_only', 'local_stream'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    $selected = optional_param_array('groupids', [], PARAM_RAW_TRIMMED);
    $selected = array_values(array_filter(array_map('trim', $selected)));
    set_config('zoom_revoke_excluded_groupids', implode(',', $selected), 'local_stream');
    redirect(
        new moodle_url('/local/stream/zoom_group_exclusions.php'),
        get_string('changesaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$groups = $help->get_zoom_groups();
$selectedcsv = (string) ($help->config->zoom_revoke_excluded_groupids ?? '');
$selected = $selectedcsv !== '' ? array_filter(array_map('trim', explode(',', $selectedcsv))) : [];
$selected = array_flip($selected);

$PAGE->set_title(get_string('zoom_revoke_excluded_groups', 'local_stream'));
$PAGE->set_heading(get_string('zoom_revoke_excluded_groups', 'local_stream'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('zoom_revoke_excluded_groups', 'local_stream'));

if ($groups === null) {
    echo $OUTPUT->notification(get_string('zoom_revoke_excluded_groups_error', 'local_stream'), 'error');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('zoom_revoke_excluded_groups_desc', 'local_stream'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/stream/zoom_group_exclusions.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);

$table = new html_table();
$table->head = [
    get_string('select'),
    get_string('name'),
    get_string('id'),
];
$table->data = [];

foreach ($groups as $g) {
    $gid = (string) ($g->id ?? '');
    $gname = (string) ($g->name ?? $gid);
    $checked = isset($selected[$gid]);
    $attrs = [
        'type' => 'checkbox',
        'name' => 'groupids[]',
        'value' => $gid,
    ];
    if ($checked) {
        $attrs['checked'] = 'checked';
    }
    $checkbox = html_writer::empty_tag('input', $attrs);
    $table->data[] = [$checkbox, s($gname), s($gid)];
}

echo html_writer::table($table);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('savechanges'),
]);

$backurl = new moodle_url('/admin/settings.php', ['section' => 'local_stream_settings']);
echo ' ' . $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo html_writer::end_tag('form');

echo $OUTPUT->footer();

