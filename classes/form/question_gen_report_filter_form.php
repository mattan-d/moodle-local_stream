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

namespace local_stream\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Date range filter for the question-generation usage report.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_gen_report_filter_form extends \moodleform {

    /**
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('date_selector', 'datefrom', get_string('qgenreportdatefrom', 'local_stream'));
        $mform->addElement('date_selector', 'dateto', get_string('qgenreportdateto', 'local_stream'));

        $this->add_action_buttons(false, get_string('qgenreportfilter', 'local_stream'));
    }
}
