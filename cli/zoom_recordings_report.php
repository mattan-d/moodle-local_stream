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
 * CLI: report how many cloud recordings a Zoom account has.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');
require_once($CFG->dirroot . '/local/stream/classes/zoom_recordings_report.php');

$defaultto = date('Y-m-d');
$defaultfrom = date('Y-m-d', strtotime('-12 months'));

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'accountid' => '',
                'clientid' => '',
                'clientsecret' => '',
                'from' => $defaultfrom,
                'to' => $defaultto,
                'trash' => false,
        ],
        [
                'h' => 'help',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "דוח כמות הקלטות בענן של חשבון Zoom.

הסקריפט מקבל את פרטי החיבור של Zoom (Server-to-Server OAuth), עובר על המשתמשים
בחשבון, וסופר פגישות עם הקלטות, קבצי הקלטה וקבצי MP4. Zoom מאפשר לשלוף חודש אחד
בכל קריאה, ולכן הטווח מחולק לחודשים.

אם לא מעבירים פרטי חיבור, נעשה שימוש בהגדרות mod_zoom כשהן קיימות, ואחרת בהגדרות local_stream.

Options:
--accountid=ID         Zoom account ID
--clientid=ID          Zoom client ID
--clientsecret=SECRET  Zoom client secret
--from=YYYY-MM-DD      תאריך התחלה (ברירת מחדל: לפני 12 חודשים)
--to=YYYY-MM-DD        תאריך סיום (ברירת מחדל: היום)
--trash                לכלול גם הקלטות בפח
-h, --help             Show this help

Examples:
\$ php local/stream/cli/zoom_recordings_report.php --help
\$ php local/stream/cli/zoom_recordings_report.php \\
    --accountid=ACCOUNT --clientid=CLIENT --clientsecret=SECRET
\$ php local/stream/cli/zoom_recordings_report.php --from=2026-01-01 --to=2026-03-31
";

    echo $help;
    exit(0);
}

$config = get_config('local_stream');
if (is_object($config)) {
    local_stream_help::apply_mod_zoom_credentials($config);
}
$accountid = trim((string) $options['accountid']);
$clientid = trim((string) $options['clientid']);
$clientsecret = (string) $options['clientsecret'];
if ($accountid === '') {
    $accountid = trim((string) ($config->accountid ?? ''));
}
if ($clientid === '') {
    $clientid = trim((string) ($config->clientid ?? ''));
}
if ($clientsecret === '') {
    $clientsecret = (string) ($config->clientsecret ?? '');
}
if ($accountid === '' || $clientid === '' || $clientsecret === '') {
    cli_error('חסרים פרטי Zoom. יש להעביר --accountid, --clientid ואת --clientsecret, או להגדיר אותם ב-mod_zoom או בתוסף זה.');
}

$from = (string) $options['from'];
$to = (string) $options['to'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    cli_error('תאריך לא תקין. השתמש בפורמט YYYY-MM-DD.');
}
if ($from > $to) {
    cli_error('--from צריך להיות לפני --to או באותו יום.');
}

try {
    $report = new \local_stream\zoom_recordings_report(
            $accountid,
            $clientid,
            $clientsecret,
            $from,
            $to,
            !empty($options['trash'])
    );
    $report->execute();
} catch (\moodle_exception $e) {
    $detail = $e->debuginfo !== null && $e->debuginfo !== '' ? $e->debuginfo : $e->getMessage();
    cli_error($detail);
}
