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
 * Teacher form for embedding preferences.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

use local_stream\embed_preferences;

/**
 * Embedding settings a teacher can override.
 */
class embed_preferences_form extends \moodleform {

    /**
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('static', 'intro', '', get_string('embedpreferences_desc', 'local_stream'));

        foreach (embed_preferences::visible_keys() as $key) {
            if ($key === 'prefix') {
                $siteprefix = embed_preferences::site_value('prefix');
                $prefixcurrent = ($siteprefix === '') ? get_string('none') : $siteprefix;
                $mform->addElement('select', 'prefixmode', get_string('prefix', 'local_stream'), [
                        embed_preferences::INHERIT => embed_preferences::inherit_label($prefixcurrent),
                        embed_preferences::PREFIX_CUSTOM => get_string('embedpref_customprefix', 'local_stream'),
                ]);
                $mform->setType('prefixmode', PARAM_ALPHA);
                $mform->addHelpButton('prefixmode', 'prefix', 'local_stream');

                $mform->addElement('text', 'prefix', get_string('embedpref_customprefix', 'local_stream'), ['size' => 40]);
                $mform->setType('prefix', PARAM_TEXT);
                $mform->hideIf('prefix', 'prefixmode', 'neq', embed_preferences::PREFIX_CUSTOM);
                continue;
            }

            if ($key === 'embedorder') {
                $siteorder = ((int) embed_preferences::site_value('embedorder') === 1)
                        ? get_string('below', 'local_stream')
                        : get_string('above', 'local_stream');
                $mform->addElement('select', 'embedorder', embed_preferences::field_label('embedorder'), [
                        embed_preferences::INHERIT => embed_preferences::inherit_label($siteorder),
                        '0' => get_string('above', 'local_stream'),
                        '1' => get_string('below', 'local_stream'),
                ]);
                $mform->setType('embedorder', PARAM_ALPHANUM);
                $mform->addHelpButton('embedorder', 'order', 'local_stream');
                continue;
            }

            $mform->addElement('select', $key, embed_preferences::field_label($key), [
                    embed_preferences::INHERIT => embed_preferences::inherit_label(
                            embed_preferences::site_yesno_label($key)
                    ),
                    '1' => get_string('yes'),
                    '0' => get_string('no'),
            ]);
            $mform->setType($key, PARAM_ALPHANUM);
            $help = embed_preferences::help_identifier($key);
            if ($help !== null) {
                $mform->addHelpButton($key, $help, 'local_stream');
            }
        }

        $this->add_action_buttons();
    }
}
