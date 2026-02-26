<?php
// ─── MG1 Engineering Report System — report_trend.php ────────────────────────
// Report 3: Single car across multiple sessions — trend view.

require_once __DIR__ . '/db.php';

$car_alias = $_GET['car'] ?? '';
if (!$car_alias) {
	header('Location: index.php');
	exit;
}

$sessions = get_car_sessions($car_alias);
if (empty($sessions)) {
	http_response_code(404);
	die('No sessions found for this car.');
}

// For each session, compute per-session averages of key channels
$session_labels = [];
$trend = [];

$trend_keys = [
	'FuelConsumptionL_Change',
	'EngineWaterTemp_Avg',
	'EngineOilTemperature_Avg',
	'VehicleSpeedVSOSig_Max',
	'FL_WS_TEMPERATURE_Avg',
	'FR_WS_TEMPERATURE_Avg',
	'RL_WS_TEMPERATURE_Avg',
	'RR_WS_TEMPERATURE_Avg',
	'FL_PSI_Avg',
	'FR_PSI_Avg',
	'RL_PSI_Avg',
	'RR_PSI_Avg',
];

foreach ($sessions as $s) {
	$label = $s['session_date'] . ' · ' . $s['session_name'];
	$session_labels[] = $label;

	$laps = get_laps((int)$s['id']);
	$avgs = [];
	foreach ($trend_keys as $key) {
		$vals = array_filter(
			array_map(fn($l) => isset($l[$key]) && is_numeric($l[$key]) ? (float)$l[$key] : null, $laps),
			fn($v) => $v !== null
		);
		$avgs[$key] = !empty($vals) ? array_sum($vals) / count($vals) : null;
	}
	$trend[] = $avgs;
}

$labels_json = json_encode($session_labels, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Trend: <?= htmlspecialchars($car_alias) ?> — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<div class="report-meta-bar">
		<div class="meta-chip">
			<dt>Car</dt>
			<dd class="mono"><?= htmlspecialchars($car_alias) ?></dd>
		</div>
		<div class="meta-chip">
			<dt>Sessions</dt>
			<dd><?= count($sessions) ?></dd>
		</div>
		<div class="report-nav">
			<button onclick="window.print()" class="btn btn-sm btn-outline">Print / PDF</button>
		</div>
	</div>

	<?php if (count($sessions) < 2): ?>
	<div class="alert alert-info">
		<p>Only one session found for <?= htmlspecialchars($car_alias) ?>. Upload more sessions to see trends.</p>
	</div>
	<?php endif; ?>

	<script>const labelsGlobal = <?= $labels_json ?>;</script>

	<?php
	function trend_series(array $trend, string $key): array {
		return array_map(fn($t) => $t[$key], $trend);
	}

	$chart_defs = [
		['title' => 'Fuel Consumption (avg L/lap)',    'id' => 'tr_fuel',
		 'datasets' => [['Fuel/lap avg','#3ecf72','FuelConsumptionL_Change']]],
		['title' => 'Engine Water Temp (avg °C)',       'id' => 'tr_water',
		 'datasets' => [['Water temp','#d42020','EngineWaterTemp_Avg'],
		                ['Oil temp','#f07820','EngineOilTemperature_Avg']]],
		['title' => 'Max Speed (avg km/h)',             'id' => 'tr_speed',
		 'datasets' => [['Max speed','#4a9eff','VehicleSpeedVSOSig_Max']]],
		['title' => 'Tire Temps (avg °C)',              'id' => 'tr_tire_temp',
		 'datasets' => [['FL','#4a9eff','FL_WS_TEMPERATURE_Avg'],
		                ['FR','#d42020','FR_WS_TEMPERATURE_Avg'],
		                ['RL','#22d4e0','RL_WS_TEMPERATURE_Avg'],
		                ['RR','#f07820','RR_WS_TEMPERATURE_Avg']]],
		['title' => 'Tire Pressures (avg PSI)',         'id' => 'tr_psi',
		 'datasets' => [['FL','#4a9eff','FL_PSI_Avg'],
		                ['FR','#d42020','FR_PSI_Avg'],
		                ['RL','#22d4e0','RL_PSI_Avg'],
		                ['RR','#f07820','RR_PSI_Avg']]],
	];

	foreach ($chart_defs as $def):
		$ds = [];
		foreach ($def['datasets'] as [$label, $color, $key]) {
			$ds[] = [
				'label'           => $label,
				'data'            => trend_series($trend, $key),
				'borderColor'     => $color,
				'backgroundColor' => $color . '22',
				'borderWidth'     => 2,
				'pointRadius'     => 4,
				'tension'         => 0.3,
				'fill'            => false,
			];
		}
		$ds_json = json_encode($ds, JSON_UNESCAPED_UNICODE);
	?>
	<section class="chart-section">
		<h2><?= htmlspecialchars($def['title']) ?></h2>
		<div class="chart-wrap">
			<div class="chart-canvas-wrap">
				<canvas id="<?= $def['id'] ?>"></canvas>
			</div>
		</div>
		<script>
		(function(){
			var ctx = document.getElementById('<?= $def['id'] ?>').getContext('2d');
			new Chart(ctx, {
				type: 'line',
				data: { labels: labelsGlobal, datasets: <?= $ds_json ?> },
				options: {
					responsive: true, maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend: { labels: { color:'#7a7a88', font:{size:11}, boxWidth:18 } },
						tooltip: { backgroundColor:'#1c1c21', borderColor:'#2a2a31', borderWidth:1,
						           titleColor:'#e8e8ec', bodyColor:'#7a7a88' }
					},
					scales: {
						x: { ticks: { color:'#50505c', font:{size:10}, maxRotation:30 }, grid:{color:'#1c1c21'} },
						y: { ticks: { color:'#50505c', font:{size:11} }, grid:{color:'#1c1c21'} }
					}
				}
			});
		}());
		</script>
	</section>
	<?php endforeach; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
