-- Instagram reels shown on the home page ("From our Instagram" section).
-- Rows come from the admin (add by link), the one-off import (bin/import-instagram-reels.php) or
-- the Instagram API sync (includes/instagram-sync.php). Covers are stored on our server under
-- storage/uploads/social/reels/<shortcode>.jpg because Instagram's image links expire.
CREATE TABLE IF NOT EXISTS social_reels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    platform VARCHAR(20) NOT NULL DEFAULT 'instagram',
    shortcode VARCHAR(64) NOT NULL,
    permalink VARCHAR(255) NOT NULL,
    caption TEXT NULL,
    cover VARCHAR(255) NULL,
    media_id VARCHAR(40) NULL,
    posted_at DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    source VARCHAR(10) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_social_reels_code (platform, shortcode),
    KEY idx_social_reels_list (is_active, pinned, posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (name, value) VALUES
    ('social_reels_enabled', '1'),
    ('social_reels_title', 'Latest from Instagram'),
    ('social_reels_limit', '12');
