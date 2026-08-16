-- AgroBusiness Malawi — bring an EXISTING deployment up to the schema of record.
--
-- p601229_AgroBusiness_MW.sql was incomplete: five tables and six
-- crowdsourced_prices columns existed only in the live database and were never
-- written down. That file is now complete, so a NEW deployment needs nothing
-- from here — restore the dump and you are done.
--
-- This file is only for a database that is already live. Every statement is
-- additive: nothing is dropped, nothing is altered in place, no row is touched.
--
-- HOW TO RUN
--   mysql -u <user> -p <database> < migrations/2026-08-16-schema-of-record.sql
--
-- The CREATE TABLE statements are IF NOT EXISTS and safe to re-run.
--
-- The ALTER TABLE statements at the bottom are NOT conditional, because MySQL 8
-- has no "ADD COLUMN IF NOT EXISTS" and faking one needs INFORMATION_SCHEMA plus
-- dynamic SQL, which is a worse thing to keep in a migration than a comment
-- telling you to check first. Production almost certainly already has these
-- columns — api.php has been writing them for a long time. Check before running:
--
--   SHOW COLUMNS FROM crowdsourced_prices;
--
-- and run only the ALTERs for columns that are genuinely absent. An ALTER for a
-- column that already exists fails with error 1060 and changes nothing, so a
-- mistake here is noisy rather than destructive.

-- ─── Tables ──────────────────────────────────────────────────────────────────
-- Identical to the definitions now in p601229_AgroBusiness_MW.sql.

CREATE TABLE IF NOT EXISTS `onboarding_applications` (
  `id`                int          NOT NULL AUTO_INCREMENT,
  `application_ref`   varchar(24)  NOT NULL,
  `user_type`         enum('farmer','seller','buyer') NOT NULL,
  `full_name`         varchar(150) NOT NULL,
  `phone_number`      varchar(20)  NOT NULL,
  `whatsapp_number`   varchar(20)  DEFAULT NULL,
  `email`             varchar(190) DEFAULT NULL,
  `national_id`       varchar(32)  DEFAULT NULL,
  `district_id`       int          DEFAULT NULL,
  `village`           varchar(120) DEFAULT NULL,
  `crops_of_interest` text         DEFAULT NULL,
  `business_name`     varchar(150) DEFAULT NULL,
  `channel`           enum('web','ussd') NOT NULL DEFAULT 'web',
  `status`            enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `admin_notes`       text         DEFAULT NULL,
  `denial_reason`     text         DEFAULT NULL,
  `created_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`       datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_application_ref` (`application_ref`),
  KEY `idx_phone`    (`phone_number`),
  KEY `idx_whatsapp` (`whatsapp_number`),
  KEY `idx_status`   (`status`),
  KEY `idx_district` (`district_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `markets` (
  `id`          int          NOT NULL AUTO_INCREMENT,
  `district_id` int          NOT NULL,
  `name`        varchar(200) NOT NULL,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_district_market` (`district_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `price_overrides` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `crop_id`      int          NOT NULL,
  `district_id`  int          NOT NULL DEFAULT 0,
  `price_per_kg` decimal(10,2) NOT NULL,
  `note`         varchar(255) DEFAULT NULL,
  `set_by`       varchar(50)  NOT NULL DEFAULT 'admin',
  `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_crop_district` (`crop_id`, `district_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            int          NOT NULL AUTO_INCREMENT,
  `username`      varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `ip`           varchar(45)  NOT NULL,
  `username`     varchar(100) DEFAULT NULL,
  `attempted_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success`      tinyint(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Columns ─────────────────────────────────────────────────────────────────
-- Read the note at the top before running these. Check SHOW COLUMNS first.

-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `price_per_bag` decimal(10,2) DEFAULT NULL AFTER `price_per_kg`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `market_id`     int          DEFAULT NULL AFTER `market_name`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `email`         varchar(200) DEFAULT NULL AFTER `submitted_by`;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `status`        enum('pending','approved','flagged','rejected') NOT NULL DEFAULT 'pending';
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `is_member`     tinyint(1)   NOT NULL DEFAULT 0;
-- ALTER TABLE `crowdsourced_prices` ADD COLUMN `flag_reason`   varchar(255) DEFAULT NULL;
-- ALTER TABLE `crowdsourced_prices` ADD KEY `idx_status` (`status`);

-- ─── whatsapp_number on an existing onboarding_applications ──────────────────
-- register.php writes this column. If the table pre-dates it, add it:
-- ALTER TABLE `onboarding_applications` ADD COLUMN `whatsapp_number` varchar(20) DEFAULT NULL AFTER `phone_number`;
-- ALTER TABLE `onboarding_applications` ADD KEY `idx_whatsapp` (`whatsapp_number`);

-- ─── Legacy contact numbers ──────────────────────────────────────────────────
-- Rows written before phone canonicalisation may hold local formats such as
-- 0888123456. Nothing here rewrites them: a bulk UPDATE that guesses a country
-- code is exactly the mistake the application now refuses to make. Review them:
--
--   SELECT id, application_ref, phone_number FROM onboarding_applications
--    WHERE phone_number NOT LIKE '+%';
--
-- and correct them individually, or leave them — every read path tolerates both.
