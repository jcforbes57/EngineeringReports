-- MG1 Engineering Report System
-- Migration: add 'fast_lap' to warning_rules.lap_filter ENUM
-- Run once against jcforbes_mg1reports

USE jcforbes_mg1reports;

ALTER TABLE warning_rules
	MODIFY lap_filter ENUM('any','first','last','fast_lap') NOT NULL DEFAULT 'any';
