// Shop admin pages: select-all on the product list, and the packing slip's Print button.
// (The admin Content-Security-Policy allows scripts only from this site, so this lives in a file.)
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-select-all]').forEach((box) => {
        box.addEventListener('change', () => {
            const table = box.closest('table');
            if (table) table.querySelectorAll('input[name="ids[]"]').forEach((row) => { row.checked = box.checked; });
        });
    });
    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
});
