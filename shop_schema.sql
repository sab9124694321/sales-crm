CREATE TABLE IF NOT EXISTS shop_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    price_xp INTEGER NOT NULL DEFAULT 0,
    image_path TEXT,
    category TEXT DEFAULT 'certificate',
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shop_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    code TEXT NOT NULL UNIQUE,
    is_sold INTEGER DEFAULT 0,
    sold_to_hunter_id INTEGER,
    sold_at DATETIME,
    FOREIGN KEY (product_id) REFERENCES shop_products(id)
);

CREATE TABLE IF NOT EXISTS shop_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hunter_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    code_id INTEGER NOT NULL,
    price_xp INTEGER NOT NULL,
    status TEXT DEFAULT 'completed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hunter_id) REFERENCES hunters(id),
    FOREIGN KEY (product_id) REFERENCES shop_products(id),
    FOREIGN KEY (code_id) REFERENCES shop_codes(id)
);

CREATE INDEX IF NOT EXISTS idx_codes_product ON shop_codes(product_id);
CREATE INDEX IF NOT EXISTS idx_codes_sold ON shop_codes(is_sold);
CREATE INDEX IF NOT EXISTS idx_orders_hunter ON shop_orders(hunter_id);
