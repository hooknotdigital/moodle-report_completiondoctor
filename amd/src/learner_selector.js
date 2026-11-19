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
 * Learner search for the Learner field of the diagnosis form.
 *
 * An ajax module for core's autocomplete form element. It searches the course's
 * learners as the viewer types, from the first few characters, and says so when
 * more learners match than are shown.
 *
 * @module     report_completiondoctor/learner_selector
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';

/**
 * Plain text as HTML, for the option labels the autocomplete inserts as HTML.
 *
 * @param {String} text Plain text.
 * @return {String} Escaped HTML.
 */
const escapeHtml = (text) => {
    const node = document.createElement('span');
    node.textContent = text;
    return node.innerHTML;
};

/** @var {String} The original select elements of the Learner fields that use this module. */
const SELECTOR = 'select[data-courseid][data-minchars]';

/**
 * The hint line above the search box of a Learner field, created empty on first use.
 *
 * It is a polite live region, put in the page empty before any text goes in,
 * because screen readers often say nothing for a live region that arrives already
 * filled. It keeps one line of space even when empty (see styles.css), so the
 * search box does not move when a hint appears. It sits above the box because the
 * list of suggestions opens below it.
 *
 * @param {HTMLElement} select The original select element.
 * @return {HTMLElement|null} The hint, or null while the search box is not in the page yet.
 */
const getHint = (select) => {
    const hintid = select.id + '_hint';
    const existing = document.getElementById(hintid);
    if (existing) {
        return existing;
    }
    const input = select.parentElement.querySelector('.form-autocomplete-input input');
    if (!input) {
        return null;
    }
    const hint = document.createElement('div');
    hint.id = hintid;
    hint.className = 'report-completiondoctor-hint form-text text-muted small w-100 mb-1';
    hint.setAttribute('aria-live', 'polite');
    input.closest('.form-autocomplete-input').before(hint);
    input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + hintid).trim());
    select.addEventListener('change', () => {
        hint.dataset.text = '';
        hint.textContent = '';
    });
    return hint;
};

/**
 * Show a hint above the search box, or clear it.
 *
 * @param {HTMLElement} select The original select element.
 * @param {String} text The hint, or an empty string to clear it.
 */
const setHint = (select, text) => {
    const isnew = !document.getElementById(select.id + '_hint');
    const hint = getHint(select);
    if (!hint) {
        return;
    }
    hint.dataset.text = text;
    if (isnew) {
        // A live region only just added: fill it a moment later, so the text is announced.
        setTimeout(() => {
            hint.textContent = hint.dataset.text;
        }, 100);
    } else {
        hint.textContent = text;
    }
};

// The autocomplete loads this module once its search box is in the page: add the empty
// hint line now, before the viewer types, so nothing moves and the first hint is announced.
document.querySelectorAll(SELECTOR).forEach(getHint);

/**
 * Search the learners matching the query.
 *
 * @param {String} selector The selector of the original select element.
 * @param {String} query What the viewer typed.
 * @param {Function} success Receives the learners, or a message to show instead.
 * @param {Function} failure Receives the error.
 */
export const transport = async(selector, query, success, failure) => {
    const select = document.querySelector(selector);
    const minchars = parseInt(select.dataset.minchars, 10) || 2;
    const text = (query || '').trim();

    try {
        if ([...text].length < minchars) {
            setHint(select, '');
            success(await getString('searchminchars', 'report_completiondoctor', minchars));
            return;
        }

        const response = await Ajax.call([{
            methodname: 'report_completiondoctor_search_learners',
            args: {
                courseid: parseInt(select.dataset.courseid, 10),
                query: text,
            },
        }])[0];

        if (response.overflow) {
            setHint(select, await getString('searchmore', 'report_completiondoctor', response.learners.length));
        } else {
            setHint(select, '');
        }
        success(response.learners);
    } catch (error) {
        failure(error);
    }
};

/**
 * Turn the learners into options for the autocomplete.
 *
 * @param {String} selector The selector of the original select element.
 * @param {Array|String} results The learners from transport(), or a message.
 * @return {Array|String} Options with value and label, or the message.
 */
export const processResults = (selector, results) => {
    if (!Array.isArray(results)) {
        return results;
    }
    return results.map((learner) => ({value: learner.id, label: escapeHtml(learner.label)}));
};
