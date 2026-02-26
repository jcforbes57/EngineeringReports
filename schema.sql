-- MG1 Engineering Report System
-- Database schema

CREATE DATABASE IF NOT EXISTS jcforbes_mg1reports
	CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

USE jcforbes_mg1reports;

-- ─── sessions ────────────────────────────────────────────────────────────────
-- One row per unique car + session combo.
CREATE TABLE IF NOT EXISTS sessions (
	id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
	car_alias    VARCHAR(50)     NOT NULL COMMENT 'e.g. C001_ABCDEF',
	session_name VARCHAR(100)    NOT NULL,
	session_date DATE            NOT NULL,
	track        VARCHAR(100),
	driver       VARCHAR(100),
	created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,

	UNIQUE KEY uq_session (car_alias, session_name, session_date),
	KEY idx_date (session_date),
	KEY idx_car  (car_alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── laps ────────────────────────────────────────────────────────────────────
-- One row per lap. All telemetry stored as JSON keyed ChannelName_StatType.
-- run_number is 1-based within the session (normalised from WinTax Run_Info).
CREATE TABLE IF NOT EXISTS laps (
	id         INT UNSIGNED        AUTO_INCREMENT PRIMARY KEY,
	session_id INT UNSIGNED        NOT NULL,
	run_number TINYINT UNSIGNED    NOT NULL DEFAULT 1,
	lap_number SMALLINT UNSIGNED   NOT NULL,
	data       JSON,
	created_at TIMESTAMP           DEFAULT CURRENT_TIMESTAMP,

	FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
	UNIQUE KEY uq_lap    (session_id, run_number, lap_number),
	KEY        idx_sess  (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── warning_rules ───────────────────────────────────────────────────────────
-- Configurable rules that fire inline warnings on report pages.
CREATE TABLE IF NOT EXISTS warning_rules (
	id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	chart_section  VARCHAR(50)  NOT NULL    COMMENT 'tires | fuel | performance | engine | life',
	channel        VARCHAR(100) NOT NULL    COMMENT 'Channel name without stat suffix',
	stat           VARCHAR(20)  NOT NULL    COMMENT 'Avg | Change | End | Max | Min | Info',
	operator       ENUM('>','<','>=','<=','=','!=') NOT NULL,
	threshold      DECIMAL(15,4)            COMMENT 'Absolute threshold (used when compare_to=absolute)',
	compare_to     ENUM('absolute','fleet_avg','prev_session','other_channel') NOT NULL DEFAULT 'absolute',
	compare_target VARCHAR(100)             COMMENT 'Fleet avg offset or other channel key',
	lap_filter     ENUM('any','first','last') NOT NULL DEFAULT 'any',
	message        TEXT         NOT NULL,
	active         TINYINT(1)   NOT NULL DEFAULT 1,
	sort_order     SMALLINT     NOT NULL DEFAULT 0,
	created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

	KEY idx_section (chart_section),
	KEY idx_active  (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── uploads ─────────────────────────────────────────────────────────────────
-- Audit trail for every CSV file received.
CREATE TABLE IF NOT EXISTS uploads (
	id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	filename      VARCHAR(255) NOT NULL  COMMENT 'Stored filename on disk',
	original_name VARCHAR(255)           COMMENT 'Original filename from user',
	uploaded_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
	status        ENUM('pending','processing','complete','error') NOT NULL DEFAULT 'pending',
	session_id    INT UNSIGNED           COMMENT 'FK to sessions on success',
	row_count     INT UNSIGNED           COMMENT 'Lap rows imported',
	error_message TEXT,

	KEY idx_status (status),
	KEY idx_sess   (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Seed: built-in warning rules ────────────────────────────────────────────
INSERT INTO warning_rules
	(chart_section, channel, stat, operator, threshold, compare_to, compare_target, lap_filter, message, active, sort_order)
VALUES
	-- Fuel not reset check: lap 1 Change on FuelConsumptionL < 10 L
	('fuel', 'FuelConsumptionL', 'Change', '<', 10, 'absolute', NULL, 'first',
	 'Fuel consumption was not reset before this session!', 1, 10),

	-- Engine temp vs fleet average
	('engine', 'EngineWaterTemp', 'Avg', '>', 5, 'fleet_avg', 'EngineWaterTemp_Avg', 'any',
	 'Engine water temp is higher than fleet average — check radiator', 1, 20),

	-- Engine oil temp vs fleet average
	('engine', 'EngineOilTemperature', 'Avg', '>', 5, 'fleet_avg', 'EngineOilTemperature_Avg', 'any',
	 'Engine oil temp is higher than fleet average', 1, 30);
