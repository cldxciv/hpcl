/*
 * Shop checkout (includes/shop-checkout.php). The delivery form posts to /shop/checkout/place, which saves
 * a pending order and answers with the Razorpay Checkout options; Checkout takes the payment and its answer
 * is confirmed at /shop/checkout/verify, which sends the customer to the order page. Closing Checkout leaves
 * the cart as it was, so paying can simply be tried again. On the order and account pages it only wires up
 * the Print button.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

    const form = document.getElementById('sco-form');
    if (!form) return;
    const alertBox = document.getElementById('sco-alert');
    const payButton = document.getElementById('sco-pay');
    const payLabel = payButton ? payButton.textContent : '';

    function showAlert(message, ok) {
        alertBox.textContent = message;
        alertBox.classList.toggle('sco-alert--ok', !!ok);
        alertBox.hidden = !message;
        if (message) {
            alertBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
            alertBox.focus({ preventScroll: true });
        }
    }
    function clearErrors() {
        showAlert('', false);
        form.querySelectorAll('.is-invalid').forEach((field) => {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.invalid-feedback').forEach((box) => { box.textContent = ''; });
    }
    function showFieldErrors(errors) {
        let first = null;
        Object.keys(errors || {}).forEach((name) => {
            const field = form.elements[name];
            const box = document.getElementById(name + '-error');
            if (!field) return;
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            if (box) box.textContent = errors[name];
            first = first || field;
        });
        if (first) first.focus();
    }
    function busy(on, label) {
        if (!payButton) return;
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
        if (form.dataset.payReady !== '1') return;
        clearErrors();
        busy(true, 'Saving your order…');
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
            if (data.errors && Object.keys(data.errors).length) {
                showAlert(data.message || 'Please correct the highlighted fields.');
                showFieldErrors(data.errors);
            } else {
                showAlert(data.message || 'We couldn’t place your order. Please try again.');
                if (data.redirect) window.setTimeout(() => { window.location.href = data.redirect; }, 2500);
            }
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
                    showAlert('The payment wasn’t completed, so nothing was charged. Your cart is saved: press Pay to try again.');
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
            result = await postJson(form.action.replace(/\/place$/, '/verify'), body);
        } catch (error) {
            result = null;
        }
        if (result && result.ok) {
            showAlert(result.message || 'Payment received.', true);
            window.location.href = result.redirect;
            return;
        }
        busy(false);
        // The money may have been taken: the order page confirms it once Razorpay tells us, and the payment ID lets the team find it.
        showAlert((result && result.message) || 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' + (response.razorpay_payment_id || '') + '.');
        if (data && data.redirect) {
            const link = document.createElement('a');
            link.href = data.redirect;
            link.textContent = ' Check this order’s status.';
            alertBox.appendChild(link);
        }
    }
})();
