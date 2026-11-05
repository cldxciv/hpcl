"""
LOCAL-ONLY end-to-end test of the shop checkout with Playwright (Python) and the fake Razorpay seam
(see bin/test-shop-flow.php and shopFakeRazorpay() in includes/shop-checkout.php). It never touches Razorpay or the
live site: it needs the local site on http://127.0.0.1:8080 with APP_ENV=local, and the local database.

    python bin/test-shop-flow.py [axe.min.js]

Environment: HPCL_BASE (default http://127.0.0.1:8080), HPCL_PHP_RUNNER (a script taking PHP code as its only
argument and running it with the local DB environment, e.g. the session's localphp.py).
Prints PASS/FAIL lines and writes screenshots next to this script's output folder (HPCL_SHOTS, default ./shop-test-shots).
"""
import hashlib, hmac, json, os, re, subprocess, sys, time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ.get("HPCL_BASE", "http://127.0.0.1:8080")
RUNNER = os.environ["HPCL_PHP_RUNNER"]
SHOTS = Path(os.environ.get("HPCL_SHOTS", "shop-test-shots")); SHOTS.mkdir(exist_ok=True)
AXE = open(sys.argv[1], encoding="utf-8").read() if len(sys.argv) > 1 else ""
SECRET = "localfakesecret0123456789"      # the fake key secret saved by `bin/test-shop-flow.php setup`
WEBHOOK_SECRET = "localfakewebhooksecret"
SITE = Path(__file__).resolve().parent.parent
FAILED = []


def php(code):
    r = subprocess.run([sys.executable, RUNNER, code], capture_output=True, text=True)
    return (r.stdout + r.stderr).strip()


def helper(cmd):
    return php('$argv=["x","%s"]; require "bin/test-shop-flow.php";' % cmd)


def sign(order_id, payment_id):
    return hmac.new(SECRET.encode(), f"{order_id}|{payment_id}".encode(), hashlib.sha256).hexdigest()


def check(what, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + what + ("" if ok or not detail else f"  [{detail}]"), flush=True)
    if not ok: FAILED.append(what)


def row(sql):
    out = php('require "includes/env.php"; require "includes/site.php"; echo json_encode(query(%s));' % json.dumps(sql))
    try: return json.loads(out)
    except Exception: return [{"error": out}]


FAKE_RAZORPAY = """
window.Razorpay = function (o) { this.o = o; this.on = function () {}; this.open = async function () {
  const pid = 'pay_FAKE' + Math.random().toString(36).slice(2, 12);
  const sig = await window.__sign(o.order_id, pid);
  o.handler({ razorpay_order_id: o.order_id, razorpay_payment_id: pid, razorpay_signature: sig });
}; };
"""
GOOD = {"name": "Asha Verma", "email": "asha@shoptest.local", "phone": "98765 43210", "address_line1": "12 Mall Road", "address_line2": "Near the library",
        "city": "Shimla", "state": "Himachal Pradesh", "pincode": "171001", "notes": "Print AVERMA 7 on the back"}


def axe(page, label):
    if not AXE: return
    page.evaluate(AXE)
    v = page.evaluate("axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag21aa']}).then(r=>r.violations.map(v=>v.id+':'+v.nodes.length+' '+v.nodes[0].target.join(' ').slice(0,70)))")
    check(f"axe 0 violations: {label}", not v, "; ".join(v))


def add_to_cart(page, slug, size=None, qty=1):
    page.goto(f"{BASE}/shop/{slug}", wait_until="load")
    if size: page.check(f'input[name="size"][value="{size}"]')
    page.fill("#shop-qty", str(qty))
    page.click("[data-shop-submit]"); page.wait_for_timeout(900)


def fill_form(page, data):
    for k, v in data.items():
        if k == "state": page.select_option("#state", v)
        else: page.fill(f"#{k}", v)


def prepare(ctx):
    page = ctx.new_page()
    page.route("https://checkout.razorpay.com/**", lambda r: r.fulfill(status=200, body="", content_type="application/javascript"))
    page.expose_function("__sign", sign)
    page.add_init_script(FAKE_RAZORPAY)
    return page


print(helper("cleanup")); print(helper("setup"))
with sync_playwright() as p:
    br = p.chromium.launch()
    # ---- A. guest, happy path, desktop
    ctx = br.new_context(viewport={"width": 1440, "height": 900}); page = prepare(ctx)
    add_to_cart(page, "zzco-jersey", "M", 2); add_to_cart(page, "zzco-cap", None, 1)
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    check("checkout shows 2 lines and the total", page.locator(".sco-summary .sco-line").count() == 2 and "₹" in page.inner_text(".sco-totals"), page.inner_text(".sco-totals"))
    page.click("#sco-pay"); page.wait_for_timeout(1200)
    errs = page.locator(".invalid-feedback:visible").count()
    check("empty form shows inline errors for every required field", errs >= 7, str(errs))
    check("first invalid field gets focus and aria-invalid", page.evaluate("document.activeElement.getAttribute('aria-invalid')") == "true")
    axe(page, "checkout with errors"); page.screenshot(path=str(SHOTS / "checkout-errors-1440.png"), full_page=True)
    fill_form(page, GOOD | {"phone": "12345"}); page.click("#sco-pay"); page.wait_for_timeout(1000)
    check("bad phone number is rejected", "10-digit" in page.inner_text("#phone-error"))
    fill_form(page, GOOD); axe(page, "checkout filled"); page.screenshot(path=str(SHOTS / "checkout-filled-1440.png"), full_page=True)
    before = row("SELECT COUNT(*) n FROM shop_orders")[0]["n"]
    page.click("#sco-pay"); page.wait_for_url("**/shop/order/*", timeout=15000)
    check("paying lands on the order page", "/shop/order/" in page.url)
    token = page.url.rstrip("/").split("/")[-1]
    body = page.inner_text("main")
    check("order page shows paid, items, address, note", "Paid" in body and "TEST Pro HPCL Jersey" in body and "12 Mall Road" in body and "AVERMA" in body, body[:200])
    check("order page is noindex", 'content="noindex' in page.content())
    order = row(f"SELECT * FROM shop_orders WHERE token = '{token}'")[0]
    items = row(f"SELECT * FROM shop_order_items WHERE order_id = {order['id']}")
    check("order is paid with a payment id and the right total (1897 + 60 shipping)", order["status"] == "paid" and order["razorpay_payment_id"].startswith("pay_FAKE") and float(order["total"]) == 1957.0, json.dumps(order)[:200])
    check("order number format", re.match(r"^HPCL-1\d{4,}$", order["order_number"]) is not None, order["order_number"])
    st = row("SELECT (SELECT stock FROM shop_product_variants WHERE product_id = p.id AND size = 'M') AS m, (SELECT stock FROM shop_products WHERE slug = 'zzco-cap') AS cap FROM shop_products p WHERE slug = 'zzco-jersey'")[0]
    check("stock reduced (M 5->3, cap 3->2)", (int(st["m"]), int(st["cap"])) == (3, 2), json.dumps(st))
    check("2 items copied with prices", len(items) == 2 and {float(i["unit_price"]) for i in items} == {799.0, 299.0})
    page.goto(f"{BASE}/shop/cart", wait_until="load")
    check("cart is empty after payment", "empty" in page.inner_text("main").lower(), page.inner_text("main")[:120])
    page.goto(f"{BASE}/shop/order/{token}", wait_until="load"); axe(page, "order page"); page.screenshot(path=str(SHOTS / "order-1440.png"), full_page=True)
    ctx.close()

    # ---- B/C. tampering and idempotent verify through the API
    ctx = br.new_context(viewport={"width": 1440, "height": 900}); page = prepare(ctx)
    add_to_cart(page, "zzco-jersey", "L", 1)
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    csrf = page.evaluate("document.querySelector('input[name=csrf_token]').value")
    r = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": csrf, "amount": "1", "total": "1", "subtotal": "1", "unit_price": "1", "shipping": "0", "price": "1"})
    d = r.json()
    check("place answers ok with Razorpay options", r.status == 200 and d.get("ok") and d["payment"]["amount"] > 100 * 100, json.dumps(d)[:200])
    o = row(f"SELECT * FROM shop_orders WHERE token = '{d['token']}'")[0]
    check("client-sent amounts are ignored (subtotal 799 from the database, total = subtotal + shipping, Razorpay amount = total in paise)",
          float(o["subtotal"]) == 799.0 and float(o["total"]) == float(o["subtotal"]) + float(o["shipping"]) and d["payment"]["amount"] == round(float(o["total"]) * 100), f"subtotal={o['subtotal']} total={o['total']} amount={d['payment']['amount']}")
    r2 = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": "bad"})
    check("wrong CSRF token is refused", r2.status == 403)
    r3 = page.request.post(f"{BASE}/shop/checkout/verify", headers={"Accept": "application/json"}, form={"csrf_token": csrf, "razorpay_order_id": d["payment"]["order_id"], "razorpay_payment_id": "pay_FAKEFORGED1", "razorpay_signature": "0" * 64})
    check("forged signature is refused", r3.status == 422 and row(f"SELECT status FROM shop_orders WHERE id = {o['id']}")[0]["status"] == "pending")
    pid = "pay_FAKEIDEMP001"
    good = {"csrf_token": csrf, "razorpay_order_id": d["payment"]["order_id"], "razorpay_payment_id": pid, "razorpay_signature": sign(d["payment"]["order_id"], pid)}
    a = page.request.post(f"{BASE}/shop/checkout/verify", headers={"Accept": "application/json"}, form=good).json()
    b = page.request.post(f"{BASE}/shop/checkout/verify", headers={"Accept": "application/json"}, form=good).json()
    check("verify twice is ok both times", a.get("ok") and b.get("ok"), json.dumps([a, b]))
    check("stock taken once for the second order (L 2->1)", int(row("SELECT stock FROM shop_product_variants WHERE size = 'L' AND product_id = (SELECT id FROM shop_products WHERE slug = 'zzco-jersey')")[0]["stock"]) == 1)
    check("one paid event only", len(row(f"SELECT id FROM shop_order_events WHERE order_id = {o['id']} AND status = 'paid' AND note LIKE 'Payment%'")) == 1)
    ctx.close()

    # ---- D. webhook
    ctx = br.new_context(); page = prepare(ctx)
    add_to_cart(page, "zzco-cap", None, 1)
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    csrf = page.evaluate("document.querySelector('input[name=csrf_token]').value")
    d = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": csrf}).json()
    payload = json.dumps({"event": "payment.captured", "payload": {"payment": {"entity": {"id": "pay_FAKEWEBHK01", "order_id": d["payment"]["order_id"], "amount": d["payment"]["amount"]}}}})
    sigh = hmac.new(WEBHOOK_SECRET.encode(), payload.encode(), hashlib.sha256).hexdigest()
    bad = page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": "00" * 32, "Content-Type": "application/json"})
    check("webhook with a bad signature is refused (400)", bad.status == 400)
    ok = page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": sigh, "Content-Type": "application/json"})
    check("webhook with the right signature is accepted", ok.status == 200)
    check("the webhook marked the order paid", row(f"SELECT status, razorpay_payment_id FROM shop_orders WHERE token = '{d['token']}'")[0]["status"] == "paid")
    cap_after = int(row("SELECT stock FROM shop_products WHERE slug = 'zzco-cap'")[0]["stock"])
    page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": sigh, "Content-Type": "application/json"})
    check("a repeated webhook changes nothing", int(row("SELECT stock FROM shop_products WHERE slug = 'zzco-cap'")[0]["stock"]) == cap_after == 1, str(cap_after))
    wrong = json.dumps({"event": "payment.captured", "payload": {"payment": {"entity": {"id": "pay_FAKEWEBHK02", "order_id": d["payment"]["order_id"], "amount": 5}}}})
    ctx.close()

    # ---- E. out-of-stock race
    ctx = br.new_context(); page = prepare(ctx)
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE shop_products SET stock = 3 WHERE slug = \'zzco-cap\'");')
    add_to_cart(page, "zzco-cap", None, 3)
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE shop_products SET stock = 1 WHERE slug = \'zzco-cap\'");')
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    csrf = page.evaluate("document.querySelector('input[name=csrf_token]').value")
    r = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": csrf})
    check("order is refused when the stock fell below the cart (or the cart is corrected)", r.status in (200, 422), str(r.status))
    # stock gone between place and verify
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE shop_products SET stock = 3 WHERE slug = \'zzco-cap\'");')
    ctx.close(); ctx = br.new_context(); page = prepare(ctx)
    add_to_cart(page, "zzco-cap", None, 2)
    page.goto(f"{BASE}/shop/checkout", wait_until="load"); csrf = page.evaluate("document.querySelector('input[name=csrf_token]').value")
    d = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": csrf}).json()
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE shop_products SET stock = 0 WHERE slug = \'zzco-cap\'");')
    pid = "pay_FAKERACE0001"
    v = page.request.post(f"{BASE}/shop/checkout/verify", headers={"Accept": "application/json"}, form={"csrf_token": csrf, "razorpay_order_id": d["payment"]["order_id"], "razorpay_payment_id": pid, "razorpay_signature": sign(d["payment"]["order_id"], pid)}).json()
    ev = row(f"SELECT note FROM shop_order_events e JOIN shop_orders o ON o.id = e.order_id WHERE o.token = '{d['token']}'")
    check("sold out between place and pay: still marked paid, flagged for the team", v.get("ok") and any("NEEDS ATTENTION" in (e["note"] or "") for e in ev), json.dumps(ev)[:200])
    check("stock never goes negative", int(row("SELECT stock FROM shop_products WHERE slug = 'zzco-cap'")[0]["stock"]) == 0)
    ctx.close()

    # ---- F. Razorpay not set up
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE site_settings SET value = \'0\' WHERE name = \'shop_fake_razorpay\'"); db()->exec("UPDATE site_settings SET value = \'0\' WHERE name = \'razorpay_enabled\'");')
    php('require "includes/env.php"; require "includes/site.php"; db()->exec("UPDATE shop_products SET stock = 3 WHERE slug = \'zzco-cap\'");')
    ctx = br.new_context(viewport={"width": 390, "height": 800}, is_mobile=True, has_touch=True); page = prepare(ctx)
    add_to_cart(page, "zzco-cap", None, 1)
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    check("with Razorpay off, Pay is disabled and a note explains", page.is_disabled("#sco-pay") and "being set up" in page.inner_text(".sco-summary"))
    csrf = page.evaluate("document.querySelector('input[name=csrf_token]').value")
    r = page.request.post(f"{BASE}/shop/checkout/place", headers={"Accept": "application/json"}, form=GOOD | {"csrf_token": csrf})
    check("posting anyway answers 503, no order created", r.status == 503 and not row("SELECT id FROM shop_orders WHERE status = 'pending' AND email = 'asha@shoptest.local' AND created_at > NOW() - INTERVAL 5 SECOND"))
    page.screenshot(path=str(SHOTS / "checkout-notready-390.png"), full_page=True)
    ctx.close()
    helper("setup")

    # ---- G. signed-in customer on mobile, orders in the account
    ctx = br.new_context(viewport={"width": 390, "height": 800}, is_mobile=True, has_touch=True); page = prepare(ctx)
    stamp = str(int(time.time()))[-6:]
    email = f"cust{stamp}@shoptest.local"
    add_to_cart(page, "zzco-jersey", "M", 1)          # shopping as a guest first: signing up must keep the cart
    page.goto(f"{BASE}/signup", wait_until="load")
    page.fill("#name", "Rohit Tester"); page.fill("#email", email); page.fill("#password", "Passw0rd!test1"); page.fill("#password_confirm", "Passw0rd!test1"); page.check("#terms")
    page.click("form button[type=submit]"); page.wait_for_timeout(1800)
    # A new account is first asked for a favourite team: mark that as done so /account shows the account home.
    php('require "includes/env.php"; require "includes/site.php"; db()->prepare("UPDATE users SET team_prompted_at = NOW() WHERE email = ?")->execute(["%s"]);' % email)
    page.goto(f"{BASE}/shop/cart", wait_until="load")
    check("the cart survived signing in", "TEST Pro HPCL Jersey" in page.inner_text("main"), page.inner_text("main")[:120])
    page.goto(f"{BASE}/shop/checkout", wait_until="load")
    check("signed-in customer's name and email are prefilled", page.input_value("#name") == "Rohit Tester" and page.input_value("#email") == email)
    fill_form(page, GOOD | {"name": "Rohit Tester", "email": email})
    check("no horizontal overflow at 390px", page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") == 0)
    page.screenshot(path=str(SHOTS / "checkout-390.png"), full_page=True)
    page.click("#sco-pay"); page.wait_for_url("**/shop/order/*", timeout=15000)
    page.screenshot(path=str(SHOTS / "order-390.png"), full_page=True)
    check("no horizontal overflow on the order page at 390px", page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") == 0)
    page.goto(f"{BASE}/account", wait_until="load")
    check("My orders link on the account home", page.locator("a[href$='/account/orders']").count() >= 1)
    page.goto(f"{BASE}/account/orders", wait_until="load"); axe(page, "account orders list"); page.screenshot(path=str(SHOTS / "account-orders-390.png"), full_page=True)
    check("the order is listed", "HPCL-" in page.inner_text("main"))
    link = page.locator("a.sco-orders__link").first.get_attribute("href"); page.goto(BASE + link if link.startswith("/") else link, wait_until="load")
    check("order detail opens in the account", "Items" in page.inner_text("main")); axe(page, "account order detail")
    other = row("SELECT order_number FROM shop_orders WHERE email = 'asha@shoptest.local' ORDER BY id LIMIT 1")[0]["order_number"]
    resp = page.goto(f"{BASE}/account/orders?order={other}", wait_until="load")
    check("another customer's order is a 404", resp.status == 404, str(resp.status))
    ctx.close()
    br.close()

# ---- emails
mails = sorted((SITE / "private" / "mail-html").glob("*.html"))
print(f"{len(mails)} HTML emails in private/mail-html")
FAILED and print("FAILED:", FAILED)
sys.exit(1 if FAILED else 0)
