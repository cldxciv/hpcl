// Booking admin: the approve form shows the total, the advance to pay now and the balance as the team types.
// (The admin Content-Security-Policy allows scripts only from this site, so this lives in a file.)
document.addEventListener('DOMContentLoaded', () => {
    const base = document.getElementById('base');
    const stay = document.getElementById('stay_amount');
    const extra = document.getElementById('extra_amount');
    const percent = document.getElementById('advance_percent');
    const total = document.querySelector('[data-booking-total]');
    const now = document.querySelector('[data-booking-now]');
    const balance = document.querySelector('[data-booking-balance]');
    if (!base || !percent || !total || !now || !balance) return;
    const number = (field) => {
        const value = parseFloat(((field && field.value) || '').replace(/[,₹\s]/g, ''));
        return Number.isFinite(value) && value >= 0 ? value : null;
    };
    const optional = (field) => (field && field.value.trim() !== '' ? number(field) : 0);
    const money = (value) => '₹' + Math.round(value).toLocaleString('en-IN');
    const update = () => {
        const price = number(base);
        const addStay = optional(stay);
        const addExtra = optional(extra);
        const share = number(percent);
        if (price === null || addStay === null || addExtra === null || share === null || share > 100) {
            total.textContent = now.textContent = balance.textContent = '—';
            return;
        }
        const sum = price + addStay + addExtra;
        const advance = Math.round((sum * share) / 100);
        total.textContent = money(sum);
        now.textContent = money(advance);
        balance.textContent = money(sum - advance);
    };
    document.querySelectorAll('[data-booking-calc]').forEach((field) => field.addEventListener('input', update));
    update();
});
