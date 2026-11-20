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

// The site home is not a course with learners: ask for a course instead.
if ($courseid == SITEID) {
    redirect(new moodle_url('/report/completiondoctor/index.php'));
}

// The form is sent by GET, which puts its button in the address. Move on to the clean
// address, the same one the course profile link opens, so a shared link stays short.
if (optional_param('submitbutton', '', PARAM_TEXT) !== '') {
    redirect(new moodle_url('/report/completiondoctor/index.php', array_filter(['courseid' => $courseid, 'userid' => $userid])));
}

$url = new moodle_url('/report/completiondoctor/index.php');
$course = null;
$title = [get_string('pluginname', 'report_completiondoctor')];
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('report/completiondoctor:view', $context);
    $url->param('courseid', $courseid);
    $PAGE->set_url(new moodle_url($url, array_filter(['userid' => $userid])));
    $PAGE->set_pagelayout('report');
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
    array_unshift($title, format_string($course->shortname, true, ['context' => $context]));
} else {
    admin_externalpage_setup('reportcompletiondoctor');
    $context = context_system::instance();
}

// Only a learner this viewer may diagnose (see \report_completiondoctor\local\learners).
$learner = ($course && $userid) ? learners::for_course($course, $userid) : [];
$user = $learner ? core_user::get_user($userid, '*', MUST_EXIST) : null;
if ($user) {
    array_unshift($title, fullname($user));
}
// Names the learner and the course, so tabs and screen readers tell two diagnoses apart.
$PAGE->set_title(implode(moodle_page::TITLE_SEPARATOR, $title));

$form = new \report_completiondoctor\form\selector_form($url->out(false), ['course' => $course], 'get');
$form->set_data(['courseid' => $courseid, 'userid' => $user ? $userid : null]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'report_completiondoctor'));

if ($course && !learners::can_diagnose_anyone($course)) {
    echo $OUTPUT->notification(get_string('nogroupsnotice', 'report_completiondoctor'), 'info', false);
}

$form->display();
// The course or learner field, whichever this page asks for.
$PAGE->requires->js_call_amd('report_completiondoctor/field_errors', 'init', [$course ? 'id_userid' : 'id_courseid']);

if ($user) {
    $engine = new \report_completiondoctor\local\engine($course, $user);
    $data = $engine->diagnose();

    // The count covers only the learners this viewer may diagnose, so say so when that is
    // their own groups.
    $others = learners::count_not_completed($course, $user->id);
    $stringid = 'othersincomplete' . (learners::limited_to_own_groups($course) ? 'groups' : '');
    if ($others == 0) {
        $data['others'] = get_string($stringid . 'none', 'report_completiondoctor');
    } else if ($others == 1) {
        $data['others'] = get_string($stringid . 'one', 'report_completiondoctor');
    } else {
        $data['others'] = get_string($stringid, 'report_completiondoctor', $others);
    }
    // That report needs criteria: without them it shows an error.
    if ($others > 0 && has_capability('report/completion:view', $context) && (new completion_info($course))->has_criteria()) {
        $data['otherslink'] = [
            'url' => (new moodle_url('/report/completion/index.php', ['course' => $course->id]))->out(false),
            'label' => get_string('linkcompletionreport', 'report_completiondoctor'),
        ];
    }

    echo $OUTPUT->render_from_template('report_completiondoctor/report', $data);
} else if ($course && $userid) {
    echo $OUTPUT->notification(learners::why_not($course, $userid), 'warning', false);
}

echo $OUTPUT->footer();
