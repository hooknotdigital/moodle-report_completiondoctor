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
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_role.php');

// The attribute comes before the docblock: moodle-cs reads @covers from a docblock directly above the
// class when it checks against Moodle 4.5, and PHPUnit 11 (Moodle 5.x) reads the attribute.
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
/**
 * Tests for the Completion Doctor diagnosis engine.
 *
 * Each test builds one learner in one course and checks what the engine reports.
 * The first tests are regression tests for two wrong diagnoses found on
 * 12 November 2026: the daily completion task flagged as overdue after two hours,
 * and per-type aggregation being ignored. The tests after them cover the launch
 * walk-through of 20 November 2026: each thing is listed once with its cause, the
 * most fundamental first, with a link to where it is fixed, and in words.
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
     * Each test that is about the tasks changes them after this. The viewer is the
     * administrator, who gets every link; tests about other viewers set their own.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
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
    public function test_daily_task_between_runs_is_not_a_problem(): void {
        $now = time();
        $this->set_task(self::DAILY, ['lastruntime' => $now - 5 * HOURSECS, 'nextruntime' => $now + 19 * HOURSECS]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertSame('', $data['verdict']['tasknote']);
        $this->assertTrue($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A task that has never run but is not due yet (a new site) is not a problem.
     */
    public function test_task_not_yet_due_on_a_new_site_is_not_a_problem(): void {
        $this->set_task(self::DAILY, ['lastruntime' => 0, 'nextruntime' => time() + HOURSECS]);
        [$course, $user] = $this->course_with_self_criterion();

        $data = $this->diagnose($course, $user);

        $this->assertSame('', $data['verdict']['tasknote']);
        $this->assertTrue($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A task whose scheduled run is days in the past means cron is not running it.
     */
    public function test_task_far_past_its_scheduled_run_is_a_problem(): void {
        $now = time();
        $this->set_task(self::DAILY, ['lastruntime' => $now - 4 * DAYSECS, 'nextruntime' => $now - 3 * DAYSECS]);
        [$course, $user] = $this->course_with_requirements_met();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskdaily', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskdaily')['ok']);
    }

    /**
     * A disabled task never runs, however recently it ran before.
     */
    public function test_disabled_task_is_a_problem(): void {
        $this->set_task(self::REGULAR, ['disabled' => 1]);
        [$course, $user] = $this->course_with_requirements_met();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskregular', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskregular')['ok']);
    }

    /**
     * A task that failed on its last run is waiting to retry: completion is not being processed.
     */
    public function test_failing_task_is_a_problem(): void {
        $this->set_task(self::REGULAR, ['faildelay' => 240]);
        [$course, $user] = $this->course_with_requirements_met();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('taskregular', 'report_completiondoctor')));
        $this->assertFalse($this->task_row($data, 'taskregular')['ok']);
    }

    /**
     * With cron switched off for the site, nothing runs.
     */
    public function test_cron_switched_off_is_a_problem(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_requirements_met();

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, get_string('crondisabled', 'report_completiondoctor')));
    }

    // Bug 2: per-type aggregation was ignored.

    /**
     * "Any" activity aggregation: once one activity is done, the others are not problems.
     * Self completion is also required, so the course stays incomplete and the verdict is
     * really computed.
     */
    public function test_any_activity_aggregation_does_not_list_activities_not_needed(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A', 'Page B'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ALL);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);
        $this->complete_activity($course, $cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertFalse($data['verdict']['iscomplete']);
        $this->assertSame([get_string('problemselfdisabled', 'report_completiondoctor')], $this->blocker_texts($data));
    }

    /**
     * An activity done but not yet recorded for the course counts as met under "any"
     * aggregation too, so its alternatives are not problems.
     */
    public function test_done_but_not_recorded_counts_under_any_aggregation(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A', 'Page B'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ALL);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);
        $this->record_activity_only($cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, 'Page B'));
        $this->assertSame([get_string('problemselfdisabled', 'report_completiondoctor')], $this->blocker_texts($data));
    }

    /**
     * "Any" activity aggregation with nothing done: one problem naming the choices.
     */
    public function test_any_activity_aggregation_with_nothing_done_is_one_problem(): void {
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
    public function test_overall_any_with_nothing_done_reports_a_problem(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Page A'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ANY);

        $data = $this->diagnose($course, $user);

        $this->assertTrue($data['verdict']['hasblockers']);
        $this->assertNotSame(get_string('verdictcompletebutmarked', 'report_completiondoctor'), $data['verdict']['message']);
        $this->assertCount(1, $this->blockers_containing($data, 'Page A'));
    }

    /**
     * Overall "any" with one criterion met but not recorded yet: nothing in the criteria
     * blocks the learner, and the verdict says the task will mark it.
     */
    public function test_overall_any_with_one_criterion_met_has_no_criteria_problem(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A'], true);
        $this->set_aggregation($course->id, null, COMPLETION_AGGREGATION_ANY);
        $this->record_activity_only($cms['Page A'], $user);

        $data = $this->diagnose($course, $user);

        $this->assertFalse($data['verdict']['iscomplete']);
        $this->assertSame([], $data['verdict']['blockers']);
        $this->assertSame(get_string('verdictcompletebutmarked', 'report_completiondoctor'), $data['verdict']['message']);
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
        $this->assertSame(
            [get_string('problemnotdonemanual', 'report_completiondoctor', 'Page B')],
            $this->blockers_containing($data, 'Page B')
        );
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

    // The settings chain.

    /**
     * Completion switched off for the site.
     */
    public function test_site_completion_disabled_is_a_problem(): void {
        [$course, $user] = $this->course_with_self_criterion();
        set_config('enablecompletion', 0);

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('chainsitedisabled', 'report_completiondoctor')], $this->blocker_texts($data));
    }

    /**
     * Completion switched off in the course: one problem, not a second one for the criteria
     * that follow from it, and the empty activity table says why it is empty.
     */
    public function test_course_completion_disabled_is_one_problem(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Page A']);
        $this->set_course_completion($course, 0);

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('chaincoursedisabled', 'report_completiondoctor')], $this->blocker_texts($data));
        $criteriarow = $data['chain']['rows'][2];
        $this->assertTrue($criteriarow['skipped']);
        $this->assertFalse($data['activities']['hasrows']);
        $this->assertSame(get_string('noactivitiestracked', 'report_completiondoctor'), $data['activities']['none']);
    }

    /**
     * No course completion criteria at all.
     */
    public function test_no_criteria_is_a_problem(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('chainnocriteria', 'report_completiondoctor')], $this->blocker_texts($data));
    }

    // Grades.

    /**
     * A grade below the pass grade, where completion needs a pass: listed once, with the grade.
     */
    public function test_grade_below_pass_is_one_problem_with_the_grade(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $blockers = $this->blockers_containing($data, 'Graded task');
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString(format_float(30, 2), $blockers[0]);
        $this->assertSame(get_string('statecompletefail', 'report_completiondoctor'), $data['activities']['rows'][0]['state']);
    }

    /**
     * Completion only asks for a grade and the gradebook has a passing grade: a lower grade is
     * recorded as failed, or as complete when the grade item is hidden, and either way Moodle
     * completes the activity for the course. The report must not call that a problem.
     */
    public function test_failing_grade_counts_when_completion_only_asks_for_a_grade(): void {
        foreach ([false, true] as $hidden) {
            [$course, $user, $assign] = $this->course_with_graded_assignment(50, false);
            if ($hidden) {
                $this->grade_item($course, $assign)->set_hidden(1);
            }
            $this->grade($course, $assign, $user, 30);

            $data = $this->diagnose($course, $user);

            $this->assertSame([], $data['verdict']['blockers']);
            $this->assertTrue($data['verdict']['iscomplete'], 'Moodle completes the course with the failing grade.');
            $row = $data['activities']['rows'][0];
            $this->assertTrue($row['ok']);
            $this->assertContains(get_string('gradepasstrap', 'report_completiondoctor'), $row['notes']);
            if (!$hidden) {
                $this->assertSame(get_string('statecompletefailing', 'report_completiondoctor'), $row['state']);
                $receivegrade = get_string('detail_desc:receivegrade', 'completion');
                $this->assertSame(
                    [get_string('requirementdonefailing', 'report_completiondoctor', $receivegrade)],
                    $row['requirements']
                );
            }
        }
    }

    /**
     * Completion asks for a pass grade, but the gradebook has none: Moodle counts any grade as
     * complete, so this is a warning on the activity, not a problem.
     */
    public function test_missing_pass_grade_is_a_warning_not_a_problem(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(0, true);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $row = $data['activities']['rows'][0];
        $this->assertTrue($row['ok']);
        $this->assertContains(get_string('gradepassmissing', 'report_completiondoctor'), $row['notes']);
        $this->assertSame([], $this->blockers_containing($data, get_string('gradepassmissing', 'report_completiondoctor')));
        $this->assertTrue($data['verdict']['iscomplete']);
    }

    /**
     * A failing grade in a hidden grade item: one problem, which says the learner cannot see it.
     */
    public function test_failing_grade_in_hidden_grade_item_is_explained(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $this->grade_item($course, $assign)->set_hidden(1);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $row = $data['activities']['rows'][0];
        $this->assertFalse($row['ok']);
        $this->assertContains(get_string('gradehidden', 'report_completiondoctor'), $row['notes']);
        $blockers = $this->blockers_containing($data, 'Graded task');
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString(format_float(30, 2), $blockers[0]);
        $this->assertStringContainsString(get_string('gradehidden', 'report_completiondoctor'), $blockers[0]);
    }

    // Hidden and restricted activities.

    /**
     * A required activity a teacher hid can never be completed: that is the problem, named once.
     */
    public function test_hidden_required_activity_is_named_as_the_cause(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Old induction video']);
        set_coursemodule_visible($cms['Old induction video'], 0);

        $data = $this->diagnose($course, $user);

        $this->assertSame(
            [get_string('problemhidden', 'report_completiondoctor', 'Old induction video')],
            $this->blocker_texts($data)
        );
        $this->assertTrue($data['availability']['rows'][0]['blocks']);
    }

    /**
     * A required activity in a hidden section, likewise, says the section is hidden.
     */
    public function test_required_activity_in_a_hidden_section_is_named_as_the_cause(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Week 9 quiz']);
        $section = get_fast_modinfo($course->id)->get_cm($cms['Week 9 quiz'])->get_section_info();
        \core_courseformat\formatactions::section($course->id)->update($section, ['visible' => 0]);

        $data = $this->diagnose($course, $user);

        $this->assertSame(
            [get_string('problemhiddensection', 'report_completiondoctor', 'Week 9 quiz')],
            $this->blocker_texts($data)
        );
    }

    /**
     * A required activity behind an access restriction: one problem, with the condition.
     */
    public function test_restricted_required_activity_is_one_problem_with_the_condition(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Locked page'], false, $this->tomorrow_only());

        $data = $this->diagnose($course, $user);

        $blockers = $this->blockers_containing($data, 'Locked page');
        $this->assertCount(1, $this->blocker_texts($data));
        $this->assertCount(1, $blockers);
        $this->assertStringStartsWith(
            get_string('problemrestricted', 'report_completiondoctor', (object) ['activity' => 'Locked page', 'reason' => '']),
            $blockers[0]
        );
        $this->assertFalse($data['availability']['allclear']);
        $this->assertTrue($data['availability']['rows'][0]['blocks']);
    }

    /**
     * A restricted activity that is not a course completion condition is shown, but it does
     * not keep the learner from completing the course.
     */
    public function test_restricted_activity_that_is_not_required_is_not_a_problem(): void {
        [$course, $user] = $this->course_with_self_criterion();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Bonus reading',
            'completion' => COMPLETION_TRACKING_MANUAL, 'availability' => $this->tomorrow_only()]);

        $data = $this->diagnose($course, $user);

        $this->assertSame([], $this->blockers_containing($data, 'Bonus reading'));
        $this->assertFalse($data['availability']['allclear']);
        $this->assertFalse($data['availability']['rows'][0]['blocks']);
        $this->assertFalse($data['activities']['rows'][0]['required']);
        $this->assertFalse($data['activities']['rows'][0]['problem']);
    }

    /**
     * A restriction set to hide the activity entirely shows the learner nothing, so the
     * report describes the condition in full, as a sentence.
     */
    public function test_activity_hidden_entirely_by_its_restriction_is_named_with_the_condition(): void {
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() + DAYSECS)],
            \core_availability\tree::OP_AND,
            false
        ));
        [$course, $user] = $this->course_with_activity_criteria(['Hidden quiz'], false, $availability);

        $data = $this->diagnose($course, $user);

        $texts = $this->blocker_texts($data);
        $this->assertCount(1, $texts);
        $a = (object) ['activity' => 'Hidden quiz', 'reason' => ''];
        $prefix = get_string('problemrestricted', 'report_completiondoctor', $a);
        $this->assertStringStartsWith($prefix, $texts[0]);
        $this->assertGreaterThan(strlen($prefix) + 5, strlen($texts[0]), 'The condition is described.');
        $this->assertMatchesRegularExpression('/[.!?]$/', $texts[0]);
        $this->assertCount(1, $data['availability']['rows']);
        $this->assertTrue($data['availability']['rows'][0]['blocks']);
    }

    /**
     * An activity in a restricted section is held back by the section: the section is shown
     * once in the table, and the activity names it in the verdict.
     */
    public function test_activity_in_a_restricted_section_names_the_section(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Week 2 task']);
        $cm = get_fast_modinfo($course->id)->get_cm($cms['Week 2 task']);
        $DB->set_field('course_sections', 'availability', $this->tomorrow_only(), ['id' => $cm->section]);
        rebuild_course_cache($course->id, true);

        $data = $this->diagnose($course, $user);

        $texts = $this->blocker_texts($data);
        $this->assertCount(1, $texts);
        $this->assertStringStartsWith('Week 2 task is in the section', $texts[0]);
        $this->assertCount(1, $data['availability']['rows']);
        $this->assertTrue($data['availability']['rows'][0]['blocks']);
    }

    /**
     * An activity locked until another activity is done waits for that activity: it comes
     * after it in the verdict, with no link to remove the restriction, and the activity it
     * waits for says it keeps it locked.
     */
    public function test_activity_waiting_for_another_activity_is_listed_after_it(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json((int) $assign->cmid, COMPLETION_COMPLETE)]
        ));
        $page = $this->page($course, 'Final reflection', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);
        $this->grade($course, $assign, $user, 30);

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $this->assertCount(2, $blockers);
        $this->assertStringStartsWith('Graded task', $blockers[0]['text']);
        $alsolocks = get_string('alsolocks', 'report_completiondoctor', 'Final reflection');
        $this->assertStringContainsString($alsolocks, $blockers[0]['text']);
        $this->assertStringStartsWith('Final reflection waits for Graded task.', $blockers[1]['text']);
        // The link opens what it waits for, not the restriction.
        $open = (new \moodle_url('/mod/assign/view.php', ['id' => $assign->cmid]))->out(false);
        $this->assertSame([$open], array_column($blockers[1]['actions'], 'url'));
    }

    /**
     * A restriction that also asks for something else, such as a date, is a restriction of
     * its own, with the link to change it.
     */
    public function test_restriction_with_other_conditions_is_not_a_wait(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json((int) $assign->cmid, COMPLETION_COMPLETE),
            \availability_date\condition::get_json('>=', time() + DAYSECS),
        ]));
        $page = $this->page($course, 'Final reflection', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $locked = array_values(array_filter($blockers, fn(array $b): bool => str_starts_with($b['text'], 'Final reflection')));
        $this->assertCount(1, $locked);
        $this->assertStringStartsWith('Final reflection is not available to this learner yet.', $locked[0]['text']);
        // Each condition stays apart in the description.
        $this->assertStringContainsString(get_string('conditionsep', 'report_completiondoctor'), $locked[0]['text']);
        $edit = (new \moodle_url('/course/modedit.php', ['update' => $page->cmid]))->out(false);
        $this->assertSame([$edit], array_column($locked[0]['actions'], 'url'));
    }

    /**
     * Conditions the learner already meets are not what it waits for: with the date passed,
     * only the activity is left.
     */
    public function test_conditions_already_met_are_left_out_of_the_wait(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json((int) $assign->cmid, COMPLETION_COMPLETE),
            \availability_date\condition::get_json('>=', time() - DAYSECS),
        ]));
        $page = $this->page($course, 'Final reflection', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Final reflection waits for Graded task.', end($texts));
    }

    /**
     * With "any" of several activities enough, it waits for one of them, and none of them
     * claims to keep it locked.
     */
    public function test_restriction_needing_any_one_activity_waits_for_one_of_them(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Video A', 'Video B']);
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json($cms['Video A'], COMPLETION_COMPLETE),
            \availability_completion\condition::get_json($cms['Video B'], COMPLETION_COMPLETE),
        ], \core_availability\tree::OP_OR));
        $quiz = $this->page($course, 'Quiz', $availability);
        $this->add_activity_criteria($course, [$quiz->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Quiz waits for one of Video A, Video B.', end($texts));
        $this->assertSame([], array_filter($texts, fn(string $t): bool => str_contains($t, 'keeps')));
    }

    /**
     * A restriction that must not be met, or a negated one, is a restriction to look at.
     */
    public function test_negated_restriction_is_not_a_wait(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Pre-test']);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json($cms['Pre-test'], COMPLETION_INCOMPLETE)]
        ));
        $page = $this->page($course, 'Remedial unit', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);
        $this->complete_activity($course, $cms['Pre-test'], $user);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Remedial unit is not available to this learner yet.', end($texts));
    }

    /**
     * Waiting for an activity no learner can open means it never opens: that comes first,
     * with the link to change the restriction.
     */
    public function test_waiting_for_a_hidden_activity_never_opens(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Unit 6 summary']);
        $old = $this->page($course, 'Old prerequisite');
        set_coursemodule_visible($old->cmid, 0);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json((int) $old->cmid, COMPLETION_COMPLETE)]
        ));
        $this->set_availability($cms['Unit 6 summary'], $availability);

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $never = 'Unit 6 summary waits for Old prerequisite, which learners cannot open';
        $this->assertStringStartsWith($never, $blockers[0]['text']);
        $edit = (new \moodle_url('/course/modedit.php', ['update' => $cms['Unit 6 summary']]))->out(false);
        $this->assertSame([$edit], array_column($blockers[0]['actions'], 'url'));
    }

    /**
     * "The previous activity" is resolved to that activity, as the condition resolves it.
     */
    public function test_previous_activity_condition_waits_for_that_activity(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Lesson 1']);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json(\availability_completion\condition::OPTION_PREVIOUS, COMPLETION_COMPLETE)]
        ));
        $page = $this->page($course, 'Lesson 2', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Lesson 2 waits for Lesson 1.', end($texts));
    }

    /**
     * Activities are named in full, not shortened as core's criterion titles are.
     */
    public function test_long_activity_names_are_not_shortened(): void {
        $name = 'Workplace health and safety induction for new warehouse staff, part one';
        [$course, $user] = $this->course_with_activity_criteria([$name]);

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('problemnotdonemanual', 'report_completiondoctor', $name)], $this->blocker_texts($data));
        $this->assertSame($name, $data['criteria']['rows'][0]['label']);
    }

    /**
     * A viewer who cannot change what needs changing is told whom to ask instead of a link.
     */
    public function test_viewer_without_edit_rights_is_told_whom_to_ask(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Old induction video']);
        set_coursemodule_visible($cms['Old induction video'], 0);
        $askeditor = get_string('askeditor', 'report_completiondoctor');

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $blocker = $this->diagnose($course, $user)['verdict']['blockers'][0];
        $this->assertSame([], $blocker['actions']);
        $this->assertStringEndsWith($askeditor, $blocker['text']);

        $this->setAdminUser();
        $blocker = $this->diagnose($course, $user)['verdict']['blockers'][0];
        $this->assertNotSame([], $blocker['actions']);
        $this->assertStringNotContainsString($askeditor, $blocker['text']);
    }

    /**
     * Where to look in Site administration is said only to those who can go there.
     */
    public function test_task_hints_are_for_administrators_only(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_activity_criteria(['Page A']);
        $hint = get_string('crondisabledhint', 'report_completiondoctor');

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $row = $this->diagnose($course, $user)['cron']['rows'][0];
        $this->assertStringContainsString(get_string('crondisabled', 'report_completiondoctor'), $row['detail']);
        $this->assertStringNotContainsString($hint, $row['detail']);

        $this->setAdminUser();
        $this->assertStringContainsString($hint, $this->diagnose($course, $user)['cron']['rows'][0]['detail']);

        // The same for a late task.
        set_config('cron_enabled', 1);
        $now = time();
        $this->set_task(self::DAILY, ['lastruntime' => $now - 4 * DAYSECS, 'nextruntime' => $now - 3 * DAYSECS]);
        $hint = get_string('taskoverduehint', 'report_completiondoctor');
        $this->assertStringContainsString($hint, $this->task_row($this->diagnose($course, $user), 'taskdaily')['detail']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->assertStringNotContainsString($hint, $this->task_row($this->diagnose($course, $user), 'taskdaily')['detail']);
    }

    /**
     * With completion tracking off in the course, late tasks are not worth a note.
     */
    public function test_no_task_note_while_course_completion_is_off(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_activity_criteria(['Page A']);
        $this->set_course_completion($course, 0);

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('chaincoursedisabled', 'report_completiondoctor')], $this->blocker_texts($data));
        $this->assertSame('', $data['verdict']['tasknote']);
    }

    /**
     * An activity in a restricted subsection is held back by the subsection, which is named.
     */
    public function test_activity_in_a_restricted_subsection_names_the_subsection(): void {
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $subsection = $this->getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 1,
            'name' => 'Assessments', 'availability' => $this->tomorrow_only()]);
        $delegated = get_fast_modinfo($course->id)->get_section_info_by_component('mod_subsection', $subsection->id);
        $quiz = $this->page($course, 'Final quiz', null, (int) $delegated->section);
        $this->add_activity_criteria($course, [$quiz->cmid]);

        $data = $this->diagnose($course, $user);

        $texts = $this->blocker_texts($data);
        $this->assertCount(1, $texts);
        $this->assertStringStartsWith('Final quiz is in the subsection "Assessments"', $texts[0]);
        $this->assertCount(1, $data['availability']['rows']);
        $this->assertTrue($data['availability']['rows'][0]['blocks']);
    }

    /**
     * A section's restriction links to the section's settings, where it is changed.
     */
    public function test_section_restriction_links_to_the_section_settings(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Week 2 task']);
        $cm = get_fast_modinfo($course->id)->get_cm($cms['Week 2 task']);
        $DB->set_field('course_sections', 'availability', $this->tomorrow_only(), ['id' => $cm->section]);
        rebuild_course_cache($course->id, true);

        $actions = $this->diagnose($course, $user)['verdict']['blockers'][0]['actions'];

        $edit = (new \moodle_url('/course/editsection.php', ['id' => $cm->section]))->out(false);
        $this->assertSame([$edit], array_column($actions, 'url'));
    }

    /**
     * A permission that keeps the learner out is named, not blamed on a restriction they meet.
     */
    public function test_permission_override_is_named(): void {
        global $DB;
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() - DAYSECS)]
        ));
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Policy'], false, $availability);
        $context = \context_module::instance($cms['Policy']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        role_change_permission($studentrole, $context, 'mod/page:view', CAP_PROHIBIT);

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('problemnoaccess', 'report_completiondoctor', 'Policy')], array_map(
            fn(string $t): string => substr($t, 0, strlen(get_string('problemnoaccess', 'report_completiondoctor', 'Policy'))),
            $this->blocker_texts($data)
        ));
        $permissions = (new \moodle_url('/admin/roles/permissions.php', ['contextid' => $context->id]))->out(false);
        $this->assertSame([$permissions], array_column($data['verdict']['blockers'][0]['actions'], 'url'));
    }

    /**
     * A required activity whose completion tracking was switched off can never be complete.
     */
    public function test_required_activity_without_completion_tracking_is_named(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Old checklist']);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $cms['Old checklist']]);
        rebuild_course_cache($course->id, true);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertSame([get_string('problemnotracking', 'report_completiondoctor', 'Old checklist')], $texts);
    }

    /**
     * A required activity being deleted can never be complete either.
     */
    public function test_required_activity_being_deleted_is_named(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Retired module']);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $cms['Retired module']]);
        rebuild_course_cache($course->id, true);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertSame([get_string('problemdeleting', 'report_completiondoctor', 'Retired module')], $texts);
    }

    /**
     * Self completion needs the Self completion block, where learners mark the course complete.
     */
    public function test_self_completion_says_whether_the_block_is_there(): void {
        global $DB;
        [$course, $user] = $this->course_with_self_criterion();

        // Off for the whole site, as on every new site since Moodle 4.0.
        $this->assertSame(
            [get_string('problemselfdisabled', 'report_completiondoctor')],
            $this->blocker_texts($this->diagnose($course, $user))
        );
        $DB->set_field('block', 'visible', 1, ['name' => 'selfcompletion']);

        $this->assertSame(
            [get_string('problemselfnoblock', 'report_completiondoctor')],
            $this->blocker_texts($this->diagnose($course, $user))
        );

        $this->getDataGenerator()->create_block('selfcompletion', [
            'parentcontextid' => \context_course::instance($course->id)->id,
            'pagetypepattern' => 'course-view-*',
        ]);
        $this->assertSame(
            [get_string('problemself', 'report_completiondoctor')],
            $this->blocker_texts($this->diagnose($course, $user))
        );
    }

    /**
     * With completion only asking for a grade, a failing grade is not what keeps the activity
     * incomplete; the rule still to do is.
     */
    public function test_failing_grade_is_not_blamed_when_completion_needs_no_pass(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Report',
            'grade' => 100, 'gradepass' => 50, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 0, 'completionsubmit' => 1]);
        $this->add_activity_criteria($course, [$assign->cmid]);
        $this->grade($course, $assign, $user, 30);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertSame([get_string('problemnotdonetodo', 'report_completiondoctor', (object) [
            'activity' => 'Report',
            'todo' => get_string('completiondetail:submit', 'assign'),
        ])], $texts);
    }

    /**
     * All requirements recorded, but the course not marked and Moodle not asked to check the
     * learner again (here a criterion was removed after the learner met the others): waiting
     * for the task will not help, and the verdict says so.
     */
    public function test_met_requirements_that_moodle_will_not_recheck(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A', 'Page B']);
        $this->complete_activity($course, $cms['Page A'], $user);
        $DB->delete_records('course_completion_criteria', ['course' => $course->id, 'moduleinstance' => $cms['Page B']]);

        $data = $this->diagnose($course, $user);

        $this->assertFalse($data['verdict']['iscomplete']);
        $this->assertSame([], $data['verdict']['blockers']);
        $this->assertSame(get_string('verdictnotrechecked', 'report_completiondoctor'), $data['verdict']['message']);
    }

    /**
     * A user who holds the roles of two approval criteria can mark only the first in the
     * course completion report, so only that one links there.
     */
    public function test_approval_link_only_for_the_criterion_the_viewer_can_mark(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $editingteacher = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        role_assign($manager, $teacher->id, \context_course::instance($course->id));
        $data = (object) ['id' => $course->id, 'criteria_role' => [$editingteacher => 1, $manager => 1]];
        (new \completion_criteria_role())->update_config($data);
        $this->setUser($teacher);

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $this->assertCount(2, $blockers);
        $this->assertCount(1, array_filter(array_column($blockers, 'actions')));
    }

    /**
     * In a course that unlocks in order, a step waiting for a step that is itself still
     * locked waits for it: it does not "never open".
     */
    public function test_step_waiting_for_a_locked_step_waits(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Unit 1 page']);
        $unit2 = $this->page($course, 'Unit 2 quiz', $this->after($cms['Unit 1 page']));
        $unit3 = $this->page($course, 'Unit 3 assignment', $this->after((int) $unit2->cmid));
        $this->add_activity_criteria($course, [$unit2->cmid, $unit3->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertCount(3, $texts);
        $this->assertStringStartsWith('Unit 1 page is not complete', $texts[0]);
        $this->assertStringContainsString(get_string('alsolocks', 'report_completiondoctor', 'Unit 2 quiz'), $texts[0]);
        $this->assertStringStartsWith('Unit 2 quiz waits for Unit 1 page.', $texts[1]);
        $this->assertStringStartsWith('Unit 3 assignment waits for Unit 2 quiz.', $texts[2]);
        $this->assertSame([], array_filter($texts, fn(string $t): bool => str_contains($t, 'never opens')));
    }

    /**
     * Waiting for an activity in a section that opens later is a wait too.
     */
    public function test_waiting_for_an_activity_in_a_section_that_opens_later_waits(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reading = $this->page($course, 'Next week reading', null, 1);
        $DB->set_field('course_sections', 'availability', $this->tomorrow_only(), ['course' => $course->id, 'section' => 1]);
        rebuild_course_cache($course->id, true);
        $quiz = $this->page($course, 'Week quiz', $this->after((int) $reading->cmid));
        $this->add_activity_criteria($course, [$reading->cmid, $quiz->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Week quiz waits for Next week reading.', end($texts));
    }

    /**
     * A chain that ends in an activity no learner can open never opens, all the way along.
     */
    public function test_chain_ending_in_a_hidden_activity_never_opens(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Step B']);
        $old = $this->page($course, 'Old step A');
        set_coursemodule_visible($old->cmid, 0);
        $this->set_availability($cms['Step B'], $this->after((int) $old->cmid));
        $stepc = $this->page($course, 'Step C', $this->after($cms['Step B']));
        $this->add_activity_criteria($course, [$stepc->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Step B waits for Old step A, which learners cannot open', $texts[0]);
        $this->assertStringStartsWith('Step C waits for Step B, which can never open', $texts[1]);
    }

    /**
     * "Complete and passed" on an activity with no passing grade can never be met, and a
     * condition on an activity without completion tracking neither: each is a restriction to
     * look at, with the link to change it.
     */
    public function test_condition_the_awaited_activity_can_never_meet_is_a_restriction(): void {
        global $DB;
        [$course, $user, $assign] = $this->course_with_graded_assignment(0, false);
        $passed = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json((int) $assign->cmid, COMPLETION_COMPLETE_PASS)]
        ));
        $unit = $this->page($course, 'Unit 2', $passed);
        $notracked = $this->page($course, 'Notes');
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $notracked->cmid]);
        $later = $this->page($course, 'Unit 3', $this->after((int) $notracked->cmid));
        $this->add_activity_criteria($course, [$unit->cmid, $later->cmid]);
        rebuild_course_cache($course->id, true);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $nopass = get_string('whynopass', 'report_completiondoctor', 'Graded task');
        $notracking = get_string('whynotracking', 'report_completiondoctor', 'Notes');
        $this->assertCount(1, array_filter($texts, fn(string $t): bool => str_starts_with($t, 'Unit 2 can never open')
            && str_contains($t, $nopass)));
        $this->assertCount(1, array_filter($texts, fn(string $t): bool => str_starts_with($t, 'Unit 3 can never open')
            && str_contains($t, $notracking)));
    }

    /**
     * Under "any" aggregation, options no learner can complete are named, and when that is
     * every option the entry says so first.
     */
    public function test_any_aggregation_names_options_that_can_never_be_done(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Reading A', 'Reading B']);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);
        set_coursemodule_visible($cms['Reading A'], 0);
        set_coursemodule_visible($cms['Reading B'], 0);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $this->assertCount(1, $blockers);
        foreach (['Reading A', 'Reading B'] as $name) {
            $this->assertStringContainsString(get_string('problemhidden', 'report_completiondoctor', $name), $blockers[0]['text']);
        }
    }

    /**
     * When the section holding a subsection is hidden, that section is named, not the
     * subsection, and the link opens it on the course page.
     */
    public function test_hidden_section_around_a_subsection_is_named(): void {
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $subsection = $this->getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 1,
            'name' => 'Assessments']);
        $delegated = get_fast_modinfo($course->id)->get_section_info_by_component('mod_subsection', $subsection->id);
        $quiz = $this->page($course, 'Final quiz', null, (int) $delegated->section);
        $this->add_activity_criteria($course, [$quiz->cmid]);
        $week = get_fast_modinfo($course->id)->get_section_info(1);
        \core_courseformat\formatactions::section($course->id)->update($week, ['visible' => 0]);

        $blocker = $this->diagnose($course, $user)['verdict']['blockers'][0];

        $hidden = get_string('problemhiddensection', 'report_completiondoctor', 'Final quiz');
        $this->assertStringStartsWith($hidden, $blocker['text']);
        $this->assertContains(course_get_url($course->id, 1)->out(false), array_column($blocker['actions'], 'url'));
    }

    /**
     * Self completion counts a block shown on course pages from the category, and not a block
     * a teacher hid in the course.
     */
    public function test_self_completion_block_as_learners_see_it(): void {
        global $DB;
        [$course, $user] = $this->course_with_self_criterion();
        $DB->set_field('block', 'visible', 1, ['name' => 'selfcompletion']);
        $categorycontext = \context_coursecat::instance($course->category);
        $block = $this->getDataGenerator()->create_block('selfcompletion', [
            'parentcontextid' => $categorycontext->id,
            'showinsubcontexts' => 1,
            'pagetypepattern' => 'course-view-*',
        ]);
        $this->assertSame(
            [get_string('problemself', 'report_completiondoctor')],
            $this->blocker_texts($this->diagnose($course, $user))
        );

        $DB->insert_record('block_positions', ['blockinstanceid' => $block->id,
            'contextid' => \context_course::instance($course->id)->id, 'pagetype' => 'course-view-topics', 'subpage' => '',
            'visible' => 0, 'region' => 'side-pre', 'weight' => 0]);
        $this->assertSame(
            [get_string('problemselfnoblock', 'report_completiondoctor')],
            $this->blocker_texts($this->diagnose($course, $user))
        );
    }

    /**
     * A restriction with several conditions and a nested set reads as one sentence, with the
     * set in brackets and no doubled separators.
     */
    public function test_nested_restriction_reads_cleanly(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Quiz A']);
        $nested = \core_availability\tree::get_nested_json([
            \availability_date\condition::get_json('>=', time() + DAYSECS),
            \availability_date\condition::get_json('>=', time() + 2 * DAYSECS),
        ], \core_availability\tree::OP_OR);
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json($cms['Quiz A'], COMPLETION_COMPLETE),
            $nested,
        ]));
        $page = $this->page($course, 'Page E', $availability);
        $this->add_activity_criteria($course, [$page->cmid]);

        $texts = $this->blockers_containing($this->diagnose($course, $user), 'Page E');
        $text = reset($texts);

        $this->assertMatchesRegularExpression('/\(.+; .+\)/', $text);
        $this->assertStringNotContainsString(' ;', $text);
        $this->assertStringNotContainsString('; ;', $text);
        $this->assertStringNotContainsString(';;', $text);
    }

    /**
     * A prerequisite course is named in full.
     */
    public function test_prerequisite_course_is_named_in_full(): void {
        global $CFG;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_course.php');
        $name = 'Workplace health and safety induction 2026 for warehouse staff';
        $prerequisite = $this->getDataGenerator()->create_course(['fullname' => $name, 'enablecompletion' => 1]);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $data = (object) ['id' => $course->id, 'criteria_course' => [$prerequisite->id]];
        (new \completion_criteria_course())->update_config($data);

        $data = $this->diagnose($course, $user);

        $this->assertSame($name, $data['criteria']['rows'][0]['label']);
        $this->assertStringContainsString($name, $data['verdict']['blockers'][0]['text']);
    }

    /**
     * The permissions link is only for a viewer who can change an override there; a teacher
     * who can only look is told whom to ask.
     */
    public function test_permissions_link_only_for_those_who_can_change_it(): void {
        global $DB;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Policy']);
        $context = \context_module::instance($cms['Policy']);
        role_change_permission(
            (int) $DB->get_field('role', 'id', ['shortname' => 'student']),
            $context,
            'mod/page:view',
            CAP_PROHIBIT
        );

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $blocker = $this->diagnose($course, $user)['verdict']['blockers'][0];
        $this->assertSame([], $blocker['actions']);
        $this->assertStringEndsWith(get_string('askeditor', 'report_completiondoctor'), $blocker['text']);
    }

    /**
     * A grade below the passing grade names the other rules still to do as well.
     */
    public function test_grade_cause_names_the_other_rules_still_to_do(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Report',
            'grade' => 100, 'gradepass' => 50, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1, 'completionsubmit' => 1]);
        $this->add_activity_criteria($course, [$assign->cmid]);
        $this->grade($course, $assign, $user, 30);

        $text = $this->blocker_texts($this->diagnose($course, $user))[0];

        $this->assertStringContainsString(format_float(30, 2), $text);
        $this->assertStringContainsString(
            get_string('stilltodo', 'report_completiondoctor', get_string('completiondetail:submit', 'assign')),
            $text
        );
    }

    /**
     * An activity that keeps a required activity locked says so in both tables.
     */
    public function test_activity_keeping_another_locked_says_so(): void {
        [$course, $user] = $this->course_with_self_criterion();
        $warmup = $this->page($course, 'Optional warm-up', $this->tomorrow_only());
        $quiz = $this->page($course, 'Unit 7 quiz', $this->after((int) $warmup->cmid));
        $this->add_activity_criteria($course, [$quiz->cmid]);

        $data = $this->diagnose($course, $user);

        $keeps = get_string('keepslocked', 'report_completiondoctor', 'Unit 7 quiz');
        $rows = array_filter($data['activities']['rows'], fn(array $r): bool => $r['activity'] === 'Optional warm-up');
        $row = reset($rows);
        $this->assertContains($keeps, $row['notes']);
        $this->assertTrue($row['problem']);
        $restricted = array_filter($data['availability']['rows'], fn(array $r): bool => str_starts_with($r['label'], 'Optional'));
        $restricted = reset($restricted);
        $this->assertTrue($restricted['blocks']);
        $this->assertStringContainsString($keeps, $restricted['detail']);
    }

    /**
     * A weekly course where each week waits for two of last week's activities is worked out
     * once per activity, not once per path: the report stays fast however long the course.
     */
    public function test_long_chains_are_worked_out_once(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 0]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $previous = [];
        $cmids = [];
        for ($week = 1; $week <= 15; $week++) {
            $availability = $previous ? json_encode(\core_availability\tree::get_root_json(array_map(
                fn(int $cmid): \stdClass => \availability_completion\condition::get_json($cmid, COMPLETION_COMPLETE),
                $previous
            ))) : null;
            $previous = [];
            foreach (['reading', 'task'] as $kind) {
                $page = $this->page($course, "Week {$week} {$kind}", $availability);
                $previous[] = (int) $page->cmid;
                $cmids[] = (int) $page->cmid;
            }
        }
        $this->add_activity_criteria($course, $cmids);

        $engine = new class (get_course($course->id), $user) extends engine {
            /** @var int How many single conditions were checked. */
            public $checks = 0;

            /**
             * Count each single condition check.
             *
             * @param \stdClass $node
             * @param \core_availability\info $info
             * @return bool
             */
            protected function condition_met(\stdClass $node, \core_availability\info $info): bool {
                $this->checks++;
                return parent::condition_met($node, $info);
            }
        };
        $data = $engine->diagnose();

        // 28 restricted activities with two conditions each: a few checks each, not millions.
        $this->assertLessThan(200, $engine->checks);
        $this->assertCount(30, $data['verdict']['blockers']);
        $this->assertSame([], array_filter($this->blocker_texts($data), fn(string $t): bool => str_contains($t, 'never')));
    }

    /**
     * Two activities waiting for each other can never open, and say so.
     */
    public function test_activities_waiting_for_each_other_never_open(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Loop A', 'Loop B']);
        $this->set_availability($cms['Loop A'], $this->after($cms['Loop B']));
        $this->set_availability($cms['Loop B'], $this->after($cms['Loop A']));

        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];

        $this->assertCount(2, $blockers);
        foreach ($blockers as $blocker) {
            $this->assertStringContainsString('wait for each other, so neither can ever open', $blocker['text']);
            $this->assertNotSame([], $blocker['actions']);
        }
    }

    /**
     * A section whose restriction waits for an activity inside it can never open.
     */
    public function test_section_waiting_for_its_own_activity_never_opens(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->page($course, 'Week 3 quiz', null, 1);
        $DB->set_field(
            'course_sections',
            'availability',
            $this->after((int) $quiz->cmid),
            ['course' => $course->id, 'section' => 1]
        );
        rebuild_course_cache($course->id, true);
        $this->add_activity_criteria($course, [$quiz->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith(get_string(
            'problemlockedself',
            'report_completiondoctor',
            (object) ['activity' => 'Week 3 quiz', 'reason' => '']
        ), $texts[0]);
    }

    /**
     * Only a completion condition's lock matters: an optional activity waiting for another
     * one leaves both out of the problems.
     */
    public function test_optional_chain_marks_nothing_as_a_problem(): void {
        [$course, $user] = $this->course_with_self_criterion();
        $first = $this->page($course, 'Optional one', $this->tomorrow_only());
        $this->page($course, 'Optional two', $this->after((int) $first->cmid));

        $data = $this->diagnose($course, $user);

        foreach ($data['activities']['rows'] as $row) {
            $this->assertFalse($row['problem'], $row['activity']);
        }
        foreach ($data['availability']['rows'] as $row) {
            $this->assertFalse($row['blocks'], $row['label']);
        }
    }

    /**
     * A nested set the learner already meets does not stop a wait.
     */
    public function test_met_nested_set_still_waits(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Quiz A']);
        $nested = \core_availability\tree::get_nested_json([
            \availability_date\condition::get_json('>=', time() - DAYSECS),
        ], \core_availability\tree::OP_OR);
        $page = $this->page($course, 'Page E', json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json($cms['Quiz A'], COMPLETION_COMPLETE),
            $nested,
        ])));
        $this->add_activity_criteria($course, [$page->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringStartsWith('Page E waits for Quiz A.', end($texts));
    }

    /**
     * A negated restriction is a restriction to look at, never a wait.
     */
    public function test_negated_tree_is_a_restriction(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Quiz A']);
        $page = $this->page($course, 'Page F', json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() - DAYSECS)],
            \core_availability\tree::OP_NOT_AND
        )));
        $this->add_activity_criteria($course, [$page->cmid]);

        $texts = $this->blockers_containing($this->diagnose($course, $user), 'Page F');

        $this->assertStringStartsWith('Page F is not available to this learner yet.', $texts[0]);
    }

    /**
     * Under "any" with one option that can never be done and one plain criterion, the entry
     * keeps the not-done order and names the impossible option once.
     */
    public function test_any_with_one_impossible_option_keeps_its_order(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Reading A', 'Reading B']);
        $this->set_aggregation($course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY, COMPLETION_AGGREGATION_ANY);
        set_coursemodule_visible($cms['Reading A'], 0);
        $hidden = $this->page($course, 'Hidden extra');
        set_coursemodule_visible($hidden->cmid, 0);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertCount(1, $texts);
        $this->assertStringContainsString(get_string('problemhidden', 'report_completiondoctor', 'Reading A'), $texts[0]);
        $this->assertSame(1, substr_count($texts[0], get_string('askeditor', 'report_completiondoctor')));
    }

    /**
     * "Any one of" with only one way left is a plain wait for that one, which then keeps it locked.
     */
    public function test_any_with_one_way_left_is_a_plain_wait(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Intro reading']);
        $old = $this->page($course, 'Old video');
        set_coursemodule_visible($old->cmid, 0);
        $unit = $this->page($course, 'Unit 7', json_encode(\core_availability\tree::get_root_json([
            \availability_completion\condition::get_json($cms['Intro reading'], COMPLETION_COMPLETE),
            \availability_completion\condition::get_json((int) $old->cmid, COMPLETION_COMPLETE),
        ], \core_availability\tree::OP_OR)));
        $this->add_activity_criteria($course, [$unit->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertStringContainsString(get_string('alsolocks', 'report_completiondoctor', 'Unit 7'), $texts[0]);
        $this->assertStringStartsWith('Unit 7 waits for Intro reading.', end($texts));
    }

    /**
     * A minimum grade on another activity is a wait for it; a completion condition on an
     * activity that failed but counts as complete is a restriction.
     */
    public function test_grade_minimum_waits_and_failed_but_counted_does_not(): void {
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, false);
        $item = $this->grade_item($course, $assign);
        $mingrade = $this->page($course, 'After the grade', json_encode(\core_availability\tree::get_root_json(
            [\availability_grade\condition::get_json((int) $item->id, 60, null)]
        )));
        $completed = $this->page($course, 'After completion', $this->after((int) $assign->cmid));
        $this->add_activity_criteria($course, [$mingrade->cmid, $completed->cmid]);
        $this->grade($course, $assign, $user, 30);

        $data = $this->diagnose($course, $user);

        $this->assertCount(1, $this->blockers_containing($data, 'After the grade waits for Graded task.'));
        $this->assertCount(1, $this->blockers_containing($data, 'After completion is not available to this learner yet.'));
    }

    /**
     * A teacher who cannot edit the course is told who can change a restriction.
     */
    public function test_restriction_tells_a_non_editing_teacher_whom_to_ask(): void {
        [$course, $user] = $this->course_with_activity_criteria(['Locked page'], false, $this->tomorrow_only());
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $text = $this->blocker_texts($this->diagnose($course, $user))[0];

        $this->assertStringEndsWith(get_string('askeditorrestriction', 'report_completiondoctor'), $text);
    }

    /**
     * Names with markup are shown as text, never as HTML, in every part of the report.
     */
    public function test_names_with_markup_are_escaped_in_the_report(): void {
        global $OUTPUT;
        [$course, $user] = $this->course_with_activity_criteria(['Q&A <b>bold</b>']);

        $html = $OUTPUT->render_from_template('report_completiondoctor/report', $this->diagnose($course, $user));

        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('Q&amp;A', $html);
    }

    // The verdict.

    /**
     * The verdict lists the most fundamental problem first: a required activity no learner can
     * open, then a failing grade, then what the learner has not done yet.
     */
    public function test_problems_are_listed_most_fundamental_first(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Reading', 'Hidden video']);
        set_coursemodule_visible($cms['Hidden video'], 0);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Graded task',
            'grade' => 100, 'gradepass' => 50, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1]);
        $this->add_activity_criteria($course, [$assign->cmid]);
        $this->grade($course, $assign, $user, 30);

        $restricted = $this->page($course, 'Opens tomorrow', $this->tomorrow_only());
        $locked = $this->page($course, 'After reading', json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json($cms['Reading'], COMPLETION_COMPLETE)]
        )));
        $this->add_activity_criteria($course, [$restricted->cmid, $locked->cmid]);

        $texts = $this->blocker_texts($this->diagnose($course, $user));

        $this->assertCount(5, $texts);
        $this->assertStringStartsWith('Hidden video', $texts[0]);
        $this->assertStringStartsWith('Graded task', $texts[1]);
        $this->assertStringStartsWith('Opens tomorrow', $texts[2]);
        $this->assertStringStartsWith('Reading', $texts[3]);
        $this->assertStringStartsWith('After reading', $texts[4]);
    }

    /**
     * Each problem links to where it is fixed, but only for a viewer who may change it there.
     */
    public function test_links_are_given_to_viewers_who_may_use_them(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $expected = (new \moodle_url('/course/completion.php', ['id' => $course->id]))->out(false);

        $this->setAdminUser();
        $actions = $this->diagnose($course, $user)['verdict']['blockers'][0]['actions'];
        $this->assertSame([$expected], array_column($actions, 'url'));
        $this->assertSame([get_string('linkcompletionsettings', 'report_completiondoctor')], array_column($actions, 'label'));

        $this->setUser($teacher);
        $this->assertSame([], $this->diagnose($course, $user)['verdict']['blockers'][0]['actions']);
    }

    /**
     * Teacher approval says who marks it and where, and links to that report only for a
     * viewer who holds the role, as only they can mark it there.
     */
    public function test_approval_says_who_marks_it_and_where(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $data = (object) ['id' => $course->id, 'criteria_role' => [$roleid => 1]];
        (new \completion_criteria_role())->update_config($data);

        $this->setUser($teacher);
        $blockers = $this->diagnose($course, $user)['verdict']['blockers'];
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Reports > Course completion', $blockers[0]['text']);
        $expected = (new \moodle_url('/report/completion/index.php', ['course' => $course->id]))->out(false);
        $this->assertSame([$expected], array_column($blockers[0]['actions'], 'url'));

        // The administrator may open the report but does not hold the role, so cannot mark it.
        $this->setAdminUser();
        $this->assertSame([], $this->diagnose($course, $user)['verdict']['blockers'][0]['actions']);
    }

    /**
     * Late tasks do not crowd out the real problems: while anything else is missing they are
     * a short note, and teachers are told to ask their site administrator.
     */
    public function test_late_tasks_are_a_note_while_anything_else_is_missing(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_activity_criteria(['Page A']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $data = $this->diagnose($course, $user);

        $this->assertSame([get_string('problemnotdonemanual', 'report_completiondoctor', 'Page A')], $this->blocker_texts($data));
        $this->assertStringStartsWith(get_string('verdicttasknote', 'report_completiondoctor'), $data['verdict']['tasknote']);
        $this->assertStringContainsString(get_string('taskaskadmin', 'report_completiondoctor'), $data['verdict']['tasknote']);
    }

    /**
     * When everything else is done, late tasks are the answer.
     */
    public function test_late_tasks_are_the_answer_when_everything_else_is_done(): void {
        set_config('cron_enabled', 0);
        [$course, $user] = $this->course_with_requirements_met();
        $this->setAdminUser();

        $data = $this->diagnose($course, $user);

        $this->assertSame(get_string('verdictwaitingfortasks', 'report_completiondoctor'), $data['verdict']['message']);
        $this->assertSame('', $data['verdict']['tasknote']);
        $actions = $data['verdict']['blockers'][0]['actions'];
        $expected = (new \moodle_url('/admin/settings.php', ['section' => 'taskprocessing']))->out(false);
        $this->assertSame([$expected], array_column($actions, 'url'));
    }

    /**
     * A learner whose course completion is recorded has no problems.
     */
    public function test_completed_course_has_no_problems(): void {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A']);
        $this->complete_activity($course, $cms['Page A'], $user);
        (new \completion_completion(['course' => $course->id, 'userid' => $user->id]))->mark_complete();

        $data = $this->diagnose($course, $user);

        $this->assertTrue($data['verdict']['iscomplete']);
        $this->assertFalse($data['verdict']['hasblockers']);
        $this->assertSame(get_string('verdictcomplete', 'report_completiondoctor'), $data['verdict']['message']);
    }

    // The activity table.

    /**
     * Requirements are described in Moodle's own words, each with the learner's status, and
     * an assignment's submission status is the one the assignment shows.
     */
    public function test_requirements_and_submission_status_are_in_words(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $generator->create_module('assign', ['course' => $course->id, 'name' => 'Essay',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Rules',
            'completion' => COMPLETION_TRACKING_MANUAL]);

        $rows = $this->diagnose($course, $user)['activities']['rows'];

        $this->assertSame(
            [get_string('requirementnotdone', 'report_completiondoctor', get_string('completiondetail:submit', 'assign'))],
            $rows[0]['requirements']
        );
        $this->assertContains(
            get_string('assignsubmission', 'report_completiondoctor', get_string('nosubmission', 'report_completiondoctor')),
            $rows[0]['notes']
        );
        $this->assertSame([get_string('requiremanual', 'report_completiondoctor')], $rows[1]['requirements']);
        $this->assertFalse($rows[0]['required']);
        $this->assertFalse($rows[0]['problem']);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('completion', implode(' ', $row['requirements']));
        }
    }

    /**
     * The activity table marks completion conditions and the ones that are a problem, shows a
     * failed passing grade as failed, and gives a new submission the assignment's wording.
     */
    public function test_activity_table_marks_conditions_and_failures(): void {
        global $DB;
        [$course, $user, $assign] = $this->course_with_graded_assignment(50, true);
        $this->grade($course, $assign, $user, 30);
        $DB->insert_record('assign_submission', ['assignment' => $assign->id, 'userid' => $user->id, 'status' => 'new',
            'latest' => 1, 'attemptnumber' => 0, 'timecreated' => time(), 'timemodified' => time()]);

        $row = $this->diagnose($course, $user)['activities']['rows'][0];

        $this->assertTrue($row['required']);
        $this->assertTrue($row['problem']);
        $this->assertSame(get_string('statecompletefail', 'report_completiondoctor'), $row['state']);
        $this->assertContains(
            get_string('requirementfailed', 'report_completiondoctor', get_string('detail_desc:receivepassgrade', 'completion')),
            $row['requirements']
        );
        $this->assertContains(
            get_string('assignsubmission', 'report_completiondoctor', get_string('submissionstatus_new', 'assign')),
            $row['notes']
        );
    }

    /**
     * The report template shows each problem with its links, and names each table row.
     */
    public function test_report_shows_problems_with_their_links(): void {
        global $OUTPUT;
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Old induction video']);
        set_coursemodule_visible($cms['Old induction video'], 0);
        $this->setAdminUser();

        $html = $OUTPUT->render_from_template('report_completiondoctor/report', $this->diagnose($course, $user));

        $edit = new \moodle_url('/course/modedit.php', ['update' => $cms['Old induction video']]);
        $this->assertStringContainsString('href="' . $edit->out() . '"', $html);
        $this->assertStringContainsString(get_string('linkactivitysettings', 'report_completiondoctor'), $html);
        $this->assertStringContainsString('<th scope="row"><span class="report-completiondoctor-status">', $html);
        $this->assertStringContainsString('aria-label="' . s(get_string('linkactivitysettings', 'report_completiondoctor')
            . get_string('labelsep', 'langconfig') . 'Old induction video') . '"', $html);
        $this->assertStringContainsString(s(get_string('problemhidden', 'report_completiondoctor', 'Old induction video')), $html);
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
     * The texts of the verdict entries, in order.
     *
     * @param array $data Engine output.
     * @return string[]
     */
    private function blocker_texts(array $data): array {
        return array_column($data['verdict']['blockers'], 'text');
    }

    /**
     * Verdict entries whose text contains a piece of text.
     *
     * @param array $data Engine output.
     * @param string $needle
     * @return string[]
     */
    private function blockers_containing(array $data, string $needle): array {
        return array_values(array_filter(
            $this->blocker_texts($data),
            fn(string $text): bool => str_contains($text, $needle)
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
     * Switch completion tracking on or off in a course.
     *
     * @param \stdClass $course
     * @param int $enabled 1 or 0.
     */
    private function set_course_completion(\stdClass $course, int $enabled): void {
        global $DB;
        $DB->set_field('course', 'enablecompletion', $enabled, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
    }

    /**
     * A page with manual completion.
     *
     * @param \stdClass $course
     * @param string $name
     * @param string|null $availability Access restriction JSON.
     * @param int $section Section number.
     * @return \stdClass The page, with cmid.
     */
    private function page(\stdClass $course, string $name, ?string $availability = null, int $section = 0): \stdClass {
        return $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => $name,
            'completion' => COMPLETION_TRACKING_MANUAL, 'availability' => $availability, 'section' => $section]);
    }

    /**
     * Make more activities course completion criteria.
     *
     * @param \stdClass $course
     * @param int[] $cmids Activities that are not criteria yet.
     */
    private function add_activity_criteria(\stdClass $course, array $cmids): void {
        $data = (object) ['id' => $course->id, 'criteria_activity' => array_fill_keys($cmids, 1)];
        (new \completion_criteria_activity())->update_config($data);
    }

    /**
     * A restriction that opens once another activity is complete, as availability JSON.
     *
     * @param int $cmid
     * @return string
     */
    private function after(int $cmid): string {
        return json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json($cmid, COMPLETION_COMPLETE)]
        ));
    }

    /**
     * Change an activity's access restriction.
     *
     * @param int $cmid
     * @param string $availability Access restriction JSON.
     */
    private function set_availability(int $cmid, string $availability): void {
        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Record an activity as complete for the learner, as a bulk update does: without
     * recording the course completion criterion or checking the course.
     *
     * @param int $cmid
     * @param \stdClass $user
     */
    private function record_activity_only(int $cmid, \stdClass $user): void {
        global $DB;
        $DB->insert_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time()]);
        \cache::make('core', 'completion')->purge();
    }

    /**
     * An access restriction that opens tomorrow, as availability JSON.
     *
     * @return string
     */
    private function tomorrow_only(): string {
        return json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() + DAYSECS)]
        ));
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
     * A course whose one activity criterion the learner has met, not yet marked complete.
     *
     * @return array [course, user]
     */
    private function course_with_requirements_met(): array {
        [$course, $user, $cms] = $this->course_with_activity_criteria(['Page A']);
        // Completing the activity the usual way would also record the criterion and the
        // course at once.
        $this->record_activity_only($cms['Page A'], $user);
        return [$course, $user];
    }

    /**
     * A course with one page per name, each an activity completion criterion.
     *
     * @param string[] $names Page names.
     * @param bool $withself Also add the self completion criterion.
     * @param string|null $availability Access restriction JSON for every page.
     * @return array [course, user, cmids keyed by name]
     */
    private function course_with_activity_criteria(array $names, bool $withself = false, ?string $availability = null): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $cms = [];
        foreach ($names as $name) {
            $page = $generator->create_module('page', ['course' => $course->id, 'name' => $name,
                'completion' => COMPLETION_TRACKING_MANUAL, 'availability' => $availability]);
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
     * A course with an assignment that needs a grade for completion.
     *
     * @param int $gradepass Pass grade in the gradebook (0 for none).
     * @param bool $needspass Whether completion asks for the passing grade, not only a grade.
     * @return array [course, user, assign]
     */
    private function course_with_graded_assignment(int $gradepass, bool $needspass): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Graded task',
            'grade' => 100, 'gradepass' => $gradepass, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => (int) $needspass]);
        $data = (object) ['id' => $course->id, 'criteria_activity' => [$assign->cmid => 1]];
        (new \completion_criteria_activity())->update_config($data);
        return [$course, $user, $assign];
    }

    /**
     * The assignment's grade item.
     *
     * @param \stdClass $course
     * @param \stdClass $assign
     * @return \grade_item
     */
    private function grade_item(\stdClass $course, \stdClass $assign): \grade_item {
        return \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0]);
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
        $this->grade_item($course, $assign)->update_final_grade($user->id, $grade, 'test');
    }
}
