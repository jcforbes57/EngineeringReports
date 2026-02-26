-- Migration: add run_number to laps and update unique constraint
-- Run once against an existing jcforbes_mg1reports database.

USE jcforbes_mg1reports;

ALTER TABLE laps
  ADD COLUMN run_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER session_id;

ALTER TABLE laps DROP INDEX uq_lap;

ALTER TABLE laps
  ADD UNIQUE KEY uq_lap (session_id, run_number, lap_number);
