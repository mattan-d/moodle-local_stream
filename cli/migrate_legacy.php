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
 * CLI: sync local_zoom_integration_rec → local_stream_rec (and plugin config).
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/stream/locallib.php');

list($options, $unrecognized) = cli_get_params(
        [
                'help' => false,
                'dry-run' => false,
                'config-only' => false,
                'records-only' => false,
                'update' => false,
                'limit' => null,
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
    $help = "Sync legacy local_zoom_integration data into local_stream.

Copies plugin settings from local_zoom_integration into local_stream, then copies
rows from {local_zoom_integration_rec} into {local_stream_rec} matched by recordingid.
Legacy-only columns (vimeoid, remote) are not migrated.

Options:
-h, --help              Show this help
-n, --dry-run           Report actions only; no database writes
    --config-only       Migrate plugin config only (no recording rows)
    --records-only      Migrate recording rows only (no config)
    --update            Update existing local_stream_rec rows (default: skip duplicates)
    --limit=NUMBER      Process at most N legacy rows (ordered by id)

Examples:
\$ php local/stream/cli/migrate_legacy.php --dry-run
\$ php local/stream/cli/migrate_legacy.php
\$ php local/stream/cli/migrate_legacy.php --records-only --update
\$ php local/stream/cli/migrate_legacy.php --config-only
\$ php local/stream/cli/migrate_legacy.php --limit=500
";

    echo $help;
    exit(0);
}

if (!empty($options['config-only']) && !empty($options['records-only'])) {
    cli_error('Use only one of --config-only or --records-only.');
}

$dryrun = !empty($options['dry-run']);
$migrateconfig = empty($options['records-only']);
$migraterecords = empty($options['config-only']);

$limit = null;
if ($options['limit'] !== null && $options['limit'] !== '') {
    $limit = (int) $options['limit'];
    if ($limit < 1) {
        cli_error('Invalid --limit (must be a positive integer).');
    }
}

$help = new local_stream_help();

cli_writeln('Legacy table: ' . local_stream_help::LEGACY_ZOOM_REC_TABLE);
cli_writeln('Target table: local_stream_rec');
cli_writeln('Legacy table exists: ' . ($help->legacy_zoom_rec_table_exists() ? 'yes' : 'no'));
cli_writeln('Migrate config: ' . ($migrateconfig ? 'yes' : 'no'));
cli_writeln('Migrate records: ' . ($migraterecords ? 'yes' : 'no'));
cli_writeln('Update existing: ' . (!empty($options['update']) ? 'yes' : 'no'));
cli_writeln('Dry run: ' . ($dryrun ? 'yes' : 'no'));
if ($limit !== null) {
    cli_writeln('Limit: ' . $limit);
}
cli_writeln(str_repeat('-', 60));

$stats = $help->migrate_legacy_zoom_integration([
        'migrateconfig' => $migrateconfig,
        'migraterecords' => $migraterecords,
        'dryrun' => $dryrun,
        'updateexisting' => !empty($options['update']),
        'limit' => $limit,
        'log' => function(string $msg): void {
            cli_writeln($msg);
        },
]);

cli_writeln(str_repeat('-', 60));
cli_writeln('Config keys: ' . $stats['config_keys']);
cli_writeln('Inserted: ' . $stats['inserted']);
cli_writeln('Updated: ' . $stats['updated']);
cli_writeln('Skipped (already exists): ' . $stats['skipped']);
cli_writeln('Invalid (empty recordingid): ' . $stats['invalid']);
cli_writeln($dryrun ? 'Dry run finished.' : 'Migration finished.');
exit(0);
