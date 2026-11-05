-- Ground booking: requests for the cricket ground (default "Shimla Cricket Ground"), approved by an admin,
-- then paid by the requester with a link. bin/migrate-booking.php applies this safely (re-runnable).
-- Prices are rupees (DECIMAL 10,2); Razorpay amounts are paise.
CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(24) NOT NULL,                    -- shown to people, e.g. BK-10042
    token CHAR(32) NOT NULL,                           -- secret in the status/payment links /booking/<token>
    user_id INT UNSIGNED DEFAULT NULL,                 -- users.id when requested while signed in
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    organisation VARCHAR(255) DEFAULT NULL,            -- team / club / company
    booking_date DATE NOT NULL,                        -- first day
    days TINYINT UNSIGNED NOT NULL DEFAULT 1,          -- consecutive days of play (1-7)
    end_date DATE DEFAULT NULL,                        -- booking_date + days - 1
    slot ENUM('morning','afternoon','evening','full_day') NOT NULL,
    format ENUM('single','double','full_day') NOT NULL, -- one match, two matches, or a full-day session
    opponent VARCHAR(255) DEFAULT NULL,                -- the other team, if already known
    players_per_team SMALLINT UNSIGNED NOT NULL DEFAULT 11, -- rates are for 11 players; extra players are charged separately
    teams_paying TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1 = this team only, 2 = this request pays for both teams (always 1 for a full-day package)
    want_lunch TINYINT(1) NOT NULL DEFAULT 1,          -- lunch and tea are included in the rates; skipping a meal reduces the bill
    want_tea TINYINT(1) NOT NULL DEFAULT 1,
    meal_deduction DECIMAL(10,2) NOT NULL DEFAULT 0,   -- amount taken off for the skipped meals (booking_meal_deduction per meal)
    stay_needed TINYINT(1) NOT NULL DEFAULT 0,         -- residential accommodation for players/teams overnight
    stay_people SMALLINT UNSIGNED DEFAULT NULL,
    stay_nights TINYINT UNSIGNED DEFAULT NULL,
    stay_notes VARCHAR(500) DEFAULT NULL,
    stay_amount DECIMAL(10,2) NOT NULL DEFAULT 0,      -- accommodation charge quoted by the admin, included in total_amount
    extra_amount DECIMAL(10,2) NOT NULL DEFAULT 0,     -- other admin extras (e.g. extra players), included in total_amount
    extra_note VARCHAR(255) DEFAULT NULL,
    admin_modified TINYINT(1) NOT NULL DEFAULT 0,      -- the approver changed the requirements; the requester is told what changed
    request_snapshot TEXT DEFAULT NULL,                -- JSON of the requirements as first requested (kept for the 'what changed' view)
    notes TEXT DEFAULT NULL,                           -- requester's message (teams, level, equipment needs...)
    status ENUM('requested','approved','confirmed','rejected','cancelled','expired') NOT NULL DEFAULT 'requested',
    quoted_amount DECIMAL(10,2) DEFAULT NULL,          -- rate-card total before the member discount (price x teams_paying, or the flat package price)
    total_amount DECIMAL(10,2) DEFAULT NULL,           -- the full price after discount and any extra-player charge, set on approval
    advance_percent DECIMAL(5,2) NOT NULL DEFAULT 50,  -- share of the total paid online to confirm the booking
    member_tier VARCHAR(24) DEFAULT NULL,              -- silver/gold/diamond/platinum when the requester is an active member
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) DEFAULT NULL,                 -- the amount to pay NOW online (the advance = total_amount x advance_percent), set on approval
    balance_amount DECIMAL(10,2) DEFAULT NULL,         -- total_amount - amount: paid at the ground
    payment_due_at DATETIME DEFAULT NULL,              -- an approved slot is held until then
    approved_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,             -- admins.id
    admin_note TEXT DEFAULT NULL,                      -- internal
    customer_note VARCHAR(500) DEFAULT NULL,           -- shown to the requester (approval message / rejection reason)
    payment_method ENUM('razorpay','offline') DEFAULT NULL,
    razorpay_order_id VARCHAR(64) DEFAULT NULL,
    razorpay_payment_id VARCHAR(64) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_bookings_reference (reference),
    UNIQUE KEY uq_bookings_token (token),
    UNIQUE KEY uq_bookings_razorpay (razorpay_order_id),
    KEY idx_bookings_date (booking_date, slot, status),
    KEY idx_bookings_status (status, created_at),
    KEY idx_bookings_user (user_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_booking_events_booking (booking_id)
) DEFAULT CHARSET=utf8mb4;

-- Dates (and optionally one slot) the ground cannot be booked: maintenance, tournament days (e.g. Season 4 fixtures).
CREATE TABLE IF NOT EXISTS booking_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    slot ENUM('all','morning','afternoon','evening') NOT NULL DEFAULT 'all',
    reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_booking_blocks_dates (start_date, end_date)
) DEFAULT CHARSET=utf8mb4;

-- The packages and rate card, one row per format (seeded by bin/migrate-booking.php from the published rate card).
-- price is per team for single/double (per_team = 1) and a flat price for the full-day package (per_team = 0).
CREATE TABLE IF NOT EXISTS booking_rates (
    format ENUM('single','double','full_day') NOT NULL PRIMARY KEY,
    price DECIMAL(10,2) DEFAULT NULL,
    per_team TINYINT(1) NOT NULL DEFAULT 1,
    advance_percent DECIMAL(5,2) NOT NULL DEFAULT 50,
    duration_label VARCHAR(120) DEFAULT NULL,
    overs_label VARCHAR(120) DEFAULT NULL,
    includes TEXT DEFAULT NULL                         -- one benefit per line
) DEFAULT CHARSET=utf8mb4;

-- site_settings rows used by booking (name => meaning):
--   booking_enabled '1'              the booking page is public
--   booking_ground_name              default "Shimla Cricket Ground"
--   booking_slot_morning / _afternoon / _evening   display times, e.g. "6:00 AM - 10:00 AM"
--   booking_hold_hours               hours an approved slot is held for payment (default 48)
--   booking_min_days_ahead           earliest bookable date = today + N days (default 1)
--   booking_max_days_ahead           latest bookable date (default 120)
--   booking_notify_email             gets every new request (default: the site contact email)
--   booking_support_phone            shown on booking pages
--   booking_meal_deduction           rupees taken off per skipped meal (lunch or tea) per team per day (default 150)
--   booking_stay_enabled '1'         offer the residential accommodation option
--   booking_stay_rate                rupees per person per night for the accommodation, including three meals and refreshments (default 1000)
--   booking_stay_note                short description of the accommodation shown to requesters; price is quoted on approval
--   booking_payment_note             bank-transfer details / instructions shown with the payment link (kept in the database, not in code)
--   booking_terms_extra              optional extra terms lines (one per line) shown with the standard terms
