-- Urban market names used by farmers in the main cities.
-- Run after 20260815_price_location_taxonomy.sql.
START TRANSACTION;
INSERT IGNORE INTO price_markets (district_id,name) VALUES
(1,'Area 2 Market'),(1,'Old Town Market'),(1,'Kawale Market'),(1,'Chipasula Market'),(1,'Kanengo Market'),(1,'Area 25 Market'),(1,'Lumbadzi Market'),(1,'Mitundu Market'),(1,'Nsalu Market'),(1,'Mponela Market'),
(2,'Blantyre Market'),(2,'Limbe Market'),(2,'Ndirande Market'),(2,'Bangwe Market'),(2,'Chigumula Market'),(2,'Chirimba Market'),(2,'Likhubula Market'),(2,'Kachere Market'),(2,'Manase Market'),(2,'Misesa Market'),(2,'Mpingwe Market'),(2,'Mulunguzi Market'),(2,'Musa Magasa Market'),
(3,'Mzuzu Market'),(3,'Chibavi Market'),(3,'Katoto Market'),(3,'Katawa Market'),
(15,'Zomba Central Market'),(15,'Zomba Flea Market'),(15,'Chinamwali Market'),(15,'Ngongomwa/Mpondabwino Market'),(15,'Sadzi Market');
INSERT IGNORE INTO price_areas (district_id,name,city_name) VALUES
(2,'Soche','Blantyre City'),(2,'Limbe','Blantyre City'),(2,'Chichiri','Blantyre City'),(2,'Kachere','Blantyre City'),(2,'Bangwe','Blantyre City'),(2,'Ndirande Matope','Blantyre City'),(2,'Maselema','Blantyre City'),(2,'Nkolokoti','Blantyre City'),
(3,'Katoto','Mzuzu City'),(3,'Chibanja','Mzuzu City'),(3,'Chiputula','Mzuzu City'),(3,'Chibavi','Mzuzu City'),
(15,'Zomba Central','Zomba City'),(15,'Masongola','Zomba City'),(15,'Chirunga','Zomba City'),(15,'Chambo','Zomba City'),(15,'Chinamwali','Zomba City'),(15,'Mbedza','Zomba City'),(15,'Mtiya','Zomba City'),(15,'Sadzi','Zomba City'),(15,'Likangala','Zomba City'),(15,'Mpira','Zomba City'),(15,'Ntiya','Zomba City');
COMMIT;
