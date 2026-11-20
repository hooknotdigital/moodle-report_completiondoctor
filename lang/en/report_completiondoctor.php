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
$string['alsolocks'] = 'It also keeps {$a} locked.';
$string['askadmin'] = 'Ask your site administrator to change it.';
$string['askeditor'] = 'Ask a teacher who can edit this course to change it.';
$string['askeditorrestriction'] = 'A teacher who can edit this course can change the restriction.';
$string['assignsubmission'] = 'Submission status: {$a}';
$string['awaitedrestricted'] = '{$a->activity} is not available to this learner yet. {$a->reason}';
$string['blockedactivity'] = '{$a->activity} is not available to this learner yet';
$string['blockedpermission'] = '{$a} cannot be opened by this learner';
$string['blockedpermissiondetail'] = 'Its access restrictions are met, so a permission keeps it closed';
$string['blockedsection'] = 'Section "{$a->section}" is not available to this learner yet';
$string['blockeranyof'] = '{$a->type}: complete at least one of {$a->titles}.';
$string['blockeranyofcriteria'] = 'Any one of these completes the course, and none is done yet: {$a}.';
$string['blockercriterion'] = '{$a->type}: {$a->title} is not complete.';
$string['chaincoursedisabled'] = 'Completion tracking is disabled in this course\'s settings. No activity in this course can be marked complete.';
$string['chainnocriteria'] = 'No course completion criteria are set. Learners can complete activities, but the course itself will never be marked complete.';
$string['chainsitedisabled'] = 'Completion tracking is disabled for the whole site (Site administration > Advanced features). Nothing can be marked complete until it is enabled.';
$string['choiceallof'] = '{$a->type} (all of {$a->titles})';
$string['choiceanyof'] = '{$a->type} (at least one of {$a->titles})';
$string['choicesep'] = '; ';
$string['chooseacoursefirst'] = 'Choose a course first.';
$string['choosecoursefirst'] = 'Choose a course first, then pick the learner.';
$string['chooselearnerfirst'] = 'Choose a learner first.';
$string['complete'] = 'Complete';
$string['completedon'] = 'Completed on {$a}';
$string['completiondoctor:view'] = 'View Completion Doctor diagnostics';
$string['conditiongroup'] = '({$a})';
$string['conditionsep'] = '; ';
$string['coursecompletestatus'] = 'Course completion status for this learner';
$string['coursecompletion'] = 'Course-level completion tracking';
$string['coursecriteriaset'] = 'Course completion criteria configured';
$string['crondisabled'] = 'Cron is switched off for this site, so no scheduled task runs and completion is not updated.';
$string['crondisabledhint'] = 'Switch it on in Site administration > Server > Tasks > Task processing.';
$string['cronprocessing'] = 'Scheduled task processing';
$string['details'] = 'Details';
$string['disabled'] = 'Disabled';
$string['enabled'] = 'Enabled';
$string['gradehidden'] = 'The grade is hidden from the learner, so they cannot see the failing grade that keeps this activity incomplete.';
$string['gradepassmissing'] = 'Completion asks for a passing grade, but no passing grade is set in the gradebook, so Moodle counts any grade, even a failing one, as complete.';
$string['gradepasstrap'] = 'A passing grade is set in the gradebook, but completion only needs "receive a grade", so the learner can complete this activity with a failing grade.';
$string['gradesofar'] = 'Grade: {$a->grade} / {$a->max} (pass: {$a->pass})';
$string['hiddeninsection'] = '{$a} is in a section that is hidden from learners';
$string['hiddeninsubsection'] = '{$a} is in a subsection that is hidden from learners';
$string['hiddenmanually'] = '{$a} is hidden from learners';
$string['hintcourse'] = 'The learner must complete that course first.';
$string['hintdate'] = 'Moodle marks it on that date.';
$string['hintduration'] = 'Moodle marks it once the learner has been enrolled for that long.';
$string['hintgrade'] = 'The learner\'s course total must reach that grade.';
$string['hintunenrol'] = 'Moodle marks it when the learner is unenrolled.';
$string['incomplete'] = 'Incomplete';
$string['keepslocked'] = 'It keeps {$a} locked.';
$string['learneraccountsuspended'] = 'This user\'s account is suspended for the whole site, so Moodle does not treat them as a learner in any course. An administrator can reactivate it in Site administration > Users > Browse list of users.';
$string['learnerenrolmentinactive'] = 'This user\'s enrolment in this course is suspended, has ended or has not started yet, so the course does not track their completion. The Participants page shows the enrolment.';
$string['learnernotactive'] = 'This user is not an active learner in this course.';
$string['learnernotenrolled'] = 'This user is not enrolled in this course.';
$string['learnernotinyourgroups'] = 'This course uses separate groups, and this user is not a learner in any of your groups.';
$string['learnernottracked'] = 'This user is enrolled, but with a role the course does not track for completion, such as a teacher role.';
$string['linkactivity'] = 'Open the activity';
$string['linkactivitysettings'] = 'Edit the activity settings';
$string['linkadvancedfeatures'] = 'Open Advanced features';
$string['linkcompletionreport'] = 'Open the course completion report';
$string['linkcompletionsettings'] = 'Edit the course completion conditions';
$string['linkcourse'] = 'Open the course page';
$string['linkcoursesettings'] = 'Edit the course settings';
$string['linkmanageblocks'] = 'Open Manage blocks';
$string['linkopen'] = 'Open {$a}';
$string['linkpermissions'] = 'Check the activity permissions';
$string['linkscheduledtasks'] = 'Open Scheduled tasks';
$string['linksection'] = 'Open the section';
$string['linksectionsettings'] = 'Edit the section settings';
$string['linktasklogs'] = 'Open Task logs';
$string['linktaskprocessing'] = 'Open Task processing';
$string['noactivitiestracked'] = 'No activity in this course tracks completion.';
$string['noblockedactivities'] = 'No activity that tracks completion is hidden from this learner or restricted.';
$string['nogradeyet'] = 'No grade yet';
$string['nogroupsnotice'] = 'This course uses separate groups and you are not in any group, so there is no learner you can diagnose here. A teacher who may access all groups can add you to a group.';
$string['nolearnerselected'] = 'No learner chosen';
$string['nopassgrade'] = 'none set';
$string['nosubmission'] = 'No submission';
$string['notchecked'] = 'Not checked while completion tracking is off';
$string['notset'] = 'Not set';
$string['othersincomplete'] = '{$a} other learners in this course have not completed it.';
$string['othersincompletegroups'] = '{$a} other learners in your groups have not completed it.';
$string['othersincompletegroupsnone'] = 'There are no other learners in your groups who have not completed it.';
$string['othersincompletegroupsone'] = '1 other learner in your groups has not completed it.';
$string['othersincompletenone'] = 'There are no other learners in this course who have not completed it.';
$string['othersincompleteone'] = '1 other learner in this course has not completed it.';
$string['pendingtask'] = 'The activity is complete: the next run of the regular completion task records it here';
$string['pluginname'] = 'Completion Doctor';
$string['privacy:metadata'] = 'The Completion Doctor plugin only displays existing completion data and does not store any personal data itself.';
$string['problemdeleting'] = '{$a} is being deleted, but it is still a course completion condition, so no learner can complete it. Remove it from the course completion conditions.';
$string['problemgrade'] = '{$a->activity}: grade {$a->grade} out of {$a->max}, below the passing grade of {$a->pass}.';
$string['problemhidden'] = '{$a} is a course completion condition, but it is hidden from learners, so no learner can complete it. Show it, or remove it from the course completion conditions.';
$string['problemhiddensection'] = '{$a} is a course completion condition, but its section is hidden from learners, so no learner can complete it. Show the section, or remove the activity from the course completion conditions.';
$string['problemhiddensubsection'] = '{$a} is a course completion condition, but its subsection is hidden from learners, so no learner can complete it. Show the subsection, or remove the activity from the course completion conditions.';
$string['problemlocked'] = '{$a->activity} waits for {$a->others}. {$a->reason}';
$string['problemlockedany'] = '{$a->activity} waits for one of {$a->others}. {$a->reason}';
$string['problemlockedcannot'] = '{$a->activity} can never open: its restriction asks for something that cannot happen, because {$a->why}. {$a->reason}';
$string['problemlockedhidden'] = '{$a->activity} waits for {$a->others}, which learners cannot open, so it never opens. {$a->reason}';
$string['problemlockedloop'] = '{$a->activity} and {$a->others} wait for each other, so neither can ever open. {$a->reason}';
$string['problemlockednever'] = '{$a->activity} waits for {$a->others}, which can never open, so it never opens either. {$a->reason}';
$string['problemlockedself'] = '{$a->activity} can never open: its restriction, or its section\'s, waits for {$a->activity} itself. {$a->reason}';
$string['problemnoaccess'] = '{$a} is visible and its access restrictions are met, but this learner still cannot open it, which usually means a permission is missing for them, for example a role override on the activity.';
$string['problemnotdone'] = '{$a} is not complete.';
$string['problemnotdonemanual'] = '{$a} is not complete: the learner has not marked it as done.';
$string['problemnotdonetodo'] = '{$a->activity} is not complete. Still to do: {$a->todo}.';
$string['problemnotracking'] = '{$a} is a course completion condition, but completion tracking is switched off for the activity, so it can never be complete. Switch tracking on in its settings, or remove it from the course completion conditions.';
$string['problemrestricted'] = '{$a->activity} is not available to this learner yet. {$a->reason}';
$string['problemrestrictedsection'] = '{$a->activity} is in the section "{$a->section}", which is not available to this learner yet. {$a->reason}';
$string['problemrestrictedsubsection'] = '{$a->activity} is in the subsection "{$a->section}", which is not available to this learner yet. {$a->reason}';
$string['problemrole'] = 'A user with the {$a} role has not marked this learner complete yet. They do it in Reports > Course completion.';
$string['problemself'] = 'The learner has not marked the course complete yet. Learners do it with the Self completion block on the course page.';
$string['problemselfdisabled'] = 'The learner has not marked the course complete, and cannot: the Self completion block, the only place learners do it, is switched off for the whole site (new sites have it off since Moodle 4.0). Switch it on in Site administration > Plugins > Blocks > Manage blocks and add it to the course page, or remove self completion from the course completion conditions.';
$string['problemselfnoblock'] = 'The learner has not marked the course complete, and cannot: the course has no Self completion block, which is where learners do it. Add the block to the course page, or remove self completion from the course completion conditions.';
$string['quizattempts'] = '{$a} finished attempt(s)';
$string['reporttitle'] = 'Diagnosis: {$a->user} in {$a->course}';
$string['requiredforcourse'] = 'Course completion condition';
$string['requiremanual'] = 'The learner marks it as done';
$string['requirement'] = 'Requirements';
$string['requirementdone'] = 'Done: {$a}';
$string['requirementdonefailing'] = 'Done, with a failing grade: {$a}';
$string['requirementfailed'] = 'Failed: {$a}';
$string['requirementnotdone'] = 'To do: {$a}';
$string['rundiagnosis'] = 'Run diagnosis';
$string['searchlearner'] = 'Name or email';
$string['searchminchars'] = 'Type at least {$a} characters to search.';
$string['searchmore'] = 'More learners match than the {$a} shown. Keep typing to narrow the list.';
$string['sectionactivities'] = 'Activity breakdown';
$string['sectionavailability'] = 'Hidden from this learner or restricted';
$string['sectionchain'] = 'Completion tracking chain';
$string['sectioncriteria'] = 'Course completion criteria';
$string['sectioncron'] = 'Background task health';
$string['selectcourse'] = 'Course';
$string['selectuser'] = 'Learner';
$string['selectuser_help'] = 'Type at least two characters of the learner\'s name, email address or another identity field the site shows you, then pick the learner. Only learners with an active enrolment and an account that is not suspended, whom the course tracks for completion, are offered. In a course with separate groups, you are offered only the learners in your own groups, unless you may access all groups.';
$string['sentence'] = '{$a}.';
$string['sentences'] = '{$a->first} {$a->second}';
$string['sitecompletion'] = 'Site-level completion tracking';
$string['state'] = 'State';
$string['statecomplete'] = 'Complete';
$string['statecompletefail'] = 'Failed: below the passing grade';
$string['statecompletefailing'] = 'Complete, with a failing grade';
$string['statecompletepass'] = 'Complete (passed)';
$string['stateincomplete'] = 'Incomplete';
$string['statusinfo'] = 'Does not affect course completion';
$string['statusok'] = 'OK';
$string['statusproblem'] = 'Problem';
$string['stilltodo'] = 'Still to do: {$a}.';
$string['taskaskadmin'] = 'Ask your site administrator to check the completion tasks.';
$string['taskdaily'] = 'Daily completion task';
$string['taskdisabled'] = 'This task is disabled, so it never runs.';
$string['taskdisabledhint'] = 'Enable it in Site administration > Server > Tasks > Scheduled tasks.';
$string['taskduenow'] = 'due now';
$string['taskfailing'] = 'This task failed the last time it ran, and Moodle is waiting before it tries again.';
$string['taskfailinghint'] = 'See Site administration > Server > Tasks > Task logs.';
$string['tasklastrun'] = 'Last run: {$a}';
$string['tasknever'] = 'Never run';
$string['tasknextrun'] = 'Next run: {$a}';
$string['taskoverdue'] = 'This task was due at {$a} and has not run since, so completion may be out of date.';
$string['taskoverduehint'] = 'Check that cron is running.';
$string['taskregular'] = 'Regular completion task';
$string['tracking'] = 'Tracking';
$string['trackingauto'] = 'Automatic';
$string['trackingmanual'] = 'Manual';
$string['verdictcomplete'] = 'This learner has completed the course.';
$string['verdictcompletebutmarked'] = 'The requirements are met, but the course is not marked complete yet. The next run of the regular completion task should mark it.';
$string['verdictincomplete'] = 'This learner has not completed the course. What is blocking it:';
$string['verdictlabel'] = 'Verdict:';
$string['verdictnotrechecked'] = 'The requirements are met, but the course is not marked complete, and Moodle will not check this learner again on its own: it only checks a learner again when one of their completion conditions is recorded. This usually follows a change to the completion conditions after the learner had met them.';
$string['verdicttasknote'] = 'Completion may also be out of date: the background completion tasks are not running on schedule (see Background task health below).';
$string['verdictwaitingfortasks'] = 'The requirements look met, but the course is not marked complete, because the background completion tasks are not running on schedule:';
$string['whynopass'] = '{$a} has no passing grade to reach';
$string['whynotracking'] = '{$a} does not track completion';
$string['withnote'] = '{$a->text}. {$a->note}';
