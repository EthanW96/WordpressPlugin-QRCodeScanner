/**
 * Merge QR Codes screen behaviour.
 *
 * Everything here is a convenience layered over forms that work without it:
 * the server validates every step and recomputes the dry run on commit.
 */
(function () {
    'use strict';

    var MIN_SELECTED = 2;

    // Step 1: filter the list and count the selection.
    function initSelect() {
        var form = document.querySelector('.qr-merge-select-form');
        if (!form) {
            return;
        }

        var filter = document.getElementById('qr-merge-filter');
        var rows = form.querySelectorAll('tbody tr');
        var counter = form.querySelector('.qr-merge-selected-count');
        var button = form.querySelector('.qr-merge-continue');

        function updateCount() {
            var selected = form.querySelectorAll('input[name="qr_merge[ids][]"]:checked').length;
            counter.textContent = selected + ' selected';
            button.disabled = selected < MIN_SELECTED;
        }

        // Filtering only hides rows; hidden rows stay in the form, so a
        // selection made before filtering is still submitted.
        function applyFilter() {
            var term = filter.value.trim().toLowerCase();
            rows.forEach(function (row) {
                var matches = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
                row.hidden = !matches;
            });
        }

        form.addEventListener('change', updateCount);
        if (filter) {
            filter.addEventListener('input', applyFilter);
        }
        updateCount();
    }

    // Step 3: show how much of each removed code's visits will be discarded.
    function initAllocations() {
        var tables = document.querySelectorAll('.qr-merge-alloc-table');
        tables.forEach(function (table) {
            function update() {
                ['scan', 'social'].forEach(function (type) {
                    var output = table.querySelector('.qr-merge-discard[data-type="' + type + '"]');
                    var total = parseInt(output.getAttribute('data-total'), 10) || 0;
                    var allocated = 0;
                    table.querySelectorAll('.qr-merge-alloc[data-type="' + type + '"]').forEach(function (input) {
                        allocated += Math.max(0, parseInt(input.value, 10) || 0);
                    });

                    var discarded = total - allocated;
                    output.textContent = discarded < 0
                        ? 'Over-allocated by ' + (-discarded)
                        : String(discarded);
                    output.classList.toggle('is-over', discarded < 0);
                    output.classList.toggle('is-discarding', discarded > 0);
                });
            }

            table.addEventListener('input', update);
            update();
        });
    }

    // Step 4: the commit button unlocks only once the count is typed.
    function initConfirm() {
        var input = document.getElementById('qr-merge-confirm');
        if (!input) {
            return;
        }

        var button = document.querySelector('.qr-merge-commit');
        var expected = input.getAttribute('data-expected');
        input.addEventListener('input', function () {
            button.disabled = input.value.trim() !== expected;
        });

        // Stop a double click from submitting twice; the second submission
        // would fail its fingerprint check, but it would read as an error.
        input.form.addEventListener('submit', function () {
            button.disabled = true;
            button.textContent = 'Merging…';
        });
    }

    function init() {
        initSelect();
        initAllocations();
        initConfirm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
