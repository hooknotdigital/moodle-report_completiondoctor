# Changes

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
