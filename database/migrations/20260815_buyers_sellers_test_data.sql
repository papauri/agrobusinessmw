/* ================================================================
   AgroBusiness Malawi — BUYER / SELLER TEST DATA ONLY

   Synthetic development/demo data only.
   This file does not seed prices, community questions, markets or areas.

   IMPORTANT:
   The phone values below are structurally valid Malawi mobile numbers
   for the database CHECK constraints, but are synthetic test identities.
   Do not use them for real customer contact.
   ================================================================ */

START TRANSACTION;

/* ---------------------------------------------------------------
   1. TEST BUYER CONTACTS
   --------------------------------------------------------------- */
INSERT IGNORE INTO buyer_contact_details
    (phone_number, whatsapp_number, email, address)
VALUES
    ('+265888000201', '+265888000201', 'buyer201@example.test', 'Lilongwe City'),
    ('+265888000202', '+265888000202', NULL, 'Blantyre City'),
    ('+265888000203', '+265888000203', 'buyer203@example.test', 'Mzuzu City'),
    ('+265888000204', '+265888000204', NULL, 'Kasungu'),
    ('+265888000205', '+265888000205', 'buyer205@example.test', 'Machinga'),
    ('+265888000206', '+265888000206', NULL, 'Salima');

INSERT INTO buyers (name, district_id, contact_id)
SELECT x.name, d.id, c.id
FROM (
    SELECT 'Lilongwe Produce Buyers — TEST' AS name, 'Lilongwe' AS district_name, '+265888000201' AS phone
    UNION ALL SELECT 'Blantyre Agro Traders — TEST', 'Blantyre', '+265888000202'
    UNION ALL SELECT 'Mzuzu Grain Buyers — TEST', 'Mzuzu', '+265888000203'
    UNION ALL SELECT 'Kasungu Commodity Buyers — TEST', 'Kasungu', '+265888000204'
    UNION ALL SELECT 'Machinga Legume Buyers — TEST', 'Machinga', '+265888000205'
    UNION ALL SELECT 'Salima Produce Exchange — TEST', 'Salima', '+265888000206'
) x
JOIN districts d ON d.name = x.district_name
JOIN buyer_contact_details c ON c.phone_number = x.phone
WHERE NOT EXISTS (
    SELECT 1 FROM buyers b WHERE b.contact_id = c.id
);

/* ---------------------------------------------------------------
   2. TEST SELLER CONTACTS
   --------------------------------------------------------------- */
INSERT IGNORE INTO seller_contact_details
    (phone_number, whatsapp_number, email, address)
VALUES
    ('+265888000301', '+265888000301', 'seller301@example.test', 'Lilongwe'),
    ('+265888000302', '+265888000302', NULL, 'Blantyre'),
    ('+265888000303', '+265888000303', 'seller303@example.test', 'Mzuzu'),
    ('+265888000304', '+265888000304', NULL, 'Kasungu'),
    ('+265888000305', '+265888000305', 'seller305@example.test', 'Machinga'),
    ('+265888000306', '+265888000306', NULL, 'Thyolo'),
    ('+265888000307', '+265888000307', 'seller307@example.test', 'Dedza'),
    ('+265888000308', '+265888000308', NULL, 'Mangochi');

INSERT INTO sellers (name, district_id, contact_id)
SELECT x.name, d.id, c.id
FROM (
    SELECT 'Lilongwe Test Farm' AS name, 'Lilongwe' AS district_name, '+265888000301' AS phone
    UNION ALL SELECT 'Blantyre Smallholder Group — TEST', 'Blantyre', '+265888000302'
    UNION ALL SELECT 'Mzuzu Highland Farm — TEST', 'Mzuzu', '+265888000303'
    UNION ALL SELECT 'Kasungu Grain Farmer — TEST', 'Kasungu', '+265888000304'
    UNION ALL SELECT 'Machinga Legume Farmer — TEST', 'Machinga', '+265888000305'
    UNION ALL SELECT 'Thyolo Mixed Farm — TEST', 'Thyolo', '+265888000306'
    UNION ALL SELECT 'Dedza Crop Cooperative — TEST', 'Dedza', '+265888000307'
    UNION ALL SELECT 'Mangochi Groundnut Farm — TEST', 'Mangochi', '+265888000308'
) x
JOIN districts d ON d.name = x.district_name
JOIN seller_contact_details c ON c.phone_number = x.phone
WHERE NOT EXISTS (
    SELECT 1 FROM sellers s WHERE s.contact_id = c.id
);

/* ---------------------------------------------------------------
   3. TEST BUYER CROP INTERESTS
   --------------------------------------------------------------- */
INSERT IGNORE INTO buyer_crops (buyer_id, crop_id)
SELECT b.id, c.id
FROM buyers b JOIN crops c ON c.name IN ('Maize','Groundnuts','Soybeans')
WHERE b.name = 'Lilongwe Produce Buyers — TEST'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Rice','Beans')
WHERE b.name = 'Blantyre Agro Traders — TEST'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Potatoes','Beans')
WHERE b.name = 'Mzuzu Grain Buyers — TEST'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Soybeans','Groundnuts')
WHERE b.name = 'Kasungu Commodity Buyers — TEST'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Pigeon Peas','Groundnuts','Soybeans')
WHERE b.name = 'Machinga Legume Buyers — TEST'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Rice','Groundnuts')
WHERE b.name = 'Salima Produce Exchange — TEST';

/* ---------------------------------------------------------------
   4. TEST SELLER CROP LISTINGS
   --------------------------------------------------------------- */
INSERT IGNORE INTO seller_crops (seller_id, crop_id)
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Groundnuts')
WHERE s.name = 'Lilongwe Test Farm'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Beans')
WHERE s.name = 'Blantyre Smallholder Group — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Irish Potatoes')
WHERE s.name = 'Mzuzu Highland Farm — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Soybeans')
WHERE s.name = 'Kasungu Grain Farmer — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Pigeon Peas','Groundnuts')
WHERE s.name = 'Machinga Legume Farmer — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Tea','Maize')
WHERE s.name = 'Thyolo Mixed Farm — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Groundnuts')
WHERE s.name = 'Dedza Crop Cooperative — TEST'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Groundnuts','Pigeon Peas')
WHERE s.name = 'Mangochi Groundnut Farm — TEST';

COMMIT;

/* Verification */
SELECT 'TEST BUYERS' AS dataset, COUNT(*) AS rows_found
FROM buyers b JOIN buyer_contact_details c ON c.id = b.contact_id
WHERE c.phone_number LIKE '+2658880002%';

SELECT 'TEST SELLERS' AS dataset, COUNT(*) AS rows_found
FROM sellers s JOIN seller_contact_details c ON c.id = s.contact_id
WHERE c.phone_number LIKE '+2658880003%';
