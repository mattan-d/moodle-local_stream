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
 * Settings
 *
 * @package   local_stream
 * @copyright 2023 CentricApp <support@centricapp.co.il>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__ . '/locallib.php');

$help = new local_stream_help();

$ADMIN->add('localplugins', new admin_category('localstreamfolder',
        get_string('pluginname', 'local_stream')));

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_stream_settings', new lang_string('settingspage', 'local_stream'));
    $settings->add(new admin_setting_heading('settingspage', get_string('settingspage', 'local_stream'), ''));
    $options = [
            $help::PLATFORM_ZOOM => get_string('zoom', 'local_stream'),
            $help::PLATFORM_WEBEX => get_string('webex', 'local_stream'),
            $help::PLATFORM_TEAMS => get_string('teams', 'local_stream'),
            $help::PLATFORM_UNICKO => get_string('unicko', 'local_stream'),
    ];

    $settings->add(new admin_setting_configtext('local_stream/streamurl',
            get_string('streamurl', 'local_stream'), '', ''));

    $settings->add(new admin_setting_configtext('local_stream/streamkey',
            get_string('streamkey', 'local_stream'), get_string('streamkey_desc', 'local_stream'), ''));

    $settings->add(new admin_setting_configtext('local_stream/streamcategoryid',
            get_string('streamcategoryid', 'local_stream'), '', ''));

    $settings->add(new admin_setting_configselect('local_stream/platform',
            get_string('platform', 'local_stream'), get_string('platform_desc', 'local_stream'), 0, $options));

    $settings->add(new admin_setting_configselect('local_stream/storage',
            get_string('storage', 'local_stream'), '', $help::STORAGE_STREAM, [
                    $help::STORAGE_STREAM => 'Stream',
                    $help::STORAGE_NODOWNLOAD => get_string('nodownload', 'local_stream'),
            ]));

    $options = [
            10 => 10, 20 => 20, 30 => 30, 50 => 50, 100 => 100, 150 => 150,
    ];

    $settings->add(new admin_setting_configselect('local_stream/recordingsperpage',
            get_string('recordingsperpage', 'local_stream'), '', 30, $options));

    $options = [
            0 => get_string('today'),
            1 => 1 . ' ' . get_string('days'),
            3 => 3 . ' ' . get_string('days'),
            7 => 7 . ' ' . get_string('days'),
            14 => 14 . ' ' . get_string('days'),
            31 => 31 . ' ' . get_string('days'),
            365 => 1 . ' ' . get_string('years'),
            730 => 2 . ' ' . get_string('years'),
            1095 => 3 . ' ' . get_string('years'),
            3650 => 10 . ' ' . get_string('years'),
    ];

    $settings->add(new admin_setting_configselect('local_stream/daystolisting',
            get_string('daystolisting', 'local_stream'), '', 0, $options));

    $options[0] = get_string('none');
    $settings->add(new admin_setting_configselect('local_stream/daystocleanup',
            get_string('daystocleanup', 'local_stream'), get_string('daystocleanup_desc', 'local_stream'), 0, $options));

    $settings->add(new admin_setting_heading('local_stream/platformsettings',
            get_string('platform_settings', 'local_stream'), ''));

    // UNICKO.
    $settings->add(new admin_setting_configtext('local_stream/unickokey',
            get_string('clientid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/unickokey', 'local_stream/platform', 'in', '0|1|2');

    $settings->add(new admin_setting_configpasswordunmask('local_stream/unickosecret',
            get_string('clientsecret', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/unickosecret', 'local_stream/platform', 'in', '0|1|2');

    // TEAMS.
    $settings->add(new admin_setting_configtext('local_stream/teamsclientsecret',
            get_string('clientsecret', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/teamsclientsecret', 'local_stream/platform', 'in', '0|1|3');

    $settings->add(new admin_setting_configtext('local_stream/teamsclientid',
            get_string('clientid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/teamsclientid', 'local_stream/platform', 'in', '0|1|3');

    $settings->add(new admin_setting_configtext('local_stream/teamstenantid',
            get_string('tenantid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/teamstenantid', 'local_stream/platform', 'in', '0|1|3');

    $settings->add(new admin_setting_configtextarea('local_stream/teamsusersfilter',
            get_string('teamsusersfilter', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/teamsusersfilter', 'local_stream/platform', 'in', '0|1|3');

    // WEBEX.
    $settings->add(new admin_setting_configtext('local_stream/webexjwt',
            get_string('webexjwt', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/webexjwt', 'local_stream/platform', 'in', '0|2|3');

    $settings->add(new admin_setting_configtext('local_stream/webexrefreshtoken',
            get_string('secrettoken', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/webexrefreshtoken', 'local_stream/platform', 'in', '0|2|3');

    $settings->add(new admin_setting_configtext('local_stream/webexclientid',
            get_string('clientid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/webexclientid', 'local_stream/platform', 'in', '0|2|3');

    $settings->add(new admin_setting_configtext('local_stream/webexclientsecret',
            get_string('clientsecret', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/webexclientsecret', 'local_stream/platform', 'in', '0|2|3');

    // ZOOM.
    $settings->add(new admin_setting_configtext('local_stream/accountid',
            get_string('accountid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/accountid', 'local_stream/platform', 'in', '1|2|3');

    $settings->add(new admin_setting_configtext('local_stream/clientid',
            get_string('clientid', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/clientid', 'local_stream/platform', 'in', '1|2|3');

    $settings->add(new admin_setting_configpasswordunmask('local_stream/clientsecret',
            get_string('clientsecret', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/clientsecret', 'local_stream/platform', 'in', '1|2|3');

    // Embedding.
    $settings->add(new admin_setting_heading('embeddingsettings', get_string('embeddingsettings', 'local_stream'), ''));

    $settings->add(new admin_setting_configtext('local_stream/prefix',
            get_string('prefix', 'local_stream'), get_string('prefix_desc', 'local_stream'), ''));
    $settings->add(new admin_setting_configcheckbox('local_stream/adddate',
            get_string('adddate', 'local_stream'), '', ''));
    $settings->hide_if('local_stream/nodownload', 'local_stream/platform', 'in', '0|2');

    $settings->add(new admin_setting_configcheckbox('local_stream/hidefromstudents',
            get_string('hidefromstudents'), '', ''));
    $settings->add(new admin_setting_configcheckbox('local_stream/hidetopic',
            get_string('hidetopic', 'local_stream'), '', ''));
    $settings->add(new admin_setting_configcheckbox('local_stream/addrecordingtype',
            get_string('addrecordingtype', 'local_stream'), get_string('addrecordingtype_desc', 'local_stream'), ''));

    $options = [
            0 => get_string('above', 'local_stream'), 1 => get_string('below', 'local_stream'),
    ];

    $settings->add(new admin_setting_configselect('local_stream/embedorder',
            get_string('order'), get_string('order_desc', 'local_stream'), 0, $options));
}

// This adds the settings link to the folder/submenu.
$ADMIN->add('localstreamfolder', $settings);

// This adds a link to an external page.
$ADMIN->add('localstreamfolder', new admin_externalpage('local_stream',
        get_string('browselist', 'local_stream'), new moodle_url('/local/stream/index.php')
));
