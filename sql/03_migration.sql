-- =====================================================
-- AP3 Agriculture Information System - 03_migration.sql
-- Brings your CURRENT Supabase database to the final 3NF design.
-- Safe to run more than once. Run in: SQL Editor > New query > paste > Run
-- =====================================================

BEGIN;

-- 1. Remove duplicate text columns (3NF).
--    These repeat what the lookup tables already store through the foreign keys:
--      farm.soil_type            -> soil_type via farm.soil_id
--      farm.irrigation_type      -> irrigation_type via farm.irrigation_id
--      crop.recommended_season   -> season via crop.season_id
ALTER TABLE farm DROP COLUMN IF EXISTS soil_type;
ALTER TABLE farm DROP COLUMN IF EXISTS irrigation_type;
ALTER TABLE crop DROP COLUMN IF EXISTS recommended_season;

-- 2. Remove the unused duplicate of harvest_remark.
--    (harvest.remark_id points to harvest_remark, not to remark_lookup.)
DROP TABLE IF EXISTS remark_lookup;

-- 3. The farmer form does not send date_registered, so give it a default.
ALTER TABLE farmer ALTER COLUMN date_registered SET DEFAULT CURRENT_DATE;

-- 4. Contact number format (Philippine mobile: 09XXXXXXXXX or +639XXXXXXXXX).
ALTER TABLE farmer DROP CONSTRAINT IF EXISTS chk_farmer_contact;
ALTER TABLE farmer ADD CONSTRAINT chk_farmer_contact
    CHECK (contact_number ~ '^(09|\+639)[0-9]{9}$');

-- 5. A harvest cannot happen before its planting date.
CREATE OR REPLACE FUNCTION check_harvest_date()
RETURNS TRIGGER AS $$
DECLARE
    p_date DATE;
BEGIN
    SELECT planting_date INTO p_date FROM planting WHERE planting_id = NEW.planting_id;
    IF NEW.harvest_date < p_date THEN
        RAISE EXCEPTION 'Harvest date cannot be earlier than the planting date';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_harvest_date_check ON harvest;
CREATE TRIGGER trg_harvest_date_check
    BEFORE INSERT OR UPDATE ON harvest
    FOR EACH ROW EXECUTE FUNCTION check_harvest_date();

-- 6. Row Level Security on the two lookup tables that were added later.
ALTER TABLE soil_type ENABLE ROW LEVEL SECURITY;
ALTER TABLE season    ENABLE ROW LEVEL SECURITY;

COMMIT;
