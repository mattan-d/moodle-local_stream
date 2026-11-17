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
 * CLI: sync only Zoom meetings whose name contains a course code.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');
require_once($CFG->dirroot . '/local/stream/classes/zoom_course_embed.php');

list($options, $unrecognized) = cli_get_params(
        [
                'days' => null,
                'date' => null,
                'meetingid' => null,
                'email' => null,
                'reset-status' => false,
                'recover-trash' => false,
                'help' => false,
        ],
        [
                'd' => 'days',
                'f' => 'date',
                'm' => 'meetingid',
                'e' => 'email',
                'r' => 'reset-status',
                't' => 'recover-trash',
                'h' => 'help',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "סנכרון Zoom רק למפגשים ששם המפגש שלהם מכיל קוד קורס.

הקוד הוא בפורמט כמו 31-2-31212, 12-33-4234234 או 8-1-324234234.
מפגשים בלי קוד כזה לא נשמרים.
לפני השמירה, ה-passcode של ההקלטה ב-Zoom מבוטל כדי שהקובץ ירד בלי סיסמה.

Options:
-d, --days=NUMBER       כמה ימים אחורה (ברירת מחדל: מהגדרות התוסף)
-f, --date=YYYY-MM-DD   תאריך אחד
-m, --meetingid=ID      רק מפגש עם המזהה הזה, אם יש לו קוד בשם
-e, --email=EMAIL       רק מארח זה
-r, --reset-status      להחזיר הקלטות קיימות לתור
-t, --recover-trash     לשחזר מהפח אם אין הקלטה פעילה
-h, --help              Show this help

Examples:
\$ php local/stream/cli/sync_course_code.php --days=2
\$ php local/stream/cli/sync_course_code.php --date=2026-11-17
";

    echo $help;
    exit(0);
}

$help = new local_stream_help();
if ((int) $help->config->platform !== local_stream_help::PLATFORM_ZOOM) {
    cli_error('הסנכרון הזה מיועד ל-Zoom בלבד.');
}

$help->synccoursecodesonly = true;
$help->clearrecordingpasscode = true;
$help->resetexistingstatus = !empty($options['reset-status']);
$help->recoverzoomtrash = !empty($options['recover-trash']);
$help->syncmeetingid = ltrim(trim((string) ($options['meetingid'] ?? '')), '#');
$help->syncemail = strtolower(trim((string) ($options['email'] ?? '')));
if ($help->syncemail !== '' && !validate_email($help->syncemail)) {
    cli_error('Error: Invalid email.');
}

if (isset($options['date']) && $options['date'] !== null) {
    $date = $options['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        cli_error('Error: Invalid date. Use YYYY-MM-DD.');
    }
    if (strtotime($date) > time()) {
        cli_error('Error: Cannot sync future dates.');
    }
    $specificdate = $date;
    $days = 0;
    cli_writeln('Using specific date: ' . $specificdate);
} else if (isset($options['days']) && $options['days'] !== null) {
    $days = (int) $options['days'];
    if ($days < 0) {
        cli_error('Error: Days must be a positive number.');
    }
    $specificdate = null;
    cli_writeln('Using custom days value: ' . $days);
} else {
    $days = $help->config->daystolisting;
    $specificdate = null;
    cli_writeln('Using default days from config: ' . $days);
}

cli_writeln('Starting Zoom sync for meetings whose name contains a course code');
if ($specificdate) {
    cli_writeln('Syncing recordings for date: ' . $specificdate);
} else {
    cli_writeln('Syncing recordings from the last ' . $days . ' day(s)');
}
cli_writeln(str_repeat('-', 60));

$data = new stdClass();
$data->page = 1;
$data->size = 300;
if ($specificdate) {
    $data->from = $specificdate;
    $data->to = $specificdate;
} else {
    $data->from = date('Y-m-d', strtotime('-' . $days . ' day', time()));
    $data->to = date('Y-m-d', time());
}
$meetingtypes = ['past', 'pastOne'];
$data->type = $meetingtypes[array_rand($meetingtypes)];

cli_writeln('Date range: ' . $data->from . ' to ' . $data->to);
cli_writeln('Meeting type: ' . $data->type);

try {
    $help->listing_zoom($data);
    cli_writeln(str_repeat('-', 60));
    cli_writeln('Sync completed successfully!');
    exit(0);
} catch (Exception $e) {
    cli_writeln(str_repeat('-', 60));
    cli_error('Error during sync: ' . $e->getMessage());
}
