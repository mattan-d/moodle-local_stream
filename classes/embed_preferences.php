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
 * Per-teacher overrides for embedding settings.
 *
 * An unset preference means "use the site setting". A saved value replaces it
 * for recordings owned by that teacher's email.
 *
 * @package    local_stream
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stream;

defined('MOODLE_INTERNAL') || die();

/**
 * Read and write teacher embedding preferences.
 */
class embed_preferences {

    /** Form value: follow the site-level setting. */
    public const INHERIT = 'inherit';

    /** Form value: teacher supplies their own activity-name prefix. */
    public const PREFIX_CUSTOM = 'custom';

    /** @var array<string,int> Email to user id, for one request. */
    private static $emailusers = [];

    /**
     * Embedding setting names that a teacher may override.
     *
     * @return string[]
     */
    public static function keys(): array {
        return [
                'prefix',
                'adddate',
                'hidefromstudents',
                'hidetopic',
                'embedorder',
                'addrecordingtype',
                'basedgrouping',
                'defaultcollectionmode',
        ];
    }

    /**
     * Settings that exist only for the Zoom platform.
     *
     * @return string[]
     */
    public static function zoom_only_keys(): array {
        return ['addrecordingtype', 'basedgrouping', 'defaultcollectionmode'];
    }

    /**
     * Checkbox-style embedding settings.
     *
     * @return string[]
     */
    public static function boolean_keys(): array {
        return [
                'adddate',
                'hidefromstudents',
                'hidetopic',
                'addrecordingtype',
                'basedgrouping',
                'defaultcollectionmode',
        ];
    }

    /**
     * Keys shown for the current site platform.
     *
     * @return string[]
     */
    public static function visible_keys(): array {
        $keys = self::keys();
        if (!self::is_zoom()) {
            $keys = array_values(array_diff($keys, self::zoom_only_keys()));
        }
        return $keys;
    }

    /**
     * Whether the configured meeting platform is Zoom.
     *
     * @return bool
     */
    public static function is_zoom(): bool {
        global $CFG;
        require_once($CFG->dirroot . '/local/stream/locallib.php');
        return (int) get_config('local_stream', 'platform') === \local_stream_help::PLATFORM_ZOOM;
    }

    /**
     * User-preference name for one embedding setting.
     *
     * @param string $key Setting name without the plugin prefix.
     * @return string
     */
    public static function preference_name(string $key): string {
        return 'local_stream_embed_' . $key;
    }

    /**
     * Teacher, editing teacher, or site administrator.
     *
     * @param int $userid
     * @return bool
     */
    public static function user_can_have_preferences(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }

        $roles = $DB->get_records_list('role', 'shortname', ['teacher', 'editingteacher'], '', 'id');
        foreach ($roles as $role) {
            if (user_has_role_assignment($userid, $role->id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current user may edit this teacher's embedding preferences.
     *
     * @param int $userid Target user.
     * @return bool
     */
    public static function can_edit_user(int $userid): bool {
        global $USER;

        if (!self::user_can_have_preferences($userid)) {
            return false;
        }
        if ((int) $USER->id === $userid) {
            return has_capability('moodle/user:editownprofile', \context_system::instance());
        }
        $personalcontext = \context_user::instance($userid);
        if (is_siteadmin($userid) && !is_siteadmin($USER)) {
            return false;
        }
        return has_capability('moodle/user:editprofile', $personalcontext);
    }

    /**
     * Permission callback for {@see local_stream_user_preferences()}.
     *
     * @param \stdClass $user
     * @param string $preferencename
     * @return bool
     */
    public static function can_edit_preference($user, $preferencename): bool {
        if (empty($user->id)) {
            return false;
        }
        return self::can_edit_user((int) $user->id);
    }

    /**
     * Definitions for core_user preference editing.
     *
     * @return array
     */
    public static function definitions(): array {
        $preferences = [];
        foreach (self::keys() as $key) {
            $name = self::preference_name($key);
            $definition = [
                    'type' => $key === 'prefix' ? PARAM_TEXT : PARAM_INT,
                    'null' => NULL_ALLOWED,
                    'default' => null,
                    'permissioncallback' => [self::class, 'can_edit_preference'],
            ];
            if ($key !== 'prefix') {
                $definition['choices'] = [0, 1];
            }
            $preferences[$name] = $definition;
        }
        return $preferences;
    }

    /**
     * Site-level value of one embedding setting.
     *
     * @param string $key
     * @return string
     */
    public static function site_value(string $key): string {
        $value = get_config('local_stream', $key);
        if ($value === false || $value === null) {
            return '';
        }
        return (string) $value;
    }

    /**
     * Saved teacher value, or null when the site setting should be used.
     *
     * @param int $userid
     * @param string $key
     * @return string|null
     */
    public static function user_value(int $userid, string $key): ?string {
        if ($userid <= 0) {
            return null;
        }
        $value = get_user_preferences(self::preference_name($key), null, $userid);
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }

    /**
     * Value used when embedding this teacher's recordings.
     *
     * @param int $userid
     * @param string $key
     * @return string
     */
    public static function effective_value(int $userid, string $key): string {
        $user = self::user_value($userid, $key);
        if ($user === null) {
            return self::site_value($key);
        }
        return $user;
    }

    /**
     * Overlay saved teacher preferences onto a plugin config object.
     *
     * @param \stdClass $config
     * @param int $userid
     * @return void
     */
    public static function apply_to_config(\stdClass $config, int $userid): void {
        if ($userid <= 0) {
            return;
        }
        $zoom = self::is_zoom();
        foreach (self::keys() as $key) {
            if (!$zoom && in_array($key, self::zoom_only_keys(), true)) {
                continue;
            }
            $value = self::user_value($userid, $key);
            if ($value !== null) {
                $config->$key = $value;
            }
        }
    }

    /**
     * Active Moodle user id for a meeting host email.
     *
     * @param string|null $email
     * @return int
     */
    public static function userid_by_email(?string $email): int {
        global $DB;

        $email = strtolower(trim((string) $email));
        if ($email === '' || strpos($email, '@') === false) {
            return 0;
        }
        if (array_key_exists($email, self::$emailusers)) {
            return self::$emailusers[$email];
        }

        $sql = 'SELECT id FROM {user}
                 WHERE deleted = 0 AND ' . $DB->sql_equal('email', ':email', false);
        $ids = $DB->get_fieldset_sql($sql, ['email' => $email]);
        $userid = $ids ? (int) reset($ids) : 0;
        self::$emailusers[$email] = $userid;
        return $userid;
    }

    /**
     * Label for the "use site setting" option, including the current site value.
     *
     * @param string $current Human-readable site value.
     * @return string
     */
    public static function inherit_label(string $current): string {
        return get_string('embedpref_usedefault', 'local_stream', $current);
    }

    /**
     * Human-readable site value for a yes/no setting.
     *
     * @param string $key
     * @return string
     */
    public static function site_yesno_label(string $key): string {
        return !empty(self::site_value($key)) ? get_string('yes') : get_string('no');
    }

    /**
     * Form field label, matching the site administration setting.
     *
     * @param string $key
     * @return string
     */
    public static function field_label(string $key): string {
        if ($key === 'hidefromstudents') {
            return get_string('hidefromstudents');
        }
        if ($key === 'embedorder') {
            return get_string('order');
        }
        return get_string($key, 'local_stream');
    }

    /**
     * Lang string identifier for a help button, when one exists.
     *
     * @param string $key
     * @return string|null
     */
    public static function help_identifier(string $key): ?string {
        $map = [
                'prefix' => 'prefix',
                'embedorder' => 'order',
                'addrecordingtype' => 'addrecordingtype',
                'basedgrouping' => 'basedgrouping',
                'defaultcollectionmode' => 'defaultcollectionmode',
        ];
        return $map[$key] ?? null;
    }

    /**
     * Default form values for a teacher.
     *
     * @param int $userid
     * @return \stdClass
     */
    public static function form_defaults(int $userid): \stdClass {
        $data = new \stdClass();
        $data->userid = $userid;

        $prefix = self::user_value($userid, 'prefix');
        if ($prefix === null) {
            $data->prefixmode = self::INHERIT;
            $data->prefix = self::site_value('prefix');
        } else {
            $data->prefixmode = self::PREFIX_CUSTOM;
            $data->prefix = $prefix;
        }

        foreach (self::visible_keys() as $key) {
            if ($key === 'prefix') {
                continue;
            }
            $value = self::user_value($userid, $key);
            $data->$key = ($value === null) ? self::INHERIT : (string) (int) $value;
        }
        return $data;
    }

    /**
     * Store the submitted preference form.
     *
     * @param int $userid
     * @param \stdClass $data
     * @return void
     */
    public static function save(int $userid, \stdClass $data): void {
        $prefixmode = $data->prefixmode ?? self::INHERIT;
        if ($prefixmode === self::PREFIX_CUSTOM) {
            set_user_preference(self::preference_name('prefix'), (string) ($data->prefix ?? ''), $userid);
        } else {
            unset_user_preference(self::preference_name('prefix'), $userid);
        }

        foreach (self::visible_keys() as $key) {
            if ($key === 'prefix') {
                continue;
            }
            $value = $data->$key ?? self::INHERIT;
            $name = self::preference_name($key);
            if ($value === self::INHERIT || $value === '') {
                unset_user_preference($name, $userid);
            } else {
                set_user_preference($name, (string) (int) $value, $userid);
            }
        }
    }

    /**
     * Preference names stored for embedding overrides.
     *
     * @return string[]
     */
    public static function preference_names(): array {
        $names = [];
        foreach (self::keys() as $key) {
            $names[] = self::preference_name($key);
        }
        return $names;
    }

    /**
     * Users who saved at least one embedding preference.
     *
     * @return int
     */
    public static function count_users_with_preferences(): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::preference_names(), SQL_PARAMS_NAMED);
        return (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {user_preferences} WHERE name {$insql}",
                $params
        );
    }

    /**
     * Delete every teacher's embedding preference so the site settings apply again.
     *
     * @return int Number of users whose preferences were removed.
     */
    public static function reset_all(): int {
        global $DB;

        $names = self::preference_names();
        [$insql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);
        $userids = $DB->get_fieldset_sql(
                "SELECT DISTINCT userid FROM {user_preferences} WHERE name {$insql}",
                $params
        );
        foreach ($userids as $userid) {
            foreach ($names as $name) {
                unset_user_preference($name, (int) $userid);
            }
        }
        return count($userids);
    }
}
