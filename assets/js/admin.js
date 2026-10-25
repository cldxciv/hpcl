// Admin header (includes/partials/admin-bar.html): on screens narrower than 1100px the menu is
// behind the Menu button. Without JavaScript the menu is simply shown open.
document.documentElement.classList.add('admin-js');

document.addEventListener('DOMContentLoaded', () => {
    // The header's dropdowns (the menu's Settings group and the account menu) close on a click
    // elsewhere or Escape; opening one closes the other.
    const account = document.querySelector('.admin-account');
    const dropdowns = [...document.querySelectorAll('.admin-bar details')];
    dropdowns.forEach((dropdown) => dropdown.addEventListener('toggle', () => {
        if (dropdown.open) dropdowns.forEach((other) => { if (other !== dropdown) other.open = false; });
    }));
    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => { if (dropdown.open && !dropdown.contains(event.target)) dropdown.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        const open = dropdowns.find((dropdown) => dropdown.open);
        if (event.key !== 'Escape' || !open) return;
        open.open = false;
        open.querySelector('summary')?.focus();
        event.stopImmediatePropagation();
    });
    const toggle = document.querySelector('.admin-bar__toggle');
    const menu = document.getElementById('admin-menu');
    if (!toggle || !menu) return;

    const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';
    const setOpen = (open) => {
        if (!open && account) account.open = false;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        menu.classList.toggle('is-open', open);
    };

    toggle.addEventListener('click', () => {
        setOpen(!isOpen());
        if (isOpen()) menu.querySelector('a, button')?.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !isOpen()) return;
        setOpen(false);
        toggle.focus();
    });
    document.addEventListener('click', (event) => {
        if (isOpen() && !event.target.closest('.admin-bar')) setOpen(false);
    });
    // Back to the full header when the window is widened.
    window.matchMedia('(min-width: 1100px)').addEventListener('change', (event) => {
        if (event.matches) setOpen(false);
    });
});
