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
 * local_stream Tasks
 *
 * @package    local_stream
 * @copyright  2023 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream\task;
defined('MOODLE_INTERNAL') || die();

$tasks = [
        [
                'classname' => 'local_stream\task\listing',
                'blocking' => 0,
                'minute' => '*/5',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
        ],
        [
                'classname' => 'local_stream\task\upload',
                'blocking' => 0,
                'minute' => '*/5',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
        ],
        [
                'classname' => 'local_stream\task\embed',
                'blocking' => 0,
                'minute' => '*/5',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
        ],
        [
                'classname' => 'local_stream\task\delete',
                'blocking' => 0,
                'minute' => '*',
                'hour' => '*/6',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
        ],
        [
                'classname' => 'local_stream\task\refresh_token',
                'blocking' => 0,
                'minute' => '0',
                'hour' => '0',
                'day' => '*/5',
                'month' => '*',
                'dayofweek' => '*',
        ],
];
