-- =====================================================
-- AP3 Agriculture Information System - Schema (3NF)
-- Run in Supabase: SQL Editor > New query > paste > Run
-- =====================================================

-- ---------- 1. LOOKUP / REFERENCE TABLES ----------

CREATE TABLE user_role (
    role_id          SMALLINT PRIMARY KEY,
    role_description VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE irrigation_type (
    irrigation_id          SMALLINT PRIMARY KEY,
    irrigation_description VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE crop_category (
    category_id          SMALLINT PRIMARY KEY,
    category_description VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE planting_status (
    status_id          SMALLINT PRIMARY KEY,
    status_description VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE quality_grade (
    grade_id          SMALLINT PRIMARY KEY,
    grade_description VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE harvest_remark (
    remark_id          SMALLINT PRIMARY KEY,
    remark_description VARCHAR(50) NOT NULL UNIQUE
);

-- Reference data
INSERT INTO user_role (role_id, role_description) VALUES
    (1, 'Administrator'), (2, 'Staff');

INSERT INTO irrigation_type (irrigation_id, irrigation_description) VALUES
    (1, 'Rainfed'), (2, 'Irrigated Canal'), (3, 'Drip Irrigation'),
    (4, 'Sprinkler'), (5, 'Pump Irrigation');

INSERT INTO crop_category (category_id, category_description) VALUES
    (1, 'Cereal'), (2, 'Vegetable'), (3, 'Fruit'),
    (4, 'Root Crop'), (5, 'Legume'), (6, 'Cash Crop');

INSERT INTO planting_status (status_id, status_description) VALUES
    (1, 'Planted'), (2, 'Growing'), (3, 'Ready for Harvest'),
    (4, 'Harvested'), (5, 'Failed');

INSERT INTO quality_grade (grade_id, grade_description) VALUES
    (1, 'Grade A'), (2, 'Grade B'), (3, 'Grade C'), (4, 'Reject');

INSERT INTO harvest_remark (remark_id, remark_description) VALUES
    (1, 'Good Harvest'), (2, 'Acceptable Quality'), (3, 'Low Yield'),
    (4, 'Poor Quality'), (5, 'Damaged Harvest');

-- ---------- 2. CORE TABLES ----------

CREATE TABLE users (
    user_id       INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,          -- use password_hash() in PHP
    full_name     VARCHAR(100) NOT NULL,
    role_id       SMALLINT     NOT NULL REFERENCES user_role(role_id),
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE farmer (
    farmer_id  INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    first_name VARCHAR(50)  NOT NULL,
    last_name  VARCHAR(50)  NOT NULL,
    contact_no VARCHAR(20)  NOT NULL,
    address    VARCHAR(200) NOT NULL,
    created_by INT REFERENCES users(user_id),
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT uq_farmer UNIQUE (first_name, last_name, contact_no)
);

CREATE TABLE farm (
    farm_id        INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    farmer_id      INT           NOT NULL REFERENCES farmer(farmer_id),
    farm_name      VARCHAR(100)  NOT NULL,
    location       VARCHAR(200)  NOT NULL,
    area_hectares  NUMERIC(8,2)  NOT NULL CHECK (area_hectares > 0),
    irrigation_id  SMALLINT      NOT NULL REFERENCES irrigation_type(irrigation_id),
    created_by     INT REFERENCES users(user_id),
    created_at     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT uq_farm UNIQUE (farmer_id, farm_name)
);

CREATE TABLE crop (
    crop_id              INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    crop_name            VARCHAR(100) NOT NULL UNIQUE,
    category_id          SMALLINT     NOT NULL REFERENCES crop_category(category_id),
    growth_duration_days INT          NOT NULL CHECK (growth_duration_days > 0),
    created_by           INT REFERENCES users(user_id),
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE planting (
    planting_id            INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    farm_id                INT          NOT NULL REFERENCES farm(farm_id),
    crop_id                INT          NOT NULL REFERENCES crop(crop_id),
    planting_date          DATE         NOT NULL,
    expected_harvest_date  DATE         NOT NULL,
    area_planted_hectares  NUMERIC(8,2) NOT NULL CHECK (area_planted_hectares > 0),
    status_id              SMALLINT     NOT NULL REFERENCES planting_status(status_id),
    created_by             INT REFERENCES users(user_id),
    created_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT chk_planting_dates CHECK (expected_harvest_date >= planting_date)
);

CREATE TABLE harvest (
    harvest_id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    planting_id        INT          NOT NULL REFERENCES planting(planting_id),
    harvest_date       DATE         NOT NULL,
    quantity_kg        NUMERIC(10,2) NOT NULL CHECK (quantity_kg > 0),
    selling_price_per_kg NUMERIC(10,2) NOT NULL CHECK (selling_price_per_kg >= 0),
    grade_id           SMALLINT     NOT NULL REFERENCES quality_grade(grade_id),
    remark_id          SMALLINT     NOT NULL REFERENCES harvest_remark(remark_id),
    created_by         INT REFERENCES users(user_id),
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ---------- 3. INDEXES (faster search, joins, filters) ----------

CREATE INDEX idx_farm_farmer      ON farm(farmer_id);
CREATE INDEX idx_planting_farm    ON planting(farm_id);
CREATE INDEX idx_planting_crop    ON planting(crop_id);
CREATE INDEX idx_planting_status  ON planting(status_id);
CREATE INDEX idx_harvest_planting ON harvest(planting_id);
CREATE INDEX idx_harvest_date     ON harvest(harvest_date);

-- ---------- 4. AUTO-UPDATE updated_at ----------

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_users_updated    BEFORE UPDATE ON users    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_farmer_updated   BEFORE UPDATE ON farmer   FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_farm_updated     BEFORE UPDATE ON farm     FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_crop_updated     BEFORE UPDATE ON crop     FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_planting_updated BEFORE UPDATE ON planting FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_harvest_updated  BEFORE UPDATE ON harvest  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------- 5. SECURITY ----------
-- Blocks Supabase's public API from reading these tables.
-- Your PHP app connects directly as the database owner, so it is not affected.

ALTER TABLE user_role       ENABLE ROW LEVEL SECURITY;
ALTER TABLE irrigation_type ENABLE ROW LEVEL SECURITY;
ALTER TABLE crop_category   ENABLE ROW LEVEL SECURITY;
ALTER TABLE planting_status ENABLE ROW LEVEL SECURITY;
ALTER TABLE quality_grade   ENABLE ROW LEVEL SECURITY;
ALTER TABLE harvest_remark  ENABLE ROW LEVEL SECURITY;
ALTER TABLE users           ENABLE ROW LEVEL SECURITY;
ALTER TABLE farmer          ENABLE ROW LEVEL SECURITY;
ALTER TABLE farm            ENABLE ROW LEVEL SECURITY;
ALTER TABLE crop            ENABLE ROW LEVEL SECURITY;
ALTER TABLE planting        ENABLE ROW LEVEL SECURITY;
ALTER TABLE harvest         ENABLE ROW LEVEL SECURITY;