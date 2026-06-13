/* global DataTable */
/**
 * QR Tracker Admin — DataTables initialisation
 *
 * Activates DataTables on every .qr-table-responsive table so that wide tables
 * become horizontally scrollable / column-collapsible on mobile devices.
 *
 * Tables already managed by server-side pagination (e.g. Scan Logs) should have
 * the class `qr-dt-no-controls` added so that DataTables' own paging, searching
 * and info controls are suppressed — only the Responsive column-collapse behaviour
 * is applied.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ── 1. Responsive-only tables (server-side pagination already present) ──────
        document.querySelectorAll(
            '.qr-table-responsive table.qr-dt-no-controls'
        ).forEach(function (table) {
            if (!DataTable.isDataTable(table)) {
                new DataTable(table, {
                    paging:    false,
                    searching: false,
                    ordering:  false,
                    info:      false,
                    responsive: true
                });
            }
        });

        // ── 2. Full-featured DataTables (search, sort, paginate, responsive) ────────
        document.querySelectorAll(
            '.qr-table-responsive table.widefat:not(.qr-dt-no-controls)'
        ).forEach(function (table) {
            if (!DataTable.isDataTable(table)) {
                new DataTable(table, {
                    responsive:  true,
                    autoWidth:   false,
                    pageLength:  25,
                    lengthMenu:  [[10, 25, 50, -1], [10, 25, 50, 'All']],
                    language: {
                        search:     'Filter:',
                        lengthMenu: 'Show _MENU_ entries'
                    }
                });
            }
        });

        // ── 3. Copy buttons for QR code links ────────────────────────────────────────
        function copyTextToClipboard(text, btn) {
            function onSuccess() {
                var original = btn.textContent;
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = original;
                    btn.classList.remove('copied');
                }, 1500);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(onSuccess);
            } else {
                // Fallback for HTTP / non-secure contexts
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try {
                    document.execCommand('copy');
                    onSuccess();
                } catch (err) {
                    // Silent fail — nothing we can do without user interaction
                }
                document.body.removeChild(ta);
            }
        }

        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('.qr-copy-btn');
            if (!btn) {
                return;
            }
            var url = btn.getAttribute('data-url');
            if (!url) {
                return;
            }
            copyTextToClipboard(url, btn);
        });

        // ── 4. Tree field layout drag-and-drop (settings page) ───────────────────────
        var treeFieldLayout = document.querySelector('.qr-tree-field-layout');
        if (treeFieldLayout) {
            var draggedItem = null;
            var zones = treeFieldLayout.querySelectorAll('.qr-tree-field-zone');

            var syncFieldLayoutInputs = function () {
                zones.forEach(function (zone) {
                    var inputId = zone.getAttribute('data-target-input');
                    if (!inputId) {
                        return;
                    }
                    var hiddenInput = document.getElementById(inputId);
                    if (!hiddenInput) {
                        return;
                    }
                    var order = Array.from(zone.querySelectorAll('.qr-tree-field-item')).map(function (item) {
                        return item.getAttribute('data-field-key');
                    });
                    hiddenInput.value = order.join(',');
                });
            };

            /**
             * Determine which list item should be inserted before the dragged element.
             * @param {Element} zone Drag-and-drop list container.
             * @param {number} clientY Current pointer Y coordinate.
             * @returns {Element|undefined} Item to insert before, or undefined to append.
             */
            var getInsertBeforeElement = function (zone, clientY) {
                var items = Array.from(zone.querySelectorAll('.qr-tree-field-item:not(.dragging)'));
                // Find the nearest item whose midpoint is above the cursor,
                // then insert the dragged item immediately before that item.
                return items.reduce(function (closest, child) {
                    var box = child.getBoundingClientRect();
                    var offset = clientY - box.top - box.height / 2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset: offset, element: child };
                    }
                    return closest;
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            };

            treeFieldLayout.querySelectorAll('.qr-tree-field-item').forEach(function (item) {
                item.addEventListener('dragstart', function () {
                    draggedItem = item;
                    item.classList.add('dragging');
                });
                item.addEventListener('dragend', function () {
                    item.classList.remove('dragging');
                    draggedItem = null;
                    syncFieldLayoutInputs();
                });
            });

            zones.forEach(function (zone) {
                zone.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    var afterElement = getInsertBeforeElement(zone, event.clientY);
                    if (!draggedItem) {
                        return;
                    }
                    if (!afterElement) {
                        zone.appendChild(draggedItem);
                    } else {
                        zone.insertBefore(draggedItem, afterElement);
                    }
                });
            });

            var settingsForm = treeFieldLayout.closest('form');
            if (settingsForm) {
                settingsForm.addEventListener('submit', syncFieldLayoutInputs);
            }
            syncFieldLayoutInputs();
        }

    });
}());
