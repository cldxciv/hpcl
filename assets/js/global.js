(function () {
    function initGlobalImageFallback() {
        const defaultFallback = '/logo.png';

        const bindFallback = (img) => {
            if (!img || img.dataset.fallbackBound === '1') {
                return;
            }

            img.dataset.fallbackBound = '1';
            img.addEventListener('error', function () {
                if (img.dataset.fallbackApplied === '1') {
                    return;
                }

                const fallbackSrc = img.dataset.fallbackSrc || defaultFallback;
                const currentSrc = img.getAttribute('src') || '';

                if (currentSrc === fallbackSrc) {
                    return;
                }

                img.dataset.fallbackApplied = '1';
                img.removeAttribute('srcset');
                img.src = fallbackSrc;
            });
        };

        document.querySelectorAll('img').forEach(bindFallback);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof HTMLElement)) {
                        return;
                    }

                    if (node.tagName === 'IMG') {
                        bindFallback(node);
                    }

                    node.querySelectorAll?.('img').forEach(bindFallback);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function initHeroCountdown() {
        const countdownRoots = document.querySelectorAll('[data-countdown-root]');
        if (!countdownRoots.length) {
            return;
        }

        countdownRoots.forEach((root) => {
            const countdown = root.querySelector('.hero-countdown');
            if (!countdown) {
                return;
            }

            const timerWrap = countdown.closest('[data-countdown-timer]') || countdown;
            const registrationWrap = root.querySelector('[data-countdown-registration]');
            const topline = root.querySelector('[data-countdown-topline]');
            const expireUrl = root.dataset.countdownExpireUrl || '';
            const shouldShowRegistrationOnExpire = countdown.dataset.registrationOpen !== '0';

            const targetRaw = countdown.dataset.countdownTarget;
            if (!targetRaw) {
                return;
            }

            const targetTime = new Date(targetRaw).getTime();
            if (Number.isNaN(targetTime)) {
                return;
            }

            const daysEl = countdown.querySelector('[data-countdown-unit="days"]');
            const hoursEl = countdown.querySelector('[data-countdown-unit="hours"]');
            const minutesEl = countdown.querySelector('[data-countdown-unit="minutes"]');
            const secondsEl = countdown.querySelector('[data-countdown-unit="seconds"]');

            if (!daysEl || !hoursEl || !minutesEl || !secondsEl) {
                return;
            }

            const pad = (value) => String(value).padStart(2, '0');
            const showRegistrationLink = () => {
                if (timerWrap) {
                    timerWrap.style.display = 'none';
                }

                if (topline) {
                    topline.textContent = shouldShowRegistrationOnExpire
                        ? (topline.dataset.countdownOpenText || 'Registration Is Open')
                        : (topline.dataset.countdownClosedText || '');
                }

                if (expireUrl) {
                    window.setTimeout(() => {
                        window.location.assign(expireUrl);
                    }, 250);
                    return;
                }

                if (registrationWrap && shouldShowRegistrationOnExpire) {
                    registrationWrap.style.display = '';
                } else if (registrationWrap) {
                    registrationWrap.style.display = 'none';
                }
            };

            const setValues = (days, hours, minutes, seconds) => {
                daysEl.textContent = pad(days);
                hoursEl.textContent = pad(hours);
                minutesEl.textContent = pad(minutes);
                secondsEl.textContent = pad(seconds);

                const setLabel = (unit, value) => {
                    const labelEl = countdown.querySelector(`[data-countdown-unit="${unit}"]`)?.parentElement?.querySelector('[data-countdown-label]');
                    if (!labelEl) {
                        return;
                    }

                    const singular = labelEl.dataset.labelSingular || labelEl.textContent || '';
                    const plural = labelEl.dataset.labelPlural || singular;
                    labelEl.textContent = value === 1 ? singular : plural;
                };

                setLabel('days', days);
                setLabel('hours', hours);
                setLabel('minutes', minutes);
                setLabel('seconds', seconds);
            };

            const updateCountdown = () => {
                const now = Date.now();
                const distance = targetTime - now;

                if (distance <= 0) {
                    showRegistrationLink();
                    return false;
                }

                const totalSeconds = Math.floor(distance / 1000);
                const days = Math.floor(totalSeconds / 86400);
                const hours = Math.floor((totalSeconds % 86400) / 3600);
                const minutes = Math.floor((totalSeconds % 3600) / 60);
                const seconds = totalSeconds % 60;

                setValues(days, hours, minutes, seconds);
                return true;
            };

            const shouldContinue = updateCountdown();
            if (!shouldContinue) {
                return;
            }

            const intervalId = window.setInterval(() => {
                if (!updateCountdown()) {
                    window.clearInterval(intervalId);
                }
            }, 1000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initGlobalImageFallback();
        initHeroCountdown();
    });
})();
