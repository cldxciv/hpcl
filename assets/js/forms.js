/*
 * AppForm: validation and AJAX submission for any public form, loaded on every page
 * (partials/scripts.html). Add data-ajax-form to a <form> and it is set up on page load; or call
 * AppForm.bind(form, options) yourself.
 *
 * Validation uses the fields' own attributes: required, type="email", minlength, maxlength,
 * pattern, accept (file types), plus
 *   data-max-size="5242880"       largest file in bytes
 *   data-validate="phone-in"      a named rule of AppForm.validators (add your own)
 *   data-not-same-as="other"      a file that must differ from the file in field "other"
 *   data-msg-required="…"         the message of a rule (data-msg-email, -minlength, -maxlength,
 *                                 -pattern, -accept, -max-size, -validate, -not-same-as)
 *   data-char-count               shows "12 / 2000" in #{id}-count as the user types
 *   data-preview                  on a file field: shows the chosen image (or the PDF's name)
 *                                 with its size in #{id}-preview, created after the field when missing
 * Errors are shown in #{name}-error (created after the field when missing) and the field gets
 * Bootstrap's is-invalid. The server checks everything again: its answer is JSON
 * {ok, message, errors: {field: message}, redirect} (formResponse() in includes/site.php), and its
 * errors are shown the same way.
 *
 * On the form: [data-form-alert] holds messages about the whole form, [data-form-progress] is a
 * Bootstrap progress bar for uploads, data-loading-text is the submit button's text while sending.
 * After success: data-success-show="#id" shows that element and hides the form (or
 * data-success-hide="#id"); otherwise the browser goes to the server's redirect. Before that the
 * form fires a cancelable "appform:success" event with the server's answer as its detail: a page
 * can cancel it to do more first (such as take a payment), then call AppForm.showSuccess(form, data).
 */
(function () {
    'use strict';

    const formatBytes = (bytes) => (bytes >= 1048576 ? +(bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB');

    const validators = {
        // An Indian mobile number: 10 digits from 6–9, with an optional +91 or 0 and spaces or dashes.
        'phone-in': (value) => value === '' || /^(?:\+?91|0)?[6-9]\d{9}$/.test(value.replace(/[\s().-]+/g, '')),
        email: (value) => value === '' || /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(value),
        // Letters of any script, spaces, and . ' - between them (as in "K. Singh", "D'Souza").
        'person-name': (value) => /^\p{L}[\p{L}\p{M} .'-]*$/u.test(value),
    };

    /** The field's label text, for default messages ("Full name is required."). */
    function labelOf(field) {
        const label = field.id && field.form.querySelector('label[for="' + CSS.escape(field.id) + '"]');
        const group = field.closest('fieldset');
        const text = (label && label.textContent) || (field.type === 'radio' && group && group.querySelector('legend')?.textContent) || field.name;
        return text.trim().replace(/\s+/g, ' ');
    }
    function message(field, rule, fallback) {
        return field.dataset['msg' + rule.replace(/(^|-)(\w)/g, (m, dash, letter) => letter.toUpperCase())] || fallback;
    }
    function acceptsFile(file, accept) {
        const name = file.name.toLowerCase();
        return accept.split(',').map((type) => type.trim().toLowerCase()).some((type) =>
            type.startsWith('.') ? name.endsWith(type) : type.endsWith('/*') ? file.type.startsWith(type.slice(0, -1)) : file.type === type);
    }

    /** The first error of a field, or ''. A radio group is checked once, through its first radio. */
    function fieldError(field) {
        const form = field.form;
        const label = labelOf(field);
        if (field.type === 'radio') {
            const checked = form.querySelector('input[type="radio"][name="' + CSS.escape(field.name) + '"]:checked');
            return field.required && !checked ? message(field, 'required', 'Choose ' + label.toLowerCase() + '.') : '';
        }
        if (field.type === 'checkbox') return field.required && !field.checked ? message(field, 'required', 'Tick ' + label.toLowerCase() + '.') : '';
        if (field.type === 'file') {
            const file = field.files[0];
            if (!file) return field.required ? message(field, 'required', 'Choose a file for ' + label.toLowerCase() + '.') : '';
            if (field.accept && !acceptsFile(file, field.accept)) return message(field, 'accept', 'This file type isn’t allowed.');
            if (file.size === 0) return 'This file is empty. Choose another one.';
            if (field.dataset.maxSize && file.size > Number(field.dataset.maxSize)) return message(field, 'max-size', 'The file must be ' + formatBytes(Number(field.dataset.maxSize)) + ' or smaller.');
            const other = field.dataset.notSameAs && form.elements[field.dataset.notSameAs];
            const otherFile = other && other.files && other.files[0];
            if (otherFile && otherFile.name === file.name && otherFile.size === file.size && otherFile.lastModified === file.lastModified) {
                return message(field, 'not-same-as', 'Choose a different file from ' + labelOf(other).toLowerCase() + '.');
            }
            return '';
        }
        const value = field.value.trim();
        if (value === '') return field.required ? message(field, 'required', label + ' is required.') : '';
        const length = [...value.replace(/\s+/g, ' ')].length;
        if (field.minLength > 0 && length < field.minLength) return message(field, 'minlength', 'Write at least ' + field.minLength + ' characters (you have ' + length + ').');
        if (field.maxLength > 0 && length > field.maxLength) return message(field, 'maxlength', 'Use ' + field.maxLength + ' characters or fewer.');
        if (field.type === 'email' && !validators.email(value)) return message(field, 'email', 'Enter a valid email address, like name@example.com.');
        if (field.pattern && !new RegExp('^(?:' + field.pattern + ')$', 'u').test(value)) return message(field, 'pattern', label + ' isn’t in the right format.');
        const rule = field.dataset.validate && validators[field.dataset.validate];
        if (rule && !rule(value, field)) return message(field, 'validate', label + ' isn’t valid.');
        return '';
    }

    /** The fields AppForm checks: named inputs, one per radio group. */
    function fieldsOf(form) {
        const seen = new Set();
        return [...form.elements].filter((field) => {
            if (!field.name || field.disabled || ['submit', 'button', 'reset', 'hidden'].includes(field.type) || field.closest('[aria-hidden="true"]')) return false;
            if (field.type === 'radio') {
                if (seen.has(field.name)) return false;
                seen.add(field.name);
            }
            return true;
        });
    }
    function feedbackOf(form, name) {
        let feedback = form.querySelector('#' + CSS.escape(name + '-error'));
        if (!feedback) {
            const field = form.elements[name];
            const anchor = field instanceof RadioNodeList ? field[field.length - 1].closest('label') || field[field.length - 1] : field;
            if (!anchor) return null;
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.id = name + '-error';
            anchor.insertAdjacentElement('afterend', feedback);
        }
        return feedback;
    }
    /** Shows or clears the error of field $name. */
    function setError(form, name, text) {
        const field = form.elements[name];
        if (!field) return;
        const fields = field instanceof RadioNodeList ? [...field] : [field];
        const feedback = feedbackOf(form, name);
        fields.forEach((input) => {
            input.classList.toggle('is-invalid', !!text);
            if (text) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
            if (feedback) input.setAttribute('aria-describedby', feedback.id);
        });
        form.querySelector('[data-field-group="' + CSS.escape(name) + '"]')?.classList.toggle('is-invalid', !!text);
        if (feedback) {
            feedback.textContent = text;
            feedback.classList.toggle('d-block', !!text);
        }
    }
    function validateField(field) {
        const text = fieldError(field);
        setError(field.form, field.name, text);
        return !text;
    }
    /** Checks every field; focuses the first invalid one. Returns whether the form is valid. */
    function validate(form) {
        const invalid = fieldsOf(form).filter((field) => !validateField(field));
        if (invalid[0]) invalid[0].focus({ preventScroll: false });
        return invalid.length === 0;
    }

    function showAlert(form, text, type, scroll = true) {
        const alert = form.querySelector('[data-form-alert]');
        if (!alert) return;
        alert.className = text ? 'alert alert-' + type : '';
        alert.textContent = text || '';
        alert.hidden = !text;
        if (text && scroll) alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function setBusy(form, busy, percent) {
        const button = form.querySelector('[type="submit"]');
        if (button) {
            if (busy && !button.dataset.idleText) button.dataset.idleText = button.textContent;
            button.disabled = busy;
            button.textContent = busy ? (form.dataset.loadingText || 'Sending…') + (percent != null && percent < 100 ? ' ' + percent + '%' : '') : button.dataset.idleText || button.textContent;
        }
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        const progress = form.querySelector('[data-form-progress]');
        if (progress) {
            progress.hidden = !busy;
            const bar = progress.querySelector('.progress-bar');
            if (bar) {
                bar.style.width = (percent || 0) + '%';
                progress.setAttribute('aria-valuenow', String(percent || 0));
            }
        }
    }

    /** Sends the form with XMLHttpRequest (for upload progress); resolves with [status, JSON or null]. */
    function send(form, onProgress) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open((form.getAttribute('method') || 'post').toUpperCase(), form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.addEventListener('progress', (event) => event.lengthComputable && onProgress(Math.round((event.loaded / event.total) * 100)));
            xhr.addEventListener('load', () => {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (error) { /* an error page, not JSON */ }
                resolve([xhr.status, data]);
            });
            xhr.addEventListener('error', () => resolve([0, null]));
            xhr.addEventListener('timeout', () => resolve([0, null]));
            xhr.timeout = 120000;
            xhr.send(new FormData(form));
        });
    }

    function succeed(form, data, options) {
        if (!form.dispatchEvent(new CustomEvent('appform:success', { detail: data, cancelable: true }))) return;
        if (options.onSuccess && options.onSuccess(data, form) === false) return;
        showSuccess(form, data);
    }
    /** The form's success step: shows its data-success-show element, follows the redirect, or shows the message. */
    function showSuccess(form, data) {
        const show = form.dataset.successShow && document.querySelector(form.dataset.successShow);
        if (show) {
            (form.dataset.successHide ? document.querySelector(form.dataset.successHide) : form).hidden = true;
            form.reset();
            show.hidden = false;
            show.setAttribute('tabindex', '-1');
            show.scrollIntoView({ behavior: 'smooth', block: 'start' });
            show.focus({ preventScroll: true });
        } else if (data.redirect) {
            window.location.assign(data.redirect);
        } else {
            form.reset();
            showAlert(form, data.message, 'success');
        }
    }

    async function submit(form, options = {}) {
        showAlert(form, '', '');
        if (!validate(form)) {
            showAlert(form, 'Please correct the highlighted fields.', 'danger', false);
            return;
        }
        setBusy(form, true, 0);
        const [status, data] = await send(form, (percent) => setBusy(form, true, percent));
        setBusy(form, false);
        if (data && data.ok) return succeed(form, data, options);
        if (options.onError) options.onError(data, status, form);
        if (data && data.errors) {
            fieldsOf(form).forEach((field) => setError(form, field.name, data.errors[field.name] || ''));
            const first = fieldsOf(form).find((field) => data.errors[field.name]);
            if (first) first.focus();
        }
        showAlert(form, data && data.message ? data.message
            : status === 413 ? 'Your files are too large to send. Upload smaller files.'
            : status === 0 ? 'Couldn’t reach the server. Check your internet connection and try again.'
            : 'Something went wrong. Please try again in a few minutes.', 'danger');
    }

    /** The preview box of a file field. */
    function previewOf(field) {
        let box = field.form.querySelector('#' + CSS.escape(field.id + '-preview'));
        if (!box) {
            box = document.createElement('div');
            box.id = field.id + '-preview';
            field.insertAdjacentElement('afterend', box);
        }
        box.classList.add('form-file-preview');
        return box;
    }
    function updatePreview(field) {
        const box = previewOf(field);
        if (box.dataset.url) URL.revokeObjectURL(box.dataset.url);
        delete box.dataset.url;
        box.replaceChildren();
        const file = field.files && field.files[0];
        box.hidden = !file;
        if (!file) return;
        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            box.dataset.url = URL.createObjectURL(file);
            img.src = box.dataset.url;
            img.alt = 'Preview of ' + labelOf(field).toLowerCase();
            img.className = 'form-file-preview__image';
            box.append(img);
        } else {
            const icon = document.createElement('span');
            icon.className = 'form-file-preview__icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = file.type === 'application/pdf' ? 'PDF' : 'FILE';
            box.append(icon);
        }
        const caption = document.createElement('span');
        caption.className = 'form-file-preview__caption';
        caption.textContent = file.name + ' · ' + formatBytes(file.size);
        box.append(caption);
    }
    function initPreviews(form) {
        const fields = form.querySelectorAll('input[type="file"][data-preview]');
        fields.forEach((field) => {
            field.addEventListener('change', () => updatePreview(field));
            updatePreview(field);
        });
        form.addEventListener('reset', () => setTimeout(() => fields.forEach(updatePreview)));
    }

    function initCounters(form) {
        form.querySelectorAll('[data-char-count]').forEach((field) => {
            const counter = form.querySelector('#' + CSS.escape(field.id + '-count'));
            if (!counter) return;
            const update = () => {
                const length = [...field.value.trim().replace(/\s+/g, ' ')].length;
                counter.textContent = length + ' / ' + field.maxLength;
                counter.classList.toggle('text-danger', field.minLength > 0 && length > 0 && length < field.minLength);
            };
            field.addEventListener('input', update);
            form.addEventListener('reset', () => setTimeout(update));
            update();
        });
    }

    /** Sets up a form: checks fields as they are left and fixed, and sends it with AJAX. */
    function bind(form, options = {}) {
        if (form.dataset.appFormBound) return;
        form.dataset.appFormBound = '1';
        // The browser's own bubbles are replaced by the messages under the fields.
        form.noValidate = true;
        form.addEventListener('focusout', (event) => {
            const field = event.target;
            if (field.name && field.form === form && field.type !== 'radio' && (field.value !== '' || field.classList.contains('is-invalid'))) validateField(field);
        });
        form.addEventListener('input', (event) => event.target.classList?.contains('is-invalid') && validateField(event.target));
        form.addEventListener('change', (event) => {
            const field = event.target;
            if (field.type === 'radio' || field.type === 'file' || field.type === 'checkbox') validateField(field);
            // A file that must differ from this one is checked again.
            if (field.type === 'file') form.querySelectorAll('[data-not-same-as="' + CSS.escape(field.name) + '"]').forEach((other) => other.files.length && validateField(other));
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (form.getAttribute('aria-busy') !== 'true') submit(form, options);
        });
        initCounters(form);
        initPreviews(form);
    }

    window.AppForm = { bind, validate, submit, setError, showAlert, setBusy, showSuccess, validators };
    const init = () => document.querySelectorAll('form[data-ajax-form]').forEach((form) => bind(form));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
