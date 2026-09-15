/*
 * SmallERP front-end behaviour.
 *
 * Deliberately small and framework-free: everything meaningful happens on the
 * server, and this file only handles the interactions that would be miserable
 * without JavaScript -- above all the invoice line editor, which has to total
 * itself as the user types.
 */
(function () {
    'use strict';

    /** Round half-up in whole dirhams, matching Money::roundHalfUp() in PHP. */
    function roundDirhams(value) {
        return value < 0 ? -Math.floor(Math.abs(value) + 0.5) : Math.floor(value + 0.5);
    }

    function parseAmount(text) {
        var cleaned = String(text == null ? '' : text).replace(/[^0-9.\-]/g, '');
        var value = parseFloat(cleaned);
        return isNaN(value) ? 0 : value;
    }

    function toDirhams(text) {
        return roundDirhams(parseAmount(text) * 100);
    }

    function formatMoney(dirhams) {
        var negative = dirhams < 0;
        var abs = Math.abs(dirhams);
        var whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var cents = ('0' + (abs % 100)).slice(-2);
        return (negative ? '-' : '') + whole + '.' + cents;
    }

    /**
     * The item catalogue arrives as a `type="application/json"` block rather
     * than an inline script, because the page's Content Security Policy
     * forbids inline scripts. A JSON block is inert data, so it is allowed.
     */
    function readItemCatalogue() {
        var el = document.getElementById('item-catalogue');
        if (!el) { return {}; }
        try {
            return JSON.parse(el.textContent) || {};
        } catch (e) {
            return {};
        }
    }

    // ------------------------------------------------------------------
    // Mobile navigation
    // ------------------------------------------------------------------

    function initNav() {
        var toggle = document.querySelector('[data-menu-toggle]');
        var sidebar = document.querySelector('.sidebar');
        var scrim = document.querySelector('.scrim');
        if (!toggle || !sidebar) { return; }

        function close() {
            sidebar.classList.remove('sidebar--open');
            if (scrim) { scrim.classList.remove('scrim--visible'); }
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('sidebar--open');
            if (scrim) { scrim.classList.toggle('scrim--visible'); }
        });
        if (scrim) { scrim.addEventListener('click', close); }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
        });
    }

    // ------------------------------------------------------------------
    // Document line editor
    // ------------------------------------------------------------------

    function initLineEditor() {
        var editor = document.querySelector('[data-line-editor]');
        if (!editor) { return; }

        var tbody = editor.querySelector('tbody');
        var template = document.getElementById('line-template');
        var addButton = editor.querySelector('[data-add-line]');
        var catalogue = readItemCatalogue();
        var pricesIncludeTax = editor.getAttribute('data-prices-include-tax') === '1';

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        }

        /** Recompute one row and return its contribution to the totals. */
        function computeRow(row) {
            var quantity = parseAmount(row.querySelector('[data-field="quantity"]').value);
            var unitPrice = toDirhams(row.querySelector('[data-field="unit_price"]').value);
            var discountPct = parseAmount(row.querySelector('[data-field="discount_pct"]').value);
            var taxInput = row.querySelector('[data-field="tax_rate"]');
            var taxRate = taxInput ? parseAmount(taxInput.value) : 0;

            var gross = roundDirhams(unitPrice * quantity);
            var discount = roundDirhams(gross * discountPct / 100);
            var net = gross - discount;
            var tax;

            if (pricesIncludeTax && taxRate > 0) {
                var taxable = roundDirhams(net / (1 + taxRate / 100));
                tax = net - taxable;
                net = taxable;
            } else {
                tax = roundDirhams(net * taxRate / 100);
            }

            var display = row.querySelector('[data-line-total]');
            if (display) { display.textContent = formatMoney(net + tax); }

            return { net: net, discount: discount, tax: tax };
        }

        function recalculate() {
            var subtotal = 0, discountTotal = 0, taxTotal = 0;

            rows().forEach(function (row) {
                var result = computeRow(row);
                subtotal += result.net;
                discountTotal += result.discount;
                taxTotal += result.tax;
            });

            set('subtotal', subtotal);
            set('discount', discountTotal);
            set('tax', taxTotal);
            set('grand', subtotal + taxTotal);

            // The discount and tax rows only clutter the screen at zero.
            toggleRow('discount', discountTotal !== 0);
            toggleRow('tax', taxTotal !== 0);
        }

        function set(name, dirhams) {
            var el = editor.querySelector('[data-total="' + name + '"]');
            if (el) { el.textContent = formatMoney(dirhams); }
        }

        function toggleRow(name, visible) {
            var el = editor.querySelector('[data-total-row="' + name + '"]');
            if (el) { el.classList.toggle('hidden', !visible); }
        }

        /** Fill price, description and unit from the chosen item. */
        function applyItem(row) {
            var select = row.querySelector('[data-field="item_id"]');
            if (!select) { return; }
            var item = catalogue[select.value];
            if (!item) { return; }

            var description = row.querySelector('[data-field="description"]');
            var price = row.querySelector('[data-field="unit_price"]');
            var uom = row.querySelector('[data-field="uom"]');
            var tax = row.querySelector('[data-field="tax_rate"]');
            var priceField = editor.getAttribute('data-price-field') || 'sale_price';

            if (description && !description.value) { description.value = item.name || ''; }
            if (price && parseAmount(price.value) === 0) { price.value = item[priceField] || '0.00'; }
            if (uom) { uom.value = item.uom || 'PCS'; }
            if (tax && parseAmount(tax.value) === 0) { tax.value = item.tax_rate || '0'; }
        }

        function renumber() {
            rows().forEach(function (row, index) {
                var label = row.querySelector('[data-line-number]');
                if (label) { label.textContent = String(index + 1); }
            });
        }

        function addRow() {
            if (!template) { return null; }
            var fragment = template.content.cloneNode(true);
            tbody.appendChild(fragment);
            renumber();
            recalculate();
            var added = tbody.lastElementChild;
            var focusable = added && added.querySelector('select, input');
            if (focusable) { focusable.focus(); }
            return added;
        }

        editor.addEventListener('input', function (e) {
            if (e.target.closest('tbody')) { recalculate(); }
        });

        editor.addEventListener('change', function (e) {
            var row = e.target.closest('tr');
            if (!row) { return; }
            if (e.target.matches('[data-field="item_id"]')) {
                applyItem(row);
            }
            recalculate();
        });

        editor.addEventListener('click', function (e) {
            var remove = e.target.closest('.line-remove');
            if (remove) {
                e.preventDefault();
                // Always leave one row, so the form is never unusable.
                if (rows().length > 1) {
                    remove.closest('tr').remove();
                } else {
                    remove.closest('tr').querySelectorAll('input, select').forEach(function (field) {
                        if (field.type !== 'hidden') { field.value = ''; }
                    });
                }
                renumber();
                recalculate();
            }
        });

        if (addButton) {
            addButton.addEventListener('click', function (e) {
                e.preventDefault();
                addRow();
            });
        }

        // Ctrl+Enter adds a line, so a data-entry clerk never has to reach for
        // the mouse between rows.
        editor.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                addRow();
            }
        });

        if (rows().length === 0) { addRow(); }
        recalculate();
    }

    // ------------------------------------------------------------------
    // Payment allocation
    // ------------------------------------------------------------------

    function initAllocation() {
        var form = document.querySelector('[data-allocation]');
        if (!form) { return; }

        var amountField = form.querySelector('[data-payment-amount]');

        function refresh() {
            var allocated = 0;
            form.querySelectorAll('[data-allocation-amount]').forEach(function (input) {
                allocated += toDirhams(input.value);
            });

            var total = amountField ? toDirhams(amountField.value) : 0;
            var unallocated = total - allocated;

            var allocatedEl = form.querySelector('[data-allocated-total]');
            var unallocatedEl = form.querySelector('[data-unallocated-total]');
            if (allocatedEl) { allocatedEl.textContent = formatMoney(allocated); }
            if (unallocatedEl) {
                unallocatedEl.textContent = formatMoney(unallocated);
                unallocatedEl.classList.toggle('text-bad', unallocated < 0);
                unallocatedEl.classList.toggle('text-warn', unallocated > 0);
            }
        }

        // "Pay in full" fills each row up to its outstanding balance, stopping
        // when the payment runs out -- the oldest invoice first.
        var payAll = form.querySelector('[data-pay-all]');
        if (payAll) {
            payAll.addEventListener('click', function (e) {
                e.preventDefault();
                var remaining = amountField ? toDirhams(amountField.value) : 0;
                form.querySelectorAll('[data-allocation-amount]').forEach(function (input) {
                    var outstanding = toDirhams(input.getAttribute('data-outstanding'));
                    var apply = Math.max(0, Math.min(remaining, outstanding));
                    input.value = apply > 0 ? formatMoney(apply).replace(/,/g, '') : '';
                    remaining -= apply;
                });
                refresh();
            });
        }

        form.addEventListener('input', refresh);
        refresh();
    }

    // ------------------------------------------------------------------
    // Small conveniences
    // ------------------------------------------------------------------

    /** Ask before anything destructive. */
    function initConfirm() {
        document.addEventListener('submit', function (e) {
            var message = e.target.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                e.preventDefault();
            }
        });
    }

    /** Auto-submit filter forms when a dropdown changes. */
    function initAutoFilters() {
        document.querySelectorAll('[data-auto-submit] select, [data-auto-submit] input[type="date"]')
            .forEach(function (field) {
                field.addEventListener('change', function () {
                    field.closest('form').submit();
                });
            });
    }

    /** Warn before leaving a form with unsaved edits. */
    function initDirtyGuard() {
        var form = document.querySelector('[data-dirty-guard]');
        if (!form) { return; }
        var dirty = false;

        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    /** Filter a table live from a search box, without a round trip. */
    function initTableFilter() {
        document.querySelectorAll('[data-table-filter]').forEach(function (input) {
            var table = document.querySelector(input.getAttribute('data-table-filter'));
            if (!table) { return; }

            input.addEventListener('input', function () {
                var needle = input.value.toLowerCase().trim();
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.classList.toggle('hidden', needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1);
                });
            });
        });
    }

    /**
     * Print buttons and the "reload this form" selects used to carry inline
     * onclick/onchange attributes. The Content Security Policy blocks those,
     * so the behaviour is declared with data attributes and bound here.
     */
    function initDeclarativeActions() {
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-print]')) {
                e.preventDefault();
                window.print();
            }
        });

        document.querySelectorAll('[data-reload-form]').forEach(function (field) {
            field.addEventListener('change', function () {
                var form = field.form;
                if (!form) { return; }
                var action = field.getAttribute('data-reload-action');
                if (action) { form.action = action; }
                form.method = 'get';
                form.submit();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initDeclarativeActions();
        initNav();
        initLineEditor();
        initAllocation();
        initConfirm();
        initAutoFilters();
        initDirtyGuard();
        initTableFilter();
    });
}());
