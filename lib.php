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

/**
 * Library callbacks for Completion Doctor.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a link to the report in the course's Reports menu.
 *
 * Not on the site home, which is not a course with learners. Managers find the
 * site-wide page in Site administration > Reports.
 *
 * @param navigation_node $navigation The navigation node to extend.
 * @param stdClass $course The course object.
 * @param context $context The course context.
 */
function report_completiondoctor_extend_navigation_course($navigation, $course, $context) {
    if ($course->id == SITEID) {
        return;
    }
    if (has_capability('report/completiondoctor:view', $context)) {
        $url = new moodle_url('/report/completiondoctor/index.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('pluginname', 'report_completiondoctor'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Add "Completion Doctor" to the Reports section of a learner's course profile.
 *
 * Only on a course profile, for a viewer who may run the report in that course,
 * and for a learner that viewer may diagnose. The link opens the report already
 * filled in for that learner.
 *
 * @param core_user\output\myprofile\tree $tree The profile tree.
 * @param stdClass $user The user whose profile is shown.
 * @param bool $iscurrentuser Whether that is the current user.
 * @param stdClass|null $course The course, when the profile is a course profile.
 */
function report_completiondoctor_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (empty($course) || $course->id == SITEID) {
        return;
    }
    if (!has_capability('report/completiondoctor:view', context_course::instance($course->id))) {
        return;
    }
    if (!\report_completiondoctor\local\learners::for_course($course, (int) $user->id)) {
        return;
    }
    $url = new moodle_url('/report/completiondoctor/index.php', ['courseid' => $course->id, 'userid' => $user->id]);
    $tree->add_node(new core_user\output\myprofile\node(
        'reports',
        'completiondoctor',
        get_string('pluginname', 'report_completiondoctor'),
        null,
        $url
    ));
}
