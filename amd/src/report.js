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
 * Take the reader to the report once it is on the page.
 *
 * The report is added below the form, so after "Run diagnosis" the answer starts below
 * the fold, and focus is back at the top of the page. This moves both to the report
 * heading, clear of every bar the theme fixes to the top of the window (some themes stack
 * two or three: a menu bar, the navigation bar, secondary navigation), and without an
 * animated scroll, which some themes switch on for every scroll whatever the reader
 * prefers. Some themes also move the page about just after it loads (a drawer that closes
 * on a phone), so the heading is placed again while that happens, until the reader
 * scrolls, taps or types.
 *
 * @module     report_completiondoctor/report
 * @copyright  2026 Hooknot Digital <rohin@hooknotdigital.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Number} How long the page may still move about once it has loaded, in milliseconds. */
const SETTLE_TIME = 1500;

/** @type {String[]} What the reader does that means they have taken over. */
const READER_EVENTS = ['wheel', 'touchstart', 'keydown', 'mousedown'];

/**
 * Scroll an element to the top of its scrolling box at once.
 *
 * @param {HTMLElement} element
 */
const scrollToTop = (element) => {
    try {
        element.scrollIntoView({block: 'start', behavior: 'instant'});
    } catch (e) {
        // Browsers that do not know "instant" use the default.
        element.scrollIntoView(true);
    }
};

/**
 * How far down the window the bars fixed over an element's top edge reach.
 *
 * @param {HTMLElement} element
 * @return {Number} The bottom of the lowest fixed or sticky bar that covers the element's top
 *     edge, in pixels from the top of the window, or 0 when nothing covers it.
 */
const coveredTo = (element) => {
    const box = element.getBoundingClientRect();
    let bottom = 0;
    [box.left + 1, box.left + box.width / 2].forEach((x) => {
        document.elementsFromPoint(x, Math.max(box.top + 1, 0)).forEach((covering) => {
            if (element.contains(covering) || covering.contains(element)) {
                return;
            }
            for (let node = covering; node && node !== document.body; node = node.parentElement) {
                const position = window.getComputedStyle(node).position;
                if (position === 'fixed' || position === 'sticky') {
                    bottom = Math.max(bottom, node.getBoundingClientRect().bottom);
                    return;
                }
            }
        });
    });
    return bottom;
};

/**
 * Scroll the heading to the top, then below each bar that covers it, one at a time.
 *
 * The page may scroll in the window or in a box of the theme's, so the scroll margin grows
 * by what is covered rather than being set from the window's top.
 *
 * @param {HTMLElement} heading
 * @param {Number} gap The space to leave above the heading, in pixels.
 */
const place = (heading, gap) => {
    heading.style.scrollMarginTop = gap + 'px';
    scrollToTop(heading);
    // A bar found below another one moves the heading again: a few rounds cover any stack.
    for (let round = 0; round < 4; round++) {
        const top = heading.getBoundingClientRect().top;
        const covered = coveredTo(heading);
        if (covered <= top) {
            return;
        }
        const margin = parseFloat(heading.style.scrollMarginTop) || 0;
        heading.style.scrollMarginTop = (margin + covered - top + gap) + 'px';
        scrollToTop(heading);
    }
};

/**
 * Move focus and the view to the report heading, and keep it there while the page settles.
 *
 * @param {String} headingid The id of the report heading.
 */
export const init = (headingid) => {
    const heading = document.getElementById(headingid);
    if (!heading) {
        return;
    }
    const gap = parseFloat(window.getComputedStyle(heading).scrollMarginTop) || 0;
    heading.focus({preventScroll: true});
    place(heading, gap);

    let observer = null;
    const replace = () => window.requestAnimationFrame(() => place(heading, gap));
    const stop = () => {
        if (observer) {
            observer.disconnect();
        }
        READER_EVENTS.forEach((type) => window.removeEventListener(type, stop, true));
        window.removeEventListener('load', replace);
    };
    READER_EVENTS.forEach((type) => window.addEventListener(type, stop, {capture: true, passive: true}));
    window.addEventListener('load', replace, {once: true});
    if (window.ResizeObserver) {
        // A drawer closing or a bar appearing changes the size of the main region.
        observer = new ResizeObserver(replace);
        observer.observe(heading.closest('[role="main"]') || heading.parentElement);
    }
    // Themes move things once the page has loaded, so the settling time counts from then.
    if (document.readyState === 'complete') {
        setTimeout(stop, SETTLE_TIME);
    } else {
        window.addEventListener('load', () => setTimeout(stop, SETTLE_TIME), {once: true});
    }
};
