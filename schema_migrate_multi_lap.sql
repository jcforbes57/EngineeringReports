-- ─── Migration: add multi-lap filter options to warning_rules ────────────────
-- Run once against an existing database to add the two new lap_filter values.
--
-- 'multiple'             — rule only fires when the condition is met on more
--                          than one lap in the session (non-consecutive ok).
-- 'multiple_consecutive' — rule only fires when the condition is met on two or
--                          more consecutive laps (isolated single-lap hits are
--                          suppressed).

ALTER TABLE warning_rules
  MODIFY lap_filter
    ENUM('any','first','last','fast_lap','multiple','multiple_consecutive')
    NOT NULL DEFAULT 'any';
