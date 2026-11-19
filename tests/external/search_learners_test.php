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
use report_completiondoctor\local\learners;

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversClass(search_learners::class)]
/**
 * Tests for the learner search behind the Learner field.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_completiondoctor\external\search_learners
 */
final class search_learners_test extends \advanced_testcase {
    /**
     * Teachers find learners by part of the name; the label is the name and the identity fields.
     */
    public function test_matches_part_of_the_name(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $priya = $generator->create_and_enrol($course, 'student', ['firstname' => 'Priya', 'lastname' => 'Sharma',
            'email' => 'priya@example.com']);
        $generator->create_and_enrol($course, 'student', ['firstname' => 'Sam', 'lastname' => 'Student']);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $result = $this->search($course->id, 'iya sha');

        $this->assertSame([['id' => (int) $priya->id, 'label' => 'Priya Sharma (priya@example.com)']], $result['learners']);
        $this->assertFalse($result['overflow']);
    }

    /**
     * The email address matches, and so does an identity field the viewer may see.
     */
    public function test_matches_email_and_identity_field(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email,department');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $byemail = $generator->create_and_enrol($course, 'student', ['email' => 'night.shift@example.com',
            'department' => 'Kitchen']);
        $bydepartment = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ola', 'lastname' => 'Nordmann',
            'email' => 'ola@example.com', 'department' => 'Logistics']);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $this->assertSame([(int) $byemail->id], $this->ids($this->search($course->id, 'night.SHIFT')));
        $result = $this->search($course->id, 'logist');
        $this->assertSame([(int) $bydepartment->id], $this->ids($result));
        $this->assertSame('Ola Nordmann (ola@example.com, Logistics)', $result['learners'][0]['label']);
    }

    /**
     * An email address the viewer may not see only matches when the learner does not hide it.
     */
    public function test_hidden_email_does_not_match(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', '');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_and_enrol($course, 'student', ['email' => 'hidden.one@example.com',
            'maildisplay' => \core_user::MAILDISPLAY_HIDE]);
        $shown = $generator->create_and_enrol($course, 'student', ['email' => 'shown.one@example.com',
            'maildisplay' => \core_user::MAILDISPLAY_COURSE_MEMBERS_ONLY]);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $this->assertSame([(int) $shown->id], $this->ids($this->search($course->id, '.one@example')));
    }

    /**
     * An email address the site hides in hiddenuserfields only matches for viewers who may see hidden user fields.
     */
    public function test_email_in_hidden_user_fields_does_not_match(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', '');
        set_config('hiddenuserfields', 'email');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $context = \context_course::instance($course->id);
        $learner = $generator->create_and_enrol($course, 'student', ['email' => 'open.book@example.com',
            'maildisplay' => \core_user::MAILDISPLAY_EVERYONE]);
        $editingteacher = $generator->create_and_enrol($course, 'editingteacher');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $roleid = (int) $this->get_role_id('editingteacher');
        assign_capability('moodle/course:viewhiddenuserfields', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->setUser($editingteacher);
        $this->assertSame([], $this->search($course->id, 'open.book')['learners']);

        $this->setUser($teacher);
        $this->assertSame([(int) $learner->id], $this->ids($this->search($course->id, 'open.book')));
    }

    /**
     * Identity fields the viewer may not see are never searched and never shown in the label.
     */
    public function test_identity_fields_the_viewer_may_not_see_are_not_searched(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email,department');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $context = \context_course::instance($course->id);
        $learner = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ola', 'lastname' => 'Nordmann',
            'email' => 'ola@example.com', 'department' => 'Logistics', 'idnumber' => 'X123',
            'maildisplay' => \core_user::MAILDISPLAY_HIDE]);
        $editingteacher = $generator->create_and_enrol($course, 'editingteacher');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $roleid = (int) $this->get_role_id('editingteacher');
        assign_capability('moodle/site:viewuseridentity', CAP_PROHIBIT, $roleid, $context->id, true);

        // Without moodle/site:viewuseridentity: no identity field is searched or shown.
        $this->setUser($editingteacher);
        $this->assertSame([], $this->search($course->id, 'logist')['learners']);
        $this->assertSame([], $this->search($course->id, 'X123')['learners']);
        $this->assertSame([], $this->search($course->id, 'ola@example')['learners']);
        $expected = [['id' => (int) $learner->id, 'label' => 'Ola Nordmann']];
        $this->assertSame($expected, $this->search($course->id, 'nordmann')['learners']);

        // With it: the fields in showuseridentity, but never idnumber, which is not one of them.
        $this->setUser($teacher);
        $this->assertSame([(int) $learner->id], $this->ids($this->search($course->id, 'logist')));
        $this->assertSame([], $this->search($course->id, 'X123')['learners']);
        $label = $this->search($course->id, 'nordmann')['learners'][0]['label'];
        $this->assertSame('Ola Nordmann (ola@example.com, Logistics)', $label);
    }

    /**
     * Learners with a suspended enrolment or a suspended site account are never returned, nor are teachers.
     */
    public function test_suspended_learners_are_never_returned(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $active = $generator->create_and_enrol($course, 'student', ['lastname' => 'Student']);
        $generator->create_and_enrol($course, 'student', ['lastname' => 'Student'], 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->create_and_enrol($course, 'student', ['lastname' => 'Student', 'suspended' => 1]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher', ['lastname' => 'Student']);
        $this->setUser($teacher);

        $this->assertSame([(int) $active->id], $this->ids($this->search($course->id, 'student')));
    }

    /**
     * At most 20 learners come back, with a signal that more match.
     */
    public function test_limit_and_overflow(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        for ($i = 1; $i <= learners::SEARCH_LIMIT; $i++) {
            $generator->create_and_enrol($course, 'student', ['lastname' => 'Match' . $i]);
        }
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $result = $this->search($course->id, 'match');
        $this->assertCount(learners::SEARCH_LIMIT, $result['learners']);
        $this->assertFalse($result['overflow']);

        $generator->create_and_enrol($course, 'student', ['lastname' => 'Match21']);
        $result = $this->search($course->id, 'match');
        $this->assertCount(learners::SEARCH_LIMIT, $result['learners']);
        $this->assertTrue($result['overflow']);
    }

    /**
     * Fewer characters than the minimum find nobody.
     */
    public function test_short_query_finds_nobody(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_and_enrol($course, 'student', ['firstname' => 'Al']);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $this->assertSame([], $this->search($course->id, ' a ')['learners']);
        $this->assertCount(1, $this->search($course->id, 'al')['learners']);
    }

    /**
     * Separate groups: a teacher without "access all groups" finds only learners in their groups.
     */
    public function test_separate_groups_limit_the_search(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $mine = $generator->create_and_enrol($course, 'student', ['lastname' => 'Grouped']);
        $other = $generator->create_and_enrol($course, 'student', ['lastname' => 'Grouped']);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $mine->id]);
        $generator->create_group_member(['groupid' => $group2->id, 'userid' => $other->id]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);
        $this->assertSame([(int) $mine->id], $this->ids($this->search($course->id, 'grouped')));

        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $this->assertCount(2, $this->search($course->id, 'grouped')['learners']);
    }

    /**
     * Without the capability, the search is refused.
     */
    public function test_requires_the_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        $this->search($course->id, 'student');
    }

    /**
     * A teacher of one course cannot search another, and never gets its learners.
     */
    public function test_other_course_is_refused(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $mycourse = $generator->create_course(['enablecompletion' => 1]);
        $othercourse = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_and_enrol($othercourse, 'student', ['lastname' => 'Elsewhere']);
        $this->setUser($generator->create_and_enrol($mycourse, 'editingteacher'));

        $this->assertSame([], $this->search($mycourse->id, 'elsewhere')['learners']);
        $this->expectException(\require_login_exception::class);
        $this->search($othercourse->id, 'elsewhere');
    }

    // Helpers.

    /**
     * Call the external function the way the web service layer does.
     *
     * @param int $courseid
     * @param string $query
     * @return array Cleaned return value.
     */
    private function search(int $courseid, string $query): array {
        $result = search_learners::execute($courseid, $query);
        return external_api::clean_returnvalue(search_learners::execute_returns(), $result);
    }

    /**
     * The id of a standard role.
     *
     * @param string $shortname Role short name.
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * The user ids in a result.
     *
     * @param array $result
     * @return int[]
     */
    private function ids(array $result): array {
        return array_column($result['learners'], 'id');
    }
}
