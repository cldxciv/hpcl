// Pro HPCL shop: product gallery, size/colour options with stock, quantity steppers and add to cart.
// Everything also works without this script (the forms post normally); it only makes it smoother.
(function () {
    'use strict';

    function setCartCount(count) {
        document.querySelectorAll('[data-cart-count]').forEach(function (badge) {
            badge.textContent = String(count);
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        });
        document.querySelectorAll('[data-shop-cart-link]').forEach(function (link) {
            link.setAttribute('aria-label', 'Cart, ' + count + ' item' + (count === 1 ? '' : 's'));
        });
    }

    // Gallery: the thumbnails swap the large picture.
    var gallery = document.querySelector('[data-shop-gallery]');
    if (gallery) {
        var main = gallery.querySelector('[data-shop-main]');
        gallery.querySelectorAll('[data-shop-thumb]').forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                main.src = thumb.getAttribute('data-shop-thumb');
                main.alt = thumb.getAttribute('data-alt') || main.alt;
                gallery.querySelectorAll('[data-shop-thumb]').forEach(function (other) { other.removeAttribute('aria-current'); });
                thumb.setAttribute('aria-current', 'true');
            });
        });
    }

    // Quantity steppers. On the cart page a change saves itself shortly after.
    document.querySelectorAll('[data-qty-step]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentNode.querySelector('[data-shop-qty]');
            var min = parseInt(input.min || '1', 10), max = parseInt(input.max || '99', 10);
            var next = Math.max(min, Math.min(max, (parseInt(input.value, 10) || min) + parseInt(button.getAttribute('data-qty-step'), 10)));
            if (String(next) === input.value) return;
            input.value = next;
            var form = input.form;
            if (form && form.hasAttribute('data-shop-cart-form')) {
                clearTimeout(form._saveTimer);
                form._saveTimer = setTimeout(function () { form.requestSubmit(); }, 500);
            }
        });
    });

    // Product form: options, stock and add to cart.
    var form = document.querySelector('[data-shop-add]');
    if (!form) return;
    var variantsEl = document.getElementById('shop-variants');
    var variants = [];
    try { variants = JSON.parse(variantsEl ? variantsEl.textContent : '[]') || []; } catch (e) { variants = []; }
    var low = parseInt(form.getAttribute('data-low') || '5', 10), maxLine = parseInt(form.getAttribute('data-max') || '20', 10);
    var simpleStock = parseInt(form.getAttribute('data-stock') || '0', 10);
    var stockBox = document.querySelector('[data-shop-stock]'), stockText = document.querySelector('[data-shop-stock-text]');
    var submit = form.querySelector('[data-shop-submit]'), qty = form.querySelector('[data-shop-qty]'), message = form.querySelector('[data-shop-msg]');
    var colourInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="colour_id"]'));
    var sizeInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="size"]'));

    function checked(inputs) { var c = inputs.filter(function (i) { return i.checked; })[0]; return c ? c.value : null; }
    function stockFor(colour, size) {
        var total = 0;
        variants.forEach(function (v) {
            if ((colour === null || String(v.colour) === colour) && (size === null || v.size === size)) total += v.stock;
        });
        return total;
    }
    function show(stock) {
        var out = stock <= 0, few = !out && stock <= low;
        if (stockText) stockText.textContent = out ? 'Out of stock' : (few ? 'Only ' + stock + ' left' : 'In stock');
        if (stockBox) stockBox.className = 'shop-stock shop-stock--' + (out ? 'out' : (few ? 'low' : 'ok'));
        var cap = Math.max(1, Math.min(stock, maxLine));
        qty.max = String(cap);
        if (parseInt(qty.value, 10) > cap) qty.value = String(cap);
        submit.disabled = out;
        submit.textContent = out ? 'Out of stock' : 'Add to cart';
    }
    function refresh() {
        if (!variants.length) { show(simpleStock); return; }
        var colour = colourInputs.length ? checked(colourInputs) : '0';
        // A colour with nothing left can't be picked, nor a size with nothing left in the chosen colour.
        colourInputs.forEach(function (i) { i.disabled = stockFor(i.value, null) <= 0; });
        sizeInputs.forEach(function (i) { i.disabled = stockFor(colour, i.value) <= 0; });
        if (sizeInputs.length && !checked(sizeInputs)) {
            var first = sizeInputs.filter(function (i) { return !i.disabled; })[0];
            if (first) first.checked = true;
        }
        var name = form.querySelector('[data-shop-colour-name]');
        var pickedColour = colourInputs.filter(function (i) { return i.checked; })[0];
        if (name && pickedColour) name.textContent = pickedColour.getAttribute('data-name') || '';
        show(stockFor(colour, sizeInputs.length ? checked(sizeInputs) : ''));
    }
    form.addEventListener('change', function (event) {
        if (event.target.name === 'colour_id' || event.target.name === 'size') refresh();
    });
    refresh();

    form.addEventListener('submit', function (event) {
        if (!window.fetch || !window.FormData) return;
        event.preventDefault();
        submit.disabled = true;
        message.className = 'shop-buy__msg';
        message.textContent = 'Adding...';
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                message.className = 'shop-buy__msg ' + (data.ok ? 'is-ok' : 'is-error');
                message.textContent = data.message || '';
                if (data.ok) {
                    var link = document.createElement('a');
                    link.href = data.cart_url || '/shop/cart';
                    link.textContent = 'View cart';
                    message.appendChild(document.createTextNode(' '));
                    message.appendChild(link);
                }
                if (typeof data.count === 'number') setCartCount(data.count);
            })
            .catch(function () { form.submit(); })
            .then(function () { refresh(); });
    });
})();
