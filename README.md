# Completion Doctor (report_completiondoctor)

Completion Doctor answers one question: why is this learner not marked complete in this course?

Pick a course and a learner, or open the report from the learner's course profile, and the report checks:

1. **The settings chain.** Completion tracking on for the site, on for the course, and course completion criteria set.
2. **Course completion criteria.** Each criterion and the learner's status, combined the way Moodle combines them: activity, prerequisite course and role criteria within their own type first ("all" or "any"), then everything by the overall method.
3. **Activities.** Each tracked activity, whether it is a course completion condition, what it requires in Moodle's own words with the learner's status for each requirement, the learner's state, grades against the pass grade, quiz attempts and assignment submissions. Two common grade traps are flagged: a pass grade in the gradebook when completion only needs "receive a grade", and completion that asks for a pass grade when the gradebook has none, which makes Moodle count any grade as complete.
4. **Hidden and restricted activities.** Activities and sections hidden from the learner, by a teacher or by an access restriction, and whether each one keeps the learner from completing the course.
5. **Background tasks.** Whether the regular and daily completion tasks run on their own schedule, are disabled or failing, and whether cron is switched on.

The verdict at the top names each thing that still blocks the learner once, with its cause, the most fundamental first, and links to the page where it is fixed when you may change it there. Nothing is changed: the report only reads.

## Requirements

Moodle 4.5 to 5.3.

## Installation

Copy this folder to `report/completiondoctor` (Moodle 4.5 and 5.0) or `public/report/completiondoctor` (Moodle 5.1 and later), then visit Site administration > Notifications or run `admin/cli/upgrade.php`.

## Use

- In a course: Reports > Completion Doctor. The course is fixed; pick the learner.
- From a learner's course profile: Reports > Completion Doctor opens the report for that learner, already filled in.
- For managers: Site administration > Reports > Completion Doctor. Pick the course, press Continue, then pick the learner.

Under the verdict, the report says how many other learners in the course have not completed it, with a link to the course completion report when you may view it. That count covers the learners you may diagnose (see below), says "in your groups" when that is all it covers, and takes one database query.

When the page is opened for someone you cannot diagnose, it says why: a suspended account, an enrolment that is suspended or ended, not enrolled, a role the course does not track, or, for a viewer limited to their own groups, not in those groups.

## Finding a course and a learner

Neither field loads a full list into the page, so both work on sites with thousands of courses and learners.

- **Course** (on the page opened from Site administration): Moodle's own course search, limited to courses where you have the capability below. Courses with completion tracking switched off are listed too, because that is one of the things the report finds.
- **Learner**: type at least two characters of the learner's name, email address or another identity field the site shows you. The search runs in the database and shows at most 20 matches; when more match, a line above the box says so, and typing more narrows the list. Each match shows the name and the identity fields the site shows you.

Only learners you may diagnose are found: learners with an active enrolment and an account that is not suspended, whom the course tracks for completion. In a course with separate groups, a user without "access all groups" finds only the learners in their own groups. Identity fields you may not see are never searched or shown. An email address that is not one of the identity fields you may see only matches when the learner does not hide it, as in Moodle's own user search, and never when the site lists email under "Hide user fields" and you may not view hidden user fields in the course. The country field is not searched.

The same rules decide who is counted under the verdict, who gets the profile link, and whom the report accepts when the page is opened with a learner already chosen. A learner who no longer qualifies (for example one suspended since the link was made) is not shown in the Learner field.

The search is the web service function `report_completiondoctor_search_learners` (available to the page through AJAX). It checks the capability in the course and returns only the learners described above.

## Themes

The report is built from Moodle's own forms, tables and alerts, so it takes its look from the site's theme. In November 2026 it was checked at phone, tablet and desktop widths on Boost in Moodle 4.5.16 and 5.3.1, and in Moodle 5.3.1 on Academi v5.3, Boost Magnific 9.8.9, Boost Union v5.3-r2, Moove 5.3.1 and Trema 5.3.0.0.

In Moodle 5.3 the report follows the dark colour mode in themes that support it. In Boost, colour modes are an experimental setting and off by default (Boost's theme settings, Experimental settings tab, "Enable colour modes").

Three of those themes have faults of their own, which show on their other pages too:

- **Academi**: with the contact details it shows by default, its top bar is wider than a phone screen and pushes the user menu out of reach. Some of its default colours (orange headings, white text on orange buttons, green links) do not meet the WCAG AA contrast minimum; Academi's primary and secondary colour settings change them.
- **Boost Magnific**: on a phone, its row of tabs under the page heading does not wrap, so the page is wider than the screen.
- **Trema**: with colour modes switched on, Trema shows Boost's switch but keeps its light-mode text colours, so the report is hard to read in dark mode. On a Trema site, leave colour modes off.

## Capability

`report/completiondoctor:view` (course context). Given by default to teachers, editing teachers and managers.

## Privacy

The plugin stores no personal data. It shows completion, grade and enrolment data that Moodle already holds, to people with the capability above.

## Bugs and requests

Report problems and ask for changes in the GitHub issues of this plugin: https://github.com/hooknotdigital/moodle-report_completiondoctor/issues

## Licence

GNU GPL v3 or later (see LICENSE). Copyright 2026 Hooknot Digital.
