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

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversClass(selector_form::class)]
/**
 * Tests for the course and learner selector of the diagnosis page.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_completiondoctor\form\selector_form
 */
final class selector_form_test extends \advanced_testcase {
    /**
     * A submitted learner the viewer may not diagnose is cleared, not shown as a bare user id;
     * a learner they may diagnose is shown by name.
     */
    public function test_submitted_learner_the_viewer_may_not_diagnose_is_cleared(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $learner = $generator->create_and_enrol($course, 'student', ['firstname' => 'Priya', 'lastname' => 'Sharma']);
        $suspended = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        foreach ([$teacher, $suspended] as $notalearner) {
            $html = $this->render_submitted($course, (int) $notalearner->id);
            $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="' . $notalearner->id . '"/', $html);
        }

        $html = $this->render_submitted($course, (int) $learner->id);
        $this->assertMatchesRegularExpression('/<option[^>]*value="' . $learner->id . '"[^>]*selected/', $html);
        $this->assertStringContainsString('Priya Sharma', $html);
    }

    /**
     * The form only searches, so leaving the page never asks about unsaved changes.
     */
    public function test_form_change_checker_is_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->setAdminUser();
        $url = new \moodle_url('/report/completiondoctor/index.php');

        foreach ([get_course($course->id), null] as $formcourse) {
            $form = new selector_form($url, ['course' => $formcourse], 'get');
            $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
            $this->assertFalse($mform->is_form_change_checker_enabled());
        }
    }

    /**
     * The form is sent by GET and changes nothing, so it carries no session key into the
     * address bar, and no submission marker that would make moodleform ask for one.
     */
    public function test_form_carries_no_session_key(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->setAdminUser();
        $url = new \moodle_url('/report/completiondoctor/index.php');

        foreach ([get_course($course->id), null] as $formcourse) {
            $html = (new selector_form($url, ['course' => $formcourse], 'get'))->render();
            $this->assertStringNotContainsString('name="sesskey"', $html);
            $this->assertStringNotContainsString('_qf__', $html);
            $this->assertStringNotContainsString(sesskey(), $html);
        }
    }

    /**
     * Render the form as if it was submitted with this learner.
     *
     * @param \stdClass $course Course record.
     * @param int $userid The submitted learner.
     * @return string The form HTML.
     */
    private function render_submitted(\stdClass $course, int $userid): string {
        $_GET = [
            'courseid' => $course->id,
            'userid' => $userid,
            'sesskey' => sesskey(),
            '_qf__report_completiondoctor_form_selector_form' => 1,
        ];
        $url = new \moodle_url('/report/completiondoctor/index.php');
        $form = new selector_form($url, ['course' => get_course($course->id)], 'get');
        $html = $form->render();
        $_GET = [];
        return $html;
    }
}
