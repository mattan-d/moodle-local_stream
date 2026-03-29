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
 * CLI: re-embed all recordings that are already embedded (ready status, linked to a course).
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');
require_once($CFG->dirroot . '/local/stream/classes/embed_recording_helper.php');

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'dry-run' => false,
                'no-remove-old' => false,
                'courseid' => null,
                'notify' => false,
        ],
        [
                'h' => 'help',
                'n' => 'dry-run',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "Re-embed existing embedded recordings (local_stream_rec with embedded=1, status=ready, course set).

By default the script removes the previous mod_stream (or removes one video from a Zoom collection)
before embedding again. Use --no-remove-old to skip removal (risk of duplicate activities for
non-collection courses).

Options:
-n, --dry-run           List actions only; no database or course changes
    --no-remove-old     Do not delete the previous embed before re-embedding
    --courseid=ID       Limit to one Moodle course id
    --notify            Queue user notification adhoc tasks after each successful embed (like cron)
-h, --help              Show this help

Examples:
\$ php local/stream/cli/reembed.php --dry-run
\$ php local/stream/cli/reembed.php -n
\$ php local/stream/cli/reembed.php --courseid=42
\$ php local/stream/cli/reembed.php --no-remove-old --dry-run
";

    echo $help;
    exit(0);
}

$removeold = empty($options['no-remove-old']);
$dryrun = !empty($options['dry-run']);
$notify = !empty($options['notify']);

$help = new local_stream_help();
$log = function(string $msg): void {
    cli_writeln($msg);
};

global $DB;

$sql = 'embedded = :emb AND status = :st AND course > 0 AND moduleid > 0';
$params = [
        'emb' => 1,
        'st' => $help::MEETING_STATUS_READY,
];
if ($options['courseid'] !== null && $options['courseid'] !== '') {
    $courseid = (int) $options['courseid'];
    if ($courseid < 1) {
        cli_error('Invalid --courseid.');
    }
    $sql .= ' AND course = :cid';
    $params['cid'] = $courseid;
}

$meetings = $DB->get_records_select('local_stream_rec', $sql, $params, 'id ASC');

if (!$meetings) {
    cli_writeln('No matching recordings (embedded=1, ready, course>0, moduleid>0).');
    exit(0);
}

$streammodule = $DB->get_record('modules', ['name' => 'stream']);
if (!$streammodule) {
    cli_error('mod_stream module type not found; cannot embed.');
}

$platformmodule = null;
if ($help->config->platform == $help::PLATFORM_ZOOM) {
    $platformmodule = $DB->get_record('modules', ['name' => 'zoom']);
} else if ($help->config->platform == $help::PLATFORM_TEAMS) {
    $platformmodule = $DB->get_record('modules', ['name' => 'msteams']);
} else if ($help->config->platform == $help::PLATFORM_UNICKO) {
    $platformmodule = $DB->get_record('modules', ['name' => 'lti']);
}

cli_writeln('Platform: ' . $help->get_platform_name());
cli_writeln('Recordings to process: ' . count($meetings));
cli_writeln('Remove old embed first: ' . ($removeold ? 'yes' : 'no'));
cli_writeln('Dry run: ' . ($dryrun ? 'yes' : 'no'));
cli_writeln(str_repeat('-', 60));

if (!$removeold && !$dryrun) {
    cli_writeln('Warning: --no-remove-old may create duplicate mod_stream activities when collection mode does not merge videos.');
}

foreach ($meetings as $meeting) {
    $log('--- Recording id ' . $meeting->id . ' (course ' . $meeting->course . ', moduleid ' . $meeting->moduleid . ') ---');
    if ($removeold) {
        $help->remove_embedded_stream_activity($meeting, $dryrun, $log);
    }
    if (!$dryrun && $removeold) {
        $meeting = $DB->get_record('local_stream_rec', ['id' => $meeting->id], '*', MUST_EXIST);
    }
    \local_stream\embed_recording_helper::process_single(
            $help,
            $meeting,
            $streammodule,
            $platformmodule,
            $dryrun,
            $notify,
            $log
    );
}

cli_writeln(str_repeat('-', 60));
cli_writeln($dryrun ? 'Dry run finished.' : 'Re-embed finished.');
exit(0);
