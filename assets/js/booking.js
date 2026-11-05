/* Ground booking: the request form (time options for the chosen package and date, price summary, ajax submit)
   and the print button of the status page. The page works without it: the form posts normally. */
(function () {
    'use strict';
    var printButtons = document.querySelectorAll('[data-print]');
    Array.prototype.forEach.call(printButtons, function (button) {
        button.addEventListener('click', function () { window.print(); });
    });

    // Photo gallery: the thumbnails are links to the full photos; with JavaScript they open in a dialog (arrow keys, Escape).
    var lightbox = document.getElementById('bk-lightbox');
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
    if (lightbox && typeof lightbox.showModal === 'function' && links.length) {
        var shown = 0;
        var big = document.getElementById('bk-lightbox-img');
        var count = document.getElementById('bk-lightbox-count');
        var show = function (i) {
            shown = (i + links.length) % links.length;
            var link = links[shown];
            big.src = link.getAttribute('href');
            big.alt = link.getAttribute('data-alt') || '';
            big.setAttribute('width', link.getAttribute('data-w') || '1280');
            big.setAttribute('height', link.getAttribute('data-h') || '960');
            count.textContent = (shown + 1) + ' / ' + links.length;
        };
        links.forEach(function (link, i) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                show(i);
                lightbox.showModal();
                lightbox.querySelector('[data-lightbox-close]').focus();
            });
        });
        lightbox.querySelector('[data-lightbox-prev]').addEventListener('click', function () { show(shown - 1); });
        lightbox.querySelector('[data-lightbox-next]').addEventListener('click', function () { show(shown + 1); });
        lightbox.querySelector('[data-lightbox-close]').addEventListener('click', function () { lightbox.close(); });
        lightbox.addEventListener('click', function (event) { if (event.target === lightbox) lightbox.close(); });
        lightbox.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight') { event.preventDefault(); show(shown + 1); }
            if (event.key === 'ArrowLeft') { event.preventDefault(); show(shown - 1); }
        });
        // The browser returns focus to the photo that was opened when the dialog closes.
        lightbox.addEventListener('close', function () { links[shown].focus(); big.removeAttribute('src'); });
    }

    var form = document.getElementById('bk-form');
    if (!form || !window.fetch || !window.URLSearchParams) return;
    var endpoint = form.getAttribute('data-availability-url');
    var slotList = document.getElementById('bk-slot-list');
    var dateInput = document.getElementById('date');
    var emailInput = document.getElementById('email');
    var daysInput = document.getElementById('days');
    var money = document.getElementById('bk-money');
    var mealText = document.getElementById('bk-meal-saving');
    var playersInput = document.getElementById('players_per_team');
    var standard = Number(form.getAttribute('data-standard-players')) || 11;
    var submit = document.getElementById('bk-submit');
    var errorBox = document.getElementById('bk-errors');
    var sums = {};
    Array.prototype.forEach.call(document.querySelectorAll('[data-sum]'), function (el) { sums[el.getAttribute('data-sum')] = el; });

    function checked(name) {
        var el = form.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : '';
    }
    function rupees(n) {
        return n === null || n === undefined ? '–' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
    }
    function box(id) {
        var el = document.getElementById(id);
        return el && el.checked ? '1' : '0';
    }
    function days() { return daysInput ? daysInput.value : '1'; }
    function longDate(d) { return d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); }
    function setSum(key, text) { if (sums[key]) sums[key].textContent = text; }
    function getJson(params) {
        return fetch(endpoint + '?' + new URLSearchParams(params).toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    // What the summary says about the choices made so far.
    function updateChoices() {
        var format = form.querySelector('input[name="format"]:checked');
        setSum('package', format ? form.querySelector('label[for="' + format.id + '"]').textContent : 'Not chosen yet');
        var parts = dateInput.value.split('-');
        var day = parts.length === 3 ? new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])) : null;
        var count = Number(days());
        if (day && !isNaN(day.getTime())) {
            var last = new Date(day.getFullYear(), day.getMonth(), day.getDate() + count - 1);
            setSum('date', count > 1 ? day.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) + ' to ' + longDate(last) + ' (' + count + ' days)' : longDate(day));
        } else {
            setSum('date', 'Not chosen yet');
        }
        var slot = form.querySelector('input[name="slot"]:checked');
        if (slot) {
            var label = slot.parentNode.querySelector('label');
            setSum('time', label.querySelector('.bk-slot__name').textContent + ' (' + label.querySelector('.bk-slot__range').textContent + ')');
        } else {
            setSum('time', 'Not chosen yet');
        }
        var perTeam = format ? format.getAttribute('data-per-team') === '1' : true;
        setSum('pay_for', !format ? '–' : (perTeam ? (checked('teams_paying') === '2' ? 'Both teams' : 'This team only') : 'Flat price for the day'));
    }
    function updateTeamsVisibility() {
        var format = form.querySelector('input[name="format"]:checked');
        var teams = document.getElementById('bk-teams');
        var perTeam = !format || format.getAttribute('data-per-team') === '1';
        teams.hidden = !perTeam;
        if (!perTeam) document.getElementById('teams-1').checked = true;
    }
    var slotRequest = 0;
    function refreshSlots() {
        var format = checked('format');
        var date = dateInput.value;
        if (!format || !date) {
            slotList.innerHTML = '<p class="bk-hint bk-slots__hint">Choose a package and a date to see the available times.</p>';
            updateChoices();
            return;
        }
        var id = ++slotRequest;
        getJson({ date: date, format: format, days: days(), slot: checked('slot') }).then(function (data) {
            if (id !== slotRequest) return;
            slotList.innerHTML = data.html || '';
            updateChoices();
        }).catch(function () {
            slotList.innerHTML = '<p class="bk-hint bk-slots__hint">We could not load the times. Please try again.</p>';
        });
    }
    var quoteRequest = 0;
    function refreshQuote() {
        var format = checked('format');
        if (!format) return;
        var id = ++quoteRequest;
        getJson({ quote: '1', format: format, slot: checked('slot'), teams: checked('teams_paying') || '1', days: days(), lunch: box('want_lunch'), tea: box('want_tea'), email: emailInput.value.trim(),
            stay_people: box('stay_needed') === '1' ? (document.getElementById('stay_people') || {}).value || '' : '', stay_nights: box('stay_needed') === '1' ? (document.getElementById('stay_nights') || {}).value || '' : '' }).then(function (data) {
            if (id !== quoteRequest || !data.ok) return;
            if (money) money.innerHTML = data.html;
            var stayCalc = document.getElementById('bk-stay-calc');
            if (stayCalc && data.stay_text) stayCalc.textContent = data.stay_text;
            if (mealText) mealText.textContent = data.meal_text;
            var one = document.querySelector('[data-teams-price="1"]');
            var both = document.querySelector('[data-teams-price="2"]');
            if (one) one.textContent = data.single_price === null ? '' : rupees(data.single_price) + ' / day';
            if (both) both.textContent = data.both_price === null ? '' : rupees(data.both_price) + ' / day';
        }).catch(function () { /* the price stays as it was */ });
    }
    function updatePlayers() {
        var note = document.getElementById('bk-extra-players');
        if (note) note.hidden = !(Number(playersInput.value) > standard);
    }

    function updateStay() {
        var on = document.getElementById('stay_needed');
        var details = document.getElementById('bk-stay-details');
        var note = document.getElementById('bk-stay-note');
        if (!on) return;
        if (details) details.hidden = !on.checked;
        if (note) note.hidden = !on.checked;
    }
    form.addEventListener('change', function (event) {
        var name = event.target.name;
        if (name === 'format') { updateTeamsVisibility(); refreshSlots(); refreshQuote(); updateChoices(); }
        else if (name === 'date') { refreshSlots(); updateChoices(); }
        else if (name === 'days') { refreshSlots(); refreshQuote(); updateChoices(); }
        else if (name === 'slot') updateChoices();
        else if (name === 'teams_paying') { refreshQuote(); updateChoices(); }
        else if (name === 'want_lunch' || name === 'want_tea') refreshQuote();
        else if (name === 'stay_needed') { updateStay(); refreshQuote(); }
        else if (name === 'stay_people' || name === 'stay_nights') refreshQuote();
        else if (name === 'email') refreshQuote();
    });
    if (playersInput) playersInput.addEventListener('input', updatePlayers);
    ['stay_people', 'stay_nights'].forEach(function (id) {
        var input = document.getElementById(id);
        if (input) input.addEventListener('input', function () { refreshQuote(); });
    });

    // Errors: under their fields, in the summary box at the top, and announced.
    function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('.bk-error'), function (el) { el.textContent = ''; });
        Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });
        errorBox.hidden = true;
        errorBox.querySelector('ul').innerHTML = '';
    }
    var labels = { format: 'Package', date: 'Date', days: 'Days', slot: 'Time', players_per_team: 'Players', name: 'Name', email: 'Email', phone: 'Phone', terms: 'Terms', stay_people: 'People staying', stay_nights: 'Nights', form: 'Your request' };
    function showErrors(errors, message) {
        var list = errorBox.querySelector('ul');
        var keys = Object.keys(errors || {});
        if (!keys.length) keys = ['form'], errors = { form: message || 'Something went wrong. Please try again.' };
        keys.forEach(function (key) {
            var text = errors[key];
            var line = document.getElementById('err-' + key);
            if (line) line.textContent = text;
            var input = document.getElementById(key);
            if (input) { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' err-' + key).trim()); }
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#' + (key === 'form' ? 'bk-form' : key === 'format' ? 'format-single' : key === 'slot' ? 'bk-slots' : key);
            link.textContent = (labels[key] || key) + ': ' + text;
            item.appendChild(link);
            list.appendChild(item);
        });
        errorBox.hidden = false;
        errorBox.focus();
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearErrors();
        var original = submit.textContent;
        submit.disabled = true;
        submit.textContent = 'Sending…';
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (result) {
                if (result.ok && result.data.ok && result.data.redirect) { window.location.href = result.data.redirect; return; }
                showErrors(result.data.errors, result.data.message);
                submit.disabled = false;
                submit.textContent = original;
            })
            .catch(function () {
                // No usable answer (offline or a server error): let the browser post the form normally.
                form.submit();
            });
    });

    updateTeamsVisibility();
    updateStay();
    updatePlayers();
    updateChoices();
    if (checked('format')) refreshQuote();
})();
