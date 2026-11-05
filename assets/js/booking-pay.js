/*
 * Ground booking payment (includes/booking-pay.php). The Pay button posts to /booking/<token>/pay/create,
 * which answers with the Razorpay Checkout options for the advance; Checkout takes the payment and its answer
 * is confirmed at /pay/verify, then the page reloads showing the confirmed booking. Closing Checkout leaves
 * the booking approved, so paying can simply be tried again. On other pages it only wires up the Print button
 * and the pay-by countdown.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

    const countdown = document.querySelector('.bkp-countdown');
    if (countdown) {
        const due = parseInt(countdown.dataset.due, 10) * 1000;
        const offset = parseInt(countdown.dataset.now, 10) * 1000 - Date.now();
        const tick = () => {
            const left = due - (Date.now() + offset);
            if (left <= 0) { countdown.textContent = ''; window.location.reload(); return; }
            const minutes = Math.floor(left / 60000);
            const days = Math.floor(minutes / 1440);
            const hours = Math.floor((minutes % 1440) / 60);
            countdown.textContent = '(' + (days ? days + ' d ' : '') + (days || hours ? hours + ' h ' : '') + (minutes % 60) + ' min left)';
        };
        tick();
        window.setInterval(tick, 30000);
    }

    const form = document.getElementById('bkp-form');
    if (!form) return;
    const alertBox = document.getElementById('bkp-alert');
    const payButton = document.getElementById('bkp-pay');
    const payLabel = payButton.textContent;

    function showAlert(message, ok) {
        alertBox.textContent = message;
        alertBox.classList.toggle('sco-alert--ok', !!ok);
        alertBox.hidden = !message;
        if (message) {
            alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alertBox.focus({ preventScroll: true });
        }
    }
    function busy(on, label) {
        payButton.disabled = on;
        payButton.textContent = on ? label : payLabel;
    }
    async function postJson(url, body) {
        const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        let data = null;
        try { data = await response.json(); } catch (error) { /* handled by the caller */ }
        return data || { ok: false, message: 'Something went wrong. Please try again.' };
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showAlert('', false);
        busy(true, 'Starting the payment…');
        let data;
        try {
            data = await postJson(form.action, new FormData(form));
        } catch (error) {
            busy(false);
            showAlert('We couldn’t reach the server. Check your connection and try again.');
            return;
        }
        if (!data.ok) {
            busy(false);
            showAlert(data.message || 'We couldn’t start the payment. Please try again.');
            if (data.redirect) window.setTimeout(() => { window.location.href = data.redirect; }, 3000);
            return;
        }
        openCheckout(data);
    });

    function openCheckout(data) {
        if (!window.Razorpay) {
            busy(false);
            showAlert('The payment window couldn’t load. Check your internet connection and press Pay again.');
            return;
        }
        busy(true, 'Waiting for payment…');
        const csrf = form.elements.csrf_token.value;
        const checkout = new window.Razorpay(Object.assign({}, data.payment, {
            handler: (response) => confirmPayment(response, csrf, data),
            modal: {
                confirm_close: true,
                ondismiss: () => {
                    busy(false);
                    showAlert('The payment wasn’t completed, so nothing was charged. Your slot is still held: press Pay to try again.');
                },
            },
        }));
        checkout.on('payment.failed', (response) => {
            busy(false);
            showAlert('The payment failed: ' + ((response.error && response.error.description) || 'please try again') + '. No money was taken; you can try again.');
        });
        checkout.open();
    }

    async function confirmPayment(response, csrf, data) {
        busy(true, 'Confirming your payment…');
        const body = new FormData();
        body.append('csrf_token', csrf);
        ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature'].forEach((name) => body.append(name, response[name] || ''));
        let result;
        try {
            result = await postJson(form.dataset.verify, body);
        } catch (error) {
            result = null;
        }
        if (result && result.ok) {
            showAlert(result.message || 'Payment received.', true);
            window.location.href = result.redirect;
            return;
        }
        busy(false);
        // The money may have been taken: the page confirms it once Razorpay tells us, and the payment ID lets the team find it.
        showAlert((result && result.message) || 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' + (response.razorpay_payment_id || '') + '.');
        if (data && data.redirect) {
            const link = document.createElement('a');
            link.href = data.redirect;
            link.textContent = ' Check this booking’s status.';
            alertBox.appendChild(link);
        }
    }
})();
