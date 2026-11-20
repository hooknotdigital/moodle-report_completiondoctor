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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps and page names for Completion Doctor.
 *
 * @package    report_completiondoctor
 * @category   test
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_completiondoctor extends behat_base {
    /**
     * Page names for "I am on the ... page".
     *
     * Recognised page names:
     * | Page type | Identifier | Description |
     * | Diagnosis | Course shortname | The Completion Doctor page of that course |
     * | Site home diagnosis | Anything | The page with the site home given as the course |
     *
     * @param string $type Page type.
     * @param string $identifier Identifier.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'diagnosis':
                return new moodle_url('/report/completiondoctor/index.php', ['courseid' => $this->get_course_id($identifier)]);
            case 'site home diagnosis':
                return new moodle_url('/report/completiondoctor/index.php', ['courseid' => SITEID]);
            default:
                throw new Exception("Unrecognised report_completiondoctor page type '{$type}'.");
        }
    }

    /**
     * Make named activities the course's activity completion criteria.
     *
     * Example: the course "C1" requires "any" of the activities "Reading A, Reading B" for completion
     *
     * @Given /^the course "(?P<course>[^"]*)" requires "(?P<method>all|any)" of the activities "(?P<names>[^"]*)" for completion$/
     * @param string $course Course short name.
     * @param string $method "all" or "any": how the activities are combined.
     * @param string $names Activity names, separated by commas.
     */
    public function the_course_requires_activities_for_completion(string $course, string $method, string $names): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

        $courseid = $this->get_course_id($course);
        $cmids = [];
        foreach (array_map('trim', explode(',', $names)) as $name) {
            $cmid = null;
            foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
                if ($cm->name === $name) {
                    $cmid = (int) $cm->id;
                }
            }
            if ($cmid === null) {
                throw new Exception("No activity called '{$name}' in course '{$course}'.");
            }
            $cmids[$cmid] = 1;
        }

        $data = (object) ['id' => $courseid, 'criteria_activity' => $cmids];
        (new completion_criteria_activity())->update_config($data);

        $aggregation = new completion_aggregation(['course' => $courseid, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]);
        $aggregation->setMethod($method === 'any' ? COMPLETION_AGGREGATION_ANY : COMPLETION_AGGREGATION_ALL);
        $aggregation->save();
    }

    /**
     * Check the address of the current page does not contain some text.
     *
     * Example: the address should not contain "sesskey"
     *
     * @Then /^the address should not contain "(?P<text>[^"]*)"$/
     * @param string $text
     */
    public function the_address_should_not_contain(string $text): void {
        $url = $this->getSession()->getCurrentUrl();
        if (str_contains($url, $text)) {
            throw new \Behat\Mink\Exception\ExpectationException("The address {$url} contains '{$text}'.", $this->getSession());
        }
    }
}
