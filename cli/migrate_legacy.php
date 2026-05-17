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
                'match' => 'recordingid',
                'from-id' => 0,
                'limit' => 0,
                'batch-size' => 200,
                'verbose' => false,
        ],
        [
                'h' => 'help',
                'n' => 'dry-run',
                'u' => 'update',
                'v' => 'verbose',
        ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$matchmodes = ['recordingid', 'legacyid', 'both'];
if ($options['help']) {
    echo "Sync recordings from legacy table {local_zoom_integration_rec} to {local_stream_rec}.

By default only rows not yet present are inserted (see --match).
Use --update to overwrite existing rows in local_stream_rec from the legacy table.

Options:
-n, --dry-run           Show what would be done without writing
-u, --update            Update existing local_stream_rec rows
    --match=MODE        How to detect an existing row (default: recordingid)
                        recordingid - match on recordingid column (install.php behaviour)
                        legacyid    - match on primary key id; new rows keep legacy id
                        both        - try legacy id first, then recordingid
    --migrate-config    Copy plugin settings from local_zoom_integration to local_stream
    --from-id=ID        Process legacy rows with id greater than ID (default 0)
    --limit=N           Stop after N legacy rows (0 = no limit)
    --batch-size=N      Progress message every N rows (default 200)
-v, --verbose           Log each skip/insert/update
-h, --help              Show this help

Examples:
\$ php local/stream/cli/migrate_legacy.php
\$ php local/stream/cli/migrate_legacy.php --update
\$ php local/stream/cli/migrate_legacy.php --match=legacyid --update
\$ php local/stream/cli/migrate_legacy.php --dry-run --verbose
";
    exit(0);
}

$dryrun = !empty($options['dry-run']);
$update = !empty($options['update']);
$verbose = !empty($options['verbose']);
$match = strtolower((string) $options['match']);
if (!in_array($match, $matchmodes, true)) {
    cli_error('Invalid --match. Use: ' . implode(', ', $matchmodes));
}
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

/**
 * Find target row for a legacy recording.
 *
 * @param \stdClass $legacy
 * @param string $match
 * @return \stdClass|false
 */
$findexisting = static function(\stdClass $legacy, string $match) {
    global $DB;
    $byid = false;
    $byrecordingid = false;

    if ($match === 'legacyid' || $match === 'both') {
        $byid = $DB->get_record('local_stream_rec', ['id' => $legacy->id]);
    }
    if ($match === 'recordingid' || ($match === 'both' && !$byid)) {
        $rid = trim((string) $legacy->recordingid);
        if ($rid !== '') {
            $byrecordingid = $DB->get_record('local_stream_rec', ['recordingid' => $rid]);
        }
    }

    if ($byid && $byrecordingid && (int) $byid->id !== (int) $byrecordingid->id) {
        return $byid;
    }
    return $byid ?: $byrecordingid;
};

cli_writeln('Legacy sync: local_zoom_integration_rec -> local_stream_rec');
cli_writeln('Dry run: ' . ($dryrun ? 'yes' : 'no'));
cli_writeln('Update existing: ' . ($update ? 'yes' : 'no'));
cli_writeln('Match mode: ' . $match);
cli_writeln('From legacy id > ' . $fromid);
if ($limit > 0) {
    cli_writeln('Limit: ' . $limit);
}
cli_writeln(str_repeat('-', 60));

$legacysql = 'SELECT COUNT(1) FROM {local_zoom_integration_rec} WHERE id > :fromid AND recordingid <> :empty';
$legacyparams = ['fromid' => $fromid, 'empty' => ''];
$legacycount = (int) $DB->get_field_sql($legacysql, $legacyparams);
$streamcount = (int) $DB->count_records('local_stream_rec');

$overlaprecordingid = (int) $DB->get_field_sql(
        "SELECT COUNT(1)
           FROM {local_zoom_integration_rec} l
           JOIN {local_stream_rec} s ON s.recordingid = l.recordingid
          WHERE l.id > :fromid AND l.recordingid <> :empty",
        $legacyparams
);

$overlaplegacyid = (int) $DB->get_field_sql(
        "SELECT COUNT(1)
           FROM {local_zoom_integration_rec} l
           JOIN {local_stream_rec} s ON s.id = l.id
          WHERE l.id > :fromid AND l.recordingid <> :empty",
        $legacyparams
);

$missingrecordingid = (int) $DB->get_field_sql(
        "SELECT COUNT(1)
           FROM {local_zoom_integration_rec} l
      LEFT JOIN {local_stream_rec} s ON s.recordingid = l.recordingid
          WHERE l.id > :fromid AND l.recordingid <> :empty AND s.id IS NULL",
        $legacyparams
);

cli_writeln('Table counts:');
cli_writeln('  legacy rows (recordingid not empty, id > ' . $fromid . '): ' . $legacycount);
cli_writeln('  local_stream_rec rows (total): ' . $streamcount);
cli_writeln('  overlap by recordingid: ' . $overlaprecordingid);
cli_writeln('  overlap by legacy id: ' . $overlaplegacyid);
cli_writeln('  legacy only (no stream row with same recordingid): ' . $missingrecordingid);
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
    $recordingid = trim((string) $legacy->recordingid);

    if ($recordingid === '') {
        $stats['skipped']++;
        if ($verbose) {
            cli_writeln('Skip legacy id ' . $legacy->id . ': empty recordingid.');
        }
        continue;
    }

    $row = $maplegacy($legacy);
    $existing = $findexisting($legacy, $match);

    try {
        if ($existing) {
            if (!$update) {
                $stats['skipped']++;
                if ($verbose) {
                    cli_writeln('Skip legacy id ' . $legacy->id . ': exists as stream_rec id ' . $existing->id
                            . ' (recordingid ' . $recordingid . ').');
                }
                continue;
            }
            $row->id = $existing->id;
            if ($dryrun) {
                $stats['updated']++;
                if ($verbose) {
                    cli_writeln('[dry-run] Would update stream_rec id ' . $existing->id . ' from legacy id ' . $legacy->id);
                }
            } else {
                $DB->update_record('local_stream_rec', $row);
                $stats['updated']++;
                if ($verbose) {
                    cli_writeln('Updated stream_rec id ' . $existing->id . ' from legacy id ' . $legacy->id);
                }
            }
        } else {
            if ($match === 'legacyid' || $match === 'both') {
                $row->id = (int) $legacy->id;
            }
            $row->embedded_at = 0;
            $row->zoom_cloud_deleted = 0;
            if ($dryrun) {
                $stats['inserted']++;
                if ($verbose) {
                    $idhint = isset($row->id) ? (' as id ' . $row->id) : '';
                    cli_writeln('[dry-run] Would insert recordingid ' . $recordingid . ' (legacy id ' . $legacy->id . ')' . $idhint);
                }
            } else {
                $DB->insert_record('local_stream_rec', $row);
                $stats['inserted']++;
                if ($verbose) {
                    cli_writeln('Inserted recordingid ' . $recordingid . ' (legacy id ' . $legacy->id . ')');
                }
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

if ($stats['inserted'] === 0 && $stats['updated'] === 0 && $stats['skipped'] > 0 && !$update) {
    cli_writeln('');
    cli_writeln('All rows were skipped because matching rows already exist in local_stream_rec.');
    cli_writeln('If the dashboard still looks empty, data may already be migrated — verify:');
    cli_writeln('  SELECT COUNT(*) FROM {local_stream_rec};');
    cli_writeln('To refresh existing rows from the legacy table, run:');
    cli_writeln('  php local/stream/cli/migrate_legacy.php --update');
    cli_writeln('To match by legacy primary key id instead of recordingid:');
    cli_writeln('  php local/stream/cli/migrate_legacy.php --match=legacyid --update');
}

cli_writeln($dryrun ? 'Dry run finished.' : 'Sync finished.');
exit($stats['errors'] > 0 ? 1 : 0);
