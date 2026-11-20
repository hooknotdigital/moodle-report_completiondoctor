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
use context_course;
use context_system;
use grade_item;
use grade_grade;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Diagnostic engine: answers "why is this learner not marked complete?".
 *
 * Read-only. Gathers the full completion picture for one user in one course
 * and produces template-ready data. The verdict names each thing that keeps the
 * learner from completing once, the most fundamental first, each with a link to
 * the page where it is fixed when the viewer may change it there.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var int Verdict order: completion switched off for the site. */
    protected const ORDER_SITE = 10;

    /** @var int Verdict order: completion switched off for the course. */
    protected const ORDER_COURSE = 20;

    /** @var int Verdict order: no course completion criteria. */
    protected const ORDER_NO_CRITERIA = 30;

    /** @var int Verdict order: a required activity no learner can complete as things stand. */
    protected const ORDER_NEVER = 40;

    /** @var int Verdict order: a grade below the passing grade. */
    protected const ORDER_GRADE = 50;

    /** @var int Verdict order: an access restriction this learner does not meet yet. */
    protected const ORDER_RESTRICTED = 60;

    /** @var int Verdict order: something the learner or a teacher still has to do. */
    protected const ORDER_NOT_DONE = 70;

    /** @var int Verdict order: an activity that stays locked until other activities are done. */
    protected const ORDER_LOCKED = 80;

    /** @var \stdClass Course record. */
    protected $course;

    /** @var \stdClass User record. */
    protected $user;

    /** @var completion_info */
    protected $completion;

    /** @var \course_modinfo Modinfo computed for the diagnosed user. */
    protected $modinfo;

    /** @var context_course */
    protected $context;

    /** @var bool Whether completion tracking is on for both the site and the course. */
    protected $tracking = false;

    /** @var int[] Course module ids of the activities that are course completion criteria. */
    protected $criteriacmids = [];

    /**
     * @var array[] What keeps the learner from completing the course, keyed so that each
     *     activity or setting is listed once: order, sequence, text and actions.
     */
    protected $problems = [];

    /**
     * @var array[] Why an activity is not complete, by course module id: order, text and
     *     actions. They reach the verdict only for activities the course still needs.
     */
    protected $causes = [];

    /** @var array[] Background tasks that are not running on schedule: text and actions. */
    protected $taskproblems = [];

    /** @var string[][] Names of the activities each activity keeps locked, by course module id. */
    protected $locks = [];

    /** @var string[][] What the learner still has to do in each activity, by course module id and rule. */
    protected $todo = [];

    /** @var int[] The learner's completion state of each activity, by course module id. */
    protected $states = [];

    /** @var bool Whether a criterion is met but waits for the completion task to record it. */
    protected $pending = false;

    /**
     * @var array Whether each activity the learner cannot open can ever open, by course module
     *     id: true or false once known, null while it is being worked out (so a chain that comes
     *     back to it is a loop).
     */
    protected $opens = [];

    /** @var array What each restriction waits for (see waits_for()), by 'c' or 's' and the id. */
    protected $waits = [];

    /** @var bool[] Whether the learner meets each restriction or condition, by a key of the item and the condition. */
    protected $met = [];

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
        $this->context = context_course::instance($course->id);
        foreach ($this->completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY) as $criterion) {
            $this->criteriacmids[] = (int) $criterion->moduleinstance;
        }
    }

    /**
     * Run every check and return template-ready data.
     *
     * @return array Data for templates/report.mustache.
     */
    public function diagnose(): array {
        $chain = $this->check_chain();
        $activities = $this->check_activities();
        $availability = $this->check_availability();
        // The criteria come after the two checks above: a missing activity is reported
        // with the cause they found, such as a failing grade or a hidden activity.
        $criteria = $this->check_criteria();
        $cron = $this->check_cron();
        $verdict = $this->build_verdict();
        foreach ($activities['rows'] as &$row) {
            if (!empty($this->locks[$row['cmid']])) {
                $row['notes'][] = get_string('keepslocked', 'report_completiondoctor', $this->names($this->locks[$row['cmid']]));
                $row['problem'] = !$row['ok'];
            }
        }
        unset($row);

        return [
            'heading' => get_string('reporttitle', 'report_completiondoctor', (object) [
                'user' => fullname($this->user),
                'course' => $this->plain(format_string($this->course->fullname, true, ['context' => $this->context])),
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
            $advancedfeatures = new moodle_url('/admin/settings.php', ['section' => 'optionalsubsystems']);
            $this->add_problem('site', self::ORDER_SITE, get_string('chainsitedisabled', 'report_completiondoctor'), [
                $this->admin_link($advancedfeatures, 'linkadvancedfeatures'),
            ], 'askadmin');
        }

        $courseenabled = !empty($this->course->enablecompletion);
        $rows[] = $this->row(
            get_string('coursecompletion', 'report_completiondoctor'),
            $courseenabled,
            $courseenabled ? get_string('enabled', 'report_completiondoctor')
                           : get_string('disabled', 'report_completiondoctor')
        );
        if ($siteenabled && !$courseenabled) {
            $this->add_problem('course', self::ORDER_COURSE, get_string('chaincoursedisabled', 'report_completiondoctor'), [
                $this->course_link(new moodle_url('/course/edit.php', ['id' => $this->course->id]), 'linkcoursesettings'),
            ], 'askeditor');
        }

        $this->tracking = $siteenabled && $courseenabled;
        $label = get_string('coursecriteriaset', 'report_completiondoctor');
        if (!$this->tracking) {
            // Criteria do nothing while tracking is off, so this is not a second problem.
            $rows[] = $this->row($label, false, get_string('notchecked', 'report_completiondoctor'), true);
            return ['rows' => $rows];
        }

        $hascriteria = $this->completion->has_criteria();
        $rows[] = $this->row(
            $label,
            $hascriteria,
            $hascriteria ? get_string('enabled', 'report_completiondoctor')
                         : get_string('notset', 'report_completiondoctor')
        );
        if (!$hascriteria) {
            $this->add_problem('criteria', self::ORDER_NO_CRITERIA, get_string('chainnocriteria', 'report_completiondoctor'), [
                $this->course_link(new moodle_url('/course/completion.php', ['id' => $this->course->id]), 'linkcompletionsettings'),
            ], 'askeditor');
        }

        return ['rows' => $rows];
    }

    /**
     * Section 2: each course completion criterion with the user's status.
     *
     * Problems follow core's aggregation (aggregate_completions() in lib/completionlib.php):
     * activity, prerequisite course and role criteria are first combined within their
     * type by that type's method, and each result, together with every other criterion
     * on its own, is then combined by the overall method. An activity that is done but
     * not recorded yet counts as met: the completion task records it.
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
            if ($this->tracking) {
                $this->add_criteria_problems($overall, $parts);
            }
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
     * Activities are named in full: core's own title for an activity criterion shortens
     * long names. Every value is plain text, escaped by the template.
     *
     * @return \stdClass[] Each with id, criteriatype, cmid (activity criteria only, else 0),
     *     roleid (role criteria only, else 0), met (complete, or done and only waiting for
     *     the completion task to record it), type, title, complete and row.
     */
    protected function criteria_items(): array {
        $items = [];
        foreach ($this->completion->get_completions($this->user->id) as $completion) {
            $criteria = $completion->get_criteria();
            $iscomplete = $completion->is_complete();
            $cmid = $criteria->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY ? (int) $criteria->moduleinstance : 0;
            $cm = $cmid ? ($this->modinfo->cms[$cmid] ?? null) : null;
            $type = $this->plain($criteria->get_type_title());
            $title = $cm ? $this->cm_name($cm) : $this->criterion_title($criteria);

            $detail = $type;
            if ($iscomplete && !empty($completion->timecompleted)) {
                $date = userdate($completion->timecompleted, get_string('strftimedate', 'langconfig'));
                $detail = $this->note($detail, get_string('completedon', 'report_completiondoctor', $date));
            }
            // Core records an activity criterion when the activity is completed, or on the next
            // run of the regular completion task when that did not happen at once.
            $pending = !$iscomplete && $cm && !$cm->deletioninprogress && $this->completion->is_enabled($cm)
                && $this->counts_as_complete($cm, $this->state($cm));
            if ($pending) {
                $this->pending = true;
                $detail = $this->note($detail, get_string('pendingtask', 'report_completiondoctor'));
            }

            $items[] = (object) [
                'id' => (int) $criteria->id,
                'criteriatype' => (int) $criteria->criteriatype,
                'cmid' => $cmid,
                'roleid' => $criteria->criteriatype == COMPLETION_CRITERIA_TYPE_ROLE ? (int) $criteria->role : 0,
                'met' => $iscomplete || $pending,
                'type' => $type,
                'title' => $title,
                'complete' => $iscomplete,
                'row' => $this->row($title, $iscomplete, $detail),
            ];
        }
        return $items;
    }

    /**
     * A criterion's title, in full.
     *
     * Core's title shortens a prerequisite course's name, so that is taken from the course.
     *
     * @param \completion_criteria $criteria
     * @return string Plain text.
     */
    protected function criterion_title(\completion_criteria $criteria): string {
        global $DB;
        if ($criteria->criteriatype == COMPLETION_CRITERIA_TYPE_COURSE) {
            $course = $DB->get_record('course', ['id' => $criteria->courseinstance], 'id, fullname');
            if ($course) {
                return $this->plain(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
            }
        }
        return $this->plain($criteria->get_title_detailed());
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
     * @return \stdClass Part with complete, choice, missing (items) and anyof (null).
     */
    protected function single_part(\stdClass $item): \stdClass {
        return (object) [
            'complete' => $item->met,
            'choice' => $this->labelled($item->type, $item->title),
            'missing' => [$item],
            'anyof' => null,
            'options' => [$item],
        ];
    }

    /**
     * The criteria of one type, combined by that type's method.
     *
     * @param int $method COMPLETION_AGGREGATION_ALL or COMPLETION_AGGREGATION_ANY.
     * @param \stdClass[] $group Items of one type.
     * @return \stdClass Part with complete, choice, missing (items, when all are needed)
     *     and anyof (the problem text, when any one is enough).
     */
    protected function type_part(int $method, array $group): \stdClass {
        $a = (object) ['type' => $group[0]->type, 'titles' => $this->names(array_column($group, 'title'))];
        $met = array_filter($group, fn(\stdClass $item): bool => $item->met);
        if ($method == COMPLETION_AGGREGATION_ANY) {
            return (object) [
                'complete' => !empty($met),
                'choice' => get_string('choiceanyof', 'report_completiondoctor', $a),
                'missing' => [],
                'anyof' => get_string('blockeranyof', 'report_completiondoctor', $a),
                'options' => $group,
            ];
        }
        $missing = array_values(array_filter($group, fn(\stdClass $item): bool => !$item->met));
        return (object) [
            'complete' => empty($missing),
            'choice' => get_string('choiceallof', 'report_completiondoctor', $a),
            'missing' => $missing,
            'anyof' => null,
            'options' => $group,
        ];
    }

    /**
     * Add a problem for each part the overall method still needs.
     *
     * @param int $overall COMPLETION_AGGREGATION_ALL or COMPLETION_AGGREGATION_ANY.
     * @param \stdClass[] $parts
     */
    protected function add_criteria_problems(int $overall, array $parts): void {
        $met = array_filter($parts, fn(\stdClass $part): bool => $part->complete);
        if ($overall == COMPLETION_AGGREGATION_ANY) {
            if (!$met) {
                $choices = implode(get_string('choicesep', 'report_completiondoctor'), array_column($parts, 'choice'));
                $groups = array_map(fn(\stdClass $part): array => [
                    'items' => $part->options,
                    'all' => $part->anyof === null,
                ], $parts);
                $text = get_string('blockeranyofcriteria', 'report_completiondoctor', $choices);
                $this->add_any_problem('anycriteria', $text, $groups);
            }
            return;
        }
        foreach ($parts as $index => $part) {
            if ($part->complete) {
                continue;
            }
            if ($part->anyof !== null) {
                // Each option is a group of its own.
                $groups = array_map(fn(\stdClass $item): array => ['items' => [$item], 'all' => true], $part->options);
                $this->add_any_problem('anyof' . $index, $part->anyof, $groups);
            }
            foreach ($part->missing as $item) {
                if (!$item->met) {
                    $this->add_criterion_problem($item);
                }
            }
        }
    }

    /**
     * Add the problem for criteria of which any one is enough.
     *
     * Options that no learner can complete as things stand are named with their cause. When
     * that is every option, the whole entry is that serious. An option made of several
     * criteria that are all needed can never be done when any one of them cannot.
     *
     * @param string $key
     * @param string $text The "complete at least one of" sentence.
     * @param array[] $groups The options: each with items (criteria items) and all (whether
     *     every item is needed, or any one).
     */
    protected function add_any_problem(string $key, string $text, array $groups): void {
        $texts = [$text];
        $actions = [];
        $askid = null;
        $nevergroups = 0;
        foreach ($groups as $group) {
            $never = 0;
            foreach ($group['items'] as $item) {
                $cause = $this->never_cause($item);
                if ($cause === null) {
                    continue;
                }
                $never++;
                $texts[$item->id] = $cause['base'];
                $actions = array_merge($actions, $cause['actions']);
                $askid = $askid ?? $cause['askid'];
            }
            if ($group['all'] ? $never > 0 : $never === count($group['items'])) {
                $nevergroups++;
            }
        }
        $order = $groups && $nevergroups === count($groups) ? self::ORDER_NEVER : self::ORDER_NOT_DONE;
        $this->add_problem($key, $order, $this->sentences(array_values($texts)), $actions, $askid);
    }

    /**
     * Why an activity criterion can never be completed as things stand, if it cannot.
     *
     * @param \stdClass $item From criteria_items().
     * @return array|null With base (the text without whom to ask), actions and askid, or null.
     */
    protected function never_cause(\stdClass $item): ?array {
        $cm = $item->cmid ? ($this->modinfo->cms[$item->cmid] ?? null) : null;
        if (!$cm) {
            return null;
        }
        $completionsettings = $this->course_link(
            new moodle_url('/course/completion.php', ['id' => $this->course->id]),
            'linkcompletionsettings'
        );
        if ($cm->deletioninprogress) {
            return $this->entry(
                get_string('problemdeleting', 'report_completiondoctor', $item->title),
                [$completionsettings],
                'askeditor'
            );
        }
        if (!$this->completion->is_enabled($cm)) {
            return $this->entry(
                get_string('problemnotracking', 'report_completiondoctor', $item->title),
                [$this->settings_link($cm), $completionsettings],
                'askeditor'
            );
        }
        foreach ($this->causes[$cm->id] ?? [] as $cause) {
            if ($cause['order'] === self::ORDER_NEVER) {
                return $cause;
            }
        }
        return null;
    }

    /**
     * Add the problem for one criterion the learner still needs.
     *
     * An activity is named with the cause the activity and access checks found, so it
     * is listed once with the reason, not once as "not complete" and again as the cause.
     *
     * @param \stdClass $item From criteria_items().
     */
    protected function add_criterion_problem(\stdClass $item): void {
        if ($item->cmid) {
            $this->add_activity_problem($item);
            return;
        }

        $key = 'criterion' . $item->id;
        switch ($item->criteriatype) {
            case COMPLETION_CRITERIA_TYPE_ROLE:
                $actions = [];
                if ($this->can_mark($item->id, $item->roleid)) {
                    $actions[] = $this->link(
                        new moodle_url('/report/completion/index.php', ['course' => $this->course->id]),
                        'linkcompletionreport'
                    );
                }
                $text = get_string('problemrole', 'report_completiondoctor', $item->title);
                $this->add_problem($key, self::ORDER_NOT_DONE, $text, $actions);
                return;

            case COMPLETION_CRITERIA_TYPE_SELF:
                // Learners mark a course complete only with the Self completion block.
                switch ($this->self_completion_block()) {
                    case 'shown':
                        $this->add_problem($key, self::ORDER_NOT_DONE, get_string('problemself', 'report_completiondoctor'));
                        break;
                    case 'disabled':
                        $this->add_problem($key, self::ORDER_NEVER, get_string('problemselfdisabled', 'report_completiondoctor'), [
                            $this->admin_link(new moodle_url('/admin/blocks.php'), 'linkmanageblocks'),
                        ], 'askadmin');
                        break;
                    default:
                        $this->add_problem($key, self::ORDER_NEVER, get_string('problemselfnoblock', 'report_completiondoctor'), [
                            $this->course_link(new moodle_url('/course/view.php', ['id' => $this->course->id]), 'linkcourse'),
                        ], 'askeditor');
                }
                return;
        }

        $hints = [
            COMPLETION_CRITERIA_TYPE_DATE => 'hintdate',
            COMPLETION_CRITERIA_TYPE_DURATION => 'hintduration',
            COMPLETION_CRITERIA_TYPE_GRADE => 'hintgrade',
            COMPLETION_CRITERIA_TYPE_UNENROL => 'hintunenrol',
            COMPLETION_CRITERIA_TYPE_COURSE => 'hintcourse',
        ];
        $texts = [get_string('blockercriterion', 'report_completiondoctor', (object) [
            'type' => $item->type,
            'title' => $item->title,
        ])];
        if (isset($hints[$item->criteriatype])) {
            $texts[] = get_string($hints[$item->criteriatype], 'report_completiondoctor');
        }
        $this->add_problem($key, self::ORDER_NOT_DONE, $this->sentences($texts));
    }

    /**
     * Add the problem for an activity criterion the learner still needs.
     *
     * Whom to ask comes last, after any note that the activity keeps others locked.
     *
     * @param \stdClass $item From criteria_items().
     */
    protected function add_activity_problem(\stdClass $item): void {
        $key = 'cm' . $item->cmid;
        $cm = $this->modinfo->cms[$item->cmid] ?? null;
        $never = $this->never_cause($item);
        if ($never !== null && ($cm === null || $cm->deletioninprogress || !$this->completion->is_enabled($cm))) {
            $this->add_problem($key, self::ORDER_NEVER, $never['base'], $never['actions'], $never['askid']);
            return;
        }

        $causes = $this->causes[$item->cmid] ?? [];
        $askid = null;
        if ($causes) {
            usort($causes, fn(array $a, array $b): int => $a['order'] <=> $b['order']);
            $order = $causes[0]['order'];
            $texts = array_column($causes, 'base');
            $actions = array_merge([], ...array_column($causes, 'actions'));
            foreach ($causes as $cause) {
                $askid = $askid ?? $cause['askid'];
            }
        } else {
            $order = self::ORDER_NOT_DONE;
            $texts = [$this->not_done_text($cm, $item->title)];
            $actions = [$cm ? $this->link($cm->url, 'linkactivity', $item->title) : null];
        }
        if (!empty($this->locks[$item->cmid])) {
            $texts[] = get_string('alsolocks', 'report_completiondoctor', $this->names($this->locks[$item->cmid]));
        }
        $this->add_problem($key, $order, $this->sentences($texts), $actions, $askid);
    }

    /**
     * What an activity that is simply not done yet still needs, in a sentence.
     *
     * @param \cm_info|null $cm
     * @param string $name The activity's name.
     * @return string
     */
    protected function not_done_text(?\cm_info $cm, string $name): string {
        if ($cm && $cm->completion == COMPLETION_TRACKING_MANUAL) {
            return get_string('problemnotdonemanual', 'report_completiondoctor', $name);
        }
        if ($cm && !empty($this->todo[$cm->id])) {
            return get_string('problemnotdonetodo', 'report_completiondoctor', (object) [
                'activity' => $name,
                'todo' => $this->names(array_values($this->todo[$cm->id])),
            ]);
        }
        return get_string('problemnotdone', 'report_completiondoctor', $name);
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

            $state = $this->state($cm);
            // First: the grade cause names the rules still to do that requirements() finds.
            $requirements = $this->requirements($cm);
            $notes = [];
            if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC && $cm->completiongradeitemnumber !== null) {
                $notes = $this->grade_notes($cm, $state);
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
                    $this->submission_status($submission ? (string) $submission->status : '')
                );
            }

            [$statelabel, $stateok] = $this->state_label($cm, $state);
            $required = in_array((int) $cm->id, $this->criteriacmids);

            $rows[] = [
                'activity' => $this->cm_name($cm),
                'modname' => $cm->modname,
                'required' => $required,
                'tracking' => $cm->completion == COMPLETION_TRACKING_AUTOMATIC
                    ? get_string('trackingauto', 'report_completiondoctor')
                    : get_string('trackingmanual', 'report_completiondoctor'),
                'requirements' => $requirements,
                'cmid' => (int) $cm->id,
                'state' => $statelabel,
                'ok' => $stateok,
                'problem' => !$stateok && $required,
                'notes' => $notes,
            ];
        }

        return [
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'none' => get_string('noactivitiestracked', 'report_completiondoctor'),
        ];
    }

    /**
     * What an activity asks of the learner, each condition with the learner's status.
     *
     * The descriptions are core's own (\core_completion\cm_completion_details), so every
     * activity type, including add-ons, is described in words, and in the site's language.
     * The conditions still to do are kept for the verdict.
     *
     * @param \cm_info $cm
     * @return string[]
     */
    protected function requirements(\cm_info $cm): array {
        if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
            return [get_string('requiremanual', 'report_completiondoctor')];
        }
        $list = [];
        $details = \core_completion\cm_completion_details::get_instance($cm, (int) $this->user->id);
        foreach ($details->get_details() as $rule => $detail) {
            $status = (int) $detail->status;
            $description = $this->plain($detail->description);
            if (in_array($status, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])) {
                $key = 'requirementdone';
            } else if ($status == COMPLETION_COMPLETE_FAIL && $rule === 'completionusegrade' && empty($cm->completionpassgrade)) {
                // Any grade is enough here, even a failing one.
                $key = 'requirementdonefailing';
            } else {
                $key = in_array($status, [COMPLETION_COMPLETE_FAIL, COMPLETION_COMPLETE_FAIL_HIDDEN])
                    ? 'requirementfailed' : 'requirementnotdone';
                $this->todo[$cm->id][$rule] = $description;
            }
            $list[] = get_string($key, 'report_completiondoctor', $description);
        }
        return $list;
    }

    /**
     * An assignment submission status in words, as the assignment itself shows it.
     *
     * @param string $status assign_submission.status, or an empty string for no submission.
     * @return string
     */
    protected function submission_status(string $status): string {
        if ($status === '') {
            return get_string('nosubmission', 'report_completiondoctor');
        }
        if (get_string_manager()->string_exists('submissionstatus_' . $status, 'assign')) {
            return get_string('submissionstatus_' . $status, 'assign');
        }
        return $status;
    }

    /**
     * Grade context, the cause when a grade keeps the activity incomplete, and the grade traps.
     *
     * A grade below the passing grade is the cause only when completion asks for the
     * passing grade. When completion only asks for "receive a grade", a lower grade is
     * recorded as failed, and that still completes the activity for the course (see
     * counts_as_complete()); anything that activity still lacks is another of its rules.
     *
     * @param \cm_info $cm
     * @param int $state The learner's completion state for the activity.
     * @return string[] Notes for the activity row.
     */
    protected function grade_notes(\cm_info $cm, int $state): array {
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

        $needspass = !empty($cm->completionpassgrade);
        $haspass = $item->gradepass > 0;
        $grade = new grade_grade(['itemid' => $item->id, 'userid' => $this->user->id]);
        if ($grade->finalgrade !== null) {
            $values = (object) [
                'activity' => $this->cm_name($cm),
                'grade' => format_float($grade->finalgrade, 2),
                'max' => format_float($item->grademax, 2),
                'pass' => $haspass ? format_float($item->gradepass, 2) : get_string('nopassgrade', 'report_completiondoctor'),
            ];
            $notes[] = get_string('gradesofar', 'report_completiondoctor', $values);
            if ($needspass && $haspass && $grade->finalgrade < $item->gradepass && !$this->counts_as_complete($cm, $state)) {
                $cause = [get_string('problemgrade', 'report_completiondoctor', $values)];
                if ($item->is_hidden()) {
                    $notes[] = get_string('gradehidden', 'report_completiondoctor');
                    $cause[] = get_string('gradehidden', 'report_completiondoctor');
                }
                // A new grade alone does not complete it when other rules are still to do.
                $others = array_diff_key($this->todo[$cm->id] ?? [], ['completionusegrade' => 1, 'completionpassgrade' => 1]);
                if ($others) {
                    $cause[] = get_string('stilltodo', 'report_completiondoctor', $this->names($others));
                }
                $this->add_cause($cm, self::ORDER_GRADE, $this->sentences($cause), [
                    $this->link($cm->url, 'linkactivity', $values->activity),
                ]);
            }
        } else {
            $notes[] = get_string('nogradeyet', 'report_completiondoctor');
        }

        if (!$needspass && $haspass) {
            $notes[] = get_string('gradepasstrap', 'report_completiondoctor');
        }
        // Completion asks for a pass grade, but none is set in the gradebook. Moodle then
        // counts any grade as complete (completion_info::internal_get_grade_state()), so
        // this is a warning, not a problem.
        if ($needspass && !$haspass) {
            $notes[] = get_string('gradepassmissing', 'report_completiondoctor');
        }

        return $notes;
    }

    /**
     * Section 4: activities and sections that are hidden from the learner or restricted.
     *
     * Each row says whether it keeps the learner from completing the course: only an
     * activity that is a course completion criterion and not complete yet does. For each
     * activity the learner cannot open, the cause is what actually holds it back: the
     * activity, its section, or the subsection it sits in, followed up to the top. An
     * activity whose restriction waits only for activities the learner can still do is
     * named as waiting for them, after them, since doing those is the fix.
     *
     * @return array
     */
    protected function check_availability(): array {
        $rows = [];

        foreach ($this->modinfo->get_section_info_all() as $section) {
            if ($section->uservisible || !$section->visible || !$this->own_restriction_fails($section)) {
                continue;
            }
            $reason = $this->restriction_text($section->availableinfo, new \core_availability\info_section($section));
            if ($reason !== '') {
                $rows['section' . $section->id] = $this->availability_row(
                    get_string('blockedsection', 'report_completiondoctor', (object) ['section' => $this->section_name($section)]),
                    $reason,
                    $this->section_blocks($section)
                );
            }
        }

        foreach ($this->modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || !$this->completion->is_enabled($cm)) {
                continue;
            }
            if ($cm->uservisible) {
                // Moodle 4.5 works out a subsection's availability for the viewer, not the learner
                // (sectiondelegatemodule::get_cm()), so a restriction around a subsection that the
                // viewer meets is missed there: check it for the learner.
                $holder = $this->delegated_holder($cm);
                if ($holder !== null) {
                    $this->add_restriction_cause($cm, $holder, $rows);
                }
                continue;
            }
            $name = $this->cm_name($cm);
            $needed = $this->is_needed($cm);

            $hidden = $this->hidden_by($cm);
            if ($hidden !== null) {
                // No learner can open it, so no learner can complete it.
                if ($hidden instanceof \section_info) {
                    $kind = $hidden->is_delegated() ? 'subsection' : 'section';
                    $show = $this->section_link($hidden);
                } else {
                    $kind = 'activity';
                    $show = $this->settings_link($cm);
                }
                $rows['cm' . $cm->id] = $this->availability_row(
                    get_string(['activity' => 'hiddenmanually', 'section' => 'hiddeninsection',
                        'subsection' => 'hiddeninsubsection'][$kind], 'report_completiondoctor', $name),
                    '',
                    $needed
                );
                $this->add_cause(
                    $cm,
                    self::ORDER_NEVER,
                    get_string(['activity' => 'problemhidden', 'section' => 'problemhiddensection',
                        'subsection' => 'problemhiddensubsection'][$kind], 'report_completiondoctor', $name),
                    [
                        $show,
                        $this->course_link(
                            new moodle_url('/course/completion.php', ['id' => $this->course->id]),
                            'linkcompletionsettings'
                        ),
                    ],
                    'askeditor'
                );
                continue;
            }

            $holder = $this->holder($cm);
            if ($holder === null && !$cm->is_user_access_restricted_by_capability()) {
                // Nothing holds it back for the learner: on Moodle 4.5 a subsection's availability
                // is worked out for the viewer, so the learner's copy can look closed when it is not.
                continue;
            }
            if ($holder === null) {
                // Visible, and nothing in its restrictions holds it back: a permission does.
                $rows['cm' . $cm->id] = $this->availability_row(
                    get_string('blockedpermission', 'report_completiondoctor', $name),
                    get_string('blockedpermissiondetail', 'report_completiondoctor'),
                    $needed
                );
                $this->add_cause($cm, self::ORDER_NEVER, get_string('problemnoaccess', 'report_completiondoctor', $name), [
                    $this->permissions_link($cm),
                ], 'askeditor');
                continue;
            }

            $this->add_restriction_cause($cm, $holder, $rows);
        }

        // An activity that keeps a required activity locked matters for completion too.
        foreach ($this->locks as $cmid => $names) {
            if (isset($rows['cm' . $cmid])) {
                $rows['cm' . $cmid]['blocks'] = true;
                $rows['cm' . $cmid]['detail'] = $this->note_after(
                    $rows['cm' . $cmid]['detail'],
                    get_string('keepslocked', 'report_completiondoctor', $this->names($names))
                );
            }
        }

        return [
            'rows' => array_values($rows),
            'allclear' => empty($rows),
            'allclearmsg' => get_string('noblockedactivities', 'report_completiondoctor'),
        ];
    }

    /**
     * Record why a restriction keeps an activity closed, and its row in the table.
     *
     * @param \cm_info $cm The activity the learner cannot open.
     * @param \cm_info|\section_info $holder The activity, section or subsection whose own
     *     restriction fails (see holder()).
     * @param array $rows The table rows, keyed so each item has one row.
     */
    protected function add_restriction_cause(\cm_info $cm, $holder, array &$rows): void {
        $name = $this->cm_name($cm);
        if ($holder instanceof \section_info) {
            $reason = $this->restriction_text($holder->availableinfo, new \core_availability\info_section($holder));
            $edit = $this->section_settings_link($holder);
            // A subsection keeps its restriction on its own section (mod/subsection/lib.php).
            $stringid = $holder->is_delegated() ? 'problemrestrictedsubsection' : 'problemrestrictedsection';
            $text = get_string($stringid, 'report_completiondoctor', (object) [
                'activity' => $name,
                'section' => $this->section_name($holder),
                'reason' => $this->sentence($reason),
            ]);
        } else {
            $reason = $this->restriction_text($holder->availableinfo, new \core_availability\info_module($holder));
            $edit = $this->settings_link($holder);
            $stringid = $holder->id == $cm->id ? 'problemrestricted' : 'problemrestrictedsubsection';
            $text = get_string($stringid, 'report_completiondoctor', (object) [
                'activity' => $name,
                'section' => $this->cm_name($holder),
                'reason' => $this->sentence($reason),
            ]);
            $label = $holder->id == $cm->id
                ? get_string('blockedactivity', 'report_completiondoctor', (object) ['activity' => $name])
                : get_string('blockedsection', 'report_completiondoctor', (object) ['section' => $this->cm_name($holder)]);
            if (!isset($rows['cm' . $holder->id])) {
                $rows['cm' . $holder->id] = $this->availability_row($label, $reason, false);
            }
            $rows['cm' . $holder->id]['blocks'] = $rows['cm' . $holder->id]['blocks'] || $this->is_needed($cm);
        }

        // While its own wait is worked out, a chain that comes back to this activity is a loop.
        $known = array_key_exists($cm->id, $this->opens);
        if (!$known) {
            $this->opens[$cm->id] = null;
        }
        $wait = $this->holder_waits($holder);
        if (!$known) {
            $this->opens[$cm->id] = !$wait || !in_array($wait['kind'], ['never', 'loop', 'cannot'], true);
        }
        if ($wait === null) {
            $this->add_cause($cm, self::ORDER_RESTRICTED, $text, [$edit], 'askeditorrestriction');
            return;
        }

        $names = array_map(fn(\cm_info $other): string => $this->cm_name($other), $wait['activities']);
        $a = (object) ['activity' => $name, 'others' => $this->names($names), 'reason' => $this->sentence($reason)];
        // Only a criterion's locks matter for completion; any one of several does not lock alone.
        if (in_array((int) $cm->id, $this->criteriacmids) && $wait['kind'] !== 'any') {
            foreach ($wait['activities'] as $other) {
                $this->locks[$other->id][] = $name;
            }
        }
        $kind = $wait['kind'];
        if (in_array($kind, ['loop', 'never'], true)) {
            $ids = array_map(fn(\cm_info $other): int => (int) $other->id, $wait['activities']);
            if (in_array((int) $cm->id, $ids, true)) {
                // Its own restriction, or its section's, waits for it.
                $this->add_cause(
                    $cm,
                    self::ORDER_NEVER,
                    get_string('problemlockedself', 'report_completiondoctor', $a),
                    [$edit],
                    'askeditor'
                );
                return;
            }
            // Activities that wait for each other are a loop, whichever of them was worked out
            // first; a loop further along is, for this activity, simply never.
            $kind = $this->waits_back($wait['activities'], $cm) ? 'loop' : 'never';
        }
        switch ($kind) {
            case 'loop':
                $this->add_cause(
                    $cm,
                    self::ORDER_NEVER,
                    get_string('problemlockedloop', 'report_completiondoctor', $a),
                    [$edit],
                    'askeditor'
                );
                return;
            case 'cannot':
                $a->why = $this->names(array_map(
                    fn(\cm_info $other): string => get_string(
                        $wait['why'][$other->id],
                        'report_completiondoctor',
                        $this->cm_name($other)
                    ),
                    $wait['activities']
                ));
                $this->add_cause(
                    $cm,
                    self::ORDER_NEVER,
                    get_string('problemlockedcannot', 'report_completiondoctor', $a),
                    [$edit],
                    'askeditor'
                );
                return;
            case 'never':
                // Learners cannot open what it waits for, or that only never opens itself, further
                // along: then the fix is named on that earlier entry, not here.
                $direct = array_filter($wait['activities'], fn(\cm_info $other): bool => $this->closed_for_good($other));
                if ($direct) {
                    $this->add_cause(
                        $cm,
                        self::ORDER_NEVER,
                        get_string('problemlockedhidden', 'report_completiondoctor', $a),
                        [$edit],
                        'askeditor'
                    );
                } else {
                    $this->add_cause($cm, self::ORDER_NEVER, get_string('problemlockednever', 'report_completiondoctor', $a));
                }
                return;
        }

        $actions = [];
        $texts = [get_string($wait['kind'] === 'all' ? 'problemlocked' : 'problemlockedany', 'report_completiondoctor', $a)];
        foreach ($wait['activities'] as $other) {
            $actions[] = $this->link($other->url, 'linkopen', null, $this->cm_name($other));
            // An awaited activity that is not a criterion has no entry of its own: say here what
            // keeps it closed, which may be the real blocker (a date, a group).
            if (!$other->uservisible && !in_array((int) $other->id, $this->criteriacmids)) {
                $otherholder = $this->holder($other);
                if ($otherholder !== null) {
                    $info = $otherholder instanceof \section_info
                        ? new \core_availability\info_section($otherholder)
                        : new \core_availability\info_module($otherholder);
                    $texts[] = get_string('awaitedrestricted', 'report_completiondoctor', (object) [
                        'activity' => $this->cm_name($other),
                        'reason' => $this->sentence($this->restriction_text($otherholder->availableinfo, $info)),
                    ]);
                }
            }
        }
        $this->add_cause($cm, self::ORDER_LOCKED, $this->sentences($texts), $actions);
    }

    /**
     * Whether the activities an activity waits for wait for it in turn.
     *
     * @param \cm_info[] $others
     * @param \cm_info $cm
     * @return bool
     */
    protected function waits_back(array $others, \cm_info $cm): bool {
        foreach ($others as $other) {
            $holder = $this->holder($other);
            $wait = $holder ? $this->holder_waits($holder) : null;
            foreach ($wait['activities'] ?? [] as $back) {
                if ($back->id == $cm->id) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether an activity is hidden from learners, by itself or by a section above it.
     *
     * @param \cm_info $cm
     * @return \cm_info|\section_info|null The hidden activity or section, or null.
     */
    protected function hidden_by(\cm_info $cm) {
        $section = $cm->get_section_info();
        $parent = $this->parent_cm($section);
        if ($parent) {
            // A hidden section further up hides everything in it: name that one. When only the
            // subsection itself is hidden, its own section is what is hidden.
            $outer = $this->hidden_by($parent);
            if ($outer !== null) {
                return $outer instanceof \section_info ? $outer : $section;
            }
        }
        if (!$section->visible) {
            return $section;
        }
        if (!$cm->visible) {
            return $cm;
        }
        return null;
    }

    /**
     * The subsection, or section around a subsection, whose own restriction the learner fails.
     *
     * @param \cm_info $cm An activity, possibly in a subsection.
     * @return \cm_info|\section_info|null Null when nothing around it holds it back.
     */
    protected function delegated_holder(\cm_info $cm) {
        for ($section = $cm->get_section_info(); $parent = $this->parent_cm($section); $section = $parent->get_section_info()) {
            if ($this->own_restriction_fails($section)) {
                return $section;
            }
            if ($this->own_restriction_fails($parent)) {
                return $parent;
            }
            if ($this->own_restriction_fails($parent->get_section_info())) {
                return $parent->get_section_info();
            }
        }
        return null;
    }

    /**
     * What holds an activity back: the activity, section or subsection whose own restriction fails.
     *
     * An activity in a subsection is held back by the subsection's own restriction, or by
     * whatever holds back the subsection, up to the top section.
     *
     * @param \cm_info $cm An activity the learner cannot open.
     * @return \cm_info|\section_info|null Null when no restriction fails (a permission holds it back).
     */
    protected function holder(\cm_info $cm) {
        $section = $cm->get_section_info();
        if ($section->available) {
            return $this->own_restriction_fails($cm) ? $cm : null;
        }
        if ($this->own_restriction_fails($section)) {
            return $section;
        }
        $parent = $this->parent_cm($section);
        return $parent ? $this->holder($parent) : null;
    }

    /**
     * The subsection activity a delegated section belongs to.
     *
     * @param \section_info $section
     * @return \cm_info|null
     */
    protected function parent_cm(\section_info $section): ?\cm_info {
        if (!$section->is_delegated()) {
            return null;
        }
        $delegate = $section->get_component_instance();
        if (!$delegate instanceof \core_courseformat\sectiondelegatemodule) {
            return null;
        }
        // The learner's copy: on Moodle 4.5 the delegate's own is the viewer's.
        return $this->modinfo->cms[$delegate->get_cm()->id] ?? null;
    }

    /**
     * Whether an activity's or section's own restriction is not met by the learner.
     *
     * @param \cm_info|\section_info $item
     * @return bool
     */
    protected function own_restriction_fails($item): bool {
        if (empty($item->availability)) {
            return false;
        }
        $key = ($item instanceof \section_info ? 's' : 'c') . $item->id;
        if (!isset($this->met[$key])) {
            $info = $item instanceof \section_info
                ? new \core_availability\info_section($item)
                : new \core_availability\info_module($item);
            $information = '';
            $this->met[$key] = $info->is_available($information, false, (int) $this->user->id, $this->modinfo);
        }
        return !$this->met[$key];
    }

    /**
     * What a restriction asks of the learner, as plain text.
     *
     * A restriction set to hide the item entirely shows the learner nothing, so then the
     * full condition is described, as a teacher sees it. Several conditions are kept apart.
     *
     * @param string|\renderable|null $availableinfo What Moodle shows the learner, if anything.
     * @param \core_availability\info $info The item's availability.
     * @return string Empty when there is no restriction to describe.
     */
    protected function restriction_text($availableinfo, \core_availability\info $info): string {
        if (empty($availableinfo)) {
            $availableinfo = $info->get_full_information();
        }
        if (!$availableinfo) {
            return '';
        }
        $html = \core_availability\info::format_info($availableinfo, $this->course);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $text = preg_replace('/\s+/u', ' ', $this->condition_text($document->documentElement, 0));
        return trim(rtrim(trim($text), ';.'));
    }

    /**
     * The text of a restriction's HTML, with its conditions kept apart.
     *
     * Each condition is a list item. Items are joined with a separator, and a nested set of
     * conditions (a restriction set) is put in brackets after its heading, so its conditions
     * do not read as part of the list around it.
     *
     * @param \DOMNode $node
     * @param int $depth How many lists the node is in.
     * @return string
     */
    protected function condition_text(\DOMNode $node, int $depth): string {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }
        if ($node->nodeName === 'ul' || $node->nodeName === 'ol') {
            $items = [];
            foreach ($node->childNodes as $child) {
                if ($child->nodeName === 'li') {
                    $item = trim(preg_replace('/\s+/u', ' ', $this->condition_text($child, $depth + 1)));
                    if ($item !== '') {
                        $items[] = $item;
                    }
                }
            }
            $list = implode(get_string('conditionsep', 'report_completiondoctor'), $items);
            return $depth > 0 ? ' ' . get_string('conditiongroup', 'report_completiondoctor', $list) : ' ' . $list;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->condition_text($child, $depth);
        }
        return $text;
    }

    /**
     * What an activity's or section's restriction waits for, worked out once per report.
     *
     * @param \cm_info|\section_info $holder
     * @return array|null See waits_for().
     */
    protected function holder_waits($holder): ?array {
        $key = ($holder instanceof \section_info ? 's' : 'c') . $holder->id;
        if (!array_key_exists($key, $this->waits)) {
            if ($holder instanceof \section_info) {
                $this->waits[$key] = $this->waits_for(
                    $holder->availability,
                    new \core_availability\info_section($holder),
                    null,
                    (int) $holder->id
                );
            } else {
                $this->waits[$key] = $this->waits_for(
                    $holder->availability,
                    new \core_availability\info_module($holder),
                    (int) $holder->id,
                    null
                );
            }
        }
        return $this->waits[$key];
    }

    /**
     * The activities a restriction waits for, when doing them is all it takes.
     *
     * Only a restriction made of activity completion and activity grade conditions, joined
     * by "all" or "any" and not negated, waits for activities; conditions the learner
     * already meets are left out. A condition counts only when doing the activity can meet
     * it: completion that must be complete (or passed), and a minimum grade with no maximum.
     * A completion condition on an activity that is failed but counts as complete is a
     * restriction to look at, not a wait. A nested set of conditions the learner already
     * meets is left out; any other makes it a restriction.
     *
     * The kinds: 'all' or 'any' when it waits for activities that can open; 'never' when it
     * needs an activity that never opens (see can_ever_open()); 'loop' when the activities
     * wait for each other; 'cannot' when what it asks of an activity can never happen (a
     * pass with no passing grade, completion of an activity that does not track it).
     *
     * @param string|null $availability The restriction as stored, in JSON.
     * @param \core_availability\info $info The item's availability.
     * @param int|null $selfcmid The restricted activity, when it is one.
     * @param int|null $selfsectionid The restricted section, when it is one.
     * @return array|null With kind, activities and, for 'cannot', why (string ids by course
     *     module id), or null when the restriction asks for anything else.
     */
    protected function waits_for(
        ?string $availability,
        \core_availability\info $info,
        ?int $selfcmid,
        ?int $selfsectionid
    ): ?array {
        $tree = $availability ? json_decode($availability) : null;
        if (!is_object($tree) || empty($tree->c) || !in_array($tree->op ?? '&', ['&', '|'], true)) {
            return null;
        }
        $open = [];
        $closed = [];
        $looped = [];
        $cannot = [];
        foreach ((array) $tree->c as $node) {
            if (!is_object($node) || (!isset($node->c) && empty($node->type))) {
                return null;
            }
            if ($this->condition_met($node, $info)) {
                continue;
            }
            $classname = isset($node->c) ? '' : '\\availability_' . clean_param($node->type, PARAM_PLUGIN) . '\\condition';
            if (!$classname || !class_exists($classname)) {
                return null;
            }
            $awaited = $this->awaited_activity($node, new $classname($node), $selfcmid, $selfsectionid);
            if ($awaited === null) {
                return null;
            }
            [$other, $why] = $awaited;
            if ($why !== null) {
                $cannot[$other->id] = $why;
                $closed[$other->id] = $other;
            } else if (!$other->uservisible && array_key_exists($other->id, $this->opens) && $this->opens[$other->id] === null) {
                // Already being worked out further up this chain: the chain loops.
                $looped[$other->id] = $other;
            } else if ($this->can_ever_open($other)) {
                $open[$other->id] = $other;
            } else {
                $closed[$other->id] = $other;
            }
        }
        $any = ($tree->op ?? '&') === '|';
        if (($looped || $closed) && (!$any || !$open)) {
            if ($looped) {
                return ['kind' => 'loop', 'activities' => array_values($looped)];
            }
            if ($cannot) {
                return ['kind' => 'cannot', 'activities' => array_values(array_intersect_key($closed, $cannot)), 'why' => $cannot];
            }
            return ['kind' => 'never', 'activities' => array_values($closed)];
        }
        if (!$open) {
            return null;
        }
        // Any one of several, with only one left, is a plain wait for that one.
        return ['kind' => $any && count($open) > 1 ? 'any' : 'all', 'activities' => array_values($open)];
    }

    /**
     * Whether an activity can ever open for the learner, as things stand.
     *
     * It cannot when it is hidden, being deleted or closed by a permission (see
     * closed_for_good()), or when what holds it back can never be met: it waits for
     * activities that never open, the activities wait for each other, or it asks for
     * something an activity cannot reach. A restriction that will be met in time (a date,
     * another activity done) does not stop it. Each answer is worked out once per report.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected function can_ever_open(\cm_info $cm): bool {
        if ($cm->uservisible) {
            return true;
        }
        if (array_key_exists($cm->id, $this->opens)) {
            // Null means it is being worked out further up this chain: a loop never opens.
            return (bool) $this->opens[$cm->id];
        }
        if ($this->closed_for_good($cm)) {
            return $this->opens[$cm->id] = false;
        }
        $holder = $this->holder($cm);
        if ($holder === null) {
            // Nothing holds it back for the learner (see check_availability()).
            return $this->opens[$cm->id] = true;
        }
        $this->opens[$cm->id] = null;
        $wait = $this->holder_waits($holder);
        return $this->opens[$cm->id] = !$wait || !in_array($wait['kind'], ['never', 'loop', 'cannot'], true);
    }

    /**
     * Whether an activity is closed to the learner for good: hidden, being deleted, or closed
     * by a permission.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected function closed_for_good(\cm_info $cm): bool {
        if ($cm->uservisible) {
            return false;
        }
        if ($cm->deletioninprogress || $this->hidden_by($cm) !== null) {
            return true;
        }
        return $this->holder($cm) === null && $cm->is_user_access_restricted_by_capability();
    }

    /**
     * Whether the learner meets one condition of a restriction.
     *
     * A condition can only be checked while its restriction is being checked (it reads the
     * course through it), so it is checked as a restriction of its own on the same item.
     * Each answer is kept for the report.
     *
     * @param \stdClass $node The condition as stored.
     * @param \core_availability\info $info The item's availability.
     * @return bool
     */
    protected function condition_met(\stdClass $node, \core_availability\info $info): bool {
        $single = json_encode(['op' => '&', 'c' => [$node], 'showc' => [true]]);
        $isection = $info instanceof \core_availability\info_section;
        $key = ($isection ? 's' . $info->get_section()->id : 'c' . $info->get_course_module()->id) . ':' . $single;
        if (isset($this->met[$key])) {
            return $this->met[$key];
        }
        if ($isection) {
            $check = new class ($info->get_section(), $single) extends \core_availability\info_section {
                /**
                 * The section, with another restriction.
                 *
                 * @param \section_info $section
                 * @param string $availability The restriction to check, in JSON.
                 */
                public function __construct(\section_info $section, string $availability) {
                    parent::__construct($section);
                    $this->availability = $availability;
                }
            };
        } else {
            $check = new class ($info->get_course_module(), $single) extends \core_availability\info_module {
                /**
                 * The activity, with another restriction.
                 *
                 * @param \cm_info $cm
                 * @param string $availability The restriction to check, in JSON.
                 */
                public function __construct(\cm_info $cm, string $availability) {
                    parent::__construct($cm);
                    $this->availability = $availability;
                }
            };
        }
        $information = '';
        return $this->met[$key] = $check->is_available($information, false, (int) $this->user->id, $this->modinfo);
    }

    /**
     * The activity a single condition waits for, when it is a condition on an activity.
     *
     * @param \stdClass $node The condition as stored.
     * @param \core_availability\condition $condition The same condition, loaded.
     * @param int|null $selfcmid The restricted activity, when it is one.
     * @param int|null $selfsectionid The restricted section, when it is one.
     * @return array|null [the activity, null or the string id of why the condition can never
     *     be met], or null when doing an activity is not what it asks.
     */
    protected function awaited_activity(
        \stdClass $node,
        \core_availability\condition $condition,
        ?int $selfcmid,
        ?int $selfsectionid
    ): ?array {
        if ($node->type === 'completion') {
            $expected = (int) ($node->e ?? COMPLETION_COMPLETE);
            if (!in_array($expected, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])) {
                return null;
            }
            // Resolves "the previous activity" the way the condition itself does.
            $other = $this->modinfo->cms[(int) $condition->get_cmid($this->course, $selfcmid, $selfsectionid)] ?? null;
            if (!$other) {
                return null;
            }
            if (!$this->completion->is_enabled($other)) {
                return [$other, 'whynotracking'];
            }
            // Complete and passed needs a passing grade to reach; without one it never comes.
            if ($expected == COMPLETION_COMPLETE_PASS && !$this->can_pass($other)) {
                return [$other, 'whynopass'];
            }
            // A failed activity that already counts as complete for the course (no passing grade
            // asked for) looks done in this report, so the restriction itself is what to look at.
            $state = $this->state($other);
            $failedbutcounts = $state == COMPLETION_COMPLETE_FAIL && $this->counts_as_complete($other, $state);
            if ($expected == COMPLETION_COMPLETE && $failedbutcounts) {
                return null;
            }
            return [$other, null];
        }
        if ($node->type === 'grade' && isset($node->min) && !isset($node->max)) {
            $item = grade_item::fetch(['id' => (int) ($node->id ?? 0), 'courseid' => $this->course->id]);
            if ($item && $item->itemtype === 'mod') {
                $other = $this->modinfo->instances[$item->itemmodule][$item->iteminstance] ?? null;
                return $other ? [$other, null] : null;
            }
        }
        return null;
    }

    /**
     * Whether an activity can be passed: its completion uses a grade with a passing grade set.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected function can_pass(\cm_info $cm): bool {
        if ($cm->completion != COMPLETION_TRACKING_AUTOMATIC || $cm->completiongradeitemnumber === null) {
            return false;
        }
        $item = grade_item::fetch([
            'courseid' => $this->course->id,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => (int) $cm->completiongradeitemnumber,
        ]);
        return $item && $item->gradepass > 0;
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
        $admin = has_capability('moodle/site:config', context_system::instance());

        if (isset($CFG->cron_enabled) && empty($CFG->cron_enabled)) {
            $label = get_string('cronprocessing', 'report_completiondoctor');
            $problem = get_string('crondisabled', 'report_completiondoctor');
            if ($admin) {
                $problem = $this->sentences([$problem, get_string('crondisabledhint', 'report_completiondoctor')]);
            }
            $rows[] = $this->row($label, false, $problem);
            $this->taskproblems[] = $this->entry($this->labelled($label, $problem), [
                $this->admin_link(new moodle_url('/admin/settings.php', ['section' => 'taskprocessing']), 'linktaskprocessing'),
            ]);
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
            $detail = $lastrun
                ? get_string('tasklastrun', 'report_completiondoctor', userdate($lastrun))
                : get_string('tasknever', 'report_completiondoctor');
            $problem = $this->task_problem($task);
            if ($problem !== null) {
                [$text, $hint, $action] = $problem;
                // Only an administrator can act on where to look in Site administration.
                if ($admin) {
                    $text = $this->sentences([$text, get_string($hint, 'report_completiondoctor')]);
                }
                $detail = $this->note($detail, $text);
                $this->taskproblems[] = $this->entry($this->labelled($label, $text), [$action]);
            } else if ($task->get_next_run_time() > 0) {
                $nextrun = (int) $task->get_next_run_time();
                $detail = $this->note($detail, get_string(
                    'tasknextrun',
                    'report_completiondoctor',
                    $nextrun <= time() ? get_string('taskduenow', 'report_completiondoctor') : userdate($nextrun)
                ));
            }
            $rows[] = $this->row($label, $problem === null, $detail);
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
     * @return array|null [problem text, string id of the hint for administrators, link to the
     *     admin page for it or null], or null when the task is on schedule.
     */
    protected function task_problem(\core\task\scheduled_task $task): ?array {
        $nextrun = (int) $task->get_next_run_time();
        $tasks = new moodle_url('/admin/tool/task/scheduledtasks.php');
        if ($task->get_disabled()) {
            return [
                get_string('taskdisabled', 'report_completiondoctor'),
                'taskdisabledhint',
                $this->admin_link($tasks, 'linkscheduledtasks'),
            ];
        }
        if ($task->get_fail_delay() > 0) {
            return [
                get_string('taskfailing', 'report_completiondoctor'),
                'taskfailinghint',
                $this->admin_link(new moodle_url('/admin/tasklogs.php'), 'linktasklogs'),
            ];
        }
        if ($nextrun > 0 && $nextrun < time() - HOURSECS) {
            return [
                get_string('taskoverdue', 'report_completiondoctor', userdate($nextrun)),
                'taskoverduehint',
                $this->admin_link($tasks, 'linkscheduledtasks'),
            ];
        }
        return null;
    }

    /**
     * Final verdict from the problems the checks found.
     *
     * Background tasks that are late are kept apart: while anything else is missing they
     * are a short note, and they are the answer only when everything else is done. With
     * everything done, core marks the course only when it has been asked to check this
     * learner again (course_completions.reaggregate, set when a criterion is recorded);
     * when it has not, waiting for the task will not help, and the verdict says so.
     *
     * @return array
     */
    protected function build_verdict(): array {
        global $DB;
        $iscomplete = $this->completion->is_course_complete($this->user->id);
        $problems = array_values($this->problems);
        usort($problems, fn(array $a, array $b): int => [$a['order'], $a['sequence']] <=> [$b['order'], $b['sequence']]);
        $askadmin = has_capability('moodle/site:config', context_system::instance())
            ? '' : get_string('taskaskadmin', 'report_completiondoctor');

        $blockers = [];
        $tasknote = '';
        if ($iscomplete) {
            $message = get_string('verdictcomplete', 'report_completiondoctor');
        } else if ($problems) {
            $message = get_string('verdictincomplete', 'report_completiondoctor');
            $blockers = $problems;
            // With tracking off the tasks have nothing to do, so they are not worth a note.
            if ($this->taskproblems && $this->tracking) {
                $tasknote = $this->sentences(array_filter([get_string('verdicttasknote', 'report_completiondoctor'), $askadmin]));
            }
        } else if (
            !$this->pending && !$DB->record_exists_select(
                'course_completions',
                'userid = :userid AND course = :course AND reaggregate > 0',
                ['userid' => $this->user->id, 'course' => $this->course->id]
            )
        ) {
            $message = get_string('verdictnotrechecked', 'report_completiondoctor');
        } else if ($this->taskproblems) {
            $message = get_string('verdictwaitingfortasks', 'report_completiondoctor');
            $blockers = $this->taskproblems;
            $tasknote = $askadmin;
        } else {
            $message = get_string('verdictcompletebutmarked', 'report_completiondoctor');
        }

        return [
            'iscomplete' => $iscomplete,
            'message' => $message,
            'blockers' => array_map(fn(array $problem): array => [
                'text' => $problem['text'],
                'actions' => $problem['actions'],
            ], $blockers),
            'hasblockers' => !empty($blockers),
            'tasknote' => $tasknote,
        ];
    }

    /**
     * Record a problem for the verdict, once per key.
     *
     * @param string $key What the problem is about, such as 'site' or 'cm42'.
     * @param int $order One of the ORDER_ constants.
     * @param string $text Plain text, one or more full sentences.
     * @param array $actions Links from link() and its relatives; nulls are left out.
     * @param string|null $askid String id of whom to ask, said when the viewer gets no link.
     */
    protected function add_problem(string $key, int $order, string $text, array $actions = [], ?string $askid = null): void {
        if (isset($this->problems[$key])) {
            return;
        }
        $this->problems[$key] = $this->entry($text, $actions, $askid) + ['order' => $order, 'sequence' => count($this->problems)];
    }

    /**
     * Record why an activity is not complete.
     *
     * @param \cm_info $cm
     * @param int $order One of the ORDER_ constants.
     * @param string $text Plain text, one or more full sentences that name the activity.
     * @param array $actions Links from link() and its relatives; nulls are left out.
     * @param string|null $askid String id of whom to ask, said when the viewer gets no link.
     */
    protected function add_cause(\cm_info $cm, int $order, string $text, array $actions = [], ?string $askid = null): void {
        $this->causes[$cm->id][] = $this->entry($text, $actions, $askid) + ['order' => $order];
    }

    /**
     * A verdict entry: text and the links that lead to where it is fixed.
     *
     * A viewer who gets no link is told whom to ask, when that is given. The text without that
     * sentence is kept too, for entries made of several causes, which say it once at the end.
     *
     * @param string $text
     * @param array $actions Links; nulls (links the viewer may not use) and repeats are left out.
     * @param string|null $askid String id of whom to ask when there is no link.
     * @return array With text, base (the text alone), actions and askid.
     */
    protected function entry(string $text, array $actions = [], ?string $askid = null): array {
        $unique = [];
        foreach (array_filter($actions) as $action) {
            $unique[$action['url']] = $action;
        }
        $full = $text;
        if (!$unique && $askid !== null) {
            $full = $this->sentences([$text, get_string($askid, 'report_completiondoctor')]);
        }
        return ['text' => $full, 'base' => $text, 'actions' => array_values($unique), 'askid' => $askid];
    }

    /**
     * A link for the verdict.
     *
     * @param moodle_url|null $url
     * @param string $labelid String id of the link text.
     * @param string|null $context What the link is about, added for screen readers when the
     *     same link text is used for different pages.
     * @param string|null $a Value for the link text's placeholder.
     * @return array|null With url, label and arialabel, or null without a URL.
     */
    protected function link(?moodle_url $url, string $labelid, ?string $context = null, ?string $a = null): ?array {
        if (!$url) {
            return null;
        }
        $label = get_string($labelid, 'report_completiondoctor', $a);
        return [
            'url' => $url->out(false),
            'label' => $label,
            'arialabel' => $context === null ? null : $this->labelled($label, $context),
        ];
    }

    /**
     * A link to a site administration page, for viewers who may change site settings.
     *
     * @param moodle_url $url
     * @param string $labelid String id of the link text.
     * @return array|null
     */
    protected function admin_link(moodle_url $url, string $labelid): ?array {
        return has_capability('moodle/site:config', context_system::instance()) ? $this->link($url, $labelid) : null;
    }

    /**
     * A link to a course settings page, for viewers who may change the course settings.
     *
     * @param moodle_url $url
     * @param string $labelid String id of the link text.
     * @return array|null
     */
    protected function course_link(moodle_url $url, string $labelid): ?array {
        return has_capability('moodle/course:update', $this->context) ? $this->link($url, $labelid) : null;
    }

    /**
     * A link to an activity's settings, for viewers who may change them.
     *
     * @param \cm_info $cm
     * @return array|null
     */
    protected function settings_link(\cm_info $cm): ?array {
        if (!has_capability('moodle/course:manageactivities', $cm->context)) {
            return null;
        }
        return $this->link(
            new moodle_url('/course/modedit.php', ['update' => $cm->id]),
            'linkactivitysettings',
            $this->cm_name($cm)
        );
    }

    /**
     * A link to a section on the course page, where it is shown or hidden, for viewers who may.
     *
     * @param \section_info $section
     * @return array|null
     */
    protected function section_link(\section_info $section): ?array {
        if (!has_capability('moodle/course:sectionvisibility', $this->context)) {
            return null;
        }
        $parent = $this->parent_cm($section);
        if ($parent) {
            // A subsection is shown or hidden on the course page, from the section holding it.
            if (!has_capability('moodle/course:activityvisibility', $parent->context)) {
                return null;
            }
            $holding = $parent->get_section_info();
            return $this->link(course_get_url($this->course, $holding->section), 'linksection', $this->section_name($holding));
        }
        return $this->link(course_get_url($this->course, $section->section), 'linksection', $this->section_name($section));
    }

    /**
     * A link to a section's settings, where its restriction is changed, for viewers who may.
     *
     * @param \section_info $section
     * @return array|null
     */
    protected function section_settings_link(\section_info $section): ?array {
        if (!has_capability('moodle/course:update', $this->context)) {
            return null;
        }
        return $this->link(
            new moodle_url('/course/editsection.php', ['id' => $section->id]),
            'linksectionsettings',
            $this->section_name($section)
        );
    }

    /**
     * A link to an activity's permissions, for viewers who may review them.
     *
     * @param \cm_info $cm
     * @return array|null
     */
    protected function permissions_link(\cm_info $cm): ?array {
        // Reviewing is not enough: the link is for those who can change an override there.
        if (
            !has_capability('moodle/role:review', $cm->context)
            || !has_any_capability(['moodle/role:safeoverride', 'moodle/role:override'], $cm->context)
        ) {
            return null;
        }
        return $this->link(
            new moodle_url('/admin/roles/permissions.php', ['contextid' => $cm->context->id]),
            'linkpermissions',
            $this->cm_name($cm)
        );
    }

    /**
     * Whether the viewer may mark a role criterion in the course completion report.
     *
     * The report lets a user mark only the first role criterion whose role they hold in the
     * course, or in a category above it (report/completion/index.php).
     *
     * @param int $criterionid
     * @param int $roleid
     * @return bool
     */
    protected function can_mark(int $criterionid, int $roleid): bool {
        global $USER;
        if (!$roleid || !has_capability('report/completion:view', $this->context)) {
            return false;
        }
        foreach ($this->completion->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE) as $criterion) {
            if (array_key_exists($USER->id, get_role_users($criterion->role, $this->context, true))) {
                return (int) $criterion->id === $criterionid;
            }
        }
        return false;
    }

    /**
     * Whether learners see a Self completion block on the course page.
     *
     * The block plugin is switched off on new sites since Moodle 4.0
     * (blocks/selfcompletion/db/install.php). When it is on, the block must be placed in the
     * course, or shown throughout from a category or the site on course pages, and not
     * hidden on this course's page.
     *
     * @return string 'shown', 'disabled' (the plugin is off for the site) or 'missing'.
     */
    protected function self_completion_block(): string {
        global $DB;
        if (!$DB->record_exists('block', ['name' => 'selfcompletion', 'visible' => 1])) {
            return 'disabled';
        }
        $contexts = $this->context->get_parent_context_ids(true);
        [$insql, $params] = $DB->get_in_or_equal($contexts, SQL_PARAMS_NAMED);
        $instances = $DB->get_records_select(
            'block_instances',
            "blockname = 'selfcompletion' AND parentcontextid {$insql}",
            $params,
            '',
            'id, parentcontextid, showinsubcontexts, pagetypepattern'
        );
        $pagetype = 'course-view-' . $this->course->format;
        foreach ($instances as $instance) {
            $here = $instance->parentcontextid == $this->context->id;
            if (!$here && empty($instance->showinsubcontexts)) {
                continue;
            }
            if (!in_array($instance->pagetypepattern, ['*', 'course-*', 'course-view-*', $pagetype], true)) {
                continue;
            }
            // A teacher can hide a block on the course page; core reads that for the page type.
            $hidden = $DB->record_exists('block_positions', ['blockinstanceid' => $instance->id,
                'contextid' => $this->context->id, 'pagetype' => $pagetype, 'visible' => 0]);
            if (!$hidden) {
                return 'shown';
            }
        }
        return 'missing';
    }

    /**
     * Whether a section's restriction keeps any activity the course still needs closed.
     *
     * Activities in subsections inside the section count too.
     *
     * @param \section_info $section
     * @return bool
     */
    protected function section_blocks(\section_info $section): bool {
        foreach ($this->modinfo->get_cms() as $cm) {
            if (!$this->is_needed($cm)) {
                continue;
            }
            for ($current = $cm->get_section_info(); $current; $current = $this->parent_section($current)) {
                if ($current->id == $section->id) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The section that holds a subsection.
     *
     * @param \section_info $section
     * @return \section_info|null Null for a section that is not a subsection.
     */
    protected function parent_section(\section_info $section): ?\section_info {
        $parent = $this->parent_cm($section);
        return $parent ? $parent->get_section_info() : null;
    }

    /**
     * A section's name as plain text.
     *
     * @param \section_info $section
     * @return string
     */
    protected function section_name(\section_info $section): string {
        return $this->plain(get_section_name($this->course, $section));
    }

    /**
     * Whether an activity is a course completion criterion the learner has not completed.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected function is_needed(\cm_info $cm): bool {
        if (!in_array((int) $cm->id, $this->criteriacmids)) {
            return false;
        }
        return !$this->counts_as_complete($cm, $this->state($cm));
    }

    /**
     * The learner's completion state for an activity.
     *
     * Read once per activity: for a user other than the viewer, core reads it from the
     * database on every call.
     *
     * @param \cm_info $cm
     * @return int A COMPLETION_* state.
     */
    protected function state(\cm_info $cm): int {
        if (!isset($this->states[$cm->id])) {
            $this->states[$cm->id] = (int) $this->completion->get_data($cm, false, $this->user->id)->completionstate;
        }
        return $this->states[$cm->id];
    }

    /**
     * Build one status row for the template.
     *
     * @param string $label Plain text.
     * @param bool $ok
     * @param string $detail Plain text.
     * @param bool $skipped Not checked, because something above it already fails.
     * @return array
     */
    protected function row(string $label, bool $ok, string $detail, bool $skipped = false): array {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'skipped' => $skipped];
    }

    /**
     * Build one row of the hidden and restricted table.
     *
     * @param string $label
     * @param string $detail
     * @param bool $blocks Whether it keeps the learner from completing the course.
     * @return array
     */
    protected function availability_row(string $label, string $detail, bool $blocks): array {
        return ['label' => $label, 'detail' => $detail, 'blocks' => $blocks];
    }

    /**
     * Sentences one after another, joined as the language joins them.
     *
     * @param string[] $sentences
     * @return string
     */
    protected function sentences(array $sentences): string {
        $text = array_shift($sentences) ?? '';
        foreach ($sentences as $next) {
            $text = get_string('sentences', 'report_completiondoctor', (object) ['first' => $text, 'second' => $next]);
        }
        return $text;
    }

    /**
     * Text with a note after it, as in a table cell.
     *
     * @param string $text
     * @param string $note
     * @return string
     */
    protected function note(string $text, string $note): string {
        return get_string('withnote', 'report_completiondoctor', (object) ['text' => $text, 'note' => $note]);
    }

    /**
     * Text with a note after it, or the note alone when there is no text yet.
     *
     * @param string $text
     * @param string $note
     * @return string
     */
    protected function note_after(string $text, string $note): string {
        return $text === '' ? $note : $this->note($text, $note);
    }

    /**
     * A label and its text, as in "Daily completion task: ...".
     *
     * @param string $label
     * @param string $text
     * @return string
     */
    protected function labelled(string $label, string $text): string {
        return $label . get_string('labelsep', 'langconfig') . $text;
    }

    /**
     * A list of names, separated as the language separates lists.
     *
     * @param string[] $names
     * @return string
     */
    protected function names(array $names): string {
        return implode(get_string('listsep', 'langconfig') . ' ', $names);
    }

    /**
     * Text from core that may not end as a sentence, made into one.
     *
     * @param string $text
     * @return string
     */
    protected function sentence(string $text): string {
        return $text === '' ? '' : get_string('sentence', 'report_completiondoctor', $text);
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
     * Whether an activity state completes the activity for course completion.
     *
     * The same rule core applies when it marks activity criteria
     * (\core_completion\api::mark_course_completions_activity_criteria()): complete or passed,
     * or failed when completion does not ask for the passing grade.
     *
     * @param \cm_info $cm
     * @param int $state A COMPLETION_* state.
     * @return bool
     */
    protected function counts_as_complete(\cm_info $cm, int $state): bool {
        return $state == COMPLETION_COMPLETE || $state == COMPLETION_COMPLETE_PASS
            || ($state == COMPLETION_COMPLETE_FAIL && empty($cm->completionpassgrade));
    }

    /**
     * Map a COMPLETION_* state to a label and ok-flag.
     *
     * @param \cm_info $cm
     * @param int $state
     * @return array [label, ok]
     */
    protected function state_label(\cm_info $cm, int $state): array {
        switch ($state) {
            case COMPLETION_COMPLETE:
                return [get_string('statecomplete', 'report_completiondoctor'), true];
            case COMPLETION_COMPLETE_PASS:
                return [get_string('statecompletepass', 'report_completiondoctor'), true];
            case COMPLETION_COMPLETE_FAIL:
            case COMPLETION_COMPLETE_FAIL_HIDDEN:
                return $this->counts_as_complete($cm, $state)
                    ? [get_string('statecompletefailing', 'report_completiondoctor'), true]
                    : [get_string('statecompletefail', 'report_completiondoctor'), false];
            default:
                return [get_string('stateincomplete', 'report_completiondoctor'), false];
        }
    }
}
