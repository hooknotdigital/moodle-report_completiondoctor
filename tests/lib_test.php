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

namespace report_completiondoctor;

use core_user\output\myprofile\tree;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/report/completiondoctor/lib.php');

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversFunction('report_completiondoctor_myprofile_navigation')]
/**
 * Tests for the Completion Doctor link on a learner's course profile.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::report_completiondoctor_myprofile_navigation
 */
final class lib_test extends \advanced_testcase {
    /**
     * A teacher on a learner's course profile gets a link that opens the report filled in.
     */
    public function test_link_on_a_learners_course_profile(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $node = $this->link($learner, get_course($course->id));

        $this->assertNotNull($node);
        $this->assertSame('reports', $node->parentcat);
        $this->assertSame(get_string('pluginname', 'report_completiondoctor'), $node->title);
        $expected = new \moodle_url('/report/completiondoctor/index.php', ['courseid' => $course->id, 'userid' => $learner->id]);
        $this->assertSame($expected->out(false), $node->url->out(false));
    }

    /**
     * No link on a profile without a course, or on the site home.
     */
    public function test_no_link_without_a_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setAdminUser();

        $this->assertNull($this->link($learner, null));
        $this->assertNull($this->link($learner, get_site()));
    }

    /**
     * No link for a viewer without the capability in the course.
     */
    public function test_no_link_without_the_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setUser($generator->create_and_enrol($course, 'student'));

        $this->assertNull($this->link($learner, get_course($course->id)));
    }

    /**
     * No link for someone the viewer may not diagnose: a learner with a suspended enrolment or
     * site account, a teacher, or a learner in another group of a course with separate groups.
     */
    public function test_no_link_for_someone_the_viewer_may_not_diagnose(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $mine = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $suspendedaccount = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $colleague = $generator->create_and_enrol($course, 'editingteacher');
        foreach ([$teacher, $mine, $suspended, $suspendedaccount, $colleague] as $member) {
            $generator->create_group_member(['groupid' => $group1->id, 'userid' => $member->id]);
        }
        $generator->create_group_member(['groupid' => $group2->id, 'userid' => $other->id]);
        $course = get_course($course->id);
        $this->setUser($teacher);

        $this->assertNotNull($this->link($mine, $course));
        $this->assertNull($this->link($other, $course));
        $this->assertNull($this->link($suspended, $course));
        $this->assertNull($this->link($suspendedaccount, $course));
        $this->assertNull($this->link($colleague, $course));
    }

    /**
     * Build a profile tree with the callback and return the Completion Doctor node.
     *
     * @param \stdClass $user The user whose profile is shown.
     * @param \stdClass|null $course The course of a course profile.
     * @return \core_user\output\myprofile\node|null
     */
    private function link(\stdClass $user, ?\stdClass $course): ?\core_user\output\myprofile\node {
        global $USER;
        $tree = new tree();
        report_completiondoctor_myprofile_navigation($tree, $user, $USER->id == $user->id, $course);
        return $tree->nodes['completiondoctor'] ?? null;
    }
}
