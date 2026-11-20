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

namespace report_completiondoctor\form;

use report_completiondoctor\local\learners;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Course and learner selector for the diagnosis page.
 *
 * Opened from a course, the course is fixed and the Learner field searches its
 * learners as the viewer types. Opened without a course, the form only asks for
 * the course, searched the same way, and continues to that course's page.
 * Neither field loads every course or learner into the page.
 *
 * It is a search form that changes nothing, so leaving the page never asks
 * about unsaved changes.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selector_form extends \moodleform {
    /**
     * Form definition.
     *
     * Custom data: course, the course record or null when the page has no course.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();
        $course = $this->_customdata['course'] ?? null;

        if (!$course) {
            // Only courses where the viewer may run a diagnosis. Courses with completion
            // switched off are listed too: that is one of the things the report finds.
            $mform->addElement('course', 'courseid', get_string('selectcourse', 'report_completiondoctor'), [
                'requiredcapabilities' => ['report/completiondoctor:view'],
            ]);
            $mform->setType('courseid', PARAM_INT);
            $mform->addRule('courseid', get_string('chooseacoursefirst', 'report_completiondoctor'), 'required', null, 'client');
            $mform->addElement('static', 'choosefirst', '', get_string('choosecoursefirst', 'report_completiondoctor'));
            $this->add_action_buttons(false, get_string('continue'));
            return;
        }

        $context = \context_course::instance($course->id);
        $mform->addElement('hidden', 'courseid', $course->id);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement(
            'static',
            'coursename',
            get_string('selectcourse', 'report_completiondoctor'),
            format_string($course->fullname, true, ['context' => $context])
        );

        // Searched as the viewer types (see amd/src/learner_selector.js and search_learners).
        $identityfields = \core_user\fields::get_identity_fields($context);
        $mform->addElement('autocomplete', 'userid', get_string('selectuser', 'report_completiondoctor'), [], [
            'ajax' => 'report_completiondoctor/learner_selector',
            'data-courseid' => $course->id,
            'data-minchars' => learners::SEARCH_MIN_LENGTH,
            'placeholder' => get_string('searchlearner', 'report_completiondoctor'),
            'noselectionstring' => get_string('nolearnerselected', 'report_completiondoctor'),
            // Shows the chosen learner by name when the page is opened with userid set.
            'valuehtmlcallback' => function ($value) use ($course, $identityfields) {
                $users = $value ? learners::for_course($course, (int) $value) : [];
                return $users ? s(learners::label(reset($users), $identityfields)) : false;
            },
        ]);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', get_string('chooselearnerfirst', 'report_completiondoctor'), 'required', null, 'client');
        $mform->addHelpButton('userid', 'selectuser', 'report_completiondoctor');

        $this->add_action_buttons(false, get_string('rundiagnosis', 'report_completiondoctor'));
    }

    /**
     * Leave out the session key, and clear a learner the viewer may not diagnose.
     *
     * This form only searches and changes nothing, so it needs no session key. Sent by
     * GET, the key would show in the address bar, browser history and server logs, and
     * in every link people copy from there. The submission marker goes too: with it,
     * moodleform would insist on the key.
     *
     * A submitted value wins over set_data(), and the autocomplete shows a value it
     * cannot name as the bare user id. So a stale or edited userid (a learner since
     * suspended, from another group, or not a learner at all) is removed here, and the
     * field shows that no learner is chosen.
     */
    public function definition_after_data() {
        $mform = $this->_form;
        foreach (['sesskey', '_qf__' . $this->_formname] as $name) {
            if ($mform->elementExists($name)) {
                $mform->removeElement($name);
            }
        }
        $course = $this->_customdata['course'] ?? null;
        if (!$course || !$mform->elementExists('userid')) {
            return;
        }
        $element = $mform->getElement('userid');
        $values = (array) $element->getValue();
        $userid = (int) reset($values);
        if ($userid && !learners::for_course($course, $userid)) {
            $element->removeOption($userid);
            $element->setValue([]);
        }
    }
}
