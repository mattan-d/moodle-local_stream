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
 * CLI: sync recordings from legacy local_zoom_integration_rec to local_stream_rec.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'dry-run' => false,
                'update' => false,
                'migrate-config' => false,
                'from-id' => 0,
                'limit' => 0,
                'batch-size' => 200,
        ],
        [
                'h' => 'help',
                'n' => 'dry-run',
                'u' => 'update',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo "Sync recordings from legacy table {local_zoom_integration_rec} to {local_stream_rec}.

By default only rows whose recordingid is not yet in local_stream_rec are inserted.
Use --update to refresh existing rows in local_stream_rec from the legacy table.

Options:
-n, --dry-run           Show what would be done without writing
-u, --update            Update existing local_stream_rec rows (matched by recordingid)
    --migrate-config    Copy plugin settings from local_zoom_integration to local_stream
    --from-id=ID        Process legacy rows with id greater than ID (default 0)
    --limit=N           Stop after N legacy rows (0 = no limit)
    --batch-size=N      Commit progress every N rows (default 200)
-h, --help              Show this help

Legacy-only columns (ignored): vimeoid, remote.
New columns (left unchanged on update): embedded_at, zoom_cloud_deleted.

Examples:
\$ php local/stream/cli/migrate_legacy.php --dry-run
\$ php local/stream/cli/migrate_legacy.php
\$ php local/stream/cli/migrate_legacy.php --update
\$ php local/stream/cli/migrate_legacy.php --migrate-config --from-id=1000 --limit=500
";
    exit(0);
}

$dryrun = !empty($options['dry-run']);
$update = !empty($options['update']);
$fromid = max(0, (int) $options['from-id']);
$limit = max(0, (int) $options['limit']);
$batchsize = max(1, (int) $options['batch-size']);

global $DB;

$legacytable = new xmldb_table('local_zoom_integration_rec');
$dbman = $DB->get_manager();

if (!$dbman->table_exists($legacytable)) {
    cli_error('Legacy table local_zoom_integration_rec does not exist; nothing to sync.');
}

if (!$dbman->table_exists(new xmldb_table('local_stream_rec'))) {
    cli_error('Target table local_stream_rec does not exist. Install or upgrade local_stream first.');
}

/**
 * Map a legacy row to local_stream_rec columns.
 *
 * @param \stdClass $legacy
 * @return \stdClass
 */
$maplegacy = static function(\stdClass $legacy): \stdClass {
    $fields = [
            'topic', 'email', 'dept', 'starttime', 'endtime', 'duration',
            'participants', 'meetingid', 'recordingid', 'timecreated',
            'visible', 'embedded', 'course', 'status', 'tries', 'views',
            'meetingdata', 'recordingdata', 'moduleid', 'streamid', 'fileid',
    ];
    $row = new \stdClass();
    foreach ($fields as $field) {
        if (property_exists($legacy, $field)) {
            $row->$field = $legacy->$field;
        }
    }
    return $row;
};

cli_writeln('Legacy sync: local_zoom_integration_rec -> local_stream_rec');
cli_writeln('Dry run: ' . ($dryrun ? 'yes' : 'no'));
cli_writeln('Update existing: ' . ($update ? 'yes' : 'no'));
cli_writeln('From legacy id > ' . $fromid);
if ($limit > 0) {
    cli_writeln('Limit: ' . $limit);
}
cli_writeln(str_repeat('-', 60));

if (!empty($options['migrate-config'])) {
    $legacyconfigs = (array) get_config('local_zoom_integration');
    if (!$legacyconfigs) {
        cli_writeln('No local_zoom_integration config found; skipping config copy.');
    } else if ($dryrun) {
        cli_writeln('[dry-run] Would copy ' . count($legacyconfigs) . ' config value(s) to local_stream.');
    } else {
        foreach ($legacyconfigs as $key => $value) {
            set_config($key, $value, 'local_stream');
        }
        cli_writeln('Copied ' . count($legacyconfigs) . ' config value(s) to local_stream.');
    }
}

$stats = [
        'processed' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
];

$sql = 'id > :fromid AND recordingid <> :empty ORDER BY id ASC';
$params = ['fromid' => $fromid, 'empty' => ''];

$legacyrows = $DB->get_records_select('local_zoom_integration_rec', $sql, $params);
$total = count($legacyrows);

if ($total === 0) {
    cli_writeln('No legacy rows to process.');
    exit(0);
}

cli_writeln('Legacy rows to scan: ' . $total);

foreach ($legacyrows as $legacy) {
    if ($limit > 0 && $stats['processed'] >= $limit) {
        break;
    }

    $stats['processed']++;
    $recordingid = (string) $legacy->recordingid;

    if ($recordingid === '') {
        $stats['skipped']++;
        cli_writeln('Skip legacy id ' . $legacy->id . ': empty recordingid.');
        continue;
    }

    $row = $maplegacy($legacy);
    $existing = $DB->get_record('local_stream_rec', ['recordingid' => $recordingid]);

    try {
        if ($existing) {
            if (!$update) {
                $stats['skipped']++;
                continue;
            }
            $row->id = $existing->id;
            if ($dryrun) {
                $stats['updated']++;
                cli_writeln('[dry-run] Would update stream_rec id ' . $existing->id . ' from legacy id ' . $legacy->id);
            } else {
                $DB->update_record('local_stream_rec', $row);
                $stats['updated']++;
            }
        } else {
            $row->embedded_at = 0;
            $row->zoom_cloud_deleted = 0;
            if ($dryrun) {
                $stats['inserted']++;
                cli_writeln('[dry-run] Would insert recordingid ' . $recordingid . ' (legacy id ' . $legacy->id . ')');
            } else {
                $DB->insert_record('local_stream_rec', $row);
                $stats['inserted']++;
            }
        }
    } catch (\Exception $e) {
        $stats['errors']++;
        cli_writeln('Error legacy id ' . $legacy->id . ' recordingid ' . $recordingid . ': ' . $e->getMessage());
    }

    if (!$dryrun && $stats['processed'] % $batchsize === 0) {
        cli_writeln('Progress: ' . $stats['processed'] . '/' . $total . ' ...');
    }
}

cli_writeln(str_repeat('-', 60));
cli_writeln('Processed: ' . $stats['processed']);
cli_writeln('Inserted:  ' . $stats['inserted']);
cli_writeln('Updated:   ' . $stats['updated']);
cli_writeln('Skipped:   ' . $stats['skipped']);
cli_writeln('Errors:    ' . $stats['errors']);
cli_writeln($dryrun ? 'Dry run finished.' : 'Sync finished.');
exit($stats['errors'] > 0 ? 1 : 0);
