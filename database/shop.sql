-- Shop v2: product visibility, orders and order history. The catalogue tables (shop_categories,
-- shop_products, shop_product_colours, shop_product_variants, shop_product_images, shop_product_features,
-- shop_product_specs, shop_reviews) already exist; bin/migrate-shop.php applies everything below safely
-- and can be re-run. Prices are GST-inclusive rupees (DECIMAL 10,2). Razorpay amounts are paise.
ALTER TABLE shop_products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position;
ALTER TABLE shop_products ADD COLUMN created_at DATETIME DEFAULT NULL AFTER is_active;
ALTER TABLE shop_products ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at;
ALTER TABLE shop_categories ADD COLUMN image VARCHAR(512) DEFAULT NULL AFTER name;
ALTER TABLE shop_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position;
-- One row per (product, colour, size) so stock can be tracked and a cart line can be keyed; the old table has no key.
ALTER TABLE shop_product_variants ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

CREATE TABLE IF NOT EXISTS shop_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(24) NOT NULL,                 -- shown to people, e.g. HPCL-10042
    token CHAR(32) NOT NULL,                           -- secret in the confirmation link /shop/order/<token>
    user_id INT UNSIGNED DEFAULT NULL,                 -- users.id when ordered while signed in
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address_line1 VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(120) NOT NULL,
    state VARCHAR(120) NOT NULL,
    pincode VARCHAR(10) NOT NULL,
    notes TEXT DEFAULT NULL,                           -- customer note, e.g. the name and number for a custom jersey
    subtotal DECIMAL(10,2) NOT NULL,
    shipping DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('pending','paid','packed','shipped','delivered','cancelled','failed','refunded') NOT NULL DEFAULT 'pending',
    razorpay_order_id VARCHAR(64) DEFAULT NULL,
    razorpay_payment_id VARCHAR(64) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
    tracking_carrier VARCHAR(120) DEFAULT NULL,
    tracking_number VARCHAR(120) DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_shop_orders_number (order_number),
    UNIQUE KEY uq_shop_orders_token (token),
    UNIQUE KEY uq_shop_orders_razorpay (razorpay_order_id),
    KEY idx_shop_orders_user (user_id),
    KEY idx_shop_orders_status (status, created_at)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    variant_id INT UNSIGNED DEFAULT NULL,              -- shop_product_variants.id when the product has variants
    product_name VARCHAR(512) NOT NULL,                -- copied at order time
    variant_label VARCHAR(120) DEFAULT NULL,           -- e.g. "Navy / L"
    image VARCHAR(512) DEFAULT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    qty SMALLINT UNSIGNED NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    KEY idx_shop_order_items_order (order_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_order_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_shop_order_events_order (order_id)
) DEFAULT CHARSET=utf8mb4;

-- site_settings rows used by the shop (name => meaning):
--   shop_enabled '1'            the shop is public (also on when SHOP_ENABLED=true in .env)
--   shop_shipping_fee           flat shipping in rupees (default 60)
--   shop_free_shipping_over     subtotal at or above which shipping is free (empty = never)
--   shop_notify_email           address that gets a copy of every paid order (default: the site contact email)
--   shop_support_phone          phone/WhatsApp number shown on the cart and order pages
