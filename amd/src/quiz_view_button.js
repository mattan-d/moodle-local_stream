// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Adds a "question from recording" button next to "Add question" on quiz pages.
 *
 * @module     local_stream/quiz_view_button
 * @copyright  2026 mattandor <mattan@centricapp.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    const BUTTON_ID = 'local-stream-add-from-recording';

    const ANCHOR_STRATEGIES = [
        {
            selector: '.tertiary-navigation a.btn[href*="mod/quiz/edit.php"]',
            insert: 'afterend',
        },
        {
            selector: '.tertiary-navigation a[href*="mod/quiz/edit"]',
            insert: 'afterend',
        },
        {
            selector: '.mod-quiz-edit-content .add-menu-outer',
            insert: 'afterend',
        },
        {
            selector: '.mod-quiz-edit-content [data-action="addquestion"]',
            insert: 'afterend',
        },
        {
            selector: '.tertiary-navigation .row',
            insert: 'append',
        },
    ];

    /**
     * @returns {{element: Element, strategy: {selector: string, insert: string}}|null}
     */
    const findAnchor = () => {
        for (const strategy of ANCHOR_STRATEGIES) {
            const element = document.querySelector(strategy.selector);
            if (element) {
                return {element, strategy};
            }
        }
        return null;
    };

    /**
     * Moodle js_call_amd passes each PHP array element as a separate argument (not one object).
     *
     * @param {string} url Target page URL.
     * @param {string} label Button label.
     */
    const init = (url, label) => {
        if (!url || !label) {
            return;
        }

        if (document.getElementById(BUTTON_ID)) {
            return;
        }

        const anchorResult = findAnchor();
        if (!anchorResult) {
            return;
        }

        const {element: anchor, strategy} = anchorResult;
        const button = document.createElement('a');
        button.id = BUTTON_ID;
        button.href = url;
        button.className = 'btn btn-secondary ms-2 local-stream-add-from-recording';
        if (anchor.classList.contains('btn')) {
            button.className = anchor.className + ' ms-2 local-stream-add-from-recording';
        }
        button.textContent = label;

        if (strategy.insert === 'append') {
            anchor.appendChild(button);
        } else {
            anchor.insertAdjacentElement(strategy.insert, button);
        }
    };

    return {
        init: init,
    };
});
