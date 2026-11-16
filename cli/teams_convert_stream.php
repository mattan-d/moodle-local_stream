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
 * CLI: convert recordings stored on Stream to Teams playback links.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');
require_once($CFG->dirroot . '/local/stream/classes/teams_link_sync.php');
require_once($CFG->dirroot . '/local/stream/classes/teams_stream_convert.php');

global $DB;

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'dry-run' => false,
                'courseid' => 0,
                'limit' => 0,
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
    $help = "Convert recordings that were saved on the Stream server to Teams links.

Walks local_stream_rec rows that still have a Stream video id. For each row with a
Teams/SharePoint playback link, the course activity is switched to a URL activity and
the Stream watch link is removed. The recording row then points at Teams (streamid is
cleared) so the old Stream link is no longer used.

Rows with no Teams file are left unchanged.

The script runs only when local_stream is configured for Teams.

Options:
--courseid=ID       Only recordings in this Moodle course
--limit=N           Convert at most N rows
-n, --dry-run       Show what would change; course activities and recording rows stay as they are
-h, --help          Show this help

Runs as the site admin user for capability checks.

Examples:
\$ php local/stream/cli/teams_convert_stream.php --dry-run
\$ php local/stream/cli/teams_convert_stream.php --courseid=736
\$ php local/stream/cli/teams_convert_stream.php --limit=50
";

    echo $help;
    exit(0);
}

$courseid = (int) $options['courseid'];
if ($courseid < 0) {
    cli_error('Invalid --courseid.');
}
if ($courseid > 0 && !$DB->record_exists('course', ['id' => $courseid])) {
    cli_error('Course ' . $courseid . ' was not found.');
}

$limitraw = (string) $options['limit'];
if (!preg_match('/^\d+$/', $limitraw)) {
    cli_error('Invalid --limit. Use a non-negative integer.');
}
$limit = (int) $limitraw;

$helpobj = new local_stream_help();
if ((int) $helpobj->config->platform !== local_stream_help::PLATFORM_TEAMS) {
    cli_error('Stream-to-Teams conversion only runs when local_stream is configured for Teams.');
}

// create_module() / course_delete_module() check capabilities against $USER.
\core\session\manager::set_user(get_admin());

$convert = new \local_stream\teams_stream_convert($helpobj, $courseid, $limit, !empty($options['dry-run']));
$convert->execute();
