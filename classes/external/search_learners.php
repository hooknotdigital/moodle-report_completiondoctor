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

namespace report_completiondoctor\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use report_completiondoctor\local\learners;

/**
 * Search the learners of a course that the current user may diagnose.
 *
 * Backs the Learner field of the diagnosis form. Only the learners that
 * \report_completiondoctor\local\learners lists are searched.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_learners extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'query' => new external_value(PARAM_RAW, 'Part of a full name, email address or identity field'),
        ]);
    }

    /**
     * Find the learners matching the query.
     *
     * @param int $courseid Course id.
     * @param string $query Text to look for, at least learners::SEARCH_MIN_LENGTH characters.
     * @return array With learners (id and label, at most learners::SEARCH_LIMIT) and overflow.
     */
    public static function execute(int $courseid, string $query): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'query' => $query]);
        if ($params['courseid'] == SITEID) {
            throw new \invalid_parameter_exception('The site home is not a course with learners.');
        }

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('report/completiondoctor:view', $context);

        $result = learners::search($course, clean_param($params['query'], PARAM_TEXT));
        $identityfields = \core_user\fields::get_identity_fields($context);
        $list = [];
        foreach ($result->users as $user) {
            $list[] = ['id' => (int) $user->id, 'label' => learners::label($user, $identityfields)];
        }

        return [
            'learners' => $list,
            'overflow' => $result->overflow,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'learners' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User id'),
                    // Names may hold any character, so this is plain text that the caller must escape.
                    'label' => new external_value(PARAM_RAW, 'Full name and the identity fields the viewer may see'),
                ])
            ),
            'overflow' => new external_value(PARAM_BOOL, 'More learners match than are returned: keep typing'),
        ]);
    }
}
