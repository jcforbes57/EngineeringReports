<?php
// ─── MG1 Engineering Report System — parse_csv.php ───────────────────────────
// Parses a WinTax "Run Summary" CSV export.
//
// CSV structure (1-indexed rows as WinTax exports them):
//   Row 1  — Metadata header keys   : Track, Session, Driver, Device, Ses.Date
//   Row 2  — Metadata values
//   Row 3  — Blank
//   Row 4  — Channel names          : LogCurrentLap, FL_WS_TEMPERATURE, …
//   Row 5  — Stat types             : Avg, Change, End, Max, Min, Info, …
//   Row 6+ — Lap data
//
// Column keys are ChannelName_StatType.
// Duplicate channel names (e.g. VehicleSpeedVSOSig appearing as Max AND Min)
// are disambiguated because the stat suffix differs.
//
// Three columns are always appended (tagged Info in row 5):
//   CarAlias, SessionName, SessionDate
//
// Returns array: [
//   'metadata' => ['Track' => …, 'Session' => …, …],
//   'columns'  => ['ChannelName_Stat', …],          // all valid keys in order
//   'laps'     => [['ColKey' => value, …], …],
//   'lap_col'  => 'LogCurrentLap_Info',              // key used for lap numbers
// ]

/**
 * @param string $filepath  Absolute path to the CSV file.
 * @return array            Parsed result (see module docblock).
 * @throws \RuntimeException on file or format errors.
 */
function parse_wintax_csv(string $filepath): array
{
	// ── 1. Read all rows ──────────────────────────────────────────────────────
	$fh = @fopen($filepath, 'r');
	if ($fh === false) {
		throw new \RuntimeException("Cannot open file: $filepath");
	}

	$rows = [];
	while (($row = fgetcsv($fh, 0, ',')) !== false) {
		$rows[] = $row;
	}
	fclose($fh);

	if (count($rows) < 6) {
		throw new \RuntimeException(
			'CSV too short: expected at least 6 rows, got ' . count($rows)
		);
	}

	// ── 2. Metadata (rows 1–2, 0-indexed 0–1) ────────────────────────────────
	$meta_header = array_map('trim', $rows[0]);
	$meta_values = array_map('trim', $rows[1]);
	// Row index 2 is blank — skip.

	$metadata = [];
	foreach ($meta_header as $i => $key) {
		if ($key !== '') {
			$metadata[$key] = $meta_values[$i] ?? '';
		}
	}

	// ── 3. Build column key map (rows 4–5, 0-indexed 3–4) ────────────────────
	$chan_row = array_map('trim', $rows[3]);
	$stat_row = array_map('trim', $rows[4]);

	$num_cols  = max(count($chan_row), count($stat_row));
	$col_keys  = [];  // index → 'ChannelName_Stat' or null

	for ($i = 0; $i < $num_cols; $i++) {
		$chan = $chan_row[$i] ?? '';
		$stat = $stat_row[$i] ?? '';

		// Skip columns where both channel and stat are empty
		if ($chan === '' && $stat === '') {
			$col_keys[$i] = null;
			continue;
		}

		$col_keys[$i] = $chan . '_' . $stat;
	}

	// Collect distinct valid keys for the caller
	$valid_columns = array_values(array_filter($col_keys, fn($k) => $k !== null));

	// ── 4. Detect lap-number column ───────────────────────────────────────────
	// WinTax typically places LogCurrentLap as the first channel with stat Info.
	$lap_col = null;
	foreach ($col_keys as $key) {
		if ($key !== null && stripos($key, 'LogCurrentLap') !== false) {
			$lap_col = $key;
			break;
		}
	}

	// ── 5. Parse lap rows (row 6+, 0-indexed 5+) ─────────────────────────────
	$laps = [];
	for ($r = 5, $auto_lap = 0; $r < count($rows); $r++) {
		$row = $rows[$r];

		// Skip rows that are entirely empty
		$non_empty = array_filter($row, static fn($v) => trim($v) !== '');
		if (empty($non_empty)) {
			continue;
		}

		$lap = [];
		foreach ($col_keys as $i => $key) {
			if ($key === null) {
				continue;
			}
			$raw       = trim($row[$i] ?? '');
			$lap[$key] = ($raw === '') ? null : $raw;
		}

		$laps[] = $lap;
		$auto_lap++;
	}

	if (empty($laps)) {
		throw new \RuntimeException('No lap rows found in CSV');
	}

	return [
		'metadata' => $metadata,
		'columns'  => $valid_columns,
		'laps'     => $laps,
		'lap_col'  => $lap_col,
	];
}

/**
 * Extract session-level metadata from a parsed result.
 *
 * Priority for session identity fields:
 *   1. Appended columns in the first lap row (CarAlias_Info, SessionName_Info, SessionDate_Info)
 *   2. Metadata header values (Track, Driver, Session, Ses.Date)
 *
 * @param array $parsed  Return value of parse_wintax_csv().
 * @return array {car_alias, session_name, session_date, track, driver}
 */
function extract_session_meta(array $parsed): array
{
	$meta      = $parsed['metadata'];
	$first_lap = $parsed['laps'][0] ?? [];

	// ── Car alias ─────────────────────────────────────────────────────────────
	$car_alias = $first_lap['CarAlias_Info']
		?? $first_lap['CarAlias_Avg']
		?? null;

	// ── Session name ──────────────────────────────────────────────────────────
	$session_name = $first_lap['SessionName_Info']
		?? $meta['Session']
		?? null;

	// ── Session date ──────────────────────────────────────────────────────────
	$session_date_raw = $first_lap['SessionDate_Info']
		?? $meta['Ses.Date']
		?? null;

	$session_date = null;
	if ($session_date_raw) {
		$ts = strtotime((string)$session_date_raw);
		if ($ts !== false) {
			$session_date = date('Y-m-d', $ts);
		}
	}

	// ── Track & driver ────────────────────────────────────────────────────────
	$track  = trim($meta['Track']  ?? '');
	$driver = trim($meta['Driver'] ?? '');

	return [
		'car_alias'    => $car_alias  ? trim((string)$car_alias)    : null,
		'session_name' => $session_name ? trim((string)$session_name) : null,
		'session_date' => $session_date,
		'track'        => $track  !== '' ? $track  : null,
		'driver'       => $driver !== '' ? $driver : null,
	];
}

/**
 * Resolve the lap number for a given lap data row.
 * Uses the lap-number column detected during parsing, falling back to the
 * row's position index.
 *
 * @param array  $lap_data  A single lap array from parse_wintax_csv().
 * @param ?string $lap_col  The key of the lap-number column.
 * @param int    $fallback  Zero-based row index used when $lap_col is absent.
 * @return int
 */
function resolve_lap_number(array $lap_data, ?string $lap_col, int $fallback): int
{
	if ($lap_col !== null && isset($lap_data[$lap_col])) {
		$v = $lap_data[$lap_col];
		if (is_numeric($v)) {
			return (int)$v;
		}
	}
	return $fallback;
}
