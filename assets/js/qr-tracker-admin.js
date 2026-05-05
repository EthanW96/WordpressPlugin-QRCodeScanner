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

    });
}());
