// Membership popup: opens once the page has fully loaded plus 5 seconds, then stays quiet for a week
// (a month after a request is sent). Esc, the backdrop and the close button dismiss it.
(function () {
    'use strict';
    var DELAY = 5000;
    var QUIET_AFTER_SHOWN = 7 * 24 * 3600 * 1000;
    var QUIET_AFTER_SENT = 30 * 24 * 3600 * 1000;
    var KEY = 'hpcl_mpop_until';
    // Pages with their own sign-up or account forms, and pages where a popup would be in the way.
    var SKIP = /^\/(membership|join-committee|signup|login|forgot-password|reset-password|account|admin|agent|shop|book|booking)(\/|$)/;

    var root = document.getElementById('mpop');
    if (!root) return;
    var dialog = root.querySelector('.mpop__dialog');
    var thanks = document.getElementById('mpop-thanks');
    var base = (root.getAttribute('data-base') || '').replace(/\/$/, '');
    var path = location.pathname;
    if (base && path.indexOf(base) === 0) path = path.slice(base.length) || '/';
    if (SKIP.test(path)) return;

    function quietUntil(ms) {
        try { localStorage.setItem(KEY, String(Date.now() + ms)); } catch (e) { /* private mode: it may show again */ }
    }
    function isQuiet() {
        try { return Number(localStorage.getItem(KEY)) > Date.now(); } catch (e) { return false; }
    }
    if (isQuiet()) return;

    var opener = null;
    function focusable() {
        return Array.prototype.filter.call(dialog.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'), function (el) {
            return !el.disabled && el.tabIndex >= 0 && el.offsetParent !== null;
        });
    }
    function open() {
        // Not while the visitor is using the chat assistant or has already started typing in a form.
        var chat = document.querySelector('.hpcl-agent__panel');
        if (!root.hidden || (chat && !chat.hidden) || isQuiet()) return;
        quietUntil(QUIET_AFTER_SHOWN);
        opener = document.activeElement;
        root.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        dialog.focus();
    }
    function close() {
        if (root.hidden) return;
        root.hidden = true;
        document.documentElement.style.overflow = '';
        if (opener && opener.focus) opener.focus();
    }

    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-mpop-close]')) close();
    });
    document.addEventListener('keydown', function (event) {
        if (root.hidden) return;
        if (event.key === 'Escape') { close(); return; }
        if (event.key !== 'Tab') return;
        var items = focusable();
        if (!items.length) return;
        var first = items[0], last = items[items.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    // forms.js reveals the thank-you step after a saved request: stay quiet for longer.
    if (thanks && window.MutationObserver) {
        new MutationObserver(function () { if (!thanks.hidden) quietUntil(QUIET_AFTER_SENT); }).observe(thanks, { attributes: true, attributeFilter: ['hidden'] });
    }

    function schedule() { window.setTimeout(open, DELAY); }
    if (document.readyState === 'complete') schedule();
    else window.addEventListener('load', schedule);
})();
