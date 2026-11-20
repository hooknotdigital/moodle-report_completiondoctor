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
 * Validation errors on the search box of an autocomplete field.
 *
 * Moodle marks the original select of an autocomplete field, which is hidden, as
 * invalid, describes it by the error and tries to focus it. The search box, which is
 * what people use, gets none of that, so pressing the button with the field empty
 * showed an error that keyboard and screen reader users were not told about. This
 * gives the search box the same state, and the focus after a submit that was stopped
 * for it.
 *
 * @module     report_completiondoctor/field_errors
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {eventTypes} from 'core_form/events';

/**
 * The search box of an autocomplete field.
 *
 * Looked up when needed: the autocomplete may add it after this module has started.
 *
 * @param {HTMLElement} select The original select element.
 * @return {HTMLElement|null}
 */
const searchBox = (select) => select.parentElement.querySelector('.form-autocomplete-input input');

/**
 * Link an autocomplete field's validation errors to its search box.
 *
 * @param {String} selectid The id of the field's original select element.
 */
export const init = (selectid) => {
    const select = document.getElementById(selectid);
    if (!select || select.dataset.errorsLinked) {
        return;
    }
    select.dataset.errorsLinked = '1';

    select.addEventListener(eventTypes.formFieldValidationFailed, (e) => {
        const input = searchBox(select);
        if (!input) {
            return;
        }
        const feedback = select.closest('.fitem')?.querySelector('.form-control-feedback');
        const ids = (input.getAttribute('aria-describedby') || '').split(' ')
            .filter((id) => id && id !== feedback?.id);
        if (e.detail.message) {
            input.setAttribute('aria-invalid', 'true');
            if (feedback?.id) {
                ids.push(feedback.id);
            }
        } else {
            input.removeAttribute('aria-invalid');
        }
        input.setAttribute('aria-describedby', ids.join(' '));
    });

    select.form?.addEventListener('submit', () => {
        const input = searchBox(select);
        if (input?.getAttribute('aria-invalid') === 'true') {
            // After Moodle's own attempt to focus the hidden select.
            setTimeout(() => input.focus(), 0);
        }
    });
};
