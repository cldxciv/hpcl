// Pro HPCL membership sales agent — chat widget client.
// Talks to /agent/chat (includes/agent.php). Keeps a per-browser session id so a visitor's
// conversation continues across page views.
(function () {
    'use strict';
    var root = document.getElementById('hpcl-agent');
    if (!root) return;

    var endpoint = root.getAttribute('data-endpoint') || '/agent/chat';
    var bubble = root.querySelector('.hpcl-agent__bubble');
    var panel = root.querySelector('.hpcl-agent__panel');
    var closeBtn = root.querySelector('.hpcl-agent__close');
    var log = root.querySelector('.hpcl-agent__log');
    var form = root.querySelector('.hpcl-agent__form');
    var input = root.querySelector('.hpcl-agent__input');
    var sendBtn = root.querySelector('.hpcl-agent__send');
    var started = false;

    function sessionId() {
        var key = 'hpcl_agent_session';
        try {
            var id = localStorage.getItem(key);
            if (!id) {
                id = (Date.now().toString(36) + Math.random().toString(36).slice(2)).slice(0, 32);
                localStorage.setItem(key, id);
            }
            return id;
        } catch (e) {
            // Private mode etc. — fall back to a per-page id.
            return (Date.now().toString(36) + Math.random().toString(36).slice(2)).slice(0, 32);
        }
    }

    function addMessage(text, who) {
        var el = document.createElement('div');
        el.className = 'hpcl-agent__msg hpcl-agent__msg--' + who;
        el.textContent = text;
        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
        return el;
    }

    function addCta(cta) {
        if (!cta || !cta.url) return;
        var a = document.createElement('a');
        a.className = 'hpcl-agent__cta';
        a.href = (root.getAttribute('data-base') || '') + cta.url;
        a.textContent = cta.label || 'Learn more';
        log.appendChild(a);
        log.scrollTop = log.scrollHeight;
    }

    function openPanel() {
        panel.hidden = false;
        bubble.setAttribute('aria-expanded', 'true');
        input.focus();
        if (!started) {
            started = true;
            addMessage("Hi! I'm the Pro HPCL membership assistant. Want more customers and visibility for your business? I can help you pick the right membership — Silver, Gold, Diamond or Platinum. What does your business do?", 'bot');
        }
    }
    function closePanel() {
        panel.hidden = true;
        bubble.setAttribute('aria-expanded', 'false');
    }

    bubble.addEventListener('click', function () {
        panel.hidden ? openPanel() : closePanel();
    });
    closeBtn.addEventListener('click', closePanel);

    // Deep link: any link to #chat (e.g. a "Chat with us" button) opens the assistant.
    function maybeOpenFromHash() { if (location.hash === '#chat') openPanel(); }
    window.addEventListener('hashchange', maybeOpenFromHash);
    maybeOpenFromHash();

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;
        addMessage(text, 'user');
        input.value = '';
        input.disabled = true;
        sendBtn.disabled = true;
        var typing = addMessage('typing…', 'bot');
        typing.classList.add('hpcl-agent__msg--typing');

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId(), message: text, page_url: location.pathname })
        }).then(function (r) { return r.json(); }).then(function (data) {
            typing.remove();
            addMessage((data && data.reply) || 'Sorry, something went wrong. Please try again.', 'bot');
            if (data && data.cta) addCta(data.cta);
        }).catch(function () {
            typing.remove();
            addMessage('Sorry, I could not reach the server. Please try again in a moment.', 'bot');
        }).finally(function () {
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        });
    });
})();
