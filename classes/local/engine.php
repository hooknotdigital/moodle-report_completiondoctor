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

use completion_info;
use grade_item;
use grade_grade;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Diagnostic engine: answers "why is this learner not marked complete?".
 *
 * Read-only. Gathers the full completion picture for one user in one course
 * and produces template-ready data plus an ordered list of blockers.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var \stdClass Course record. */
    protected $course;

    /** @var \stdClass User record. */
    protected $user;

    /** @var completion_info */
    protected $completion;

    /** @var \course_modinfo Modinfo computed for the diagnosed user. */
    protected $modinfo;

    /** @var string[] Ordered plain-English blockers, populated by the checks. */
    protected $blockers = [];

    /**
     * Set up a diagnosis of one learner in one course.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     */
    public function __construct(\stdClass $course, \stdClass $user) {
        $this->course = $course;
        $this->user = $user;
        $this->completion = new completion_info($course);
        $this->modinfo = get_fast_modinfo($course, $user->id);
    }

    /**
     * Run every check and return template-ready data.
     *
     * @return array Data for templates/report.mustache.
     */
    public function diagnose(): array {
        $chain = $this->check_chain();
        $criteria = $this->check_criteria();
        $activities = $this->check_activities();
        $availability = $this->check_availability();
        $cron = $this->check_cron();
        $verdict = $this->build_verdict();
        $coursecontext = \context_course::instance($this->course->id);

        return [
            'heading' => get_string('reporttitle', 'report_completiondoctor', (object) [
                'user' => fullname($this->user),
                'course' => $this->plain(format_string($this->course->fullname, true, ['context' => $coursecontext])),
            ]),
            'chain' => $chain,
            'criteria' => $criteria,
            'activities' => $activities,
            'availability' => $availability,
            'cron' => $cron,
            'verdict' => $verdict,
        ];
    }

    /**
     * Section 1: the chain of settings that must be on: site, course, criteria.
     *
     * @return array
     */
    protected function check_chain(): array {
        $rows = [];

        $siteenabled = completion_info::is_enabled_for_site();
        $rows[] = $this->row(
            get_string('sitecompletion', 'report_completiondoctor'),
            $siteenabled,
            $siteenabled ? get_string('enabled', 'report_completiondoctor')
                         : get_string('disabled', 'report_completiondoctor')
        );
        if (!$siteenabled) {
            $this->blockers[] = get_string('chainsitedisabled', 'report_completiondoctor');
        }

        $courseenabled = !empty($this->course->enablecompletion);
        $rows[] = $this->row(
            get_string('coursecompletion', 'report_completiondoctor'),
            $courseenabled,
            $courseenabled ? get_string('enabled', 'report_completiondoctor')
                           : get_string('disabled', 'report_completiondoctor')
        );
        if ($siteenabled && !$courseenabled) {
            $this->blockers[] = get_string('chaincoursedisabled', 'report_completiondoctor');
        }

        $hascriteria = $this->completion->has_criteria();
        $rows[] = $this->row(
            get_string('coursecriteriaset', 'report_completiondoctor'),
            $hascriteria,
            $hascriteria ? get_string('enabled', 'report_completiondoctor')
                         : get_string('notset', 'report_completiondoctor')
        );
        if ($siteenabled && $courseenabled && !$hascriteria) {
            $this->blockers[] = get_string('chainnocriteria', 'report_completiondoctor');
        }

        return ['rows' => $rows];
    }

    /**
     * Section 2: each course completion criterion with the user's status.
     *
     * Blockers follow core's aggregation (aggregate_completions() in lib/completionlib.php):
     * activity, prerequisite course and role criteria are first combined within their
     * type by that type's method, and each result, together with every other criterion
     * on its own, is then combined by the overall method.
     *
     * @return array
     */
    protected function check_criteria(): array {
        $items = $this->completion->has_criteria() ? $this->criteria_items() : [];
        $aggregation = [];

        if ($items) {
            $overall = (int) $this->completion->get_aggregation_method();
            $aggregation[] = get_string(
                $overall == COMPLETION_AGGREGATION_ANY ? 'aggregationany' : 'aggregationall',
                'report_completiondoctor'
            );
            $parts = [];
            foreach ($this->group_by_type($items) as $type => $group) {
                if ($type === 0) {
                    foreach ($group as $item) {
                        $parts[] = $this->single_part($item);
                    }
                    continue;
                }
                $method = (int) $this->completion->get_aggregation_method($type);
                if (count($group) > 1) {
                    $aggregation[] = get_string(
                        $method == COMPLETION_AGGREGATION_ANY ? 'aggregationtypeany' : 'aggregationtypeall',
                        'report_completiondoctor',
                        $group[0]->type
                    );
                }
                $parts[] = $this->type_part($method, $group);
            }
            $this->add_criteria_blockers($overall, $parts);
        }

        $iscoursecomplete = $this->completion->is_course_complete($this->user->id);
        return [
            'aggregation' => implode(' ', $aggregation),
            'rows' => array_column($items, 'row'),
            'coursecomplete' => $iscoursecomplete,
            'coursecompletelabel' => get_string('coursecompletestatus', 'report_completiondoctor'),
            'coursecompletestatus' => $iscoursecomplete
                ? get_string('complete', 'report_completiondoctor')
                : get_string('incomplete', 'report_completiondoctor'),
        ];
    }

    /**
     * One item per course completion criterion, with its table row.
     *
     * @return \stdClass[] Each with criteriatype, type, title, complete and row.
     */
    protected function criteria_items(): array {
        $items = [];
        foreach ($this->completion->get_completions($this->user->id) as $completion) {
            $criteria = $completion->get_criteria();
            $iscomplete = $completion->is_complete();
            $detail = $criteria->get_title_detailed();
            if ($iscomplete && !empty($completion->timecompleted)) {
                $date = userdate($completion->timecompleted, get_string('strftimedate', 'langconfig'));
                $detail .= '. ' . get_string('completedon', 'report_completiondoctor', $date);
            }
            $items[] = (object) [
                'criteriatype' => (int) $criteria->criteriatype,
                'type' => $this->plain($criteria->get_type_title()),
                'title' => $this->plain($criteria->get_title_detailed()),
                'complete' => $iscomplete,
                'row' => $this->row($criteria->get_type_title(), $iscomplete, $detail),
            ];
        }
        return $items;
    }

    /**
     * Group items the way core aggregates them.
     *
     * @param \stdClass[] $items From criteria_items().
     * @return array Key 0 holds criteria aggregated on their own; the other keys are
     *     the criteria types combined within their type first. Empty groups are left out.
     */
    protected function group_by_type(array $items): array {
        $groups = [
            0 => [],
            COMPLETION_CRITERIA_TYPE_ACTIVITY => [],
            COMPLETION_CRITERIA_TYPE_COURSE => [],
            COMPLETION_CRITERIA_TYPE_ROLE => [],
        ];
        foreach ($items as $item) {
            $groups[array_key_exists($item->criteriatype, $groups) ? $item->criteriatype : 0][] = $item;
        }
        return array_filter($groups);
    }

    /**
     * One criterion that goes straight into the overall aggregation.
     *
     * @param \stdClass $item
     * @return \stdClass Part with complete, choice and missing.
     */
    protected function single_part(\stdClass $item): \stdClass {
        $a = (object) ['type' => $item->type, 'title' => $item->title];
        return (object) [
            'complete' => $item->complete,
            'choice' => $item->type . ': ' . $item->title,
            'missing' => [get_string('blockercriterion', 'report_completiondoctor', $a)],
        ];
    }

    /**
     * The criteria of one type, combined by that type's method.
     *
     * @param int $method COMPLETION_AGGREGATION_ALL or COMPLETION_AGGREGATION_ANY.
     * @param \stdClass[] $group Items of one type.
     * @return \stdClass Part with complete, choice and missing.
     */
    protected function type_part(int $method, array $group): \stdClass {
        $a = (object) ['type' => $group[0]->type, 'titles' => implode(', ', array_column($group, 'title'))];
        $done = array_filter($group, fn(\stdClass $item): bool => $item->complete);
        if ($method == COMPLETION_AGGREGATION_ANY) {
            return (object) [
                'complete' => !empty($done),
                'choice' => get_string('choiceanyof', 'report_completiondoctor', $a),
                'missing' => [get_string('blockeranyof', 'report_completiondoctor', $a)],
            ];
        }
        $missing = [];
        foreach ($group as $item) {
            if (!$item->complete) {
                $b = (object) ['type' => $item->type, 'title' => $item->title];
                $missing[] = get_string('blockercriterion', 'report_completiondoctor', $b);
            }
        }
        return (object) [
            'complete' => empty($missing),
            'choice' => get_string('choiceallof', 'report_completiondoctor', $a),
            'missing' => $missing,
        ];
    }

    /**
     * Add blockers for the parts the overall method still needs.
     *
     * @param int $overall COMPLETION_AGGREGATION_ALL or COMPLETION_AGGREGATION_ANY.
     * @param \stdClass[] $parts
     */
    protected function add_criteria_blockers(int $overall, array $parts): void {
        $met = array_filter($parts, fn(\stdClass $part): bool => $part->complete);
        if ($overall == COMPLETION_AGGREGATION_ANY) {
            if (!$met) {
                $choices = implode('; ', array_column($parts, 'choice'));
                $this->blockers[] = get_string('blockeranyofcriteria', 'report_completiondoctor', $choices);
            }
            return;
        }
        foreach ($parts as $part) {
            if (!$part->complete) {
                array_push($this->blockers, ...$part->missing);
            }
        }
    }

    /**
     * Section 3: per-activity completion breakdown with grade/attempt context.
     *
     * @return array
     */
    protected function check_activities(): array {
        global $DB;
        $rows = [];

        foreach ($this->modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            if (!$this->completion->is_enabled($cm)) {
                continue;
            }

            $data = $this->completion->get_data($cm, false, $this->user->id);
            $requirements = [];
            $notes = [];

            if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
                if (!empty($cm->completionview)) {
                    $requirements[] = get_string('requireview', 'report_completiondoctor');
                }
                if ($cm->completiongradeitemnumber !== null) {
                    $requirements[] = empty($cm->completionpassgrade)
                        ? get_string('requiregrade', 'report_completiondoctor')
                        : get_string('requirepassgrade', 'report_completiondoctor');
                    $notes = array_merge($notes, $this->grade_notes($cm));
                }
                // Module-specific custom rules (e.g. assign "require submit").
                $customrules = $cm->customdata['customcompletionrules'] ?? [];
                foreach (array_keys(array_filter((array) $customrules)) as $rule) {
                    $requirements[] = s($rule);
                }
            }

            if ($cm->modname === 'quiz') {
                $attempts = $DB->count_records(
                    'quiz_attempts',
                    ['quiz' => $cm->instance, 'userid' => $this->user->id, 'state' => 'finished']
                );
                $notes[] = get_string('quizattempts', 'report_completiondoctor', $attempts);
            } else if ($cm->modname === 'assign') {
                $submission = $DB->get_record(
                    'assign_submission',
                    ['assignment' => $cm->instance, 'userid' => $this->user->id, 'latest' => 1]
                );
                $notes[] = get_string(
                    'assignsubmission',
                    'report_completiondoctor',
                    $submission ? s($submission->status) : get_string('nosubmission', 'report_completiondoctor')
                );
            }

            [$statelabel, $stateok] = $this->state_label((int) $data->completionstate);

            $rows[] = [
                'activity' => $this->cm_name($cm),
                'modname' => $cm->modname,
                'tracking' => $cm->completion == COMPLETION_TRACKING_AUTOMATIC
                    ? get_string('trackingauto', 'report_completiondoctor')
                    : get_string('trackingmanual', 'report_completiondoctor'),
                'requirements' => implode(', ', $requirements),
                'state' => $statelabel,
                'ok' => $stateok,
                'notes' => $notes,
            ];
        }

        return ['rows' => $rows, 'hasrows' => !empty($rows)];
    }

    /**
     * Grade context + the two classic grade-to-pass traps for one cm.
     *
     * @param \cm_info $cm
     * @return string[] Notes for the activity row.
     */
    protected function grade_notes(\cm_info $cm): array {
        $notes = [];
        $item = grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => (int) $cm->completiongradeitemnumber,
        ]);
        if (!$item) {
            return $notes;
        }

        $grade = new grade_grade(['itemid' => $item->id, 'userid' => $this->user->id]);
        if ($grade->finalgrade !== null) {
            $notes[] = get_string('gradesofar', 'report_completiondoctor', (object) [
                'grade' => format_float($grade->finalgrade, 2),
                'max' => format_float($item->grademax, 2),
                'pass' => $item->gradepass > 0 ? format_float($item->gradepass, 2)
                    : get_string('nopassgrade', 'report_completiondoctor'),
            ]);
            if (
                !empty($cm->completionpassgrade) && $item->gradepass > 0
                    && $grade->finalgrade < $item->gradepass
            ) {
                $this->blockers[] = $this->cm_name($cm) . ': '
                    . get_string('gradesofar', 'report_completiondoctor', (object) [
                        'grade' => format_float($grade->finalgrade, 2),
                        'max' => format_float($item->grademax, 2),
                        'pass' => format_float($item->gradepass, 2),
                    ]);
                // Moodle stores a failing grade in a hidden grade item as plain "not complete",
                // and the learner cannot see the grade.
                if ($item->is_hidden()) {
                    $notes[] = get_string('gradehidden', 'report_completiondoctor');
                }
            }
        } else {
            $notes[] = get_string('nogradeyet', 'report_completiondoctor');
        }

        // Trap 1: gradebook pass grade set, but completion only needs "any grade".
        if (empty($cm->completionpassgrade) && $item->gradepass > 0) {
            $notes[] = get_string('gradepasstrap', 'report_completiondoctor');
        }
        // Trap 2: completion asks for a pass grade, but none is set in the gradebook. Moodle then
        // counts any grade as complete (completion_info::internal_get_grade_state()), so this is
        // a warning, not a blocker.
        if (!empty($cm->completionpassgrade) && !($item->gradepass > 0)) {
            $notes[] = get_string('gradepassmissing', 'report_completiondoctor');
        }

        return $notes;
    }

    /**
     * Section 4: restrict-access conditions currently hiding tracked items.
     *
     * @return array
     */
    protected function check_availability(): array {
        $rows = [];

        foreach ($this->modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible && !empty($section->availableinfo)) {
                $name = $this->plain(get_section_name($this->course, $section));
                $rows[] = $this->row(
                    get_string('blockedsection', 'report_completiondoctor', (object) ['section' => $name]),
                    false,
                    get_string(
                        'restrictreason',
                        'report_completiondoctor',
                        $this->plain(\core_availability\info::format_info($section->availableinfo, $this->course))
                    )
                );
            }
        }

        foreach ($this->modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || $cm->uservisible) {
                continue;
            }
            if (!$this->completion->is_enabled($cm)) {
                continue;
            }
            if (!empty($cm->availableinfo)) {
                $reason = $this->plain(\core_availability\info::format_info($cm->availableinfo, $this->course));
                $rows[] = $this->row(
                    get_string(
                        'blockedactivity',
                        'report_completiondoctor',
                        (object) ['activity' => $this->cm_name($cm)]
                    ),
                    false,
                    get_string('restrictreason', 'report_completiondoctor', $reason)
                );
                $this->blockers[] = $this->cm_name($cm) . ': ' . $reason;
            } else if (!$cm->visible) {
                $rows[] = $this->row(
                    get_string('hiddenmanually', 'report_completiondoctor', $this->cm_name($cm)),
                    false,
                    ''
                );
            }
        }

        return [
            'rows' => $rows,
            'allclear' => empty($rows),
            'allclearmsg' => get_string('noblockedactivities', 'report_completiondoctor'),
        ];
    }

    /**
     * Section 5: are the completion tasks running on schedule?
     *
     * A task is judged against its own schedule, not a fixed age: the daily task
     * runs once a day at a random hour, so a last run several hours ago is normal.
     *
     * @return array
     */
    protected function check_cron(): array {
        global $CFG;
        $rows = [];

        if (isset($CFG->cron_enabled) && empty($CFG->cron_enabled)) {
            $label = get_string('cronprocessing', 'report_completiondoctor');
            $problem = get_string('crondisabled', 'report_completiondoctor');
            $rows[] = $this->row($label, false, $problem);
            $this->blockers[] = $label . ': ' . $problem;
        }

        $tasks = [
            'taskregular' => \core\task\completion_regular_task::class,
            'taskdaily' => \core\task\completion_daily_task::class,
        ];
        foreach ($tasks as $labelid => $classname) {
            $task = \core\task\manager::get_scheduled_task($classname);
            if (!$task) {
                continue;
            }
            $label = get_string($labelid, 'report_completiondoctor');
            $lastrun = (int) $task->get_last_run_time();
            $details = [$lastrun
                ? get_string('tasklastrun', 'report_completiondoctor', userdate($lastrun))
                : get_string('tasknever', 'report_completiondoctor')];
            $problem = $this->task_problem($task);
            if ($problem !== null) {
                $details[] = $problem;
                $this->blockers[] = $label . ': ' . $problem;
            } else if ($task->get_next_run_time() > 0) {
                $nextrun = (int) $task->get_next_run_time();
                $details[] = get_string(
                    'tasknextrun',
                    'report_completiondoctor',
                    $nextrun <= time() ? get_string('taskduenow', 'report_completiondoctor') : userdate($nextrun)
                );
            }
            $rows[] = $this->row($label, $problem === null, implode('. ', $details));
        }

        return ['rows' => $rows];
    }

    /**
     * Why a scheduled task is not keeping completion up to date, if it is not.
     *
     * A task is a problem when it is disabled, its last run failed, or its
     * scheduled run is more than an hour in the past.
     *
     * @param \core\task\scheduled_task $task
     * @return string|null The problem, or null when the task is on schedule.
     */
    protected function task_problem(\core\task\scheduled_task $task): ?string {
        $nextrun = (int) $task->get_next_run_time();
        if ($task->get_disabled()) {
            return get_string('taskdisabled', 'report_completiondoctor');
        }
        if ($task->get_fail_delay() > 0) {
            return get_string('taskfailing', 'report_completiondoctor');
        }
        if ($nextrun > 0 && $nextrun < time() - HOURSECS) {
            return get_string('taskoverdue', 'report_completiondoctor', userdate($nextrun));
        }
        return null;
    }

    /**
     * Final verdict from the blockers accumulated by all checks.
     *
     * @return array
     */
    protected function build_verdict(): array {
        $iscomplete = $this->completion->is_course_complete($this->user->id);

        if ($iscomplete) {
            $message = get_string('verdictcomplete', 'report_completiondoctor');
        } else if (empty($this->blockers)) {
            $message = get_string('verdictcompletebutmarked', 'report_completiondoctor');
        } else {
            $message = get_string('verdictincomplete', 'report_completiondoctor');
        }

        return [
            'iscomplete' => $iscomplete,
            'message' => $message,
            'blockers' => array_values(array_unique($this->blockers)),
            'hasblockers' => !empty($this->blockers) && !$iscomplete,
        ];
    }

    /**
     * Build one status row for the template.
     *
     * @param string $label
     * @param bool $ok
     * @param string $detail
     * @return array
     */
    protected function row(string $label, bool $ok, string $detail): array {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * An activity's name as plain text.
     *
     * @param \cm_info $cm
     * @return string
     */
    protected function cm_name(\cm_info $cm): string {
        return $this->plain(format_string($cm->name, true, ['context' => $cm->context]));
    }

    /**
     * Plain text from HTML, for values the template escapes.
     *
     * @param string $html
     * @return string
     */
    protected function plain(string $html): string {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Map a COMPLETION_* state to a label and ok-flag.
     *
     * @param int $state
     * @return array [label, ok]
     */
    protected function state_label(int $state): array {
        switch ($state) {
            case COMPLETION_COMPLETE:
                return [get_string('statecomplete', 'report_completiondoctor'), true];
            case COMPLETION_COMPLETE_PASS:
                return [get_string('statecompletepass', 'report_completiondoctor'), true];
            case COMPLETION_COMPLETE_FAIL:
                return [get_string('statecompletefail', 'report_completiondoctor'), false];
            default:
                return [get_string('stateincomplete', 'report_completiondoctor'), false];
        }
    }
}
