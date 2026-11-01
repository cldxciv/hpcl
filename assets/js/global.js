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

    // Scrollable table wrappers must be reachable (and labelled) for keyboard users.
    function initScrollableRegions() {
        const wrappers = document.querySelectorAll('.table-responsive, [class*="-table-wrap"]');
        const label = (el) => {
            let node = el;
            while (node && node !== document.body) {
                let prev = node.previousElementSibling;
                while (prev) {
                    if (/^H[1-6]$/.test(prev.tagName) && prev.textContent.trim()) {
                        return prev.textContent.trim().replace(/\s+/g, ' ');
                    }
                    const inner = prev.querySelector && prev.querySelector('h1,h2,h3,h4,h5,h6');
                    if (inner && inner.textContent.trim()) {
                        return inner.textContent.trim().replace(/\s+/g, ' ');
                    }
                    prev = prev.previousElementSibling;
                }
                node = node.parentElement;
            }
            return 'Scrollable table';
        };

        const update = () => {
            wrappers.forEach((el) => {
                if (el.scrollWidth > el.clientWidth + 1) {
                    if (!el.hasAttribute('tabindex')) {
                        el.setAttribute('tabindex', '0');
                        el.dataset.scrollRegion = '1';
                    }
                    if (!el.hasAttribute('role') && !el.hasAttribute('aria-label')) {
                        el.setAttribute('role', 'region');
                        el.setAttribute('aria-label', label(el));
                    }
                } else if (el.dataset.scrollRegion === '1') {
                    el.removeAttribute('tabindex');
                    el.removeAttribute('role');
                    el.removeAttribute('aria-label');
                    delete el.dataset.scrollRegion;
                }
            });
        };

        update();
        window.addEventListener('resize', update);
        window.addEventListener('load', update);
    }

    // Hides the fade cue of .chip-scroll rows once they are scrolled to the end (or fit).
    function initChipScroll() {
        document.querySelectorAll('.chip-scroll').forEach((row) => {
            const update = () => {
                const max = row.scrollWidth - row.clientWidth;
                row.classList.toggle('is-not-scrollable', max <= 1);
                row.classList.toggle('is-scrolled-end', max > 1 && row.scrollLeft >= max - 2);
            };
            row.addEventListener('scroll', update, { passive: true });
            window.addEventListener('resize', update);
            update();
        });
    }

    // Esc closes the open mobile menu and hands focus back to its toggle button.
    function initMenuEscape() {
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const toggler = document.querySelector('.navbar-toggler[aria-expanded="true"]');
            if (!toggler) return;
            toggler.click();
            toggler.focus();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initGlobalImageFallback();
        initHeroCountdown();
        initScrollableRegions();
        initChipScroll();
        initMenuEscape();
    });
})();
