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
 * Completion Doctor diagnosis page.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_completiondoctor\local\learners;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$url = new moodle_url('/report/completiondoctor/index.php');
$course = null;
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('report/completiondoctor:view', $context);
    $url->param('courseid', $courseid);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('report');
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
} else {
    admin_externalpage_setup('reportcompletiondoctor');
    $context = context_system::instance();
}

$PAGE->set_title(get_string('pluginname', 'report_completiondoctor'));

// Only a learner this viewer may diagnose (see \report_completiondoctor\local\learners).
$learner = ($course && $userid) ? learners::for_course($course, $userid) : [];

$form = new \report_completiondoctor\form\selector_form($url->out(false), ['course' => $course], 'get');
$form->set_data(['courseid' => $courseid, 'userid' => $learner ? $userid : null]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'report_completiondoctor'));

$form->display();

if ($course && $userid) {
    if ($learner) {
        $user = core_user::get_user($userid, '*', MUST_EXIST);
        $engine = new \report_completiondoctor\local\engine($course, $user);
        $data = $engine->diagnose();

        $others = learners::count_not_completed($course, $user->id);
        if ($others == 0) {
            $data['others'] = get_string('othersincompletenone', 'report_completiondoctor');
        } else if ($others == 1) {
            $data['others'] = get_string('othersincompleteone', 'report_completiondoctor');
        } else {
            $data['others'] = get_string('othersincomplete', 'report_completiondoctor', $others);
        }

        echo $OUTPUT->render_from_template('report_completiondoctor/report', $data);
    } else {
        echo $OUTPUT->notification(get_string('usernotenrolled', 'report_completiondoctor'), 'error');
    }
}

echo $OUTPUT->footer();
