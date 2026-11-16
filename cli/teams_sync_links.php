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
 * CLI: sync Teams recording links from the server into courses.
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
require_once($CFG->dirroot . '/local/stream/classes/teams_link_sync.php');

global $DB;

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'days' => 7,
                'courseid' => 0,
                'dry-run' => false,
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
    $help = "Sync Teams recording links from the server into Moodle courses.

Lists Teams recordings from the server, compares each playback link with links already
in the course (stream activities, labels, pages, URLs, and section summaries), embeds
recordings that are missing, and updates a stream activity when its link no longer
matches the server. A recording already in the course is reused: the script does not
create a second activity for the same file, link, or recording name. Links that exist
only in the course are left in place.

The script runs only when local_stream is configured for Teams.

Options:
--days=N            How many days back to sync (default 7). 0 means since today midnight.
--courseid=ID       Limit course updates to one Moodle course id
-n, --dry-run       Show course changes only. Indexing from Teams still runs.
-h, --help          Show this help

Runs as the site admin user for capability checks.

Examples:
\$ php local/stream/cli/teams_sync_links.php
\$ php local/stream/cli/teams_sync_links.php --days=14
\$ php local/stream/cli/teams_sync_links.php --days=7 --courseid=736 --dry-run
";

    echo $help;
    exit(0);
}

$daysraw = (string) $options['days'];
if (!preg_match('/^\d+$/', $daysraw)) {
    cli_error('Invalid --days. Use a non-negative integer (default 7).');
}
$days = (int) $daysraw;

$courseid = (int) $options['courseid'];
if ($courseid < 0) {
    cli_error('Invalid --courseid.');
}
if ($courseid > 0 && !$DB->record_exists('course', ['id' => $courseid])) {
    cli_error('Course ' . $courseid . ' was not found.');
}

$helpobj = new local_stream_help();
if ((int) $helpobj->config->platform !== local_stream_help::PLATFORM_TEAMS) {
    cli_error('Teams link sync only runs when local_stream is configured for Teams.');
}

// create_module() checks capabilities against $USER; CLI has no session user by default.
\core\session\manager::set_user(get_admin());

$sync = new \local_stream\teams_link_sync($helpobj, $days, $courseid, !empty($options['dry-run']));
$sync->execute();
