/*
 * Payment of the committee member form (includes/committee.php), loaded with Razorpay's
 * checkout.js only while payment is on. When the server has saved an application that must be
 * paid, its answer has "payment" (the Checkout options): AppForm's success step waits while
 * Checkout takes the payment, which is then confirmed at /join-committee/payment. Closing
 * Checkout keeps the application unpaid, with a button to pay again.
 */
(function () {
    'use strict';

    const form = document.querySelector('form[data-ajax-form][data-payment]');
    if (!form) return;
    const submitButton = form.querySelector('[type="submit"]');
    const payButton = form.querySelector('[data-pay-again]');
    let pending = null;

    /** While an order waits for payment, Pay replaces Submit, so the application isn't sent twice. */
    function setPending(data) {
        pending = data;
        submitButton.hidden = !!data;
        payButton.hidden = !data;
        if (data) payButton.textContent = 'Pay ' + data.payment.amount_label + ' now';
    }

    form.addEventListener('appform:success', (event) => {
        if (!event.detail.payment) return;
        event.preventDefault();
        setPending(event.detail);
        pay();
    });
    payButton.addEventListener('click', () => pending && pay());

    function pay() {
        AppForm.showAlert(form, '', '');
        if (!window.Razorpay) {
            AppForm.showAlert(form, 'The payment window couldn’t load. Check your internet connection and press Pay again.', 'danger');
            return;
        }
        // amount_label is for this page, not an option of Checkout.
        const { amount_label: amountLabel, ...options } = pending.payment;
        const checkout = new window.Razorpay({
            ...options,
            handler: confirmPayment,
            modal: {
                confirm_close: true,
                ondismiss: () => AppForm.showAlert(form, 'The payment wasn’t completed. Your application is saved, but it is only sent once you pay ' + amountLabel + '.', 'warning'),
            },
        });
        checkout.on('payment.failed', (response) => AppForm.showAlert(form, 'The payment failed: '
            + ((response.error && response.error.description) || 'please try again') + '. No money was taken; you can try again.', 'danger'));
        checkout.open();
    }

    async function confirmPayment(response) {
        const body = new FormData();
        ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature'].forEach((name) => body.append(name, response[name] || ''));
        payButton.disabled = true;
        AppForm.showAlert(form, 'Confirming your payment…', 'info', false);
        let data = null;
        try {
            const answer = await fetch(form.action.replace(/\/?$/, '/payment'), { method: 'POST', body, headers: { Accept: 'application/json' } });
            data = await answer.json();
        } catch (error) { /* shown below */ }
        payButton.disabled = false;
        if (!data || !data.ok) {
            // The money may have been taken: the payment ID lets the league find it.
            AppForm.showAlert(form, (data && data.message) || 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' + response.razorpay_payment_id + '.', 'danger');
            payButton.hidden = true;
            return;
        }
        const receipt = document.querySelector('[data-payment-receipt]');
        if (receipt) {
            receipt.querySelector('strong').textContent = data.payment_id;
            receipt.hidden = false;
        }
        setPending(null);
        AppForm.showAlert(form, '', '');
        AppForm.showSuccess(form, data);
    }
})();
