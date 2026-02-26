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
	$stmt = $db->prepare('SELECT lap_number, data FROM laps WHERE session_id = ? ORDER BY lap_number ASC');
	$stmt->execute([$session_id]);
	$rows = [];
	foreach ($stmt->fetchAll() as $row) {
		$data         = json_decode($row['data'], true) ?? [];
		$data['lap_number'] = (int)$row['lap_number'];
		$rows[]       = $data;
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
		"SELECT lap_number, data FROM laps WHERE session_id IN ($placeholders) ORDER BY lap_number ASC"
	);
	$stmt->execute($session_ids);

	// Accumulate sums per lap per channel
	$sums   = [];  // [lap => [channel => [sum, count]]]
	foreach ($stmt->fetchAll() as $row) {
		$lap  = (int)$row['lap_number'];
		$data = json_decode($row['data'], true) ?? [];
		foreach ($channel_keys as $key) {
			if (isset($data[$key]) && is_numeric($data[$key])) {
				$sums[$lap][$key][0] = ($sums[$lap][$key][0] ?? 0) + (float)$data[$key];
				$sums[$lap][$key][1] = ($sums[$lap][$key][1] ?? 0) + 1;
			}
		}
	}

	// Convert to averages
	$avgs = [];
	foreach ($sums as $lap => $channels) {
		foreach ($channels as $key => [$sum, $count]) {
			$avgs[$lap][$key] = $count > 0 ? $sum / $count : null;
		}
	}
	return $avgs;
}

/**
 * Evaluate warning rules for a set of laps against optional fleet averages.
 * Returns array of ['rule' => $rule, 'lap' => $lap_number, 'actual' => $value].
 */
function evaluate_warnings(array $rules, array $laps, array $fleet_avgs = []): array
{
	$triggered = [];

	foreach ($rules as $rule) {
		$key    = $rule['channel'] . '_' . $rule['stat'];
		$op     = $rule['operator'];
		$filter = $rule['lap_filter'];

		foreach ($laps as $lap) {
			$lap_num = $lap['lap_number'];

			// Apply lap filter
			if ($filter === 'first' && $lap_num !== min(array_column($laps, 'lap_number'))) continue;
			if ($filter === 'last'  && $lap_num !== max(array_column($laps, 'lap_number'))) continue;

			if (!array_key_exists($key, $lap) || $lap[$key] === null) continue;
			$actual = (float)$lap[$key];

			// Determine threshold
			if ($rule['compare_to'] === 'absolute') {
				$threshold = (float)$rule['threshold'];
			} elseif ($rule['compare_to'] === 'fleet_avg') {
				$target = $rule['compare_target'] ?? $key;
				if (!isset($fleet_avgs[$lap_num][$target])) continue;
				$threshold = (float)$fleet_avgs[$lap_num][$target] + (float)$rule['threshold'];
			} else {
				continue; // other compare types handled by reports
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
				$triggered[] = [
					'rule'   => $rule,
					'lap'    => $lap_num,
					'actual' => $actual,
				];
			}
		}
	}

	return $triggered;
}
