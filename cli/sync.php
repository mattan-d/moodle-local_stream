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
 * CLI script for manual synchronization of recordings.
 *
 * @package    local_stream
 * @copyright  2024 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
        [
                'days' => null,
                'date' => null,
                'help' => false,
        ],
        [
                'd' => 'days',
                'f' => 'date',
                'h' => 'help',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Display help.
if ($options['help']) {
    $help = "Manual synchronization of recordings from video conferencing platforms.

Options:
-d, --days=NUMBER       Number of days to sync backwards (default: from plugin config)
-f, --date=DATE         Specific date to sync (format: YYYY-MM-DD)
-h, --help              Print out this help

Note: If both --days and --date are specified, --date takes precedence.

Examples:
\$ php cli/sync.php --days=7
\$ php cli/sync.php -d 30
\$ php cli/sync.php --date=2024-01-15
\$ php cli/sync.php -f 2024-01-15
";

    echo $help;
    exit(0);
}

// Initialize helper.
$help = new local_stream_help();

if (isset($options['date']) && $options['date'] !== null) {
    // Validate date format.
    $date = $options['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        cli_error("Error: Invalid date format. Please use YYYY-MM-DD format.");
    }

    // Validate that date is valid.
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        cli_error("Error: Invalid date specified.");
    }

    // Check if date is not in the future.
    if ($timestamp > time()) {
        cli_error("Error: Cannot sync future dates.");
    }

    $specificdate = $date;
    $days = 0; // Not used when specific date is provided.
    cli_writeln("Using specific date: {$specificdate}");
} else if (isset($options['days']) && $options['days'] !== null) {
    $days = (int)$options['days'];
    if ($days < 0) {
        cli_error("Error: Days must be a positive number.");
    }
    $specificdate = null;
    cli_writeln("Using custom days value: {$days}");
} else {
    $days = $help->config->daystolisting;
    $specificdate = null;
    cli_writeln("Using default days from config: {$days}");
}

cli_writeln("Starting manual sync for platform: " . $help->get_platform_name());
if ($specificdate) {
    cli_writeln("Syncing recordings for date: {$specificdate}");
} else {
    cli_writeln("Syncing recordings from the last {$days} day(s)");
}
cli_writeln(str_repeat('-', 60));

$data = new stdClass();
$today = date('Y-m-d', strtotime('-0 day', time()));
$data->from = $today;
$data->to = $today;

try {
    if ($help->config->platform == $help::PLATFORM_TEAMS) {
        cli_writeln("Processing Teams recordings...");
        $help->listing_teams();
    } else if ($help->config->platform == $help::PLATFORM_UNICKO) {
        cli_writeln("Processing Unicko recordings...");
        $data->page_size = 100;
        $data->order = 'desc';
        if ($specificdate) {
            $timestamp = strtotime($specificdate);
            $days = (int)((time() - $timestamp) / (60 * 60 * 24));
        }
        $data->days = $days;
        $help->listing_unicko($data);
    } else {
        if ($specificdate) {
            // Sync only the specific date.
            $data->from = $specificdate;
            $data->to = $specificdate;

            if ($help->config->platform == $help::PLATFORM_ZOOM) {
                cli_writeln("Processing Zoom recordings for specific date...");
                $data->page = 1;
                $data->size = 300;

                $options = ['past', 'pastOne'];
                $randomkey = array_rand($options);
                $data->type = $options[$randomkey];

                cli_writeln("Date range: {$data->from} to {$data->to}");
                cli_writeln("Meeting type: {$data->type}");
                $help->listing_zoom($data);
            } else if ($help->config->platform == $help::PLATFORM_WEBEX) {
                cli_writeln("Processing Webex recordings for date: {$specificdate}");
                $data->from = $data->from . 'T00:00:00';
                $data->to = $data->to . 'T23:59:00';
                $data->max = 100;
                $data->order = 'desc';

                cli_writeln("Date range: {$data->from} to {$data->to}");
                $help->listing_webex($data);
            }
        } else {
            // Original days-based sync logic.
            for ($i = $days; $i >= 0; $i--) {
                $today = date('Y-m-d', strtotime('-' . $i . ' day', time()));
                $data->from = $today;
                $data->to = $today;

                if ($help->config->platform == $help::PLATFORM_ZOOM) {
                    cli_writeln("Processing Zoom recordings...");
                    $data->page = 1;
                    $data->size = 300;
                    $data->from = date('Y-m-d', strtotime('-' . $days . ' day', time()));
                    $data->to = date('Y-m-d', time());

                    $options = ['past', 'pastOne'];
                    $randomkey = array_rand($options);
                    $data->type = $options[$randomkey];

                    cli_writeln("Date range: {$data->from} to {$data->to}");
                    cli_writeln("Meeting type: {$data->type}");
                    $help->listing_zoom($data);
                    break;
                } else if ($help->config->platform == $help::PLATFORM_WEBEX) {
                    cli_writeln("Processing Webex recordings for date: {$today}");
                    $data->from = $data->from . 'T00:00:00';
                    $data->to = $data->to . 'T23:59:00';
                    $data->max = 100;
                    $data->order = 'desc';

                    cli_writeln("Date range: {$data->from} to {$data->to}");
                    $help->listing_webex($data);
                }
            }
        }
    }

    cli_writeln(str_repeat('-', 60));
    cli_writeln("Sync completed successfully!");
    exit(0);

} catch (Exception $e) {
    cli_writeln(str_repeat('-', 60));
    cli_error("Error during sync: " . $e->getMessage());
}
