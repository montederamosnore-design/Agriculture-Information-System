-- =====================================================
-- AP3 Agriculture Information System - Sample Data (02_seed.sql)
-- Data from the instructor's document (15 farmers, 15 farms, 10 crops).
-- Run AFTER 01_schema.sql. (03_migration.sql is only for upgrading the old database.)
-- Clears the old sample data in the 5 core tables and restarts their IDs.
-- The users table (admin/staff accounts) is NOT touched.
-- =====================================================

TRUNCATE TABLE harvest, planting, farm, crop, farmer RESTART IDENTITY CASCADE;

-- ---------- FARMERS (15) ----------
INSERT INTO farmer (first_name, last_name, contact_number, address, date_registered) VALUES
    ('Juan', 'Dela Cruz', '09171234567', 'Cebu City', '2026-10-26'),
    ('Maria', 'Santos', '09181234567', 'Carcar City, Cebu', '2026-10-26'),
    ('Pedro', 'Reyes', '09191234567', 'Danao City, Cebu', '2026-10-26'),
    ('Ana', 'Garcia', '09201234567', 'Argao, Cebu', '2026-10-26'),
    ('Jose', 'Mendoza', '09211234567', 'Bogo City, Cebu', '2026-10-26'),
    ('Ramon', 'Flores', '09221234567', 'Toledo City, Cebu', '2026-10-26'),
    ('Elena', 'Cruz', '09231234567', 'Naga City, Cebu', '2026-10-26'),
    ('Antonio', 'Lim', '09241234567', 'Minglanilla, Cebu', '2026-10-26'),
    ('Rosa', 'Fernandez', '09251234567', 'Liloan, Cebu', '2026-10-26'),
    ('Mario', 'Villanueva', '09261234567', 'Compostela, Cebu', '2026-10-26'),
    ('Teresa', 'Navarro', '09271234567', 'Talisay City, Cebu', '2026-10-26'),
    ('Carlos', 'Ramos', '09281234567', 'Consolacion, Cebu', '2026-10-26'),
    ('Liza', 'Torres', '09291234567', 'Dalaguete, Cebu', '2026-10-26'),
    ('Roberto', 'Castillo', '09301234567', 'Barili, Cebu', '2026-10-26'),
    ('Susan', 'Aquino', '09311234567', 'Sibonga, Cebu', '2026-10-26');

-- ---------- FARMS (15) ----------
-- soil_id: 1 Clay, 2 Clay Loam, 3 Loam, 4 Sandy Loam | irrigation_id: 1 Rainfed, 2 Irrigated Canal, 3 Drip Irrigation
INSERT INTO farm (farmer_id, farm_name, location, area_hectares, soil_id, irrigation_id) VALUES
    (1, 'Dela Cruz Farm', 'Cebu City', 2.50, 3, 1),
    (2, 'Santos Rice Farm', 'Carcar City, Cebu', 4.20, 2, 2),
    (3, 'Reyes Agricultural Farm', 'Danao City, Cebu', 3.80, 4, 1),
    (4, 'Garcia Vegetable Farm', 'Argao, Cebu', 1.75, 3, 3),
    (5, 'Mendoza Farm', 'Bogo City, Cebu', 5.00, 1, 2),
    (6, 'Flores Corn Farm', 'Toledo City, Cebu', 3.40, 4, 1),
    (7, 'Cruz Family Farm', 'Naga City, Cebu', 2.80, 3, 2),
    (8, 'Lim Agricultural Farm', 'Minglanilla, Cebu', 2.25, 2, 3),
    (9, 'Fernandez Farm', 'Liloan, Cebu', 3.10, 4, 1),
    (10, 'Villanueva Green Farm', 'Compostela, Cebu', 4.50, 3, 2),
    (11, 'Navarro Rice Field', 'Talisay City, Cebu', 3.75, 1, 2),
    (12, 'Ramos Farm', 'Consolacion, Cebu', 2.60, 3, 1),
    (13, 'Torres Vegetable Farm', 'Dalaguete, Cebu', 1.80, 4, 3),
    (14, 'Castillo Agricultural Farm', 'Barili, Cebu', 5.50, 2, 2),
    (15, 'Aquino Farm', 'Sibonga, Cebu', 3.20, 3, 1);

-- ---------- CROPS (10) ----------
-- season_id: 1 Dry Season, 2 Wet Season, 3 Year Round
INSERT INTO crop (crop_name, variety, category_id, growing_period_days, season_id) VALUES
    ('Rice', 'NSIC Rc 222', 1, 115, 2),
    ('Corn', 'Yellow Corn', 1, 100, 1),
    ('Tomato', 'Diamante Max', 2, 90, 1),
    ('Eggplant', 'Morena', 2, 100, 3),
    ('Banana', 'Lakatan', 3, 300, 3),
    ('Mango', 'Carabao', 3, 150, 3),
    ('Peanut', 'BPI Pn 9', 5, 110, 3),
    ('Cassava', 'Golden Yellow', 4, 240, 3),
    ('Sweet Potato', 'NSIC Sp 30', 4, 120, 3),
    ('Watermelon', 'Sweet 16', 3, 85, 1);

-- ---------- PLANTINGS (28) ----------
-- status_id: 2 Growing, 4 Harvested
-- expected_harvest_date = planting_date + crop growing_period_days
INSERT INTO planting (farm_id, crop_id, planting_date, expected_harvest_date, area_planted, seed_quantity, status_id) VALUES
    (1, 1, '2026-01-10', '2026-05-05', 0.50, 10.00, 4),
    (2, 2, '2026-01-13', '2026-04-23', 0.60, 15.00, 4),
    (3, 3, '2026-01-16', '2026-04-16', 0.70, 20.00, 4),
    (4, 4, '2026-01-19', '2026-04-29', 0.80, 25.00, 4),
    (5, 5, '2026-01-22', '2026-11-18', 0.90, 30.00, 4),
    (6, 6, '2026-01-25', '2026-06-24', 1.00, 35.00, 4),
    (7, 7, '2026-01-28', '2026-05-18', 1.10, 40.00, 4),
    (8, 8, '2026-01-31', '2026-09-28', 1.20, 45.00, 4),
    (9, 9, '2026-02-03', '2026-06-03', 1.30, 10.00, 4),
    (10, 10, '2026-02-06', '2026-05-02', 1.40, 15.00, 4),
    (11, 1, '2026-02-09', '2026-06-04', 0.50, 20.00, 4),
    (12, 2, '2026-02-12', '2026-05-23', 0.60, 25.00, 4),
    (13, 3, '2026-02-15', '2026-05-16', 0.70, 30.00, 4),
    (14, 4, '2026-02-18', '2026-05-29', 0.80, 35.00, 4),
    (15, 5, '2026-02-21', '2026-12-18', 0.90, 40.00, 4),
    (1, 6, '2026-02-24', '2026-07-24', 1.00, 45.00, 4),
    (2, 7, '2026-02-27', '2026-06-17', 1.10, 10.00, 4),
    (3, 8, '2026-03-02', '2026-10-28', 1.20, 15.00, 4),
    (4, 9, '2026-03-05', '2026-07-03', 1.30, 20.00, 4),
    (5, 10, '2026-03-08', '2026-06-01', 1.40, 25.00, 4),
    (6, 1, '2026-03-11', '2026-07-04', 0.50, 30.00, 2),
    (7, 2, '2026-03-14', '2026-06-22', 0.60, 35.00, 2),
    (8, 3, '2026-03-17', '2026-06-15', 0.70, 40.00, 2),
    (9, 4, '2026-03-20', '2026-06-28', 0.80, 45.00, 2),
    (10, 5, '2026-03-23', '2027-01-17', 0.90, 10.00, 2),
    (11, 6, '2026-03-26', '2026-08-23', 1.00, 15.00, 2),
    (12, 7, '2026-03-29', '2026-07-17', 1.10, 20.00, 2),
    (13, 8, '2026-04-01', '2026-11-27', 1.20, 25.00, 2);

-- ---------- HARVESTS (20) ----------
-- grade_id: 1 A, 2 B, 3 C | remark_id: 1 Good Harvest, 2 Acceptable Quality
-- (document's 'High quality harvest' and 'Good harvest' both map to 1)
INSERT INTO harvest (planting_id, harvest_date, quantity_kg, selling_price_per_kg, grade_id, remark_id) VALUES
    (1, '2026-05-07', 900.00, 22.00, 1, 1),
    (2, '2026-04-25', 1025.00, 25.50, 2, 1),
    (3, '2026-04-18', 1150.00, 29.00, 3, 2),
    (4, '2026-05-01', 1275.00, 32.50, 1, 1),
    (5, '2026-11-10', 1400.00, 36.00, 2, 1),
    (6, '2026-06-26', 1525.00, 39.50, 3, 2),
    (7, '2026-05-20', 1650.00, 43.00, 1, 1),
    (8, '2026-09-30', 1775.00, 46.50, 2, 1),
    (9, '2026-06-05', 1900.00, 22.00, 3, 2),
    (10, '2026-05-04', 2025.00, 25.50, 1, 1),
    (11, '2026-06-06', 2150.00, 29.00, 2, 1),
    (12, '2026-05-25', 2275.00, 32.50, 3, 2),
    (13, '2026-05-18', 2400.00, 36.00, 1, 1),
    (14, '2026-05-31', 2525.00, 39.50, 2, 1),
    (15, '2026-11-12', 2650.00, 43.00, 3, 2),
    (16, '2026-07-26', 2775.00, 46.50, 1, 1),
    (17, '2026-06-19', 2900.00, 22.00, 2, 1),
    (18, '2026-10-30', 3025.00, 25.50, 3, 2),
    (19, '2026-07-05', 3150.00, 29.00, 1, 1),
    (20, '2026-06-03', 3275.00, 32.50, 2, 1);