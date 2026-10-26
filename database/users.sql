-- Pro HPCL site users: public accounts for sign-up, sign-in and a profile (includes/account.php).
-- Separate from `admins`. Applied to the local dev DB; mirror onto the live DB only when the
-- feature is approved for production.

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(100) NOT NULL,
    email               VARCHAR(254) NOT NULL,
    phone               VARCHAR(10) NULL,
    password_hash       VARCHAR(255) NOT NULL,
    city                VARCHAR(120) NULL,
    favourite_team_id   INT UNSIGNED NULL,
    email_verified_at   DATETIME NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at       DATETIME NULL,
    signup_ip           VARCHAR(45) NOT NULL,
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_phone (phone),
    KEY idx_users_team (favourite_team_id),
    KEY idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-ins, to slow down password guessing (as admin_login_attempts).
CREATE TABLE IF NOT EXISTS user_login_attempts (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip                  VARCHAR(45) NOT NULL,
    email               VARCHAR(255) NOT NULL,
    attempted_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_attempts_email (email, attempted_at),
    KEY idx_user_attempts_ip (ip, attempted_at),
    KEY idx_user_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time links sent by email: password resets now ("reset"), email verification later
-- ("verify"). Only the token's SHA-256 is stored, so a copy of the table can't be used.
CREATE TABLE IF NOT EXISTS user_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    purpose             VARCHAR(16) NOT NULL,
    token_hash          CHAR(64) NOT NULL,
    expires_at          DATETIME NOT NULL,
    used_at             DATETIME NULL,
    ip                  VARCHAR(45) NOT NULL,
    created_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_tokens_hash (token_hash),
    KEY idx_user_tokens_user (user_id, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
