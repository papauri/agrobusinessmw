-- AgroBusiness Malawi — reconcile an EXISTING deployment with the schema of record.
--
-- BACKGROUND
--   p601229_AgroBusiness_MW.sql drifted away from the live database in both
--   directions. It was regenerated on 2026-08-16 from a production export
--   (phpMyAdmin 5.2.3 / MySQL 8.0.46) and now reproduces production exactly:
--   verified by restoring it and diffing information_schema — 156 columns,
--   66 indexes, 19 foreign keys and 24 engines all identical.
--
-- WHO NEEDS THIS FILE
--   Nobody, if you are provisioning a NEW database: restore
--   p601229_AgroBusiness_MW.sql and you are done.
--
--   This file exists for a deployment that predates the reconciliation — most
--   likely a copy someone restored from the OLD schema file, which was missing
--   three tables and six columns. Production itself already has everything
--   here, so running it against production is a no-op.
--
-- HOW TO RUN
--   mysql -u <user> -p <database> < migrations/2026-08-16-schema-of-record.sql
--
--   Every CREATE TABLE is IF NOT EXISTS and safe to re-run. The ALTER TABLE
--   statements at the bottom are NOT conditional — MySQL 8 has no
--   "ADD COLUMN IF NOT EXISTS" — so they are commented out. Check what you
--   actually have first:
--
--     SHOW COLUMNS FROM crowdsourced_prices;
--     SHOW COLUMNS FROM seller_contact_details;
--     SHOW COLUMNS FROM onboarding_applications;
--
--   and uncomment only the lines for columns you are genuinely missing. An
--   ALTER for a column that already exists fails with error 1060 and changes
--   nothing, so a mistake here is noisy rather than destructive.


-- ─── Tables that were missing from the old schema file ───────────────────────

--
-- price_review_audit — audit trail for community price moderation.
-- READ BY admin/price-audit.php. Its absence is not cosmetic: on a database
-- built from the old schema file, that admin page fails outright with
-- "Table 'price_review_audit' doesn't exist".
--
CREATE TABLE IF NOT EXISTS `price_review_audit` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `price_report_id` int NOT NULL,
  `event_type` varchar(30) NOT NULL,
  `crop_id` int DEFAULT NULL,
  `district_id` int DEFAULT NULL,
  `market_name` varchar(150) DEFAULT NULL,
  `price_per_kg` decimal(12,2) DEFAULT NULL,
  `price_per_bag` decimal(12,2) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `submitted_by` varchar(255) DEFAULT NULL,
  `channel` varchar(50) DEFAULT NULL,
  `verified` tinyint(1) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `is_member` tinyint(1) DEFAULT NULL,
  `flag_reason` text,
  `reviewed_by` varchar(255) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `old_price_per_kg` decimal(12,2) DEFAULT NULL,
  `new_price_per_kg` decimal(12,2) DEFAULT NULL,
  `old_price_per_bag` decimal(12,2) DEFAULT NULL,
  `new_price_per_bag` decimal(12,2) DEFAULT NULL,
  `old_market_name` varchar(150) DEFAULT NULL,
  `new_market_name` varchar(150) DEFAULT NULL,
  `old_flag_reason` text,
  `new_flag_reason` text,
  `event_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_price_audit_report` (`price_report_id`,`event_at`),
  KEY `idx_price_audit_event` (`event_type`,`event_at`),
  KEY `idx_price_audit_status` (`status`,`event_at`),
  KEY `idx_price_audit_reviewer` (`reviewed_by`,`event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- price_markets / price_areas — curated market and area reference lists.
--
-- No code in the repository reads these today. They are here because
-- production has them, with real data (120 and 216 rows), and a schema of
-- record that omits live tables is how this drift started.
--
-- COLLATION WARNING: both are utf8mb4_unicode_ci while every other table is
-- utf8mb4_0900_ai_ci. Comparing a string column across that boundary raises
-- "Illegal mix of collations". Nothing does so today — do not add a join
-- between price_markets.name and markets.name without converting one side.
--
CREATE TABLE IF NOT EXISTS `price_markets` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `district_id` int NOT NULL,
  `name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_price_market` (`district_id`,`name`),
  KEY `idx_price_market_district` (`district_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `price_areas` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `district_id` int NOT NULL,
  `name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_price_area` (`district_id`,`name`),
  KEY `idx_price_area_district` (`district_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Columns that were missing from the old schema file ──────────────────────
-- Read the note at the top. Check SHOW COLUMNS first, then uncomment what you need.

-- crowdsourced_prices: production has four columns the old file did not declare.
-- `verified` is set on 301 of 332 production rows. `area_id` is NULL on all of
-- them — the area feature was never used.
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `area_id`     int UNSIGNED DEFAULT NULL AFTER `market_id`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `verified`    tinyint(1)   DEFAULT '0'  AFTER `channel`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `reviewed_by` varchar(50)  DEFAULT NULL AFTER `flag_reason`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `reviewed_at` datetime     DEFAULT NULL AFTER `reviewed_by`;
-- ALTER TABLE `crowdsourced_prices` ADD KEY `idx_cp_area` (`area_id`);

-- Contact tables: production carries a dedicated WhatsApp number per contact.
-- Nothing reads it yet — assets/js/directory-navigation.js derives the WhatsApp
-- link from phone_number instead. Worth wiring up once real contacts exist.
-- ALTER TABLE `seller_contact_details` ADD COLUMN `whatsapp_number` varchar(30) DEFAULT NULL AFTER `phone_number`;
-- ALTER TABLE `buyer_contact_details`  ADD COLUMN `whatsapp_number` varchar(30) DEFAULT NULL AFTER `phone_number`;

-- Production enforces one contact row per phone number, and one per WhatsApp
-- number. These are the constraints that rejected the old seed data's duplicate
-- rows. Add them only after de-duplicating whatever you already hold:
--   SELECT phone_number, COUNT(*) FROM seller_contact_details
--    WHERE phone_number IS NOT NULL GROUP BY 1 HAVING COUNT(*) > 1;
-- ALTER TABLE `seller_contact_details` ADD UNIQUE KEY `uniq_seller_contact_phone` (`phone_number`);
-- ALTER TABLE `seller_contact_details` ADD UNIQUE KEY `uniq_seller_whatsapp` (`whatsapp_number`);
-- ALTER TABLE `buyer_contact_details`  ADD UNIQUE KEY `uniq_buyer_contact_phone` (`phone_number`);
-- ALTER TABLE `buyer_contact_details`  ADD UNIQUE KEY `uniq_buyer_whatsapp` (`whatsapp_number`);

-- onboarding_applications: production declares nine columns wider than the old
-- file did. Widening is non-destructive; register.php validates below both
-- limits either way, so this is about the file telling the truth.
-- ALTER TABLE `onboarding_applications`
--   MODIFY `application_ref` varchar(20)  NOT NULL,
--   MODIFY `full_name`       varchar(200) NOT NULL,
--   MODIFY `whatsapp_number` varchar(30)  DEFAULT NULL,
--   MODIFY `email`           varchar(200) DEFAULT NULL,
--   MODIFY `national_id`     varchar(50)  DEFAULT NULL,
--   MODIFY `village`         varchar(200) DEFAULT NULL,
--   MODIFY `business_name`   varchar(200) DEFAULT NULL,
--   MODIFY `denial_reason`   varchar(500) DEFAULT NULL,
--   MODIFY `created_at`      datetime     DEFAULT CURRENT_TIMESTAMP;


-- ─── Legacy contact numbers ──────────────────────────────────────────────────
-- Rows written before phone canonicalisation may hold local formats such as
-- 0888123456, and the seed data uses spaced forms like '+265 881 123 456'.
-- Nothing here rewrites them: a bulk UPDATE that guesses a country code is
-- exactly the mistake config/phone.php now refuses to make. Review them:
--
--   SELECT id, application_ref, phone_number FROM onboarding_applications
--    WHERE phone_number NOT LIKE '+%';
--
-- and correct them individually, or leave them — every read path tolerates both.
