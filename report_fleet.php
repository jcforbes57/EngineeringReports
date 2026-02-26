<?php
// ─── MG1 Engineering Report System — report_fleet.php ────────────────────────
// Report 2: Fleet comparison — all cars for a given session on the same charts.

require_once __DIR__ . '/db.php';

$session_name = $_GET['session'] ?? '';
$session_date = $_GET['date']    ?? '';

if (!$session_name || !$session_date) {
	header('Location: index.php');
	exit;
}

$sessions = get_fleet_sessions($session_name, $session_date);
if (empty($sessions)) {
	http_response_code(404);
	die('No sessions found for this fleet.');
}

// Load laps for every car
$fleet_laps = [];
foreach ($sessions as $s) {
	$fleet_laps[$s['car_alias']] = get_laps((int)$s['id']);
}

// Derive a common label set (union of all run+lap combos across all cars).
// Keys are "run-lap" composite strings; values are display labels.
$all_laps_set = [];
foreach ($fleet_laps as $laps) {
	foreach ($laps as $l) {
		$lk = $l['run_number'] . '-' . $l['lap_number'];
		$all_laps_set[$lk] = true;
	}
}
// Sort by run number then lap number
uksort($all_laps_set, function ($a, $b) {
	list($ar, $al) = explode('-', $a, 2);
	list($br, $bl) = explode('-', $b, 2);
	return $ar !== $br ? (int)$ar - (int)$br : (int)$al - (int)$bl;
});
$lap_keys = array_keys($all_laps_set);

// Display labels: show run prefix only when any car has multiple runs
$max_run_fleet = 1;
foreach ($fleet_laps as $laps) {
	if (!empty($laps)) {
		$max_run_fleet = max($max_run_fleet, max(array_column($laps, 'run_number')));
	}
}
$lap_labels = array_map(function ($lk) use ($max_run_fleet) {
	list($r, $l) = explode('-', $lk, 2);
	return $max_run_fleet > 1 ? 'R' . $r . ' L' . $l : $l;
}, $lap_keys);
$lap_labels_json = json_encode(array_values($lap_labels));

// Car palette (up to 8 cars — extend if needed)
$car_colors = ['#4a9eff','#d42020','#3ecf72','#f07820','#a880f0','#22d4e0','#f5c518','#e8e8ec'];
$car_color_map = [];
foreach (array_values($sessions) as $i => $s) {
	$car_color_map[$s['car_alias']] = $car_colors[$i % count($car_colors)];
}

function fleet_lap_series(array $laps, string $key, array $lap_keys): array {
	$indexed = [];
	foreach ($laps as $l) {
		$lk = $l['run_number'] . '-' . $l['lap_number'];
		$indexed[$lk] = $l;
	}
	return array_map(fn($lk) =>
		isset($indexed[$lk][$key]) && is_numeric($indexed[$lk][$key])
			? (float)$indexed[$lk][$key]
			: null,
		$lap_keys
	);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Fleet: <?= htmlspecialchars($session_name) ?> — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<div class="report-meta-bar">
		<div class="meta-chip"><dt>Session</dt><dd><?= htmlspecialchars($session_name) ?></dd></div>
		<div class="meta-chip"><dt>Date</dt><dd class="mono"><?= htmlspecialchars($session_date) ?></dd></div>
		<div class="meta-chip"><dt>Cars</dt><dd><?= count($sessions) ?></dd></div>
		<div class="report-nav">
			<button onclick="window.print()" class="btn btn-sm btn-outline">Print / PDF</button>
		</div>
	</div>

	<script>const labelsGlobal = <?= $lap_labels_json ?>;</script>

	<?php
	function fleet_chart_json(string $id, array $datasets): void {
		$ds_json = json_encode($datasets, JSON_UNESCAPED_UNICODE);
		echo <<<JS
		<script>
		(function(){
			var ctx = document.getElementById('{$id}').getContext('2d');
			new Chart(ctx, {
				type: 'line',
				data: { labels: labelsGlobal, datasets: {$ds_json} },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend: { labels: { color: '#7a7a88', font: { size: 11 }, boxWidth: 18 } },
						tooltip: { backgroundColor: '#1c1c21', borderColor: '#2a2a31', borderWidth: 1,
						           titleColor: '#e8e8ec', bodyColor: '#7a7a88' }
					},
					scales: {
						x: { ticks: { color:'#50505c', font:{size:11} }, grid: { color:'#1c1c21' } },
						y: { ticks: { color:'#50505c', font:{size:11} }, grid: { color:'#1c1c21' } }
					}
				}
			});
		}());
		</script>
		JS;
	}

	$channels = [
		'Tire Temps'    => ['FL_WS_TEMPERATURE_Avg','FR_WS_TEMPERATURE_Avg','RL_WS_TEMPERATURE_Avg','RR_WS_TEMPERATURE_Avg'],
		'Tire Pressures'=> ['FL_PSI_Avg','FR_PSI_Avg','RL_PSI_Avg','RR_PSI_Avg'],
		'Fuel / Lap'    => ['FuelConsumptionL_Change'],
		'Engine Water'  => ['EngineWaterTemp_Avg'],
		'Engine Oil'    => ['EngineOilTemperature_Avg'],
		'Max Speed'     => ['VehicleSpeedVSOSig_Max'],
	];
	$chart_idx = 0;

	foreach ($channels as $title => $keys):
		$chart_id = 'fleet_ch_' . $chart_idx++;
	?>
	<section class="chart-section">
		<h2><?= htmlspecialchars($title) ?></h2>
		<div class="chart-wrap">
			<div class="chart-canvas-wrap" style="height:280px">
				<canvas id="<?= $chart_id ?>"></canvas>
			</div>
		</div>
		<?php
		$ds = [];
		foreach ($sessions as $s) {
			$alias = $s['car_alias'];
			$color = $car_color_map[$alias];
			$laps  = $fleet_laps[$alias];
			foreach ($keys as $key) {
				$chan_label = count($keys) > 1
					? $alias . ' ' . preg_replace('/_[A-Za-z]+$/', '', $key)
					: $alias;
				$ds[] = [
					'label'           => $chan_label,
					'data'            => fleet_lap_series($laps, $key, $lap_keys),
					'borderColor'     => $color,
					'backgroundColor' => $color . '22',
					'borderWidth'     => 2,
					'pointRadius'     => 2,
					'tension'         => 0,
					'fill'            => false,
				];
			}
		}
		fleet_chart_json($chart_id, $ds);
		?>
	</section>
	<?php endforeach; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
