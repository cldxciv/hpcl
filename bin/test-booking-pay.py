"""
LOCAL-ONLY end-to-end test of the ground-booking payment and the account bookings with Playwright (Python) and the
fake Razorpay seam (see bin/test-booking-pay.php and bookingFakeRazorpay() in includes/booking-pay.php). It never
touches Razorpay or the live site: it needs the local site on http://127.0.0.1:8080 with APP_ENV=local, and the local database.

    python bin/test-booking-pay.py [axe.min.js]

Environment: HPCL_BASE (default http://127.0.0.1:8080), HPCL_PHP_RUNNER (a script taking PHP code as its only
argument and running it with the local DB environment, e.g. the session's localphp.py), HPCL_SHOTS (screenshot folder).
Prints PASS/FAIL lines.
"""
import hashlib, hmac, json, os, subprocess, sys
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ.get("HPCL_BASE", "http://127.0.0.1:8080")
RUNNER = os.environ["HPCL_PHP_RUNNER"]
SHOTS = Path(os.environ.get("HPCL_SHOTS", "booking-test-shots")); SHOTS.mkdir(exist_ok=True)
AXE = open(sys.argv[1], encoding="utf-8").read() if len(sys.argv) > 1 else ""
SECRET = "localfakesecret0123456789"       # the fake key secret saved by `bin/test-booking-pay.php setup`
WEBHOOK_SECRET = "localfakewebhooksecret"
FAILED = []


def php(code):
    r = subprocess.run([sys.executable, RUNNER, code], capture_output=True, text=True)
    return (r.stdout + r.stderr).strip()


def helper(*args):
    return php('$argv=["x",%s]; require "bin/test-booking-pay.php";' % ",".join(json.dumps(str(a)) for a in args))


import random
_DAYS = random.sample(range(200, 1000, 4), 150)   # far-off dates 4 days apart, so no other (local) booking and no multi-day test booking collides


def make(status, advance=3750, due=24, email="tester@bookingtest.local", user="", days=None, extra=None):
    """A test booking on its own random far-off date, so no two compete for the same slot."""
    if days is None:
        days = _DAYS.pop()
    return json.loads(helper("make", status, advance, due, email, user, days, json.dumps(extra) if extra else ""))


def get(booking_id):
    return json.loads(helper("get", booking_id))


def sign(order_id, payment_id):
    return hmac.new(SECRET.encode(), f"{order_id}|{payment_id}".encode(), hashlib.sha256).hexdigest()


def check(what, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + what + ("" if ok or not detail else f"  [{detail}]"), flush=True)
    if not ok: FAILED.append(what)


def axe(page, label):
    if not AXE: return
    page.evaluate(AXE)
    v = page.evaluate("axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag21aa']}).then(r=>r.violations.map(v=>v.id+':'+v.nodes.length+' '+v.nodes[0].target.join(' ').slice(0,70)))")
    check(f"axe 0 violations: {label}", not v, "; ".join(v))


FAKE_RAZORPAY = """
window.Razorpay = function (o) { this.o = o; this.on = function () {}; this.open = async function () {
  const pid = 'pay_FAKE' + Math.random().toString(36).slice(2, 12);
  const sig = await window.__sign(o.order_id, pid);
  o.handler({ razorpay_order_id: o.order_id, razorpay_payment_id: pid, razorpay_signature: sig });
}; };
"""


def csrf_of(page):
    return page.evaluate("document.querySelector('input[name=csrf_token]').value")


def run():
    print(helper("setup"))
    with sync_playwright() as p:
        br = p.chromium.launch()
        ctx = br.new_context(viewport={"width": 1440, "height": 900})
        page = ctx.new_page()
        page.expose_function("__sign", sign)
        page.add_init_script(FAKE_RAZORPAY)
        page.route("https://checkout.razorpay.com/**", lambda r: r.fulfill(status=200, body="", content_type="application/javascript"))  # keep the real Checkout out of the way

        # --- the pay page ------------------------------------------------------------------------------
        a = make("approved")
        r = page.goto(f"{BASE}/booking/{a['token']}/pay", wait_until="load")
        check("pay page opens", r.status == 200)
        check("pay page is noindex", "noindex" in (r.headers.get("x-robots-tag", "") + page.content()))
        text = page.inner_text("main") if page.query_selector("main") else page.inner_text("body")
        check("pay page shows the reference, the advance and the balance", a["reference"] in text and "3,750" in text and "Advance to pay now" in text and "Balance" in text, text[:200])
        check("pay page has a Pay button", page.query_selector("#bkp-pay") is not None)
        axe(page, "pay page (approved)")
        page.screenshot(path=str(SHOTS / "pay-approved-1440.png"), full_page=True)
        check("an unknown token is a 404", page.goto(f"{BASE}/booking/{'0' * 32}/pay").status == 404)
        page.goto(f"{BASE}/booking/{a['token']}/pay", wait_until="load")

        # --- paying through the (fake) Razorpay Checkout --------------------------------------------------
        page.click("#bkp-pay")
        page.wait_for_function("document.querySelector('#bkp-pay') === null && /confirmed/i.test(document.body.innerText)", timeout=20000)
        page.wait_for_load_state("load")
        state = get(a["id"])
        check("paying confirms the booking", state["row"]["status"] == "confirmed" and state["row"]["payment_method"] == "razorpay" and state["row"]["razorpay_payment_id"].startswith("pay_FAKE"), json.dumps(state))
        body = page.inner_text("body")
        check("the page now shows the confirmed receipt", "confirmed" in body.lower() and "Advance paid" in body and "Pay " not in page.inner_text(".sco-actions") and page.query_selector("#bkp-pay") is None)
        axe(page, "pay page (confirmed receipt)")
        page.screenshot(path=str(SHOTS / "pay-confirmed-1440.png"), full_page=True)

        # --- the API: CSRF, repeats, tampering ----------------------------------------------------------------
        b = make("approved")
        page.goto(f"{BASE}/booking/{b['token']}/pay", wait_until="load")
        token = csrf_of(page)
        no_csrf = page.request.post(f"{BASE}/booking/{b['token']}/pay/create", headers={"Accept": "application/json"}, form={})
        check("create without the CSRF token is refused (403)", no_csrf.status == 403)
        create = page.request.post(f"{BASE}/booking/{b['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": token, "amount": "1"})
        d = create.json()
        check("create returns Checkout options for the advance (client amounts ignored)", create.status == 200 and d["ok"] and d["payment"]["amount"] == 375000 and d["payment"]["order_id"].startswith("order_FAKE"), json.dumps(d)[:200])
        order = d["payment"]["order_id"]
        forged = page.request.post(f"{BASE}/booking/{b['token']}/pay/verify", headers={"Accept": "application/json"}, form={"csrf_token": token, "razorpay_order_id": order, "razorpay_payment_id": "pay_FAKEFORGED1", "razorpay_signature": "0" * 64})
        check("a forged signature is refused", forged.status == 422 and get(b["id"])["row"]["status"] == "approved")
        pid = "pay_FAKEGOOD0001"
        good = {"csrf_token": token, "razorpay_order_id": order, "razorpay_payment_id": pid, "razorpay_signature": sign(order, pid)}
        v1 = page.request.post(f"{BASE}/booking/{b['token']}/pay/verify", headers={"Accept": "application/json"}, form=good)
        v2 = page.request.post(f"{BASE}/booking/{b['token']}/pay/verify", headers={"Accept": "application/json"}, form=good)
        check("verify twice is fine both times", v1.status == 200 and v2.status == 200 and v1.json()["ok"] and v2.json()["ok"])
        check("confirmed once", get(b["id"])["row"]["status"] == "confirmed")
        other = make("approved")
        page.goto(f"{BASE}/booking/{other['token']}/pay", wait_until="load")
        t2 = csrf_of(page)
        cross = page.request.post(f"{BASE}/booking/{other['token']}/pay/verify", headers={"Accept": "application/json"},
                                  form={"csrf_token": t2, "razorpay_order_id": order, "razorpay_payment_id": pid, "razorpay_signature": sign(order, pid)})
        check("another booking cannot use this booking's payment", cross.status == 422 and get(other["id"])["row"]["status"] == "approved")

        # --- states that must not take payment -------------------------------------------------------------------
        for status, words in (("requested", "waiting for approval"), ("rejected", "could not be approved"), ("cancelled", "was cancelled"), ("expired", "time to pay has passed")):
            s = make(status)
            page.goto(f"{BASE}/booking/{s['token']}/pay", wait_until="load")
            check(f"{status}: message shown and no Pay button", words in page.inner_text("body").lower() and page.query_selector("#bkp-pay") is None)
            if status in ("requested", "expired"): axe(page, f"pay page ({status})")
            tk = csrf_of(page) if page.query_selector("input[name=csrf_token]") else token
            r = page.request.post(f"{BASE}/booking/{s['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": tk})
            check(f"{status}: create is refused", r.status in (403, 409, 410), str(r.status))
        late = make("approved", due=-1)
        page.goto(f"{BASE}/booking/{late['token']}/pay", wait_until="load")
        check("an approved booking past its deadline shows as expired", "time to pay has passed" in page.inner_text("body").lower() and get(late["id"])["row"]["status"] == "expired")

        # --- webhook ------------------------------------------------------------------------------------------
        w = make("approved")
        page.goto(f"{BASE}/booking/{w['token']}/pay", wait_until="load")
        wt = csrf_of(page)
        wd = page.request.post(f"{BASE}/booking/{w['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": wt}).json()
        word = wd["payment"]["order_id"]
        payload = json.dumps({"event": "payment.captured", "payload": {"payment": {"entity": {"id": "pay_WEBHOOK0001", "order_id": word, "amount": 375000}}}})
        bad = page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": "0" * 64, "Content-Type": "application/json"})
        check("webhook with a bad signature is refused (400)", bad.status == 400)
        ws = hmac.new(WEBHOOK_SECRET.encode(), payload.encode(), hashlib.sha256).hexdigest()
        ok = page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": ws, "Content-Type": "application/json"})
        check("webhook with the right signature is accepted", ok.status == 200)
        check("the webhook confirmed the booking", get(w["id"])["row"]["status"] == "confirmed" and get(w["id"])["row"]["razorpay_payment_id"] == "pay_WEBHOOK0001")
        again = page.request.post(f"{BASE}/razorpay/webhook", data=payload, headers={"X-Razorpay-Signature": ws, "Content-Type": "application/json"})
        check("a repeated webhook changes nothing", again.status == 200 and get(w["id"])["row"]["razorpay_payment_id"] == "pay_WEBHOOK0001")
        wrong = make("approved")
        page.goto(f"{BASE}/booking/{wrong['token']}/pay", wait_until="load")
        wo = page.request.post(f"{BASE}/booking/{wrong['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": csrf_of(page)}).json()["payment"]["order_id"]
        wp = json.dumps({"event": "payment.captured", "payload": {"payment": {"entity": {"id": "pay_WEBHOOK0002", "order_id": wo, "amount": 100}}}})
        page.request.post(f"{BASE}/razorpay/webhook", data=wp, headers={"X-Razorpay-Signature": hmac.new(WEBHOOK_SECRET.encode(), wp.encode(), hashlib.sha256).hexdigest(), "Content-Type": "application/json"})
        st = get(wrong["id"])
        check("a webhook for the wrong amount is flagged and does not confirm", st["row"]["status"] == "approved" and any(e["status"] == "payment_issue" for e in st["events"]))


        # --- the full breakdown: days, meals skipped, accommodation, the team's changes -------------------------------------
        rich = make("approved", extra={"price": True, "days": 2, "teams_paying": 2, "want_tea": 0, "stay_needed": 1, "stay_people": 6, "stay_nights": 1, "stay_amount": 3000})
        page.goto(f"{BASE}/booking/{rich['token']}/pay", wait_until="load")
        body = page.inner_text("body")
        due_now = page.evaluate("document.querySelector('.sco-totals__grand + dd').innerText")
        check("breakdown shows the dates, meals, accommodation, total, advance and balance",
              " to " in body and "2 days" in body and "No tea" in body and "Accommodation" in body and "Total price" in body and "Advance to pay now" in body and "Balance" in body
              and "teams" in body, body[body.find("Your booking"):body.find("Your booking") + 700])
        check("the Pay button shows the same advance as the breakdown", due_now.strip() in page.inner_text("#bkp-pay"), due_now)
        axe(page, "pay page (multi-day, meals skipped, accommodation)")
        page.screenshot(path=str(SHOTS / "pay-breakdown-1440.png"), full_page=True)
        page.click("#bkp-pay")
        page.wait_for_function("document.querySelector('#bkp-pay') === null && /confirmed/i.test(document.body.innerText)", timeout=20000)
        check("paying the multi-day booking confirms it and the receipt keeps the breakdown", get(rich["id"])["row"]["status"] == "confirmed" and "Advance paid" in page.inner_text("body") and "Accommodation" in page.inner_text("body"))
        axe(page, "receipt (multi-day, meals skipped, accommodation)")

        snap = {"booking_date": "2030-01-01", "days": 1, "slot": "morning", "format": "single", "teams_paying": 1, "players_per_team": 11, "opponent": "", "organisation": "Test XI",
                "want_lunch": 1, "want_tea": 1, "stay_needed": 0, "stay_people": None, "stay_nights": None, "stay_notes": ""}
        changed = make("approved", extra={"price": True, "days": 2, "admin_modified": 1, "request_snapshot": json.dumps(snap)})
        page.goto(f"{BASE}/booking/{changed['token']}/pay", wait_until="load")
        check("an adjusted request shows what the team changed", "We adjusted your request" in page.inner_text("body"))
        axe(page, "pay page (adjusted request)")

        mrich = br.new_context(viewport={"width": 390, "height": 800}, is_mobile=True, has_touch=True)
        mrp = mrich.new_page(); mrp.goto(f"{BASE}/booking/{rich['token']}/pay", wait_until="load")
        check("no horizontal overflow at 390px (breakdown)", mrp.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth"))
        axe(mrp, "receipt 390px (breakdown)")
        mrp.screenshot(path=str(SHOTS / "pay-breakdown-390.png"), full_page=True)
        mrich.close()

        # --- a price revised after the payment was started ---------------------------------------------------------------
        def start(b):
            """Opens the pay page of booking b and starts a payment; returns (csrf, order_id, amount)."""
            page.goto(f"{BASE}/booking/{b['token']}/pay", wait_until="load")
            tk = csrf_of(page)
            d = page.request.post(f"{BASE}/booking/{b['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": tk}).json()
            return tk, d["payment"]["order_id"], d["payment"]["amount"]

        def verify(b, tk, order, pid):
            return page.request.post(f"{BASE}/booking/{b['token']}/pay/verify", headers={"Accept": "application/json"},
                                     form={"csrf_token": tk, "razorpay_order_id": order, "razorpay_payment_id": pid, "razorpay_signature": sign(order, pid)})

        r1 = make("approved")
        tk, old_order, old_amount = start(r1)
        check("the first order is for the advance then due (Rs 3,750)", old_amount == 375000)
        helper("approve", r1["id"], 10000)   # the team revises the total: advance 5,000, the old Razorpay order is cleared
        st = get(r1["id"])
        check("revising the price clears the old Razorpay order", st["row"]["razorpay_order_id"] is None and st["row"]["amount"] == "5000.00", json.dumps(st["row"]))
        late_old = verify(r1, tk, old_order, "pay_FAKESTALE001")
        st = get(r1["id"])
        check("a payment on the old order (different amount) is not confirmed and says so", late_old.status == 422 and st["row"]["status"] == "approved" and "price" in late_old.json()["message"].lower(), late_old.text()[:200])
        check("the stale payment is flagged NEEDS ATTENTION", any(e["status"] == "payment_issue" and "NEEDS ATTENTION" in (e["note"] or "") for e in st["events"]))
        tk, new_order, new_amount = start(r1)
        check("a fresh order is created for the new advance (Rs 5,000)", new_order != old_order and new_amount == 500000 and get(r1["id"])["row"]["razorpay_order_id"] == new_order)
        okv = verify(r1, tk, new_order, "pay_FAKEFRESH001")
        check("paying the fresh order confirms the booking", okv.status == 200 and get(r1["id"])["row"]["status"] == "confirmed")

        r2 = make("approved")
        tk, first_order, _ = start(r2)
        tk, second_order, _ = start(r2)    # a second attempt: the first order is now only in the history
        same = verify(r2, tk, first_order, "pay_FAKESAME0001")
        check("a payment on an earlier order with the SAME amount is still confirmed", second_order != first_order and same.status == 200 and get(r2["id"])["row"]["status"] == "confirmed", same.text()[:200])

        # --- late payment: the slot is still free / the slot was taken ------------------------------------------------------
        l1 = make("approved")
        tk, o_late, _ = start(l1)
        print(helper("expire", l1["id"]))
        check("the overdue booking is expired", get(l1["id"])["row"]["status"] == "expired")
        v = verify(l1, tk, o_late, "pay_FAKELATE0001")
        check("a late payment with the slot still free confirms the booking", v.status == 200 and get(l1["id"])["row"]["status"] == "confirmed", v.text()[:200])

        l2 = make("approved")
        tk, o_gone, _ = start(l2)
        print(helper("expire", l2["id"]))
        helper("takeslot", l2["id"])
        v = verify(l2, tk, o_gone, "pay_FAKELATE0002")
        st = get(l2["id"])
        msg = v.json().get("message", "")
        check("a late payment whose slot was taken is not confirmed and tells the payer we will refund", v.status == 422 and st["row"]["status"] == "expired" and "refund" in msg.lower() and "slot" in msg.lower(), msg)
        check("it is flagged: refund required", any("refund required" in (e["note"] or "") for e in st["events"]))
        page.goto(f"{BASE}/booking/{l2['token']}/pay", wait_until="load")
        check("the booking page says a payment arrived that could not be applied", "could not apply" in page.inner_text("body").lower() and page.query_selector("#bkp-pay") is None)
        axe(page, "pay page (payment received, slot gone)")
        page.screenshot(path=str(SHOTS / "pay-slot-gone-1440.png"), full_page=True)

        # --- Razorpay off: the offline fallback -----------------------------------------------------------------
        print(helper("offline"))
        off = make("approved")
        page.goto(f"{BASE}/booking/{off['token']}/pay", wait_until="load")
        body = page.inner_text("body")
        check("with Razorpay off there is no Pay button and the offline instructions show", page.query_selector("#bkp-pay") is None and "How to pay" in body and off["reference"] in body)
        axe(page, "pay page (Razorpay off)")
        page.screenshot(path=str(SHOTS / "pay-offline-1440.png"), full_page=True)
        r = page.request.post(f"{BASE}/booking/{off['token']}/pay/create", headers={"Accept": "application/json"}, form={"csrf_token": csrf_of(page) if page.query_selector("input[name=csrf_token]") else token})
        check("create answers 503 when payment is not set up", r.status in (503, 403), str(r.status))
        print(helper("online"))

        # --- 390px ----------------------------------------------------------------------------------------------
        m = br.new_context(viewport={"width": 390, "height": 800}, is_mobile=True, has_touch=True)
        mp = m.new_page()
        mk = make("approved")
        mp.goto(f"{BASE}/booking/{mk['token']}/pay", wait_until="load")
        check("no horizontal overflow at 390px (pay page)", mp.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth"))
        axe(mp, "pay page 390px")
        mp.screenshot(path=str(SHOTS / "pay-approved-390.png"), full_page=True)
        m.close()

        # --- account bookings -------------------------------------------------------------------------------------
        email = "owner@bookingtest.local"
        acct = br.new_context(viewport={"width": 1440, "height": 900})
        ap = acct.new_page()
        ap.goto(f"{BASE}/signup", wait_until="load")
        ap.fill("#name", "Owner Tester"); ap.fill("#email", email); ap.fill("#password", "Passw0rd!test1"); ap.fill("#password_confirm", "Passw0rd!test1"); ap.check("#terms")
        ap.click("form button[type=submit]"); ap.wait_for_load_state("load"); ap.wait_for_timeout(600)
        uid = json.loads(php('require "includes/env.php"; require "includes/site.php"; echo json_encode(query("SELECT id FROM users WHERE email = ?", [%s])[0]);' % json.dumps(email)))["id"]
        own_a = make("approved", email="someone@bookingtest.local", user=str(uid))
        own_b = make("confirmed", email=email)
        foreign = make("approved", email="stranger@bookingtest.local")
        ap.goto(f"{BASE}/account/bookings", wait_until="load")
        listing = ap.inner_text("body")
        check("account list shows the user's bookings (by user id and by email)", own_a["reference"] in listing and own_b["reference"] in listing)
        check("account list hides other people's bookings", foreign["reference"] not in listing)
        check("an approved booking has a Pay now link", ap.query_selector(f'a[href*="/booking/{own_a["token"]}/pay"]') is not None)
        axe(ap, "account bookings list")
        ap.screenshot(path=str(SHOTS / "account-bookings-1440.png"), full_page=True)
        ap.goto(f"{BASE}/account/bookings?booking={own_a['reference']}", wait_until="load")
        check("account detail opens", own_a["reference"] in ap.inner_text("body") and "Pay" in ap.inner_text("body"))
        axe(ap, "account booking detail")
        check("another user's booking is a 404", ap.goto(f"{BASE}/account/bookings?booking={foreign['reference']}").status == 404)
        ap.goto(f"{BASE}/account", wait_until="load")
        if "/account/team" in ap.url:   # a new account is first asked for a favourite team: skip it
            ap.click("text=Skip for now"); ap.wait_for_load_state("load")
            ap.goto(f"{BASE}/account", wait_until="load")
        check("My bookings link on the account home", ap.query_selector('a[href$="/account/bookings"]') is not None)
        mob = br.new_context(viewport={"width": 390, "height": 800}, is_mobile=True, has_touch=True, storage_state=acct.storage_state())
        mpg = mob.new_page(); mpg.goto(f"{BASE}/account/bookings", wait_until="load")
        check("no horizontal overflow at 390px (account bookings)", mpg.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth"))
        mpg.screenshot(path=str(SHOTS / "account-bookings-390.png"), full_page=True)
        br.close()
    print("\nFAILED: " + "; ".join(FAILED) if FAILED else "\nall checks passed")
    sys.exit(1 if FAILED else 0)


if __name__ == "__main__":
    run()
