// Home page "Latest from Instagram" section: arrows scroll the row; a card opens the reel in
// Instagram's own player inside a dialog (the player loads only then). Without JavaScript, or a
// browser without <dialog>, a card is a plain link to the reel on Instagram.
(function () {
    'use strict';
    var root = document.querySelector('[data-ig-reels]');
    if (!root) return;
    var track = root.querySelector('[data-ig-track]');
    var prev = root.querySelector('[data-ig-prev]');
    var next = root.querySelector('[data-ig-next]');
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-reel]'));

    function updateArrows() {
        if (!prev || !next) return;
        prev.disabled = track.scrollLeft <= 4;
        next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    }
    function scrollByPage(direction) {
        track.scrollBy({ left: direction * track.clientWidth * 0.9, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    if (prev) prev.addEventListener('click', function () { scrollByPage(-1); });
    if (next) next.addEventListener('click', function () { scrollByPage(1); });
    track.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    updateArrows();

    var dialog = root.querySelector('[data-ig-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var frame = dialog.querySelector('[data-ig-frame]');
    var openLink = dialog.querySelector('[data-ig-open]');
    var current = -1;
    var opener = null;

    // Instagram's player reports its height (a "MEASURE" message): fit the dialog to it, so
    // landscape reels don't leave an empty white box. Until then the CSS height applies.
    window.addEventListener('message', function (event) {
        if (event.origin !== 'https://www.instagram.com' || !dialog.open) return;
        var data = event.data;
        try { if (typeof data === 'string') data = JSON.parse(data); } catch (e) { return; }
        var height = data && data.type === 'MEASURE' && data.details ? Number(data.details.height) : 0;
        if (height > 200) frame.style.height = Math.min(height, window.innerHeight - 110) + 'px';
    });

    function show(index) {
        current = (index + cards.length) % cards.length;
        frame.style.height = '';
        var card = cards[current];
        var code = card.getAttribute('data-reel');
        var iframe = document.createElement('iframe');
        iframe.src = 'https://www.instagram.com/reel/' + encodeURIComponent(code) + '/embed/';
        iframe.title = card.getAttribute('aria-label') || 'Instagram reel';
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; clipboard-write');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('loading', 'eager');
        frame.replaceChildren(iframe);
        openLink.href = card.href;
    }
    cards.forEach(function (card, index) {
        card.addEventListener('click', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            opener = card;
            show(index);
            dialog.showModal();
            dialog.querySelector('[data-ig-close]').focus();
        });
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog || event.target.closest('[data-ig-close]')) { dialog.close(); return; }
        var step = event.target.closest('[data-ig-step]');
        if (step) show(current + Number(step.getAttribute('data-ig-step')));
    });
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') show(current - 1);
        else if (event.key === 'ArrowRight') show(current + 1);
    });
    dialog.addEventListener('close', function () {
        frame.replaceChildren();   // stops the video
        if (opener) opener.focus();
    });
})();
