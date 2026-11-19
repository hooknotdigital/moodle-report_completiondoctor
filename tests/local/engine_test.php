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

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
/**
 * Tests for the Completion Doctor diagnosis engine.
 *
 * Each test builds one learner in one course and checks the blockers the
 * engine reports. The first tests are regression tests for two wrong
 * diagnoses found on 12 November 2026: the daily completion task flagged as
 * overdue after two hours, and per-type aggregation being ignored.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_completiondoctor\local\engine
 */
final class engine_test extends \advanced_testcase {
    /** @var string Class name of the regular completion task, as stored in task_scheduled. */
    private const REGULAR = '\\core\\task\\completion_regular_task';

    /** @var string Class name of the daily completion task, as stored in task_scheduled. */
    private const DAILY = '\\core\\task\\completion_daily_task';

    /**
     * Turn completion and availability on, and leave both completion tasks healthy.
     *
     * Each test that is about the tasks changes them after this.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        set_config('enableavailability', 1);
        $now = time();
        $this->set_task(self::REGULAR, ['lastruntime' => $now - 30, 'nextruntime' => $now + 30]);
        $this->set_task(self::DAILY, ['lastruntime' => $now - HOURSECS, 'nextruntime' => $now + 20 * HOURSECS]);
    }

    // Bug 1: the daily task was flagged as overdue two hours after it ran.

    /**
     * The daily task ran five hours ago and is due again tomorrow: that is normal.
     */
    public function test_daily_task_between_runs_is_not_a_blocker(): void {
        $now = time();
        $this->set_task(self::DAILY, ['lastruntime' => $now - 5 * HOURSECS, 'nextruntime' => $now + 19 * HOURSECS]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, get_string('taskdaily', 'report_completiondoctor')));
        $this->assertTrue($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A task that has never run but is not due yet (a new site) is not a blocker.
     */
    public function test_task_not_yet_due_on_a_new_site_is_not_a_blocker(): void {
        $this->set_task(self::DAILY, ['lastruntime' => 0, 'nextruntime' => time() + HOURSECS]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, get_string('taskdaily', 'report_completiondoctor')));
        $this->assertTrue($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A task whose scheduled run is days in the past means cron is not running it.
     */
    public function test_task_far_past_its_scheduled_run_is_a_blocker(): void {
        $now = time();
        $this->set_task(self::DAILY, ['lastruntime' => $now - 4 * DAYSECS, 'nextruntime' => $now - 3 * DAYSECS]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskdaily', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A disabled task never runs, however recently it ran before.
     */
    public function test_disabled_task_is_a_blocker(): void {
        $this->set_task(self::REGULAR, ['disabled' => 1]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskregular', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskregular')['ok']);
    }

    /**
     * A task that failed on its last run is waiting to retry: completion is not being processed.
     */
    public function test_failing_task_is_a_blocker(): void {
        $this->set_task(self::REGULAR, ['faildelay' => 240]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskregular', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskregular')['ok']);
    }

    /**
     * With cron switched off for the site, nothing runs.
     */
    public function test_cron_switched_off_is_a_blocker(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('crondisabled', 'report_completiondoctor')));
    }

    // Bug 2: per-type aggregation was ignored.

    /**
     * "Any" activity aggregation: once one activity is done, the others are not blockers.
     */
    public function test_any_activity_aggregation_does_not_list_activities_not_needed(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A', 'Page B']);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ALL);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);
        $this->complete_activity($course, $cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, 'Page B'));
        $this->assertSame([], $this->blockers_containing($data, 'Page A'));
    }

    /**
     * "Any" activity aggregation with nothing done: one blocker naming the choices.
     */
    public function test_any_activity_aggregation_with_nothing_done_is_one_blocker(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Page A', 'Page B']);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ALL);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);

        $data = $this->diagnose($course, $user);

        $blockers = $this->blockers_containing($data, 'Page A');
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Page B', reset($blockers));
        $this->assertTrue($data['verdict']['hasblockers']);
    }

    /**
     * Overall "any" with nothing done must not say the requirements look satisfied.
     */
    public function test_overall_any_with_nothing_done_reports_a_blocker(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Page A'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ANY);

        $data = $this->diagnose($course, $user);

        $this->assertTrue($data['verdict']['hasblockers']);
        $this->assertNotSame(get_string('verdictcompletebutmarked', 'report_completiondoctor'), $data['verdict']['message']);
        $this->assertCount(1, $this->blockers_containing($data, 'Page A'));
    }

    /**
     * Overall "any" with one criterion met: nothing in the criteria blocks the learner.
     */
    public function test_overall_any_with_one_criterion_met_has_no_criteria_blocker(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ANY);
        $this->complete_activity($course, $cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $data['verdict']['blockers']);
        $this->assertFalse($data['verdict']['hasblockers']);
    }

    /**
     * "All" activity aggregation lists each missing activity, and only those.
     */
    public function test_all_activity_aggregation_lists_each_missing_activity(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A', 'Page B', 'Page C']);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ALL);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ALL);
        $this->complete_activity($course, $cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, 'Page A'));
        $this->assertCount(1, $this->blockers_containing($data, 'Page B'));
        $this->assertCount(1, $this->blockers_containing($data, 'Page C'));
    }

    /**
     * Names are plain text: the template escapes them, so no HTML entities.
     */
    public function test_names_are_plain_text(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Q&A <session>']);

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, 'Q&A'));
        $this->assertSame([], $this->blockers_containing($data, '&amp;'));
        $this->assertSame('Q&A', $data['activities']['rows'][0]['activity']);
    }

    // One test for each of the other blocker types.

    /**
     * Completion switched off for the site.
     */
    public function test_site_completion_disabled_is_a_blocker(): void {
        [$course, $user] = $this->course_with_self_criterion();
        set_config('enablecompletion', 0);

        $data = $this->diagnose($course, $user);

        $this->assertContains(get_string('chainsitedisabled', 'report_completiondoctor'), $data['verdict']['blockers']);
    }

    /**
     * Completion switched off in the course.
     */
    public function test_course_completion_disabled_is_a_blocker(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $data = $this->diagnose($course, $user);

        $this->assertContains(get_string('chaincoursedisabled', 'report_completiondoctor'), $data['verdict']['blockers']);
    }

    /**
     * No course completion criteria at all.
     */
    public function test_no_criteria_is_a_blocker(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $data = $this->diagnose($course, $user);

        $this->assertContains(get_string('chainnocriteria', 'report_completiondoctor'), $data['verdict']['blockers']);
    }

    /**
     * A grade below the pass grade, where completion needs a pass.
     */
    public function test_grade_below_pass_is_a_blocker(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        // The incomplete criterion is listed too; the grade blocker says why.
        $blockers = array_filter(
            $this->blockers_containing($data, 'Graded task'),
            fn(string $blocker): bool => str_contains($blocker, format_float(30, 2))
        );
        $this->assertCount(1, $blockers);
    }

    /**
     * Completion asks for a pass grade, but the gradebook has none: Moodle counts any grade as
     * complete, so this is a warning on the activity, not a blocker.
     */
    public function test_missing_pass_grade_is_a_warning_not_a_blocker(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(0);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $row = $data['activities']['rows'][0];
        $this->assertTrue($row['ok']);
        $this->assertContains(get_string('gradepassmissing', 'report_completiondoctor'), $row['notes']);
        $this->assertSame([], $this->blockers_containing($data, get_string('gradepassmissing', 'report_completiondoctor')));
        $this->assertTrue($data['verdict']['iscomplete']);
    }

    /**
     * A failing grade in a hidden grade item: a blocker, and a note that the learner cannot see it.
     */
    public function test_failing_grade_in_hidden_grade_item_is_explained(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0]);
        $item->set_hidden(1);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $row = $data['activities']['rows'][0];
        $this->assertFalse($row['ok']);
        $this->assertContains(get_string('gradehidden', 'report_completiondoctor'), $row['notes']);
        $grades = array_filter(
            $this->blockers_containing($data, 'Graded task'),
            fn(string $blocker): bool => str_contains($blocker, format_float(30, 2))
        );
        $this->assertCount(1, $grades);
    }

    /**
     * A tracked activity hidden from the learner by an access restriction.
     */
    public function test_restricted_activity_is_a_blocker(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() + DAYSECS)]
        ));
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Locked page',
            'completion' => COMPLETION_TRACKING_MANUAL, 'availability' => $availability]);

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, 'Locked page'));
        $this->assertFalse($data['availability']['allclear']);
    }

    /**
     * A learner whose course completion is recorded has no blockers.
     */
    public function test_completed_course_has_no_blockers(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A']);
        $this->complete_activity($course, $cms['Page A'], $user);
        (new \completion_completion(['course' => $course->id, 'userid' => $user->id]))->mark_complete();

        $data = $this->diagnose($course, $user);

        $this->assertTrue($data['verdict']['iscomplete']);
        $this->assertFalse($data['verdict']['hasblockers']);
        $this->assertSame(get_string('verdictcomplete', 'report_completiondoctor'), $data['verdict']['message']);
    }

    // Helpers.

    /**
     * Run the engine.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return array
     */
    private function diagnose(\stdClass $course, \stdClass $user): array {
        $course = get_course($course->id);
        return (new engine($course, $user))->diagnose();
    }

    /**
     * Change fields of a scheduled task record.
     *
     * @param string $classname Class name with the leading backslash.
     * @param array $fields Field values to set.
     */
    private function set_task(string $classname, array $fields): void {
        global $DB;
        $record = $DB->get_record('task_scheduled', ['classname' => $classname], '*', MUST_EXIST);
        foreach ($fields as $name => $value) {
            $record->$name = $value;
        }
        $DB->update_record('task_scheduled', $record);
    }

    /**
     * Blockers that contain a piece of text.
     *
     * @param array $data Engine output.
     * @param string $needle
     * @return string[]
     */
    private function blockers_containing(array $data, string $needle): array {
        return array_values(array_filter(
            $data['verdict']['blockers'],
            fn(string $blocker): bool => str_contains($blocker, $needle)
        ));
    }

    /**
     * The background task row with a given label.
     *
     * @param array $data Engine output.
     * @param string $labelid String id of the task label.
     * @return array
     */
    private function task_row(array $data, string $labelid): array {
        $label = get_string($labelid, 'report_completiondoctor');
        foreach ($data['cron']['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        $this->fail("No task row for {$labelid}");
    }

    /**
     * Set the overall aggregation (type null) or the aggregation of one criteria type.
     *
     * @param int $courseid
     * @param int|null $criteriatype
     * @param int $method COMPLETION_AGGREGATION_ALL or COMPLETION_AGGREGATION_ANY.
     */
    private function set_aggregation(int $courseid, ?int $criteriatype, int $method): void {
        $aggregation = new \completion_aggregation(['course' => $courseid, 'criteriatype' => $criteriatype]);
        $aggregation->setMethod($method);
        $aggregation->save();
    }

    /**
     * A course whose only criterion is self completion, and an enrolled learner.
     *
     * @return array [course, user]
     */
    private function course_with_self_criterion(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $data = (object) ['id' => $course->id, 'criteria_self' => 1];
        (new \completion_criteria_self())->update_config($data);
        return [$course, $user];
    }

    /**
     * A course with one page per name, each an activity completion criterion.
     *
     * @param string[] $names Page names.
     * @param bool $withself Also add the self completion criterion.
     * @return array [course, user, cmids keyed by name]
     */
    private function course_with_activity_criteria(array $names, bool $withself = false): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $cms = [];
        foreach ($names as $name) {
            $page = $generator->create_module('page', ['course' => $course->id, 'name' => $name,
                'completion' => COMPLETION_TRACKING_MANUAL]);
            $cms[$name] = (int) $page->cmid;
        }
        $data = (object) ['id' => $course->id, 'criteria_activity' => array_fill_keys(array_values($cms), 1)];
        (new \completion_criteria_activity())->update_config($data);
        if ($withself) {
            $data = (object) ['id' => $course->id, 'criteria_self' => 1];
            (new \completion_criteria_self())->update_config($data);
        }
        return [$course, $user, $cms];
    }

    /**
     * Mark an activity complete for the learner, and its course completion criterion.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param \stdClass $user
     */
    private function complete_activity(\stdClass $course, int $cmid, \stdClass $user): void {
        global $DB;
        $cm = get_fast_modinfo($course->id)->get_cm($cmid);
        (new \completion_info(get_course($course->id)))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $criterion = $DB->get_record('course_completion_criteria', ['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'moduleinstance' => $cmid], '*', MUST_EXIST);
        $completion = new \completion_criteria_completion(['course' => $course->id, 'userid' => $user->id,
            'criteriaid' => $criterion->id]);
        if (!$completion->is_complete()) {
            $completion->mark_complete();
        }
    }

    /**
     * A course with an assignment that needs a passing grade for completion.
     *
     * @param int $gradepass Pass grade in the gradebook (0 for none).
     * @return array [course, user, assign]
     */
    private function course_with_graded_assignment(int $gradepass): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Graded task',
            'grade' => 100, 'gradepass' => $gradepass, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1]);
        $data = (object) ['id' => $course->id, 'criteria_activity' => [$assign->cmid => 1]];
        (new \completion_criteria_activity())->update_config($data);
        return [$course, $user, $assign];
    }

    /**
     * Give the learner a grade in the assignment's grade item.
     *
     * @param \stdClass $course
     * @param \stdClass $assign
     * @param \stdClass $user
     * @param float $grade
     */
    private function grade(\stdClass $course, \stdClass $assign, \stdClass $user, float $grade): void {
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0]);
        $item->update_final_grade($user->id, $grade, 'test');
    }
}
