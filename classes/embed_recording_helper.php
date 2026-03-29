<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared logic for embedding one local_stream_rec row (scheduled task + CLI).
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Embed runner for a single recording row.
 */
class embed_recording_helper {

    /**
     * Place mod_stream before or after the platform meeting module per local_stream/embedorder (0=above, 1=below).
     *
     * @param \local_stream_help $help
     * @param \stdClass $source Stream course_modules row.
     * @param \stdClass $destination Platform (zoom/msteams/lti) course_modules row.
     * @param \stdClass $section Section record for the course (same section as destination).
     * @return void
     */
    protected static function apply_embed_order_relative_to_platform(
            \local_stream_help $help,
            \stdClass $source,
            \stdClass $destination,
            \stdClass $section
    ): void {
        $below = ((int) $help->config->embedorder === 1);
        if ($below) {
            moveto_module($source, $section, $destination);
            moveto_module($destination, $section, $source);
        } else {
            moveto_module($source, $section, $destination);
        }
    }

    /**
     * Embed one ready recording (same rules as scheduled embed task).
     *
     * @param \local_stream_help $help Plugin helper.
     * @param \stdClass $meeting Row from local_stream_rec (updated on success unless dry-run).
     * @param \stdClass $streammodule Row from modules for mod_stream.
     * @param \stdClass|null $platformmodule Row from modules for zoom / msteams / lti, or null if not used.
     * @param bool $dryrun If true, no DB writes and no add_module / moveto_module.
     * @param bool $queuenotifications If true, queue notification adhoc tasks (cron only).
     * @param callable|null $log function(string $msg): void; default mtrace.
     * @return void
     */
    public static function process_single(
            \local_stream_help $help,
            \stdClass $meeting,
            \stdClass $streammodule,
            $platformmodule,
            bool $dryrun,
            bool $queuenotifications,
            ?callable $log = null
    ): void {
        global $DB;

        $logfn = $log ?? function(string $msg): void {
            mtrace($msg);
        };

        $details = [];
        $platform = false;

        if ($help->config->platform == $help::PLATFORM_ZOOM) {
            $platform = $DB->get_record('zoom', ['meeting_id' => $meeting->meetingid]);
        }

        if ($help->config->platform == $help::PLATFORM_UNICKO) {
            $recordingdata = json_decode($meeting->recordingdata);
            if (isset($recordingdata->instanceid) && $recordingdata->instanceid && $platformmodule) {
                $platform = $DB->get_record('course_modules',
                        ['instance' => $recordingdata->instanceid, 'module' => $platformmodule->id]);

                if ($platform && isset($platform->course)) {
                    $platform->id = $recordingdata->instanceid;
                } else {
                    if (!$dryrun) {
                        $meeting->embedded = 2;
                        $DB->update_record('local_stream_rec', $meeting);
                    }
                    $logfn('The video with ID #' . $meeting->id . ' not found in course #' . $meeting->course . '.');
                    return;
                }
            }
        }

        if ($help->config->platform == $help::PLATFORM_TEAMS) {
            $pattern = '/^.*:meeting_([A-Za-z0-9]+)@thread\.v2$/';
            $teamsinstanceid = null;
            if (preg_match($pattern, $meeting->recordingid, $matches)) {
                $tmpmeetingid = $matches[1];
                $logfn('checking meeting: ' . $meeting->recordingid);
                $likesql = $DB->sql_like('externalurl', ':externalurl');
                $platform = $DB->get_record_sql(
                        "SELECT id, course FROM {msteams} WHERE {$likesql}",
                        ['externalurl' => '%' . $tmpmeetingid . '%']
                );
                if ($platform) {
                    $teamsinstanceid = $platform->id;
                }
            }

            $details = $help->teams_course_data($meeting->topic);
            if ($details['courseid'] > 0) {
                $platform = new \stdClass();
                $platform->course = $details['courseid'];
                $platform->id = $teamsinstanceid;
            }
        }

        if (!$platform) {
            if ($meeting->course) {
                if ($dryrun) {
                    $logfn('[dry-run] Would embed NO-PLATFORM video #' . $meeting->id . ' in course #' . $meeting->course . '.');
                    return;
                }
                if ($page = $help->add_module($meeting)) {
                    $source = $DB->get_record('course_modules', [
                            'course' => $meeting->course,
                            'module' => $streammodule->id,
                            'instance' => $page->id,
                    ]);
                    if ($source) {
                        if ($help->config->platform == $help::PLATFORM_ZOOM && $platformmodule) {
                            $zoominstance = $DB->get_record('zoom', ['meeting_id' => $meeting->meetingid]);
                            if ($zoominstance) {
                                $destination = $DB->get_record('course_modules', [
                                        'course' => $meeting->course,
                                        'module' => $platformmodule->id,
                                        'instance' => $zoominstance->id,
                                ]);
                                if ($destination) {
                                    $section = $DB->get_record('course_sections', [
                                            'course' => $meeting->course,
                                            'id' => $destination->section,
                                    ]);
                                    if ($section) {
                                        self::apply_embed_order_relative_to_platform($help, $source, $destination, $section);
                                    }
                                }
                            }
                        }
                        $meeting->moduleid = $page->id;
                        $meeting->embedded = 1;
                        if ($help->config->platform == $help::PLATFORM_ZOOM) {
                            $meeting->embedded_at = time();
                        }
                        $logfn('NO-PLATFORM: The video with ID #' . $meeting->id . ' was embedded in course #' . $meeting->course . '.');
                    } else {
                        $meeting->embedded = 2;
                        $logfn('NO-PLATFORM: stream course module not found for video #' . $meeting->id . '.');
                    }
                } else {
                    $meeting->embedded = 2;
                    $logfn('NO-PLATFORM: failed to create stream activity for video #' . $meeting->id . '.');
                }
            } else {
                $meeting->embedded = 2;
                $logfn('meeting not found.');
            }
        } else {
            $meeting->course = $platform->course;
            if (!$DB->get_record('course', ['id' => $meeting->course])) {
                if (!$dryrun) {
                    $meeting->embedded = 2;
                    $DB->update_record('local_stream_rec', $meeting);
                }
                $logfn('The video with ID #' . $meeting->id . ' not found in course #' . $meeting->course . '.');
                return;
            }

            if ($dryrun) {
                $logfn('[dry-run] Would embed video #' . $meeting->id . ' in course #' . $platform->course . '.');
                return;
            }

            if ($page = $help->add_module($meeting)) {
                if (!$platformmodule) {
                    $meeting->embedded = 2;
                    $logfn('Platform module type not configured for video #' . $meeting->id . '.');
                } else {
                    $source = $DB->get_record('course_modules',
                            ['course' => $platform->course, 'module' => $streammodule->id, 'instance' => $page->id]);
                    $destination = $DB->get_record('course_modules',
                            ['course' => $platform->course, 'module' => $platformmodule->id, 'instance' => $platform->id]);

                    $section = null;
                    if ($destination) {
                        $section = $DB->get_record('course_sections',
                                ['course' => $platform->course, 'id' => $destination->section]);
                    }

                    if (isset($details['sectionname']) && $details['sectionname']) {
                        $namedsection = $DB->get_record('course_sections',
                                ['course' => $platform->course, 'name' => $details['sectionname']]);

                        if ($namedsection && $source) {
                            moveto_module($source, $namedsection);
                            if ($destination) {
                                $destsection = $DB->get_record('course_sections',
                                        ['course' => $platform->course, 'id' => $destination->section]);
                                if ($destsection && (int) $destsection->id === (int) $namedsection->id) {
                                    self::apply_embed_order_relative_to_platform($help, $source, $destination, $namedsection);
                                }
                            }
                        }
                    } else if ($section && $source && $destination) {
                        self::apply_embed_order_relative_to_platform($help, $source, $destination, $section);
                    }

                    if ($source) {
                        $meeting->embedded = 1;
                        $meeting->course = $platform->course;
                        $meeting->moduleid = $page->id;
                        if ($help->config->platform == $help::PLATFORM_ZOOM) {
                            $meeting->embedded_at = time();
                        }
                        $logfn('The video with ID #' . $meeting->id . ' was embedded in course #' . $platform->course . '.');
                    } else {
                        $meeting->embedded = 2;
                        $logfn('stream course module not found for video #' . $meeting->id . ' in course #' . $platform->course . '.');
                    }
                }
            } else {
                $meeting->embedded = 2;
                $logfn('failed to create stream activity for video #' . $meeting->id . ' in course #' . $platform->course . '.');
            }
        }

        if (!$dryrun) {
            $DB->update_record('local_stream_rec', $meeting);
        }

        if (!$dryrun && $queuenotifications && $meeting->course && $meeting->visible && (int) $meeting->embedded === 1) {
            $task = new \local_stream\task\notifications();
            $coursecontext = \context_course::instance($meeting->course);
            $users = get_enrolled_users($coursecontext);
            foreach ($users as $user) {
                $task->set_custom_data([
                        'userid' => $user->id,
                        'courseid' => $meeting->course,
                        'meetingid' => $meeting->id,
                        'date' => userdate(strtotime($meeting->starttime), '%d/%m/%Y'),
                        'time' => userdate(strtotime($meeting->starttime), '%H:%M'),
                        'topic' => $meeting->topic,
                ]);
                \core\task\manager::queue_adhoc_task($task);
            }
        }
    }
}
