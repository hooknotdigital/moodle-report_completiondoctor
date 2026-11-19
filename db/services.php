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
 * External functions for Completion Doctor.
 *
 * @package    report_completiondoctor
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'report_completiondoctor_search_learners' => [
        'classname' => \report_completiondoctor\external\search_learners::class,
        'description' => 'Search the learners of a course that the current user may diagnose.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'report/completiondoctor:view',
        // A typeahead that writes nothing to the session: it need not wait for the session lock.
        'readonlysession' => true,
    ],
];
