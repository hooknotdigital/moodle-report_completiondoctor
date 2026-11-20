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

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversClass(learners::class)]
/**
 * Tests for the list of learners a user may diagnose.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_completiondoctor\local\learners
 */
final class learners_test extends \advanced_testcase {
    /**
     * Active learners only: no suspended enrolment, no suspended site account, no teacher.
     */
    public function test_lists_active_learners_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $active = $generator->create_and_enrol($course, 'student');
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $suspendedaccount = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $ids = array_keys(learners::for_course(get_course($course->id)));

        $this->assertSame([(int) $active->id], array_map('intval', $ids));
        $this->assertSame([], learners::for_course(get_course($course->id), (int) $suspended->id));
        $this->assertSame([], learners::for_course(get_course($course->id), (int) $suspendedaccount->id));
    }

    /**
     * Separate groups: a teacher without "access all groups" gets their own groups' learners only.
     */
    public function test_separate_groups_limit_the_list(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $learner1 = $generator->create_and_enrol($course, 'student');
        $learner2 = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $editor = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $learner1->id]);
        $generator->create_group_member(['groupid' => $group2->id, 'userid' => $learner2->id]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $teacher->id]);
        $course = get_course($course->id);

        $this->setUser($teacher);
        $this->assertSame([(int) $learner1->id], array_map('intval', array_keys(learners::for_course($course))));
        $this->assertSame([], learners::for_course($course, (int) $learner2->id));

        $this->setUser($editor);
        $this->assertCount(2, learners::for_course($course));
    }

    /**
     * Separate groups and no group of one's own: nobody to diagnose.
     */
    public function test_separate_groups_without_a_group_lists_nobody(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $this->assertSame([], learners::for_course(get_course($course->id)));
    }

    /**
     * Each user who cannot be diagnosed is told apart, so the page can say why.
     */
    public function test_why_a_user_cannot_be_diagnosed(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $suspendedaccount = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $ended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, time() - DAYSECS);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $stranger = $generator->create_user();
        $this->setUser($teacher);
        $course = get_course($course->id);

        $expected = [
            [$suspendedaccount, 'learneraccountsuspended'],
            [$suspended, 'learnerenrolmentinactive'],
            [$ended, 'learnerenrolmentinactive'],
            [$teacher, 'learnernottracked'],
            [$stranger, 'learnernotenrolled'],
        ];
        foreach ($expected as [$user, $stringid]) {
            $this->assertSame(
                get_string($stringid, 'report_completiondoctor'),
                learners::why_not($course, (int) $user->id),
                $stringid
            );
        }
        $this->assertSame(get_string('learnernotenrolled', 'report_completiondoctor'), learners::why_not($course, 999999));
    }

    /**
     * A viewer limited to their own groups learns nothing about users outside them. For a
     * user in one of their groups, a suspended account or enrolment is named only to a viewer
     * who may review enrolments, as on the Participants page.
     */
    public function test_why_not_says_nothing_about_users_outside_the_viewers_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $other = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $own = $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group2->id, 'userid' => $other->id]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $own->id]);
        $this->setUser($teacher);
        $course = get_course($course->id);

        $this->assertTrue(learners::limited_to_own_groups($course));
        $this->assertSame(
            get_string('learnernotinyourgroups', 'report_completiondoctor'),
            learners::why_not($course, (int) $other->id)
        );
        $this->assertSame(
            get_string('learnernotactive', 'report_completiondoctor'),
            learners::why_not($course, (int) $own->id)
        );

        $context = \context_course::instance($course->id);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('moodle/course:enrolreview', CAP_ALLOW, $roleid, $context->id);
        $this->assertSame(
            get_string('learneraccountsuspended', 'report_completiondoctor'),
            learners::why_not($course, (int) $own->id)
        );
    }

    /**
     * Nobody to diagnose only when the viewer is limited to their own groups and is in none.
     */
    public function test_can_diagnose_anyone(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $editingteacher = $generator->create_and_enrol($course, 'editingteacher');
        $course = get_course($course->id);

        $this->setUser($teacher);
        $this->assertFalse(learners::can_diagnose_anyone($course));
        $this->setUser($editingteacher);
        $this->assertFalse(learners::limited_to_own_groups($course));
        $this->assertTrue(learners::can_diagnose_anyone($course));
    }

    /**
     * The count leaves out the learner being diagnosed, learners who completed the course,
     * and learners with a suspended enrolment or site account.
     */
    public function test_count_excludes_the_learner_and_completed_learners(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $diagnosed = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student');
        $done = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->create_and_enrol($course, 'student', ['suspended' => 1]);
        (new \completion_completion(['course' => $course->id, 'userid' => $done->id]))->mark_complete();
        // A course completion record that is not complete yet still counts.
        (new \completion_completion(['course' => $course->id, 'userid' => $diagnosed->id]))->mark_inprogress();
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $course = get_course($course->id);

        $this->assertSame(2, learners::count_not_completed($course, (int) $diagnosed->id));
        $this->assertSame(3, learners::count_not_completed($course, (int) $done->id));
    }

    /**
     * Separate groups: the count covers only the learners in the viewer's groups.
     */
    public function test_count_respects_separate_groups(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $teacher->id]);
        $diagnosed = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $diagnosed->id]);
        $classmate = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $classmate->id]);
        foreach ([1, 2, 3] as $unused) {
            $stranger = $generator->create_and_enrol($course, 'student');
            $generator->create_group_member(['groupid' => $group2->id, 'userid' => $stranger->id]);
        }
        $course = get_course($course->id);

        $this->setUser($teacher);
        $this->assertSame(1, learners::count_not_completed($course, (int) $diagnosed->id));

        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $this->assertSame(4, learners::count_not_completed($course, (int) $diagnosed->id));
    }

    /**
     * The label is the full name, then the identity fields that have a value.
     */
    public function test_label_shows_name_and_identity_fields(): void {
        $user = (object) ['firstname' => 'Sam', 'lastname' => 'Student', 'email' => 'sam@example.com',
            'department' => '', 'idnumber' => 'S17'];
        foreach (\core_user\fields::get_name_fields() as $field) {
            $user->$field = $user->$field ?? '';
        }

        $this->assertSame('Sam Student (sam@example.com, S17)', learners::label($user, ['email', 'department', 'idnumber']));
        $this->assertSame('Sam Student', learners::label($user, []));
    }
}
