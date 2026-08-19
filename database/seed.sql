-- ================================================
-- Inventory Management System - Sample Data
-- Course: Advanced PHP and MySQL
--
-- Optional sample rows so a fresh install isn't completely empty.
-- Run this AFTER schema.sql (Docker's docker-entrypoint-initdb.d does
-- this automatically, in filename order; in phpMyAdmin, import
-- schema.sql first, then this file).
--
-- Does NOT seed `roles` (already inserted by schema.sql) or `users` —
-- register your own account through the app, then promote it to Admin
-- by setting `role_id` to 1 on your row in the `users` table. See the
-- README's "Setup & Installation" section for the exact steps.
-- ================================================

USE inventory_db;

-- ---------- Categories ----------
INSERT INTO categories (name, slug, note) VALUES
('Phone Cases', 'phone-cases', 'Protective cases for smartphones'),
('Chargers & Cables', 'chargers-cables', 'Wall chargers, car chargers, and USB cables'),
('Screen Protectors', 'screen-protectors', 'Tempered glass and film protectors'),
('Power Banks', 'power-banks', 'Portable battery packs'),
('Earphones & Headphones', 'earphones-headphones', 'Wired and wireless audio accessories');

-- ---------- Units ----------
INSERT INTO units (name, note) VALUES
('pcs', 'Individual piece'),
('box', 'Box of multiple units'),
('set', 'Matched set, e.g. case + protector bundle'),
('pack', 'Multi-pack, e.g. 3 protectors per pack');

-- ---------- Suppliers ----------
INSERT INTO suppliers (name, phone, email, address, note) VALUES
('Golden Gadget Trading Co.', '012 345 678', 'sales@goldengadget.com.kh', '#12, Street 214, Phnom Penh', 'Primary distributor for cases & protectors'),
('Bright Circuit Supplies', '077 888 999', 'contact@brightcircuit.com', '#45, Kampuchea Krom Blvd, Phnom Penh', 'Chargers, cables, and power banks'),
('SoundWave Imports', '096 111 222', 'info@soundwaveimports.com', '#7, Street 51, Phnom Penh', 'Audio accessories importer'),
('Mega Mobile Accessories Ltd.', '010 555 333', 'orders@megamobile.asia', 'Olympia Mall, Unit B-12, Phnom Penh', 'General mobile accessories, bulk orders');

-- ---------- Products ----------
-- current_stock is set directly here for a populated-looking demo; the
-- app itself always creates new products at 0 and only changes stock
-- through Stock In / Stock Out / Adjustment transactions.
INSERT INTO products (name, sku, barcode, category_id, supplier_id, unit_id, note, cost_price, sale_price, min_stock, current_stock) VALUES
('Clear TPU Case - iPhone 15', 'CS-IP15-CLR', '8801234500011',
    (SELECT id FROM categories WHERE slug = 'phone-cases'),
    (SELECT id FROM suppliers WHERE name = 'Golden Gadget Trading Co.'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Slim clear case', 1.50, 5.00, 20, 45),

('Clear TPU Case - Samsung S24', 'CS-SS24-CLR', '8801234500012',
    (SELECT id FROM categories WHERE slug = 'phone-cases'),
    (SELECT id FROM suppliers WHERE name = 'Golden Gadget Trading Co.'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Slim clear case', 1.50, 5.00, 20, 8),

('Leather Wallet Case - iPhone 15', 'CS-IP15-LTHR', '8801234500013',
    (SELECT id FROM categories WHERE slug = 'phone-cases'),
    (SELECT id FROM suppliers WHERE name = 'Golden Gadget Trading Co.'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'PU leather with card slots', 4.00, 12.00, 15, 30),

('Tempered Glass Protector - Universal', 'SP-UNIV-TG', '8801234500021',
    (SELECT id FROM categories WHERE slug = 'screen-protectors'),
    (SELECT id FROM suppliers WHERE name = 'Golden Gadget Trading Co.'),
    (SELECT id FROM units WHERE name = 'pack'),
    '3-pack, fits most 6.1"-6.7" phones', 0.80, 3.50, 30, 12),

('20W USB-C Wall Charger', 'CH-20W-USBC', '8801234500031',
    (SELECT id FROM categories WHERE slug = 'chargers-cables'),
    (SELECT id FROM suppliers WHERE name = 'Bright Circuit Supplies'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'PD fast charging', 3.20, 9.99, 25, 60),

('USB-C to USB-C Cable 1m', 'CB-CC-1M', '8801234500032',
    (SELECT id FROM categories WHERE slug = 'chargers-cables'),
    (SELECT id FROM suppliers WHERE name = 'Bright Circuit Supplies'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Braided, 60W rated', 1.10, 4.50, 40, 90),

('Car Charger Dual Port 24W', 'CH-CAR-24W', '8801234500033',
    (SELECT id FROM categories WHERE slug = 'chargers-cables'),
    (SELECT id FROM suppliers WHERE name = 'Bright Circuit Supplies'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Dual USB-A, 24W combined', 2.50, 8.00, 15, 5),

('10,000mAh Power Bank', 'PB-10K', '8801234500041',
    (SELECT id FROM categories WHERE slug = 'power-banks'),
    (SELECT id FROM suppliers WHERE name = 'Bright Circuit Supplies'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Slim, dual output', 8.00, 19.99, 10, 22),

('20,000mAh Power Bank Fast Charge', 'PB-20K-FC', '8801234500042',
    (SELECT id FROM categories WHERE slug = 'power-banks'),
    (SELECT id FROM suppliers WHERE name = 'Bright Circuit Supplies'),
    (SELECT id FROM units WHERE name = 'pcs'),
    '22.5W fast charge, USB-C PD', 14.00, 32.00, 8, 3),

('Wireless Bluetooth Earbuds', 'EP-BT-TWS', '8801234500051',
    (SELECT id FROM categories WHERE slug = 'earphones-headphones'),
    (SELECT id FROM suppliers WHERE name = 'SoundWave Imports'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'True wireless, charging case included', 9.00, 24.99, 12, 18),

('Wired Earphones with Mic', 'EP-WRD-MIC', '8801234500052',
    (SELECT id FROM categories WHERE slug = 'earphones-headphones'),
    (SELECT id FROM suppliers WHERE name = 'SoundWave Imports'),
    (SELECT id FROM units WHERE name = 'pcs'),
    '3.5mm jack, in-line remote', 1.80, 6.00, 25, 50),

('Over-Ear Headphones', 'HP-OE-STD', '8801234500053',
    (SELECT id FROM categories WHERE slug = 'earphones-headphones'),
    (SELECT id FROM suppliers WHERE name = 'SoundWave Imports'),
    (SELECT id FROM units WHERE name = 'pcs'),
    'Foldable, padded headband', 12.00, 28.00, 6, 6);
