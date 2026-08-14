/* ================================================================
   AgroBusiness Malawi — TEST DATA ONLY

   Purpose:
     Populate realistic development/demo records for:
       - community price reporting
       - community Q&A
       - buyers
       - sellers
       - buyer/seller crop interests

   IMPORTANT:
     This file is intentionally separate from production data.
     All phone numbers use obviously synthetic +265000... values.
     Price records are PENDING test reports based on ADMARC's published
     2026/27 maize buying price of MK900/kg. They are NOT represented
     as independent farmer observations.

   ADMARC source checked 15 Aug 2026:
     https://admarc.co.mw/admarc-commences-maize-purchases/

   ADMARC says its 2026/27 maize purchasing began 1 June 2026 at the
   government-approved price of MK900/kg through ADMARC markets
   nationwide.
   ================================================================ */

START TRANSACTION;

/* ================================================================
   1. TEST COMMUNITY Q&A
   ================================================================ */

INSERT INTO community_qa
    (district_id, question_en, question_ci, answer_en, answer_ci)
SELECT d.id,
       x.question_en,
       x.question_ci,
       x.answer_en,
       x.answer_ci
FROM districts d
JOIN (
    SELECT 'Lilongwe' district_name,
           'When should I plant maize in Lilongwe?' question_en,
           'Kodi ndibzale liti chimanga ku Lilongwe?' question_ci,
           'Plant maize with the first effective rains and use locally recommended certified seed.' answer_en,
           'Bzalani chimanga ndi mvula yoyamba yabwino ndipo gwiritsani ntchito mbewu yovomerezeka yoyenera dera.' answer_ci
    UNION ALL
    SELECT 'Blantyre',
           'Which market can I use to sell groundnuts?',
           'Kodi ndingagulitse mtedza ku msika uti?',
           'Compare offers at nearby markets and confirm the current buying terms before transporting produce.',
           'Yerekezerani mitengo ya misika yapafupi ndipo tsimikizirani mtengo wogulira musananyamule zokolola.'
    UNION ALL
    SELECT 'Mzuzu',
           'How should I store maize after harvest?',
           'Kodi ndisunge bwanji chimanga nditakolola?',
           'Dry grain thoroughly, keep it clean, and use a sealed or hermetic storage option to reduce pest and moisture damage.',
           'Yanitsani chimanga bwino, chisungeni chaukhondo, ndipo gwiritsani ntchito chosungira chotsekedwa bwino kuti muchepetse tizilombo ndi chinyezi.'
    UNION ALL
    SELECT 'Kasungu',
           'What should I check before selling my crop?',
           'Ndiyang''ane chiyani ndisanagulitse zokolola zanga?',
           'Check moisture, cleanliness, quantity, current market price, transport cost and the buyer''s payment terms.',
           'Yang''anani chinyezi, ukhondo, kuchuluka kwa zokolola, mtengo wapano, mtengo wa mayendedwe ndi njira yolipirira ya wogula.'
    UNION ALL
    SELECT 'Machinga',
           'Where can I find a buyer for pigeon peas?',
           'Kodi ndingapeze kuti wogula nandolo?',
           'Use the buyer directory and compare buyers in your district before agreeing a price.',
           'Gwiritsani ntchito mndandanda wa ogula ndipo yerekezerani ogula a m''dera lanu musanagwirizane pa mtengo.'
    UNION ALL
    SELECT 'Dowa',
           'Is email required when reporting a crop price?',
           'Kodi imelo ndiyofunika popereka mtengo wa mbeu?',
           'No. Email is optional. A price report can be submitted without an email address.',
           'Ayi. Imelo ndi yosankha. Mutha kupereka mtengo wa mbeu popanda imelo.'
    UNION ALL
    SELECT 'Mulanje',
           'Can I report a price without selecting a market?',
           'Kodi ndingapereke mtengo popanda kusankha msika?',
           'Yes. Market and area are optional. Select them when you know where the price was observed.',
           'Inde. Msika ndi dera ndi zosankha. Zisankheni mukadziwa kumene mtengowo unapezeka.'
    UNION ALL
    SELECT 'Salima',
           'How does price approval work?',
           'Kodi kuvomereza mitengo kumagwira ntchito bwanji?',
           'Submitted prices are reviewed before publication. Approved reports can contribute to the community price view.',
           'Mitengo yoperekedwa imawunikidwa isanatulutsidwe. Mitengo yovomerezeka ingathandize pa chiwonetsero cha mitengo ya anthu.'
) x ON x.district_name = d.name
WHERE NOT EXISTS (
    SELECT 1
    FROM community_qa q
    WHERE q.district_id = d.id
      AND q.question_en = x.question_en
);

/* ================================================================
   2. TEST BUYER CONTACTS
   ================================================================ */

INSERT IGNORE INTO buyer_contact_details
    (phone_number, whatsapp_number, email, address)
VALUES
    ('+265000000001', '+265000000001', 'buyer1@example.test', 'Lilongwe City'),
    ('+265000000002', '+265000000002', 'buyer2@example.test', 'Blantyre City'),
    ('+265000000003', '+265000000003', NULL, 'Mzuzu City'),
    ('+265000000004', '+265000000004', 'buyer4@example.test', 'Kasungu'),
    ('+265000000005', '+265000000005', NULL, 'Machinga'),
    ('+265000000006', '+265000000006', 'buyer6@example.test', 'Salima');

INSERT IGNORE INTO buyers (name, district_id, contact_id)
SELECT x.name, d.id, c.id
FROM (
    SELECT 'Lilongwe Produce Buyers Ltd' name, 'Lilongwe' district_name, '+265000000001' phone
    UNION ALL SELECT 'Blantyre Agro Traders', 'Blantyre', '+265000000002'
    UNION ALL SELECT 'Mzuzu Grain Buyers', 'Mzuzu', '+265000000003'
    UNION ALL SELECT 'Kasungu Commodity Buyers', 'Kasungu', '+265000000004'
    UNION ALL SELECT 'Machinga Legume Buyers', 'Machinga', '+265000000005'
    UNION ALL SELECT 'Salima Produce Exchange', 'Salima', '+265000000006'
) x
JOIN districts d ON d.name = x.district_name
JOIN buyer_contact_details c ON c.phone_number = x.phone
WHERE NOT EXISTS (
    SELECT 1 FROM buyers b WHERE b.contact_id = c.id
);

/* ================================================================
   3. TEST SELLER CONTACTS
   ================================================================ */

INSERT IGNORE INTO seller_contact_details
    (phone_number, whatsapp_number, email, address)
VALUES
    ('+265000000101', '+265000000101', 'seller1@example.test', 'Lilongwe'),
    ('+265000000102', '+265000000102', NULL, 'Blantyre'),
    ('+265000000103', '+265000000103', 'seller3@example.test', 'Mzuzu'),
    ('+265000000104', '+265000000104', NULL, 'Kasungu'),
    ('+265000000105', '+265000000105', 'seller5@example.test', 'Machinga'),
    ('+265000000106', '+265000000106', NULL, 'Thyolo'),
    ('+265000000107', '+265000000107', 'seller7@example.test', 'Dedza'),
    ('+265000000108', '+265000000108', NULL, 'Mangochi');

INSERT IGNORE INTO sellers (name, district_id, contact_id)
SELECT x.name, d.id, c.id
FROM (
    SELECT 'Lilongwe Test Farm' name, 'Lilongwe' district_name, '+265000000101' phone
    UNION ALL SELECT 'Blantyre Smallholder Group', 'Blantyre', '+265000000102'
    UNION ALL SELECT 'Mzuzu Highland Farm', 'Mzuzu', '+265000000103'
    UNION ALL SELECT 'Kasungu Grain Farmer', 'Kasungu', '+265000000104'
    UNION ALL SELECT 'Machinga Legume Farmer', 'Machinga', '+265000000105'
    UNION ALL SELECT 'Thyolo Mixed Farm', 'Thyolo', '+265000000106'
    UNION ALL SELECT 'Dedza Crop Cooperative', 'Dedza', '+265000000107'
    UNION ALL SELECT 'Mangochi Groundnut Farm', 'Mangochi', '+265000000108'
) x
JOIN districts d ON d.name = x.district_name
JOIN seller_contact_details c ON c.phone_number = x.phone
WHERE NOT EXISTS (
    SELECT 1 FROM sellers s WHERE s.contact_id = c.id
);

/* ================================================================
   4. TEST BUYER CROP INTERESTS
   ================================================================ */

INSERT IGNORE INTO buyer_crops (buyer_id, crop_id)
SELECT b.id, c.id
FROM buyers b
JOIN crops c ON c.name IN ('Maize','Groundnuts','Soybeans')
WHERE b.name = 'Lilongwe Produce Buyers Ltd'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Rice','Beans') WHERE b.name = 'Blantyre Agro Traders'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Potatoes','Beans') WHERE b.name = 'Mzuzu Grain Buyers'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Soybeans','Groundnuts') WHERE b.name = 'Kasungu Commodity Buyers'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Pigeon Peas','Groundnuts','Soybeans') WHERE b.name = 'Machinga Legume Buyers'
UNION ALL
SELECT b.id, c.id FROM buyers b JOIN crops c ON c.name IN ('Maize','Rice','Groundnuts') WHERE b.name = 'Salima Produce Exchange';

/* ================================================================
   5. TEST SELLER CROP LISTINGS
   ================================================================ */

INSERT IGNORE INTO seller_crops (seller_id, crop_id)
SELECT s.id, c.id
FROM sellers s
JOIN crops c ON c.name IN ('Maize','Groundnuts')
WHERE s.name = 'Lilongwe Test Farm'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Beans') WHERE s.name = 'Blantyre Smallholder Group'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Irish Potatoes') WHERE s.name = 'Mzuzu Highland Farm'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Soybeans') WHERE s.name = 'Kasungu Grain Farmer'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Pigeon Peas','Groundnuts') WHERE s.name = 'Machinga Legume Farmer'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Tea','Maize') WHERE s.name = 'Thyolo Mixed Farm'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Maize','Groundnuts') WHERE s.name = 'Dedza Crop Cooperative'
UNION ALL
SELECT s.id, c.id FROM sellers s JOIN crops c ON c.name IN ('Groundnuts','Pigeon Peas') WHERE s.name = 'Mangochi Groundnut Farm';

/* ================================================================
   6. TEST COMMUNITY PRICE REPORTS

   ADMARC's current published 2026/27 maize purchase price is
   MK900/kg nationwide. These are deliberately PENDING records so
   an administrator can exercise the real approval workflow.
   They are marked as test/reference records and must not be treated
   as independent community observations.
   ================================================================ */

INSERT INTO crowdsourced_prices
    (crop_id, district_id, price_per_kg, unit, market_name, market_id,
     area_id, submitted_by, email, channel, verified, status, is_member,
     flag_reason, created_at)
SELECT
    c.id,
    d.id,
    900.00,
    'kg',
    x.market_name,
    pm.id,
    NULL,
    'ADMARC-REFERENCE-TEST',
    NULL,
    'web',
    0,
    'pending',
    0,
    'TEST DATA — ADMARC published 2026/27 maize buying price MK900/kg; not an independent farmer report.',
    '2026-08-15 09:00:00'
FROM (
    SELECT 'Lilongwe' district_name, 'Lilongwe Market' market_name
    UNION ALL SELECT 'Blantyre', 'Blantyre Market'
    UNION ALL SELECT 'Mzuzu', 'Mzuzu Market'
    UNION ALL SELECT 'Mchinji', 'Mchinji Market'
    UNION ALL SELECT 'Ntchisi', 'Ntchisi Market'
    UNION ALL SELECT 'Dedza', 'Dedza Market'
    UNION ALL SELECT 'Kasungu', 'Kasungu Market'
    UNION ALL SELECT 'Nkhata Bay', 'Nkhata Bay Market'
    UNION ALL SELECT 'Rumphi', 'Rumphi Market'
    UNION ALL SELECT 'Karonga', 'Karonga Market'
    UNION ALL SELECT 'Thyolo', 'Thyolo Market'
    UNION ALL SELECT 'Chitipa', 'Chitipa Market'
    UNION ALL SELECT 'Mangochi', 'Mangochi Market'
    UNION ALL SELECT 'Chikwawa', 'Chikwawa Market'
    UNION ALL SELECT 'Zomba', 'Zomba Central Market'
    UNION ALL SELECT 'Nkhotakota', 'Nkhotakota Market'
    UNION ALL SELECT 'Ntcheu', 'Ntcheu Market'
    UNION ALL SELECT 'Balaka', 'Balaka Market'
    UNION ALL SELECT 'Mulanje', 'Mulanje Market'
    UNION ALL SELECT 'Machinga', 'Machinga Market'
    UNION ALL SELECT 'Phalombe', 'Phalombe Market'
    UNION ALL SELECT 'Dowa', 'Dowa Market'
    UNION ALL SELECT 'Likoma', 'Likoma Market'
    UNION ALL SELECT 'Salima', 'Salima Market'
    UNION ALL SELECT 'Chiradzulu', 'Chiradzulu Market'
    UNION ALL SELECT 'Mwanza', 'Mwanza Market'
    UNION ALL SELECT 'Mzimba', 'Mzimba Market'
    UNION ALL SELECT 'Neno', 'Neno Market'
    UNION ALL SELECT 'Nsanje', 'Nsanje Market'
) x
JOIN districts d ON d.name = x.district_name
JOIN crops c ON c.name = 'Maize'
JOIN price_markets pm
  ON pm.district_id = d.id
 AND pm.name = x.market_name
WHERE NOT EXISTS (
    SELECT 1
    FROM crowdsourced_prices cp
    WHERE cp.submitted_by = 'ADMARC-REFERENCE-TEST'
      AND cp.district_id = d.id
      AND cp.market_name = x.market_name
      AND cp.crop_id = c.id
      AND cp.created_at = '2026-08-15 09:00:00'
);

COMMIT;

/* ================================================================
   VERIFICATION
   ================================================================ */
SELECT 'TEST COMMUNITY Q&A' AS dataset, COUNT(*) AS rows_added
FROM community_qa
WHERE question_en LIKE '%test%' OR question_en LIKE '%price%'
   OR question_en LIKE '%plant%' OR question_en LIKE '%market%';

SELECT 'TEST BUYERS' AS dataset, COUNT(*) AS rows_added
FROM buyers b
JOIN buyer_contact_details c ON c.id = b.contact_id
WHERE c.phone_number LIKE '+265000000%';

SELECT 'TEST SELLERS' AS dataset, COUNT(*) AS rows_added
FROM sellers s
JOIN seller_contact_details c ON c.id = s.contact_id
WHERE c.phone_number LIKE '+265000000%';

SELECT 'TEST ADMARC PRICE REPORTS' AS dataset, COUNT(*) AS rows_added
FROM crowdsourced_prices
WHERE submitted_by = 'ADMARC-REFERENCE-TEST';
