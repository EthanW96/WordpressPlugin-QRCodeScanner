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

        // ── 3. Tree field layout drag-and-drop (settings page) ───────────────────────
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

            var getDragAfterElement = function (zone, clientY) {
                var items = Array.from(zone.querySelectorAll('.qr-tree-field-item:not(.dragging)'));
                // Find the nearest item whose midpoint is still below the cursor,
                // so we can insert the dragged item immediately before it.
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
                    var afterElement = getDragAfterElement(zone, event.clientY);
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
