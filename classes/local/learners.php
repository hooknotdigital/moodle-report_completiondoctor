<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace report_completiondoctor\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/grouplib.php');

/**
 * The learners the current user may diagnose in a course.
 *
 * These are the users the course tracks for completion (an active enrolment and
 * moodle/course:isincompletionreports), as in core's completion reports, whose
 * site account is not suspended. In a course with separate groups, a user without
 * moodle/site:accessallgroups only gets the learners in their own groups.
 *
 * Every method here applies the same rules, so the list, the search, the count
 * and the check on a single learner never disagree.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learners {
    /** @var int The most learners a search returns. */
    public const SEARCH_LIMIT = 20;

    /** @var int The fewest characters a search needs. */
    public const SEARCH_MIN_LENGTH = 2;

    /**
     * Learners in a course, with name and identity fields.
     *
     * @param \stdClass $course Course record.
     * @param int|null $userid Only this user, if given.
     * @return \stdClass[] Users keyed by id, sorted by last name.
     */
    public static function for_course(\stdClass $course, ?int $userid = null): array {
        $shared = self::conditions($course);
        if ($shared === null) {
            return [];
        }
        [$conditions, $params] = $shared;
        if ($userid !== null) {
            $conditions[] = 'u.id = :cdlearnerid';
            $params['cdlearnerid'] = $userid;
        }

        $context = \context_course::instance($course->id);
        $completion = new \completion_info($course);
        $sort = 'u.lastname ASC, u.firstname ASC, u.id ASC';
        return $completion->get_tracked_users(implode(' AND ', $conditions), $params, 0, $sort, '', '', $context);
    }

    /**
     * Learners whose full name, email address or identity fields contain the query.
     *
     * The search runs in the database and returns at most $limit learners. An email
     * address that is not one of the identity fields the viewer may see only matches
     * when the learner does not hide it, as in core's user search
     * (\core_user::get_users_search_sql()), and never when the site lists email in
     * hiddenuserfields and the viewer may not see hidden user fields in the course, as
     * on a course profile. Identity fields the viewer may not see are never searched.
     * The country field is not searched: its two-letter codes would match far too much.
     *
     * @param \stdClass $course Course record.
     * @param string $query Text to look for, at least SEARCH_MIN_LENGTH characters.
     * @param int $limit The most learners to return.
     * @return \stdClass With users (records keyed by id, with name and identity fields, best
     *     matches first) and overflow (true when more learners match than are returned).
     */
    public static function search(\stdClass $course, string $query, int $limit = self::SEARCH_LIMIT): \stdClass {
        global $CFG, $DB;

        $result = (object) ['users' => [], 'overflow' => false];
        $query = trim($query);
        $shared = self::conditions($course);
        if (\core_text::strlen($query) < self::SEARCH_MIN_LENGTH || $shared === null) {
            return $result;
        }
        [$conditions, $params] = $shared;

        $context = \context_course::instance($course->id);
        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, 'moodle/course:isincompletionreports', 0, true);
        $identityfields = \core_user\fields::get_identity_fields($context);
        $fieldssql = \core_user\fields::for_identity($context)->with_name()->excluding('id')->get_sql('u', true);
        $params += $enrolledparams + $fieldssql->params;

        $pattern = '%' . $DB->sql_like_escape($query) . '%';
        [$fullname, $fullnameparams] = \core_user\fields::get_sql_fullname('u');
        $matches = [$DB->sql_like($fullname, ':cdqname', false, false)];
        $params += $fullnameparams + ['cdqname' => $pattern];

        $email = $DB->sql_like('u.email', ':cdqemail', false, false);
        if (in_array('email', $identityfields)) {
            $matches[] = $email;
            $params['cdqemail'] = $pattern;
        } else {
            $hiddenfields = array_map('trim', explode(',', $CFG->hiddenuserfields ?? ''));
            if (!in_array('email', $hiddenfields) || has_capability('moodle/course:viewhiddenuserfields', $context)) {
                $matches[] = "({$email} AND u.maildisplay <> :cdqmailhidden)";
                $params += ['cdqemail' => $pattern, 'cdqmailhidden' => \core_user::MAILDISPLAY_HIDE];
            }
        }

        foreach (array_values($identityfields) as $index => $field) {
            if ($field === 'email' || $field === 'country' || !isset($fieldssql->mappings[$field])) {
                continue;
            }
            $matches[] = $DB->sql_like($fieldssql->mappings[$field], ":cdqfield{$index}", false, false);
            $params["cdqfield{$index}"] = $pattern;
        }
        $conditions[] = '(' . implode(' OR ', $matches) . ')';

        [$sort, $sortparams] = users_order_by_sql('u', $query, $context);
        $params += $sortparams;

        $sql = "SELECT u.id {$fieldssql->selects}
                  FROM ({$enrolledsql}) eu
                  JOIN {user} u ON u.id = eu.id
                       {$fieldssql->joins}
                 WHERE " . implode(' AND ', $conditions) . "
              ORDER BY {$sort}";
        $users = $DB->get_records_sql($sql, $params, 0, $limit + 1);

        $result->overflow = count($users) > $limit;
        $result->users = array_slice($users, 0, $limit, true);
        return $result;
    }

    /**
     * How many other learners in the course have not completed it.
     *
     * One COUNT query over the learners this viewer may diagnose.
     *
     * @param \stdClass $course Course record.
     * @param int $userid The learner being diagnosed, who is not counted.
     * @return int
     */
    public static function count_not_completed(\stdClass $course, int $userid): int {
        $shared = self::conditions($course);
        if ($shared === null) {
            return 0;
        }
        [$conditions, $params] = $shared;
        $conditions[] = 'u.id <> :cdexcluded';
        $conditions[] = 'NOT EXISTS (
            SELECT 1
              FROM {course_completions} cc
             WHERE cc.userid = u.id AND cc.course = :cdcourseid AND cc.timecompleted > 0
        )';
        $params += ['cdexcluded' => $userid, 'cdcourseid' => $course->id];

        return (new \completion_info($course))->get_num_tracked_users(implode(' AND ', $conditions), $params);
    }

    /**
     * How a learner is named in the Learner field: the full name, then the identity fields.
     *
     * @param \stdClass $user User record with name fields and the identity fields.
     * @param string[] $identityfields Identity fields the viewer may see.
     * @return string Plain text: the full name, then the identity fields in brackets.
     */
    public static function label(\stdClass $user, array $identityfields): string {
        $identity = [];
        foreach ($identityfields as $field) {
            if (isset($user->$field) && $user->$field !== '') {
                $identity[] = $user->$field;
            }
        }
        return fullname($user) . ($identity ? ' (' . implode(', ', $identity) . ')' : '');
    }

    /**
     * The rules every method shares, as SQL conditions on the user table (alias u).
     *
     * The site account is not suspended (core's enrolment checks only look at the
     * enrolment), and the separate groups rule.
     *
     * @param \stdClass $course Course record.
     * @return array|null [conditions, params], or null when the viewer may diagnose nobody.
     */
    protected static function conditions(\stdClass $course): ?array {
        global $DB, $USER;

        $conditions = ['u.suspended = 0'];
        $context = \context_course::instance($course->id);
        if (
            groups_get_course_groupmode($course) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context)
        ) {
            return [$conditions, []];
        }
        $groupids = array_keys(groups_get_all_groups($course->id, $USER->id));
        if (!$groupids) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'cdgroup');
        $conditions[] = "u.id IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid {$insql})";
        return [$conditions, $params];
    }
}
