<?php
// ─── MG1 Engineering Report System — db.php ───────────────────────────────
// Database connection and shared configuration.

define('MG1_VERSION', 'b1.000.02');

// ── Credentials — loaded from config.php ─────────────────────────────────────
$_cfg = __DIR__ . '/config.php';
if (!file_exists($_cfg)) {
	http_response_code(500);
	die('<pre>config.php not found. Copy config.sample.php to config.php and fill in your database credentials.</pre>');
}
require_once $_cfg;
unset($_cfg);

// ── Directory paths ───────────────────────────────────────────────────────────
define('MG1_ROOT',    __DIR__);
define('UPLOAD_DIR',  __DIR__ . '/uploads/');
define('EXPORT_DIR',  __DIR__ . '/exports/');

// ── PDO singleton ─────────────────────────────────────────────────────────────
function get_db(): PDO
{
	static $pdo = null;
	if ($pdo === null) {
		$dsn = sprintf(
			'mysql:host=%s;dbname=%s;charset=utf8mb4',
			DB_HOST,
			DB_NAME
		);
		try {
			$pdo = new PDO($dsn, DB_USER, DB_PASS, [
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]);
		} catch (\PDOException $e) {
			http_response_code(500);
			die('<pre>Database connection failed: ' . htmlspecialchars($e->getMessage()) . "\n\nCheck DB_HOST, DB_NAME, DB_USER, DB_PASS in config.php</pre>");
		}
	}
	return $pdo;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch all laps for a session, decoding JSON data column.
 * Returns array of ['lap_number' => int, ...channel_key => value...].
 */
function get_laps(int $session_id): array
{
	$db   = get_db();
	$stmt = $db->prepare(
		'SELECT run_number, lap_number, data FROM laps WHERE session_id = ? ORDER BY run_number ASC, lap_number ASC'
	);
	$stmt->execute([$session_id]);
	$rows = [];
	foreach ($stmt->fetchAll() as $row) {
		$data               = json_decode($row['data'], true) ?? [];
		$data['run_number'] = (int)$row['run_number'];
		$data['lap_number'] = (int)$row['lap_number'];
		$rows[]             = $data;
	}
	return $rows;
}

/**
 * Fetch a session row by id.
 */
function get_session(int $id): ?array
{
	$db   = get_db();
	$stmt = $db->prepare('SELECT * FROM sessions WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch();
	return $row ?: null;
}

/**
 * Fetch all sessions for a given session_name + session_date (fleet view).
 */
function get_fleet_sessions(string $session_name, string $session_date): array
{
	$db   = get_db();
	$stmt = $db->prepare(
		'SELECT * FROM sessions WHERE session_name = ? AND session_date = ? ORDER BY car_alias ASC'
	);
	$stmt->execute([$session_name, $session_date]);
	return $stmt->fetchAll();
}

/**
 * Fetch all sessions for a given car (trend view).
 */
function get_car_sessions(string $car_alias): array
{
	$db   = get_db();
	$stmt = $db->prepare(
		'SELECT * FROM sessions WHERE car_alias = ? ORDER BY session_date ASC, session_name ASC'
	);
	$stmt->execute([$car_alias]);
	return $stmt->fetchAll();
}

/**
 * Return active warning rules for a given chart section.
 */
function get_warning_rules(string $section): array
{
	$db   = get_db();
	$stmt = $db->prepare(
		'SELECT * FROM warning_rules WHERE chart_section = ? AND active = 1 ORDER BY sort_order ASC'
	);
	$stmt->execute([$section]);
	return $stmt->fetchAll();
}

/**
 * Compute per-lap fleet averages for a list of channel keys across sessions.
 * Returns ['lap_number' => ['channel_key' => avg_value, ...], ...]
 */
function compute_fleet_averages(array $session_ids, array $channel_keys): array
{
	if (empty($session_ids) || empty($channel_keys)) {
		return [];
	}
	$db = get_db();

	// Pull all laps for all sessions in this fleet
	$placeholders = implode(',', array_fill(0, count($session_ids), '?'));
	$stmt = $db->prepare(
		"SELECT run_number, lap_number, data FROM laps WHERE session_id IN ($placeholders) ORDER BY run_number ASC, lap_number ASC"
	);
	$stmt->execute($session_ids);

	// Accumulate sums per (run, lap) composite key per channel
	$sums   = [];  // ['{run}-{lap}' => [channel => [sum, count]]]
	foreach ($stmt->fetchAll() as $row) {
		$lk   = (int)$row['run_number'] . '-' . (int)$row['lap_number'];
		$data = json_decode($row['data'], true) ?? [];
		foreach ($channel_keys as $key) {
			if (isset($data[$key]) && is_numeric($data[$key])) {
				$sums[$lk][$key][0] = ($sums[$lk][$key][0] ?? 0) + (float)$data[$key];
				$sums[$lk][$key][1] = ($sums[$lk][$key][1] ?? 0) + 1;
			}
		}
	}

	// Convert to averages
	$avgs = [];
	foreach ($sums as $lk => $channels) {
		foreach ($channels as $key => [$sum, $count]) {
			$avgs[$lk][$key] = $count > 0 ? $sum / $count : null;
		}
	}
	return $avgs;
}

/**
 * Evaluate warning rules for a set of laps against optional fleet averages.
 * Returns array of ['rule' => $rule, 'lap' => $lap_number, 'actual' => $value].
 *
 * $fast_lap_idx: 0-based index into $laps of the fastest lap (for 'fast_lap' filter).
 *
 * lap_filter modes:
 *   any                  — every lap is checked independently
 *   first / last         — only that single lap is checked
 *   fast_lap             — only the fastest lap is checked
 *   multiple             — all laps are checked; the rule only fires if 2+ laps hit
 *   multiple_consecutive — all laps are checked; only runs of 2+ consecutive hits
 *                          are kept (isolated single-lap hits are suppressed)
 */
function evaluate_warnings(array $rules, array $laps, array $fleet_avgs = [], ?int $fast_lap_idx = null): array
{
	$triggered = [];

	// laps array is sorted by run_number, lap_number
	$first_lap = !empty($laps) ? $laps[0]                : [];
	$last_lap  = !empty($laps) ? $laps[count($laps) - 1] : [];
	$fast_lap  = ($fast_lap_idx !== null && isset($laps[$fast_lap_idx])) ? $laps[$fast_lap_idx] : null;

	foreach ($rules as $rule) {
		$key    = $rule['channel'] . '_' . $rule['stat'];
		$op     = $rule['operator'];
		$filter = $rule['lap_filter'];

		// Collect all hits for this rule (with their sequential $laps index so
		// the consecutive-run detector has something to work with).
		$rule_hits = [];

		foreach ($laps as $seq_idx => $lap) {
			$run_num = $lap['run_number'] ?? 1;
			$lap_num = $lap['lap_number'];
			$lk      = $run_num . '-' . $lap_num;

			// Apply single-lap filters (skip irrelevant laps early)
			if ($filter === 'first' && ($run_num !== ($first_lap['run_number'] ?? 1) || $lap_num !== ($first_lap['lap_number'] ?? 1))) continue;
			if ($filter === 'last'  && ($run_num !== ($last_lap['run_number']  ?? 1) || $lap_num !== ($last_lap['lap_number']  ?? 1))) continue;
			if ($filter === 'fast_lap') {
				if ($fast_lap === null) continue;
				if ($run_num !== ($fast_lap['run_number'] ?? 0) || $lap_num !== ($fast_lap['lap_number'] ?? 0)) continue;
			}

			if (!array_key_exists($key, $lap) || $lap[$key] === null) continue;
			$actual = (float)$lap[$key];

			// Determine threshold
			if ($rule['compare_to'] === 'absolute') {
				$threshold = (float)$rule['threshold'];
			} elseif ($rule['compare_to'] === 'fleet_avg') {
				$target = $rule['compare_target'] ?? $key;
				if (!isset($fleet_avgs[$lk][$target])) continue;
				$threshold = (float)$fleet_avgs[$lk][$target] + (float)$rule['threshold'];
			} else {
				continue; // other compare types not yet implemented
			}

			$hit = match ($op) {
				'>'  => $actual >  $threshold,
				'<'  => $actual <  $threshold,
				'>=' => $actual >= $threshold,
				'<=' => $actual <= $threshold,
				'='  => $actual == $threshold,
				'!=' => $actual != $threshold,
				default => false,
			};

			if ($hit) {
				$rule_hits[] = [
					'_seq'   => $seq_idx,   // sequential index within $laps — used for consecutive check
					'rule'   => $rule,
					'run'    => $run_num,
					'lap'    => $lap_num,
					'actual' => $actual,
				];
			}
		}

		if (empty($rule_hits)) continue;

		// Post-filter for multi-lap modes
		if ($filter === 'multiple') {
			// Fire only when 2 or more laps triggered
			if (count($rule_hits) < 2) continue;
			foreach ($rule_hits as $h) {
				unset($h['_seq']);
				$triggered[] = $h;
			}
		} elseif ($filter === 'multiple_consecutive') {
			// Keep only hits that form part of a run of 2+ consecutive laps.
			// Sort by sequential index (should already be sorted, but be safe).
			usort($rule_hits, fn($a, $b) => $a['_seq'] <=> $b['_seq']);

			// Walk through building runs of consecutive _seq values
			$n     = count($rule_hits);
			$start = 0;
			while ($start < $n) {
				$end = $start;
				while ($end + 1 < $n && $rule_hits[$end + 1]['_seq'] === $rule_hits[$end]['_seq'] + 1) {
					$end++;
				}
				$run_length = $end - $start + 1;
				if ($run_length >= 2) {
					for ($j = $start; $j <= $end; $j++) {
						$h = $rule_hits[$j];
						unset($h['_seq']);
						$triggered[] = $h;
					}
				}
				$start = $end + 1;
			}
		} else {
			// any / first / last / fast_lap — emit hits directly
			foreach ($rule_hits as $h) {
				unset($h['_seq']);
				$triggered[] = $h;
			}
		}
	}

	return $triggered;
}
