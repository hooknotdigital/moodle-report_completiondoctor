# Changes

## 0.4.2 (21 November 2026)

Fixed:

- On a phone, the links after a verdict entry ("Edit the activity settings", "Edit the course completion conditions") could each end up on a line of their own with no space between them, so the wrong one was easy to tap. Each link is now a little taller, with a small gap above and below.

Added:

- The README names the themes and versions the report has been checked on, and three faults in those themes that are not the report's: Academi's top bar on a phone and its default colours, Boost Magnific's tab row on a phone, and Trema in dark mode.

## 0.4.1 (20 November 2026)

Fixes from a walk-through of the report as a site administrator, a teacher, a manager and a non-editing teacher on Moodle 4.5 and 5.3, two re-tests and a code review.

Changed:

- The verdict names each thing once, with its cause, the most fundamental first: completion switched off, then a required activity no learner can complete as things stand (hidden, in a hidden section or subsection, without completion tracking, being deleted, closed by a permission, or waiting for an activity learners cannot open), then a failing grade, then access restrictions, then what is simply not done yet, then activities that wait for other activities. Before, an activity with a failing grade was listed twice, once as "not complete" and once with the grade, and the order followed the checks.
- An activity that is not done yet says what is still to do ("Still to do: Make a submission"), or that the learner has not marked it as done. A grade below the passing grade also names any other rule still to do.
- Each entry links to the page where it is fixed: the course completion conditions, the course, section or activity settings, the activity, the activity permissions, the course completion report for approvals (only for a viewer who can mark that approval there), and the task pages. Links appear only for viewers who may change the setting there; other viewers are told whom to ask.
- An activity whose restriction waits only for other activities the learner can still do is listed after them, as waiting for them (or for one of them, when any one is enough), with links to them, and each activity it waits for says it keeps it locked. Conditions already met are left out, "the previous activity" is named, and a chain of activities that unlock one after another is followed to its end: a step waiting for a step that is still locked waits, and only a chain that ends in an activity no learner can open "never opens". Activities that wait for each other, or whose own section waits for them, are named as never opening, and so is a restriction that asks for something the other activity can never reach (a pass without a passing grade, completion of an activity that does not track it), with the reason. A restriction that also asks for something else, or is negated, is a restriction of its own with the link to change it. When the activity waited for is not a completion condition, what keeps it closed (a date, a group) is said too. An activity that keeps a required activity locked says so in the activity table and the restrictions table.
- Background completion tasks that are late no longer fill the verdict while something else is missing; they are a short note under it, and only administrators are told where to look in Site administration. When everything else is done, the late tasks are the answer.
- The activity table describes each requirement in Moodle's own words with the learner's status, as the course page does ("To do: Make a submission"), instead of internal names such as `completionsubmit`, and an assignment's submission status as the assignment shows it ("No submission" instead of "new").
- Every activity row has a status icon, and activities that are course completion conditions say so. A grade below the passing grade where completion needs the pass reads "Failed: below the passing grade", not "Complete (failed)"; where completion only needs a grade, it reads "Complete, with a failing grade", because Moodle counts it.
- The criteria table names each criterion in its first column.
- A user who cannot be diagnosed is told why: not enrolled, a role the course does not track, not in your groups, or, for viewers who may review enrolments, a suspended account or an enrolment that is suspended or ended. Before, every case said "not an active learner".
- A non-editing teacher in a separate-groups course who is in no group is told why no learner can be found.
- The count of other learners says "in your groups" when it covers only your groups, and links to the course completion report when the course has criteria.
- Criteria such as a date, a duration, a course grade, unenrolment and another course say how they are met, and a prerequisite course is named in full.
- Under "any" aggregation, options that no learner can complete as things stand are named with their cause.
- The page title names the learner and the course.
- After a diagnosis, the page moves to the report, below every bar the theme fixes to the top of the window, without an animated scroll, and stays there while the theme settles the page (a drawer closing on a phone) until the reader scrolls or types.

Fixed:

- Completion Doctor was offered in the site home's Reports menu, where typing a name opened an error. The site home is not a course; the link is gone, and the page asks for a course instead.
- Run diagnosis put the session key in the address bar, and so in browser history, server logs and shared links. The form no longer carries it, and the address after a diagnosis is the short one the course profile link uses.
- A required activity hidden by a teacher, in a hidden section, behind a restriction that hides it entirely, in a restricted section or subsection, or closed by a permission, was listed only as "not complete". The cause is now named.
- An activity that is done but not yet recorded for the course by the regular completion task was listed as not complete, also under "any" aggregation.
- When every requirement is recorded but Moodle will not check the learner again on its own (for example after a criterion was removed), the report said the next task run would mark the course. It now says the task will not.
- Long activity names were shortened in the verdict and the criteria table.
- Access restrictions on activities that are not completion conditions were listed as blockers.
- Self completion now says when learners have no Self completion block to mark the course complete with: the block switched off for the whole site (the default on new sites since Moodle 4.0), none in the course or shown on course pages from the category or site, or the course's own hidden.
- Pressing Run diagnosis without a learner, or Continue without a course, showed an error that keyboard and screen reader users were not told about. The search box is now marked invalid, described by the error ("Choose a learner first.", "Choose a course first.") and focused.
- With completion tracking off, the empty activity table now says why, and the criteria row no longer counts as a second problem.
- Restrictions with several conditions keep the conditions apart, and a nested set of conditions is shown in brackets.
- On Moodle 4.5, a restriction around a subsection was worked out for the viewer, not the learner, and could be missed. A hidden section around a subsection is named, with a link to it on the course page.
- Each table row starts with its status icon inside the row header, so screen readers name the row with its status, and links that share a text say what they open. The permissions link is offered only to viewers who can change an override there. The report's links and the activity table's scroll box keep a focus ring in themes that remove outlines, and links in sentences are underlined.
- Sentence endings and joins come from the language strings, so translations can change them.
- Each activity's completion state, each restriction and each chain of waits is worked out once per report, so long courses that unlock in order stay fast.

## 0.4.0 (16 November 2026)

Changed:

- Maturity is beta, no longer alpha.

Fixed:

- On a phone, the activity table made the whole page scroll sideways. The table now scrolls inside its own box, which can also be scrolled with the keyboard.

Added:

- The GNU GPL v3 licence text in LICENSE.
- Where to report problems and ask for changes, in the README.

## 0.3.0 (15 November 2026)

Changed:

- The Learner field searches as you type instead of listing every learner. Type at least two characters of a name, email address or identity field; the search runs in the database and shows at most 20 matches, with a line saying when more match. It finds only the learners you may diagnose, as before. On sites with thousands of learners the old menu loaded all of them into the page.
- Opened from a course, the course is fixed and shown by name. Opened from Site administration, the Course field is Moodle's course search, limited to courses where you may run the report, and Continue takes you to that course's page.
- Learners whose site account is suspended are no longer offered, counted or accepted, even with an active enrolment. Before, only a suspended enrolment kept a learner out.

Added:

- A Completion Doctor link in the Reports section of a learner's course profile, for viewers who may diagnose that learner. It opens the report already filled in.
- Under the verdict, how many other learners in the course have not completed it, counted with one query over the learners you may diagnose.
- The web service function `report_completiondoctor_search_learners`.
- PHPUnit tests for the search, the count and the profile link, and Behat scenarios for finding a learner by typing, the page opened without a course, and the profile link.

## 0.2.0 (15 November 2026)

Fixed:

- The daily completion task was reported as overdue two hours after it ran, although it runs once a day. Both completion tasks are now judged against their own schedule: a task is a problem only when it is disabled, its last run failed, or its scheduled run is more than an hour in the past. A task that has not run yet on a new site is no longer a problem. Cron switched off for the site is reported.
- Per-type aggregation was ignored. With "any" activity aggregation, activities the learner did not need were listed as blockers. With overall "any" and nothing done, the report said the requirements looked satisfied. Criteria are now combined the way Moodle combines them.
- Visiting Site administration listed a duplicate admin page (settings.php did not clear `$settings`).
- A missing core string (`usernotinselectedcourse`) was used for a learner not in the course.
- The course menu listed every course on the site. It now lists only courses where the viewer has the capability.
- The learner menu listed suspended learners and showed raw email addresses. It now lists the learners the course tracks for completion, with the identity fields the site shows to the viewer.
- A pass grade missing from the gradebook, where completion asks for one, was reported as a requirement that can never be met. Moodle counts any grade as complete in that case, so it is now a warning on the activity, not a blocker.
- A failing grade in a hidden grade item now carries a note that the learner cannot see that grade.
- In a course with separate groups, a teacher without "access all groups" saw every learner. They now see only the learners in their own groups, as in core's completion report.
- Names with "&" and similar characters showed as HTML entities, for example "Q&amp;A", in blockers, the activity table, restriction rows and the report heading.
- The page heading "Completion Doctor" showed twice in a course. The course page now has the course name as its page heading.

Changed:

- Status icons are Moodle icons with text alternatives instead of emoji.
- Wording of several strings.

Added:

- PHPUnit tests for each kind of blocker and Behat scenarios, run in GitHub Actions on Moodle 4.5, 5.1, 5.2 and 5.3.

## 0.1.0 (20 July 2026)

First version.
