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
 * CLI: embed ready recordings into the course whose idnumber is in the meeting name.
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
                'help' => false,
                'collection' => false,
                'dry-run' => false,
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
    $help = "שיבוץ הקלטות מוכנות לקורס לפי מזהה שמופיע בשם המפגש.

המזהה הוא קוד כמו 31-2-31212, 12-33-4234234 או 8-1-324234234.
הסקריפט מחפש קורס שמספר הזיהוי שלו (idnumber) שווה לקוד, ומשבץ שם את ההקלטה.

בלי --collection כל הקלטה נוצרת כפעילות נפרדת.
עם --collection כל ההקלטות של אותו קורס נכנסות לפעילות אחת, כרשימת השמעה.

Options:
--collection     אוסף: רשימת השמעה אחת לכל קורס
-n, --dry-run    הצגה בלבד, בלי יצירת פעילויות
--limit=N        מספר הקלטות לכל היותר
-h, --help       Show this help

Examples:
\$ php local/stream/cli/embed_recordings.php --dry-run
\$ php local/stream/cli/embed_recordings.php --collection
\$ php local/stream/cli/embed_recordings.php --collection --limit=20
";

    echo $help;
    exit(0);
}

$limit = $options['limit'];
if (!preg_match('/^\d+$/', (string) $limit)) {
    cli_error('--limit חייב להיות מספר.');
}

if (!empty($options['collection']) && !\core_plugin_manager::instance()->get_plugin_info('mod_stream')) {
    $streaminstalled = file_exists($CFG->dirroot . '/mod/stream/version.php');
    if (!$streaminstalled) {
        cli_error('מצב אוסף דורש את רכיב mod_stream.');
    }
}

\core\session\manager::set_user(get_admin());

$embed = new \local_stream\zoom_course_embed(
        new \local_stream_help(),
        !empty($options['collection']),
        !empty($options['dry-run']),
        (int) $limit
);
$embed->execute();
