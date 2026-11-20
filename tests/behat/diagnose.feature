@report @report_completiondoctor
Feature: Diagnose why a learner has not completed a course
  In order to fix a learner's course completion
  As a teacher
  I need to find the learner and see what still blocks them, and only that

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | suspended |
      | teacher1 | Terry     | Teacher  | teacher1@example.com | 0         |
      | student1 | Sam       | Student  | student1@example.com | 0         |
      | student2 | Sue       | Student  | student2@example.com | 0         |
      | student3 | Sid       | Student  | student3@example.com | 1         |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student3 | C1     | student        |
    And the following "course enrolments" exist:
      | user     | course | role    | status |
      | student2 | C1     | student | 1      |
    And the following "activities" exist:
      | activity | course | name      | completion |
      | page     | C1     | Reading A | 1          |
      | page     | C1     | Reading B | 1          |
    And the course "C1" requires "any" of the activities "Reading A, Reading B" for completion

  @javascript @accessibility
  Scenario: A teacher finds the learner by part of the name and sees one blocker when any one of two activities is enough
    Given I am on the "C1" "report_completiondoctor > Diagnosis" page logged in as "teacher1"
    When I set the field "Learner" to "Sam"
    And I press "Run diagnosis"
    Then I should see "Diagnosis: Sam Student in Course 1"
    And I should see "Sam Student (student1@example.com)" in the ".form-autocomplete-selection" "css_element"
    And I should see "This learner has not completed the course. What is blocking it:"
    And I should see "Activities: complete at least one of Reading A, Reading B"
    And I should not see "Reading B is not complete"
    And the address should not contain "sesskey"
    And the address should not contain "submitbutton"
    And the ".report-completiondoctor" "css_element" should meet accessibility standards

  @javascript
  Scenario: Running a diagnosis without a learner says what to do and moves to the Learner field
    Given I am on the "C1" "report_completiondoctor > Diagnosis" page logged in as "teacher1"
    When I press "Run diagnosis"
    Then I should see "Choose a learner first."
    And the focused element is "Learner" "field"

  @javascript
  Scenario: Learners whose enrolment or site account is suspended are not offered
    Given I am on the "C1" "report_completiondoctor > Diagnosis" page logged in as "teacher1"
    When I click on "Learner" "field"
    And I type "Student"
    Then I should see "Sam Student (student1@example.com)" in the ".form-autocomplete-suggestions" "css_element"
    And I should not see "Sue Student"
    And I should not see "Sid Student"

  @javascript
  Scenario: Opened without a course, the page asks for the course first
    Given I log in as "admin"
    And I navigate to "Reports > Completion Doctor" in site administration
    When I set the field "Course" to "Course 1"
    And I press "Continue"
    And I set the field "Learner" to "Sam"
    And I press "Run diagnosis"
    Then I should see "Diagnosis: Sam Student in Course 1"

  @javascript
  Scenario: Continuing without a course says what to do and moves to the Course field
    Given I log in as "admin"
    And I navigate to "Reports > Completion Doctor" in site administration
    When I press "Continue"
    Then I should see "Choose a course first."
    And the focused element is "Course" "field"

  Scenario: The site home is not a course, so the page asks for a course instead
    Given I am on the "Anything" "report_completiondoctor > Site home diagnosis" page logged in as "admin"
    Then I should see "Choose a course first, then pick the learner."
    And I should not see "Learner"

  Scenario: The learner's course profile links to the report for that learner
    Given I am on the "C1" "enrolled users" page logged in as "teacher1"
    When I follow "Sam Student"
    And I follow "Completion Doctor"
    Then I should see "Diagnosis: Sam Student in Course 1"
