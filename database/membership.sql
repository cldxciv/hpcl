-- Pro HPCL Membership Programme tables. Applied to the local dev DB; mirror onto the
-- live DB only when the feature is approved for production.

CREATE TABLE IF NOT EXISTS membership_applications (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_name       VARCHAR(255) NOT NULL,
    contact_name        VARCHAR(255) NOT NULL,
    email               VARCHAR(255) NOT NULL,
    phone               VARCHAR(10)  NOT NULL,
    tier                VARCHAR(16)  NOT NULL,
    website             VARCHAR(255) DEFAULT NULL,
    city                VARCHAR(120) DEFAULT NULL,
    message             TEXT         NOT NULL,
    status              VARCHAR(16)  NOT NULL DEFAULT 'pending',
    payment_status      VARCHAR(16)  NOT NULL DEFAULT 'not_required',
    amount              INT UNSIGNED DEFAULT NULL,
    razorpay_order_id   VARCHAR(64)  DEFAULT NULL,
    razorpay_payment_id VARCHAR(64)  DEFAULT NULL,
    paid_at             DATETIME     DEFAULT NULL,
    admin_note          TEXT         DEFAULT NULL,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    reviewed_at         DATETIME     DEFAULT NULL,
    ip                  VARCHAR(45)  NOT NULL,
    created_at          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_membership_order (razorpay_order_id),
    KEY idx_membership_status (status),
    KEY idx_membership_email (email),
    KEY idx_membership_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public member business profiles shown at /members and /member/<slug>.
CREATE TABLE IF NOT EXISTS members (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug           VARCHAR(191) NOT NULL,
    business_name  VARCHAR(255) NOT NULL,
    tier           VARCHAR(16)  NOT NULL,
    logo           VARCHAR(512) DEFAULT NULL,
    tagline        VARCHAR(255) DEFAULT NULL,
    about          TEXT         DEFAULT NULL,
    website        VARCHAR(255) DEFAULT NULL,
    phone          VARCHAR(32)  DEFAULT NULL,
    email          VARCHAR(255) DEFAULT NULL,
    city           VARCHAR(120) DEFAULT NULL,
    instagram      VARCHAR(255) DEFAULT NULL,
    facebook       VARCHAR(255) DEFAULT NULL,
    featured       TINYINT(1)   NOT NULL DEFAULT 0,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    published      TINYINT(1)   NOT NULL DEFAULT 1,
    application_id INT UNSIGNED DEFAULT NULL,
    created_at     DATETIME     NOT NULL,
    updated_at     DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_slug (slug),
    KEY idx_members_tier (tier),
    KEY idx_members_published (published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
