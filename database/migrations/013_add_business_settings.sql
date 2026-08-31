-- ================================================
-- Migration 013: add business identity to app_settings (Sale Invoice
-- feature)
--
-- The Sale Invoice header (business name/address/phone/email, shown
-- alongside the existing system logo) previously had nowhere to read
-- this from - app_settings only held usd_to_khr_rate. Rather than
-- hardcoding PCTN's details into the invoice template, they're added
-- here as four nullable columns on the same singleton settings row, so
-- an Admin can edit them from Settings and a different shop could
-- reuse this software later by changing the same four fields - no
-- multi-tenant system, just enough structure to not hardcode one
-- shop's identity into the template.
--
-- Also seeds the current demo shop's details into the existing row (or
-- creates it, for a database that has never saved an exchange rate
-- yet) - ON DUPLICATE KEY UPDATE only touches the four business_*
-- columns, so an already-configured usd_to_khr_rate is left exactly as
-- it was.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include these
-- columns (NULL until an Admin fills them in via Settings).
-- ================================================

USE inventory_db;

ALTER TABLE app_settings
    ADD COLUMN business_name VARCHAR(150) DEFAULT NULL AFTER usd_to_khr_rate,
    ADD COLUMN business_address VARCHAR(255) DEFAULT NULL AFTER business_name,
    ADD COLUMN business_phone VARCHAR(30) DEFAULT NULL AFTER business_address,
    ADD COLUMN business_email VARCHAR(150) DEFAULT NULL AFTER business_phone;

INSERT INTO app_settings (id, usd_to_khr_rate, business_name, business_address, business_phone, business_email)
VALUES (1, 4100.00, 'PCTN Fertilizer Shop', 'Phnom Penh, Cambodia', '0973100485', 'chanthornpich22@gmail.com')
ON DUPLICATE KEY UPDATE
    business_name = VALUES(business_name),
    business_address = VALUES(business_address),
    business_phone = VALUES(business_phone),
    business_email = VALUES(business_email);
