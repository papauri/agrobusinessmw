-- AgroBusiness Malawi: complete price-reporting area coverage
--
-- The price form needs AREA choices for every administrative district/city,
-- not only the four cities that have detailed urban-area taxonomies.
-- This migration is additive and safe to run after
-- 20260815_price_location_taxonomy.sql.
--
-- Areas here are localities, district headquarters, trading centres and
-- recognised urban neighbourhoods. They are deliberately separate from
-- price_markets: a user can select a market, an area, both, or neither.

START TRANSACTION;

INSERT IGNORE INTO price_areas (district_id, name, city_name) VALUES
-- 1 Lilongwe: the detailed Area 1-58 taxonomy is already loaded by the base migration.
(1,'Nathenje',NULL),(1,'Lumbadzi',NULL),(1,'Kasiya',NULL),(1,'Nkhoma',NULL),(1,'Mitundu',NULL),(1,'Malingunde',NULL),(1,'Chiwamba',NULL),(1,'Kachere',NULL),

-- 2 Blantyre: detailed urban neighbourhoods are already loaded; add wider district localities.
(2,'Blantyre Central','Blantyre City'),(2,'Limbe','Blantyre City'),(2,'Machinjiri','Blantyre City'),(2,'Bangwe','Blantyre City'),(2,'Mbayani','Blantyre City'),(2,'Soche','Blantyre City'),(2,'Ndirande','Blantyre City'),(2,'Chileka','Blantyre City'),(2,'Lunzu','Blantyre City'),(2,'Chigumula','Blantyre City'),

-- 3 Mzuzu City.
(3,'Mzuzu Central','Mzuzu City'),(3,'Chibavi','Mzuzu City'),(3,'Chibanja','Mzuzu City'),(3,'Chiputula','Mzuzu City'),(3,'Katawa','Mzuzu City'),(3,'Luwinga','Mzuzu City'),(3,'Masasa','Mzuzu City'),(3,'Zolozolo','Mzuzu City'),(3,'Mchengautuwa','Mzuzu City'),(3,'Nkhorongo','Mzuzu City'),

-- 4 Mchinji.
(4,'Mchinji Boma',NULL),(4,'Mduwa',NULL),(4,'Kapiri',NULL),(4,'Mkanda',NULL),(4,'Mikundi',NULL),(4,'Guilleme',NULL),(4,'Chioshwe',NULL),(4,'Kawere',NULL),

-- 5 Ntchisi.
(5,'Ntchisi Boma',NULL),(5,'Malomo',NULL),(5,'Kamsonga',NULL),(5,'Kalira',NULL),(5,'Nkhata',NULL),(5,'Chikwatula',NULL),(5,'Liziwanga',NULL),

-- 6 Dedza.
(6,'Dedza Boma',NULL),(6,'Lobi',NULL),(6,'Mayani',NULL),(6,'Bembeke',NULL),(6,'Kaphuka',NULL),(6,'Mua',NULL),(6,'Kamtendere',NULL),(6,'Kachindamoto',NULL),

-- 7 Kasungu.
(7,'Kasungu Boma',NULL),(7,'Santhe',NULL),(7,'Chamama',NULL),(7,'Chulu',NULL),(7,'Lisasadzi',NULL),(7,'Kaomba',NULL),(7,'Lifupa',NULL),(7,'Kawamba',NULL),

-- 8 Nkhata Bay.
(8,'Nkhata Bay Boma',NULL),(8,'Chintheche',NULL),(8,'Chinthechi',NULL),(8,'Usisya',NULL),(8,'Chintheche North',NULL),(8,'Chintheche South',NULL),(8,'Chinthechi Trading Centre',NULL),(8,'Mzenga',NULL),

-- 9 Rumphi.
(9,'Rumphi Boma',NULL),(9,'Bolero',NULL),(9,'Livingstonia',NULL),(9,'Nchenachena',NULL),(9,'Mhuju',NULL),(9,'Mzokoto',NULL),(9,'Katowo',NULL),(9,'Chiweta',NULL),

-- 10 Karonga.
(10,'Karonga Boma',NULL),(10,'Chilumba',NULL),(10,'Kaporo',NULL),(10,'Nyungwe',NULL),(10,'Iponga',NULL),(10,'Wovwe',NULL),(10,'Hara',NULL),(10,'Fulirwa',NULL),

-- 11 Thyolo.
(11,'Thyolo Boma',NULL),(11,'Luchenza',NULL),(11,'Thekerani',NULL),(11,'Goliati',NULL),(11,'Chaone',NULL),(11,'Khonjeni',NULL),(11,'Makwasa',NULL),(11,'Didi',NULL),

-- 12 Chitipa.
(12,'Chitipa Boma',NULL),(12,'Chisenga',NULL),(12,'Nthalire',NULL),(12,'Misuku',NULL),(12,'Mahowe',NULL),(12,'Lufita',NULL),(12,'Kameme',NULL),(12,'Mwamkumbwa',NULL),

-- 13 Mangochi.
(13,'Mangochi Boma',NULL),(13,'Namwera',NULL),(13,'Monkey Bay',NULL),(13,'Cape Maclear',NULL),(13,'Makanjira',NULL),(13,'Katuli',NULL),(13,'Fort Maguire',NULL),(13,'Chiripa',NULL),(13,'Nankhwali',NULL),

-- 14 Chikwawa.
(14,'Chikwawa Boma',NULL),(14,'Nchalo',NULL),(14,'Bangula',NULL),(14,'Muona',NULL),(14,'Ngabu',NULL),(14,'Kakoma',NULL),(14,'Linga',NULL),(14,'Mbewe',NULL),

-- 15 Zomba City.
(15,'Zomba Central','Zomba City'),(15,'Chirunga','Zomba City'),(15,'Chambo','Zomba City'),(15,'Chinamwali','Zomba City'),(15,'Masongola','Zomba City'),(15,'Mtiya','Zomba City'),(15,'Sadzi','Zomba City'),(15,'Likangala','Zomba City'),(15,'Mpira','Zomba City'),(15,'Thondwe','Zomba City'),

-- 16 Nkhotakota.
(16,'Nkhotakota Boma',NULL),(16,'Benga',NULL),(16,'Bua',NULL),(16,'Ngala',NULL),(16,'Nsenjere',NULL),(16,'Kachulu',NULL),(16,'Dwambazi',NULL),(16,'Nkhunga',NULL),

-- 17 Ntcheu.
(17,'Ntcheu Boma',NULL),(17,'Nsipe',NULL),(17,'Manjawira',NULL),(17,'Senzani',NULL),(17,'Kasinje',NULL),(17,'Lizulu',NULL),(17,'Tsangano',NULL),(17,'Bilira',NULL),

-- 18 Balaka.
(18,'Balaka Boma',NULL),(18,'Ulongwe',NULL),(18,'Kachenga',NULL),(18,'Chanthunya',NULL),(18,'Ntombedzi',NULL),(18,'Phalula',NULL),(18,'Nselema',NULL),(18,'Mbela',NULL),

-- 19 Mulanje.
(19,'Mulanje Boma',NULL),(19,'Lujeri',NULL),(19,'Milonde',NULL),(19,'Namulenga',NULL),(19,'Muloza',NULL),(19,'Mwamadi',NULL),(19,'Chitakale',NULL),(19,'Nantombozi',NULL),

-- 20 Machinga.
(20,'Machinga Boma',NULL),(20,'Liwonde',NULL),(20,'Ntaja',NULL),(20,'Namasika',NULL),(20,'Chikwewo',NULL),(20,'Namanja',NULL),(20,'Nkwepele',NULL),(20,'Machinga Hills',NULL),

-- 21 Phalombe.
(21,'Phalombe Boma',NULL),(21,'Chiringa',NULL),(21,'Nkhulambe',NULL),(21,'Kasongo',NULL),(21,'Waruma',NULL),(21,'Kalinde',NULL),(21,'Tamani',NULL),(21,'Mikolongwe',NULL),

-- 22 Dowa.
(22,'Dowa Boma',NULL),(22,'Mponela',NULL),(22,'Madisi',NULL),(22,'Nambuma',NULL),(22,'Bowe',NULL),(22,'Mvera',NULL),(22,'Dzaleka',NULL),(22,'Chisepo',NULL),(22,'Nalunga',NULL),

-- 23 Likoma.
(23,'Likoma Boma',NULL),(23,'Chizumulu',NULL),(23,'Mbamba',NULL),(23,'Kachulu',NULL),(23,'Likoma Island',NULL),(23,'Nkhata Bay Landing',NULL),

-- 24 Salima.
(24,'Salima Boma',NULL),(24,'Senga Bay',NULL),(24,'Chipoka',NULL),(24,'Khombeza',NULL),(24,'Pemba',NULL),(24,'Ntonga',NULL),(24,'Chinguluwe',NULL),(24,'Nankhokwe',NULL),(24,'Kamuona',NULL),(24,'Dzongwe',NULL),

-- 25 Chiradzulu.
(25,'Chiradzulu Boma',NULL),(25,'Mbulumbuzi',NULL),(25,'Namadzi',NULL),(25,'Sedi',NULL),(25,'Thumbwe',NULL),(25,'Mpasa',NULL),(25,'Namitete',NULL),(25,'Chitera',NULL),

-- 26 Mwanza.
(26,'Mwanza Boma',NULL),(26,'Thambani',NULL),(26,'Masaula',NULL),(26,'Neno Road',NULL),(26,'Chitera',NULL),(26,'Kachindamoto',NULL),(26,'Mwanza Border',NULL),

-- 27 Mzimba District.
(27,'Mzimba Boma',NULL),(27,'Jenda',NULL),(27,'Ekwendeni',NULL),(27,'Mpherembe',NULL),(27,'Bwengu',NULL),(27,'Manyamula',NULL),(27,'Emfeni',NULL),(27,'Khosolo',NULL),(27,'Champhira',NULL),(27,'Malidade',NULL),(27,'Njuyu',NULL),(27,'Kamchocho',NULL),

-- 28 Neno.
(28,'Neno Boma',NULL),(28,'Lisungwi',NULL),(28,'Dzaone',NULL),(28,'Kanono',NULL),(28,'Zalewa',NULL),(28,'Matope',NULL),(28,'Chifunga',NULL),

-- 29 Nsanje.
(29,'Nsanje Boma',NULL),(29,'Bangula',NULL),(29,'Thundu',NULL),(29,'Mankhokwe',NULL),(29,'Magoti',NULL),(29,'Sorgin',NULL),(29,'Ndamera',NULL),(29,'Marka',NULL);

-- Guarantee that every district currently represented in districts also has
-- at least one area, even if a future district is added to the market list.
INSERT IGNORE INTO price_areas (district_id, name, city_name)
SELECT d.id,
       CONCAT(d.name, ' Boma'),
       NULL
FROM districts d
LEFT JOIN price_areas pa ON pa.district_id = d.id
WHERE pa.id IS NULL;

COMMIT;
