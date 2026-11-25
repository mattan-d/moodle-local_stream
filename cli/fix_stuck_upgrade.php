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
 * Clear a stuck Moodle upgrade lock and finish the upgrade.
 *
 * A leftover outagelessupgrade flag makes moodle_needs_upgrading() return false,
 * so admin/settings.php throws sectionerror for plugin pages whose code is newer
 * than the database. This script removes that lock and runs the normal CLI upgrade.
 *
 * Do not run this while another upgrade process is still working.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
// Bootstrap refuses to start while upgraderunning is still in the future.
define('NO_UPGRADE_CHECK', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'skip-upgrade' => false,
        'disable-maintenance' => false,
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
    $help = "Clear a stuck Moodle upgrade and finish it from the command line.

A crashed no-outage upgrade can leave the outagelessupgrade flag set. The site
then hides the upgrade screen and plugin settings pages fail with sectionerror.

Do not run this while another upgrade process is still working.

Options:
-h, --help                 Show this help
-n, --dry-run              Report the stuck state only; change nothing
--skip-upgrade             Clear the lock and the running flag, but do not upgrade
--disable-maintenance      Turn maintenance mode off after a successful run

Examples:
\$ php local/stream/cli/fix_stuck_upgrade.php --dry-run
\$ php local/stream/cli/fix_stuck_upgrade.php
\$ php local/stream/cli/fix_stuck_upgrade.php --disable-maintenance
";
    echo $help;
    exit(0);
}

$dryrun = !empty($options['dry-run']);

cli_heading('Stuck upgrade repair (' . $CFG->wwwroot . ')');

$outageless = get_config(null, 'outagelessupgrade');
$running = get_config(null, 'upgraderunning');
$maintenancefile = $CFG->dataroot . '/climaintenance.html';
$maintenanceon = !empty($CFG->maintenance_enabled) || file_exists($maintenancefile);

cli_writeln('outagelessupgrade: ' . ($outageless ? $outageless : '(not set)'));
if ($running) {
    $runningnote = ((int) $running > time()) ? 'still in the future' : 'already expired';
    cli_writeln('upgraderunning: ' . userdate((int) $running) . ' (' . $runningnote . ')');
} else {
    cli_writeln('upgraderunning: (not set)');
}
cli_writeln('maintenance: ' . ($maintenanceon ? 'on' : 'off'));

$pending = moodle_needs_upgrading(false);
cli_writeln('upgrade pending (ignoring the lock): ' . ($pending ? 'yes' : 'no'));

$pluginman = core_plugin_manager::instance();
$behind = [];
foreach ($pluginman->get_plugins() as $plugins) {
    foreach ($plugins as $plugin) {
        // Missing code is not a pending upgrade.
        if (empty($plugin->rootdir) || $plugin->versiondisk === null) {
            continue;
        }
        if ($plugin->is_installed_and_upgraded()) {
            continue;
        }
        $behind[] = $plugin->component . ' db=' . ($plugin->versiondb ?? 'not installed') .
                ' disk=' . $plugin->versiondisk;
    }
}
if ($behind) {
    cli_writeln('plugins whose database version does not match the code:');
    foreach ($behind as $line) {
        cli_writeln('  ' . $line);
    }
} else {
    cli_writeln('every installed plugin matches the code on disk');
}

if ($dryrun) {
    cli_writeln('Dry run. Nothing was changed.');
    exit(0);
}

$cleared = false;
if ($outageless) {
    unset_config('outagelessupgrade');
    cli_writeln('Cleared outagelessupgrade.');
    $cleared = true;
}
if ($running) {
    unset_config('upgraderunning');
    cli_writeln('Cleared upgraderunning.');
    $cleared = true;
}
if (!$cleared) {
    cli_writeln('No upgrade lock was set.');
}

if (!empty($options['skip-upgrade'])) {
    fix_stuck_upgrade_maintenance($options, $maintenanceon, $maintenancefile);
    cli_writeln('Skipped the upgrade.');
    exit(0);
}

if (!$pending) {
    fix_stuck_upgrade_maintenance($options, $maintenanceon, $maintenancefile);
    cli_writeln('No upgrade is pending.');
    exit(0);
}

$php = escapeshellarg(PHP_BINARY);
$script = escapeshellarg($CFG->dirroot . '/admin/cli/upgrade.php');
$command = $php . ' ' . $script . ' --non-interactive';
cli_writeln('Running ' . $command);
passthru($command, $exitcode);

if ($exitcode !== 0) {
    cli_error('Upgrade failed with exit code ' . $exitcode . '. The lock was already cleared.', $exitcode);
}

fix_stuck_upgrade_maintenance($options, $maintenanceon, $maintenancefile);
cli_writeln('Upgrade repair finished.');
exit(0);

/**
 * Turn maintenance off when requested.
 *
 * @param array $options CLI options.
 * @param bool $maintenanceon Whether maintenance was on before this script changed anything.
 * @param string $maintenancefile Path to climaintenance.html.
 * @return void
 */
function fix_stuck_upgrade_maintenance(array $options, bool $maintenanceon, string $maintenancefile): void {
    if (empty($options['disable-maintenance'])) {
        if ($maintenanceon) {
            cli_writeln('Maintenance mode is still on. Re-run with --disable-maintenance to turn it off.');
        }
        return;
    }

    set_config('maintenance_enabled', 0);
    unset_config('maintenance_later');
    if (file_exists($maintenancefile)) {
        unlink($maintenancefile);
    }
    cli_writeln('Maintenance mode is off.');
}
