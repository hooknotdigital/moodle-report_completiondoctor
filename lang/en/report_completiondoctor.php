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
 * English strings for Completion Doctor.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activity'] = 'Activity';
$string['aggregationall'] = 'All of the criteria below are required.';
$string['aggregationany'] = 'Any one of the criteria below is enough.';
$string['aggregationtypeall'] = '{$a}: all are required.';
$string['aggregationtypeany'] = '{$a}: any one is enough.';
$string['assignsubmission'] = 'Submission status: {$a}';
$string['blockedactivity'] = '{$a->activity} is currently hidden from this learner';
$string['blockedsection'] = 'Section "{$a->section}" is currently hidden from this learner';
$string['blockeranyof'] = '{$a->type}: complete at least one of {$a->titles}';
$string['blockeranyofcriteria'] = 'Any one of these completes the course, and none is done yet: {$a}';
$string['blockercriterion'] = '{$a->type}: {$a->title} is not complete';
$string['chaincoursedisabled'] = 'Completion tracking is disabled in this course\'s settings. No activity in this course can be marked complete.';
$string['chainnocriteria'] = 'No course completion criteria are set. Learners can complete activities, but the course itself will never be marked complete.';
$string['chainsitedisabled'] = 'Completion tracking is disabled for the whole site (Site administration > Advanced features). Nothing can be marked complete until it is enabled.';
$string['choiceallof'] = '{$a->type} (all of {$a->titles})';
$string['choiceanyof'] = '{$a->type} (at least one of {$a->titles})';
$string['choosecoursefirst'] = 'Choose a course first, then pick the learner.';
$string['complete'] = 'Complete';
$string['completedon'] = 'Completed on {$a}';
$string['completiondoctor:view'] = 'View Completion Doctor diagnostics';
$string['coursecompletestatus'] = 'Course completion status for this learner';
$string['coursecompletion'] = 'Course-level completion tracking';
$string['coursecriteriaset'] = 'Course completion criteria configured';
$string['crondisabled'] = 'Cron is switched off for this site, so no scheduled task runs and completion is not updated. Switch it on in Site administration > Server > Tasks > Task processing.';
$string['cronprocessing'] = 'Scheduled task processing';
$string['details'] = 'Details';
$string['disabled'] = 'Disabled';
$string['enabled'] = 'Enabled';
$string['gradehidden'] = 'The grade is hidden from the learner, so they cannot see the failing grade that keeps this activity incomplete.';
$string['gradepassmissing'] = 'Completion asks for a passing grade, but no passing grade is set in the gradebook, so Moodle counts any grade, even a failing one, as complete.';
$string['gradepasstrap'] = 'A passing grade is set in the gradebook, but completion only needs "receive a grade", so the learner can complete this activity with a failing grade.';
$string['gradesofar'] = 'Grade: {$a->grade} / {$a->max} (pass: {$a->pass})';
$string['hiddenmanually'] = '{$a} is hidden by the teacher (this is not an access restriction).';
$string['incomplete'] = 'Incomplete';
$string['noblockedactivities'] = 'No access restrictions are blocking this learner from any tracked activity.';
$string['nogradeyet'] = 'No grade yet';
$string['nolearnerselected'] = 'No learner chosen';
$string['nopassgrade'] = 'none set';
$string['nosubmission'] = 'No submission';
$string['notset'] = 'Not set';
$string['othersincomplete'] = '{$a} other learners in this course have not completed it.';
$string['othersincompletenone'] = 'There are no other learners in this course who have not completed it.';
$string['othersincompleteone'] = '1 other learner in this course has not completed it.';
$string['pluginname'] = 'Completion Doctor';
$string['privacy:metadata'] = 'The Completion Doctor plugin only displays existing completion data and does not store any personal data itself.';
$string['quizattempts'] = '{$a} finished attempt(s)';
$string['reporttitle'] = 'Diagnosis: {$a->user} in {$a->course}';
$string['requiregrade'] = 'Receive a grade';
$string['requirement'] = 'Requirements';
$string['requirepassgrade'] = 'Passing grade';
$string['requireview'] = 'View';
$string['restrictreason'] = 'Condition: {$a}';
$string['rundiagnosis'] = 'Run diagnosis';
$string['searchlearner'] = 'Name or email';
$string['searchminchars'] = 'Type at least {$a} characters to search.';
$string['searchmore'] = 'More learners match than the {$a} shown. Keep typing to narrow the list.';
$string['sectionactivities'] = 'Activity breakdown';
$string['sectionavailability'] = 'Access restrictions affecting this learner';
$string['sectionchain'] = 'Completion tracking chain';
$string['sectioncriteria'] = 'Course completion criteria';
$string['sectioncron'] = 'Background task health';
$string['sectionverdict'] = 'Verdict';
$string['selectcourse'] = 'Course';
$string['selectuser'] = 'Learner';
$string['selectuser_help'] = 'Type at least two characters of the learner\'s name, email address or another identity field the site shows you, then pick the learner. Only learners with an active enrolment and an account that is not suspended, whom the course tracks for completion, are offered. In a course with separate groups, you are offered only the learners in your own groups, unless you may access all groups.';
$string['sitecompletion'] = 'Site-level completion tracking';
$string['state'] = 'State';
$string['statecomplete'] = 'Complete';
$string['statecompletefail'] = 'Complete (failed)';
$string['statecompletepass'] = 'Complete (passed)';
$string['stateincomplete'] = 'Incomplete';
$string['statusok'] = 'OK';
$string['statusproblem'] = 'Problem';
$string['taskdaily'] = 'Daily completion task';
$string['taskdisabled'] = 'This task is disabled, so it never runs. Enable it in Site administration > Server > Tasks > Scheduled tasks.';
$string['taskduenow'] = 'due now';
$string['taskfailing'] = 'This task failed the last time it ran, and Moodle is waiting before it tries again. See Site administration > Server > Tasks > Task logs.';
$string['tasklastrun'] = 'Last run: {$a}';
$string['tasknever'] = 'Never run';
$string['tasknextrun'] = 'Next run: {$a}';
$string['taskoverdue'] = 'This task was due at {$a} and has not run since. Completion may be out of date: check that cron is running.';
$string['taskregular'] = 'Regular completion task';
$string['tracking'] = 'Tracking';
$string['trackingauto'] = 'Automatic';
$string['trackingmanual'] = 'Manual';
$string['usernotenrolled'] = 'This user is not an active learner in the selected course.';
$string['verdictcomplete'] = 'This learner has completed the course.';
$string['verdictcompletebutmarked'] = 'The requirements are met, but the course is not marked complete yet. The next run of the regular completion task should mark it.';
$string['verdictincomplete'] = 'This learner has not completed the course. What is blocking it:';
