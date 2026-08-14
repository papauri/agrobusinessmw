-- AgroBusiness Malawi: CANONICAL price-location migration
-- ONE migration for price markets, price areas and optional price-report location.
-- Tailored to the current database state verified from SHOW COLUMNS:
--   district_id exists and is nullable
--   market_id exists and is nullable/indexed
--   email exists and is nullable
--   area_id is the missing column
-- Run this file once against p601229_AgroBusiness_MW.

CREATE TABLE IF NOT EXISTS price_markets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  district_id INT NOT NULL,
  name VARCHAR(180) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_market (district_id,name),
  KEY idx_price_market_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_areas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  district_id INT NOT NULL,
  name VARCHAR(180) NOT NULL,
  city_name VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_area (district_id,name),
  KEY idx_price_area_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations already contain district_id, market_id and email.
-- Only add the missing area_id column and its lookup index.
ALTER TABLE crowdsourced_prices
  MODIFY district_id INT NULL,
  ADD COLUMN area_id INT UNSIGNED NULL AFTER market_id,
  ADD KEY idx_cp_area (area_id);

-- Markets across all 29 districts represented by the application.
INSERT IGNORE INTO price_markets (district_id,name) VALUES
(1,'Lilongwe Market'),(1,'Area 2 Market'),(1,'Old Town Market'),(1,'Kawale Market'),(1,'Kanengo Market'),(1,'Area 25 Market'),(1,'Lumbadzi Market'),(1,'Mitundu Market'),
(2,'Blantyre Market'),(2,'Limbe Market'),(2,'Ndirande Market'),(2,'Bangwe Market'),(2,'Chigumula Market'),(2,'Chirimba Market'),(2,'Kachere Market'),(2,'Manase Market'),(2,'Misesa Market'),(2,'Mpingwe Market'),
(3,'Mzuzu Market'),(3,'Chibavi Market'),(3,'Katoto Market'),(3,'Katawa Market'),
(4,'Mchinji Market'),(4,'Mikundi Market'),(4,'Mkanda Market'),(4,'Kapiri Market'),
(5,'Ntchisi Market'),(5,'Malomo Market'),(5,'Kalira Market'),
(6,'Dedza Market'),(6,'Mayani Market'),(6,'Bembeke Market'),
(7,'Kasungu Market'),(7,'Santhe Market'),(7,'Chamama Market'),(7,'Chulu Market'),
(8,'Nkhata Bay Market'),(8,'Chintheche Market'),(8,'Usisya Market'),
(9,'Rumphi Market'),(9,'Bolero Market'),(9,'Livingstonia Market'),(9,'Nchenachena Market'),
(10,'Karonga Market'),(10,'Chilumba Market'),(10,'Kaporo Market'),(10,'Nyungwe Market'),
(11,'Thyolo Market'),(11,'Luchenza Market'),(11,'Thekerani Market'),(11,'Goliati Market'),
(12,'Chitipa Market'),(12,'Chisenga Market'),(12,'Nthalire Market'),(12,'Misuku Market'),
(13,'Mangochi Market'),(13,'Namwera Market'),(13,'Monkey Bay Market'),(13,'Makanjira Market'),(13,'Cape Maclear Market'),
(14,'Chikwawa Market'),(14,'Nchalo Market'),(14,'Bangula Market'),(14,'Ngabu Market'),
(15,'Zomba Central Market'),(15,'Chinamwali Market'),(15,'Sadzi Market'),(15,'Thondwe Market'),
(16,'Nkhotakota Market'),(16,'Benga Market'),(16,'Bua Market'),(16,'Nsenjere Market'),
(17,'Ntcheu Market'),(17,'Nsipe Market'),(17,'Manjawira Market'),(17,'Senzani Market'),
(18,'Balaka Market'),(18,'Ulongwe Market'),(18,'Kachenga Market'),(18,'Chanthunya Market'),
(19,'Mulanje Market'),(19,'Lujeri Market'),(19,'Milonde Market'),(19,'Namulenga Market'),(19,'Muloza Market'),
(20,'Machinga Market'),(20,'Liwonde Market'),(20,'Ntaja Market'),(20,'Namasika Market'),
(21,'Phalombe Market'),(21,'Chiringa Market'),(21,'Nkhulambe Market'),
(22,'Dowa Market'),(22,'Mponela Market'),(22,'Madisi Market'),(22,'Nambuma Market'),
(23,'Likoma Market'),(23,'Chizumulu Market'),
(24,'Salima Market'),(24,'Senga Bay Market'),(24,'Chipoka Market'),(24,'Pemba Market'),
(25,'Chiradzulu Market'),(25,'Mbulumbuzi Market'),(25,'Namadzi Market'),
(26,'Mwanza Market'),(26,'Thambani Market'),
(27,'Mzimba Market'),(27,'Jenda Market'),(27,'Ekwendeni Market'),(27,'Mpherembe Market'),(27,'Bwengu Market'),(27,'Manyamula Market'),
(28,'Neno Market'),(28,'Lisungwi Market'),(28,'Zalewa Market'),
(29,'Nsanje Market'),(29,'Bangula Market'),(29,'Thundu Market'),(29,'Marka Market');

-- Areas/localities across every district. Market and area are separate:
-- either, both, or neither may be selected by the reporter.
INSERT IGNORE INTO price_areas (district_id,name,city_name) VALUES
(1,'Area 1','Lilongwe City'),(1,'Area 2','Lilongwe City'),(1,'Area 3','Lilongwe City'),(1,'Area 4','Lilongwe City'),(1,'Area 5','Lilongwe City'),(1,'Area 6','Lilongwe City'),(1,'Area 7','Lilongwe City'),(1,'Area 8','Lilongwe City'),(1,'Area 9','Lilongwe City'),(1,'Area 10','Lilongwe City'),(1,'Area 11','Lilongwe City'),(1,'Area 12','Lilongwe City'),(1,'Area 13','Lilongwe City'),(1,'Area 14','Lilongwe City'),(1,'Area 15','Lilongwe City'),(1,'Area 16','Lilongwe City'),(1,'Area 17','Lilongwe City'),(1,'Area 18','Lilongwe City'),(1,'Area 19','Lilongwe City'),(1,'Area 20','Lilongwe City'),(1,'Area 21','Lilongwe City'),(1,'Area 22','Lilongwe City'),(1,'Area 23','Lilongwe City'),(1,'Area 24','Lilongwe City'),(1,'Area 25','Lilongwe City'),(1,'Area 26','Lilongwe City'),(1,'Area 27','Lilongwe City'),(1,'Area 28','Lilongwe City'),(1,'Area 29','Lilongwe City'),(1,'Area 30','Lilongwe City'),(1,'Area 31','Lilongwe City'),(1,'Area 32','Lilongwe City'),(1,'Area 33','Lilongwe City'),(1,'Area 34','Lilongwe City'),(1,'Area 35','Lilongwe City'),(1,'Area 36','Lilongwe City'),(1,'Area 37','Lilongwe City'),(1,'Area 38','Lilongwe City'),(1,'Area 39','Lilongwe City'),(1,'Area 40','Lilongwe City'),(1,'Area 41','Lilongwe City'),(1,'Area 42','Lilongwe City'),(1,'Area 43','Lilongwe City'),(1,'Area 44','Lilongwe City'),(1,'Area 45','Lilongwe City'),(1,'Area 46','Lilongwe City'),(1,'Area 47','Lilongwe City'),(1,'Area 48','Lilongwe City'),(1,'Area 49','Lilongwe City'),(1,'Area 50','Lilongwe City'),(1,'Area 51','Lilongwe City'),(1,'Area 52','Lilongwe City'),(1,'Area 53','Lilongwe City'),(1,'Area 54','Lilongwe City'),(1,'Area 55','Lilongwe City'),(1,'Area 56','Lilongwe City'),(1,'Area 57','Lilongwe City'),(1,'Area 58','Lilongwe City'),
(2,'Soche','Blantyre City'),(2,'Limbe','Blantyre City'),(2,'Chichiri','Blantyre City'),(2,'Kachere','Blantyre City'),(2,'Bangwe','Blantyre City'),(2,'Ndirande','Blantyre City'),(2,'Nyambadwe','Blantyre City'),(2,'Namiwawa','Blantyre City'),(2,'Chilomoni','Blantyre City'),(2,'Mbayani','Blantyre City'),(2,'Chirimba','Blantyre City'),(2,'Kameza','Blantyre City'),(2,'Ngumbe','Blantyre City'),(2,'Chileka','Blantyre City'),(2,'Lunzu','Blantyre City'),
(3,'Mzuzu Central','Mzuzu City'),(3,'Chibavi','Mzuzu City'),(3,'Chibanja','Mzuzu City'),(3,'Chiputula','Mzuzu City'),(3,'Katawa','Mzuzu City'),(3,'Luwinga','Mzuzu City'),(3,'Masasa','Mzuzu City'),(3,'Zolozolo','Mzuzu City'),(3,'Mchengautuwa','Mzuzu City'),(3,'Nkhorongo','Mzuzu City'),
(4,'Mchinji Boma',NULL),(4,'Mduwa',NULL),(4,'Kapiri',NULL),(4,'Mkanda',NULL),(4,'Mikundi',NULL),(4,'Kawere',NULL),
(5,'Ntchisi Boma',NULL),(5,'Malomo',NULL),(5,'Kalira',NULL),(5,'Nkhata',NULL),
(6,'Dedza Boma',NULL),(6,'Lobi',NULL),(6,'Mayani',NULL),(6,'Bembeke',NULL),(6,'Mua',NULL),
(7,'Kasungu Boma',NULL),(7,'Santhe',NULL),(7,'Chamama',NULL),(7,'Chulu',NULL),(7,'Lisasadzi',NULL),
(8,'Nkhata Bay Boma',NULL),(8,'Chintheche',NULL),(8,'Usisya',NULL),(8,'Mzenga',NULL),
(9,'Rumphi Boma',NULL),(9,'Bolero',NULL),(9,'Livingstonia',NULL),(9,'Nchenachena',NULL),(9,'Mhuju',NULL),
(10,'Karonga Boma',NULL),(10,'Chilumba',NULL),(10,'Kaporo',NULL),(10,'Nyungwe',NULL),(10,'Iponga',NULL),
(11,'Thyolo Boma',NULL),(11,'Luchenza',NULL),(11,'Thekerani',NULL),(11,'Goliati',NULL),(11,'Khonjeni',NULL),
(12,'Chitipa Boma',NULL),(12,'Chisenga',NULL),(12,'Nthalire',NULL),(12,'Misuku',NULL),(12,'Mahowe',NULL),
(13,'Mangochi Boma',NULL),(13,'Namwera',NULL),(13,'Monkey Bay',NULL),(13,'Cape Maclear',NULL),(13,'Makanjira',NULL),
(14,'Chikwawa Boma',NULL),(14,'Nchalo',NULL),(14,'Bangula',NULL),(14,'Muona',NULL),(14,'Ngabu',NULL),
(15,'Zomba Central','Zomba City'),(15,'Chirunga','Zomba City'),(15,'Chambo','Zomba City'),(15,'Chinamwali','Zomba City'),(15,'Masongola','Zomba City'),(15,'Mtiya','Zomba City'),(15,'Sadzi','Zomba City'),(15,'Likangala','Zomba City'),(15,'Mpira','Zomba City'),(15,'Thondwe','Zomba City'),
(16,'Nkhotakota Boma',NULL),(16,'Benga',NULL),(16,'Bua',NULL),(16,'Ngala',NULL),(16,'Nsenjere',NULL),
(17,'Ntcheu Boma',NULL),(17,'Nsipe',NULL),(17,'Manjawira',NULL),(17,'Senzani',NULL),(17,'Kasinje',NULL),
(18,'Balaka Boma',NULL),(18,'Ulongwe',NULL),(18,'Kachenga',NULL),(18,'Chanthunya',NULL),(18,'Mbela',NULL),
(19,'Mulanje Boma',NULL),(19,'Lujeri',NULL),(19,'Milonde',NULL),(19,'Namulenga',NULL),(19,'Muloza',NULL),
(20,'Machinga Boma',NULL),(20,'Liwonde',NULL),(20,'Ntaja',NULL),(20,'Namasika',NULL),(20,'Chikwewo',NULL),
(21,'Phalombe Boma',NULL),(21,'Chiringa',NULL),(21,'Nkhulambe',NULL),(21,'Kasongo',NULL),(21,'Waruma',NULL),
(22,'Dowa Boma',NULL),(22,'Mponela',NULL),(22,'Madisi',NULL),(22,'Nambuma',NULL),(22,'Bowe',NULL),
(23,'Likoma Boma',NULL),(23,'Chizumulu',NULL),(23,'Mbamba',NULL),(23,'Likoma Island',NULL),
(24,'Salima Boma',NULL),(24,'Senga Bay',NULL),(24,'Chipoka',NULL),(24,'Pemba',NULL),(24,'Ntonga',NULL),
(25,'Chiradzulu Boma',NULL),(25,'Mbulumbuzi',NULL),(25,'Namadzi',NULL),(25,'Sedi',NULL),(25,'Thumbwe',NULL),
(26,'Mwanza Boma',NULL),(26,'Thambani',NULL),(26,'Masaula',NULL),(26,'Mwanza Border',NULL),
(27,'Mzimba Boma',NULL),(27,'Jenda',NULL),(27,'Ekwendeni',NULL),(27,'Mpherembe',NULL),(27,'Bwengu',NULL),(27,'Manyamula',NULL),
(28,'Neno Boma',NULL),(28,'Lisungwi',NULL),(28,'Dzaone',NULL),(28,'Kanono',NULL),(28,'Zalewa',NULL),
(29,'Nsanje Boma',NULL),(29,'Bangula',NULL),(29,'Thundu',NULL),(29,'Mankhokwe',NULL),(29,'Marka',NULL);

INSERT IGNORE INTO price_areas (district_id,name,city_name)
SELECT d.id, CONCAT(d.name,' Boma'), NULL
FROM districts d
LEFT JOIN price_areas pa ON pa.district_id=d.id
WHERE pa.id IS NULL;

SELECT COUNT(*) AS market_count FROM price_markets;
SELECT COUNT(*) AS area_count FROM price_areas;
SELECT d.id,d.name
FROM districts d
LEFT JOIN price_areas a ON a.district_id=d.id AND a.active=1
GROUP BY d.id,d.name
HAVING COUNT(a.id)=0;
