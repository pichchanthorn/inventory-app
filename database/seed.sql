-- ================================================
-- Inventory Management System - Sample Data
-- PCTN Fertilizer Shop (ជី និង ថ្នាំបាញ់ស្រូវ)
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
--
-- Does NOT seed `suppliers` — PCTN's actual distributor relationships
-- aren't confirmed, so every product below is left with supplier_id
-- NULL (nullable in schema.sql) rather than inventing distributor
-- names. Add real suppliers through the app when known.
--
-- Prices, stock levels, and expiry dates below are ILLUSTRATIVE
-- example data (a realistic range for this kind of shop), not PCTN's
-- actual costs, margins, or real product lots — replace them with real
-- figures before relying on this for anything but a demo/test install.
-- Active ingredient names (Abamectin, Glyphosate, Propiconazole, etc.)
-- are real, commonly used active ingredients shown here only as a
-- format example, not as an assertion that PCTN specifically stocks
-- those exact formulations.
-- ================================================

USE inventory_db;

-- ---------- Categories ----------
-- Flat list (schema has no parent_id/hierarchy) - "ជី" (fertilizer) and
-- "ថ្នាំបាញ់" (pesticide) are represented directly at this sub-category
-- level rather than as a separate grouping concept in the database.
INSERT INTO categories (name, slug, note) VALUES
('ជីគីមី/ជីប្រទាប់', 'inorganic-fertilizer', 'Chemical/inorganic fertilizer, e.g. Urea, DAP, NPK blends'),
('ជីសរីរាង្គ', 'organic-fertilizer', 'Organic/compost fertilizer'),
('ជីស្លឹក/ជីទឹក', 'foliar-fertilizer', 'Liquid foliar fertilizer'),
('ថ្នាំសម្លាប់សត្វល្អិត', 'insecticide', 'Insecticide'),
('ថ្នាំកម្ចាត់ស្មៅ', 'herbicide', 'Herbicide'),
('ថ្នាំកម្ចាត់ផ្សិត', 'fungicide', 'Fungicide');

-- ---------- Units ----------
-- Package size (e.g. 25kg vs 50kg bag) lives in the product name, not as
-- a separate unit row per size - see products below.
INSERT INTO units (name, note) VALUES
('បាវ', 'Bag - used for all fertilizer'),
('ដប', 'Bottle - used for liquid pesticide'),
('កញ្ចប់', 'Packet - used for powder/granule pesticide');

-- ---------- Products ----------
-- current_stock is set directly here for a populated-looking demo; the
-- app itself always creates new products at 0 and only changes stock
-- through Stock In / Stock Out / Adjustment transactions.
INSERT INTO products (name, sku, barcode, category_id, supplier_id, unit_id, note, active_ingredient, expiry_date, cost_price, sale_price, min_stock, current_stock) VALUES
('ជីអ៊ុយរ៉េ ៤៦% (Buffalo Head) - បាវ ៥០kg', 'FERT-UREA-50', NULL,
    (SELECT id FROM categories WHERE slug = 'inorganic-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'បាវ'),
    NULL, 'Urea 46% N', '2027-06-30', 28.00, 32.00, 20, 40),

('ជី DAP ១៨-៤៦-០ (Binh Dien) - បាវ ៥០kg', 'FERT-DAP-50', NULL,
    (SELECT id FROM categories WHERE slug = 'inorganic-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'បាវ'),
    NULL, 'DAP 18-46-0', '2027-06-30', 45.00, 50.00, 15, 25),

('ជី NPK ១៦-១៦-៨ (Royal Crown) - បាវ ៥០kg', 'FERT-NPK1616-50', NULL,
    (SELECT id FROM categories WHERE slug = 'inorganic-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'បាវ'),
    NULL, 'NPK 16-16-8', '2027-06-30', 26.00, 30.00, 20, 35),

('ជី NPK ១៦-១៦-៨ (Royal Crown) - បាវ ២៥kg', 'FERT-NPK1616-25', NULL,
    (SELECT id FROM categories WHERE slug = 'inorganic-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'បាវ'),
    NULL, 'NPK 16-16-8', '2027-06-30', 14.00, 16.00, 20, 6),

('ជីសរីរាង្គ (Terragro) - បាវ ២៥kg', 'FERT-ORG-25', NULL,
    (SELECT id FROM categories WHERE slug = 'organic-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'បាវ'),
    NULL, NULL, '2027-03-31', 8.00, 10.00, 15, 30),

('ជីទឹក Foliar Boost (Terragro) - ដប ១L', 'FERT-FOLIAR-1L', NULL,
    (SELECT id FROM categories WHERE slug = 'foliar-fertilizer'),
    NULL,
    (SELECT id FROM units WHERE name = 'ដប'),
    NULL, NULL, '2026-12-31', 6.00, 9.00, 15, 22),

('ថ្នាំសម្លាប់សត្វល្អិត Abamectin 1.8% EC - ដប ៥០០ml', 'PEST-ABA-500ML', NULL,
    (SELECT id FROM categories WHERE slug = 'insecticide'),
    NULL,
    (SELECT id FROM units WHERE name = 'ដប'),
    NULL, 'Abamectin 1.8% EC', '2027-01-31', 4.00, 6.00, 20, 18),

('ថ្នាំសម្លាប់សត្វល្អិត Abamectin 1.8% EC - ដប ១L', 'PEST-ABA-1L', NULL,
    (SELECT id FROM categories WHERE slug = 'insecticide'),
    NULL,
    (SELECT id FROM units WHERE name = 'ដប'),
    NULL, 'Abamectin 1.8% EC', '2027-01-31', 7.00, 11.00, 10, 4),

('ថ្នាំកម្ចាត់ស្មៅ Glyphosate 41% SL - ដប ១L', 'PEST-GLY-1L', NULL,
    (SELECT id FROM categories WHERE slug = 'herbicide'),
    NULL,
    (SELECT id FROM units WHERE name = 'ដប'),
    NULL, 'Glyphosate 41% SL', '2027-05-31', 3.50, 5.50, 25, 48),

('ថ្នាំកម្ចាត់ផ្សិត Propiconazole 25% EC - ដប ២៥០ml', 'PEST-PROP-250ML', NULL,
    (SELECT id FROM categories WHERE slug = 'fungicide'),
    NULL,
    (SELECT id FROM units WHERE name = 'ដប'),
    NULL, 'Propiconazole 25% EC', '2027-02-28', 3.00, 4.50, 20, 15),

('ថ្នាំកម្ចាត់ផ្សិត (ម្សៅ) - កញ្ចប់ ៥០០g', 'PEST-FUNGP-500G', NULL,
    (SELECT id FROM categories WHERE slug = 'fungicide'),
    NULL,
    (SELECT id FROM units WHERE name = 'កញ្ចប់'),
    NULL, NULL, '2027-02-28', 2.50, 4.00, 20, 3);
