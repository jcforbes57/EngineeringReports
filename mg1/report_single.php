<?php
// ─── MG1 Engineering Report System — report_single.php ───────────────────────
// Report 1: Single car + session — replicates the WinTax PDF layout.
// Chart sections: Tires · Fuel · Performance · Engine Temps · Life Data
// Fleet average overlaid as faint dashed lines on each chart.
//
// TODO: Chart.js rendering and full section build-out (next phase).

require_once __DIR__ . '/db.php';

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if (!$session_id) {
	header('Location: index.php');
	exit;
}

$session = get_session($session_id);
if (!$session) {
	http_response_code(404);
	die('Session not found.');
}

$laps = get_laps($session_id);
if (empty($laps)) {
	http_response_code(404);
	die('No lap data for this session.');
}

// Build lap labels
$lap_labels = array_column($laps, 'lap_number');

// Fleet sessions for the same session_name + date (to overlay averages)
$fleet = get_fleet_sessions($session['session_name'], $session['session_date']);
$fleet_ids = array_column($fleet, 'id');
$fleet_avg_keys = [
	// Tire temps
	'FL_WS_TEMPERATURE_Avg','FR_WS_TEMPERATURE_Avg','RL_WS_TEMPERATURE_Avg','RR_WS_TEMPERATURE_Avg',
	// Tire pressures
	'FL_PSI_Avg','FR_PSI_Avg','RL_PSI_Avg','RR_PSI_Avg',
	// Fuel
	'FuelConsumptionL_Change','NVRAM_TotalFuelConsumption_End',
	// Performance
	'VehicleSpeedVSOSig_Max','VehicleSpeedVSOSig_Min','BestLapTime_Min',
	// Engine
	'EngineWaterTemp_Avg','EngineOilTemperature_Avg','IntkAirTempMnfld_SX_Avg','IntkAirTempMnfld_DX_Avg',
];
$fleet_avgs = compute_fleet_averages($fleet_ids, $fleet_avg_keys);

// Helper: extract a channel series from laps
function lap_series(array $laps, string $key): array {
	return array_map(fn($l) => isset($l[$key]) && is_numeric($l[$key]) ? (float)$l[$key] : null, $laps);
}
// Helper: extract fleet avg series aligned to lap labels
function fleet_series(array $fleet_avgs, array $lap_labels, string $key): array {
	return array_map(fn($lap) => $fleet_avgs[$lap][$key] ?? null, $lap_labels);
}

// ── Warning evaluation ────────────────────────────────────────────────────────
$section_warnings = [];
foreach (['tires','fuel','performance','engine','life'] as $sec) {
	$rules = get_warning_rules($sec);
	$section_warnings[$sec] = evaluate_warnings($rules, $laps, $fleet_avgs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars($session['car_alias'] . ' · ' . $session['session_name']) ?> — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<!-- ── Report meta bar ──────────────────────────────────────────────────── -->
	<div class="report-meta-bar">
		<div class="meta-chip">
			<dt>Car</dt>
			<dd class="mono"><?= htmlspecialchars($session['car_alias']) ?></dd>
		</div>
		<div class="meta-chip">
			<dt>Session</dt>
			<dd><?= htmlspecialchars($session['session_name']) ?></dd>
		</div>
		<div class="meta-chip">
			<dt>Date</dt>
			<dd class="mono"><?= htmlspecialchars($session['session_date']) ?></dd>
		</div>
		<?php if ($session['driver']): ?>
		<div class="meta-chip">
			<dt>Driver</dt>
			<dd><?= htmlspecialchars($session['driver']) ?></dd>
		</div>
		<?php endif; ?>
		<?php if ($session['track']): ?>
		<div class="meta-chip">
			<dt>Track</dt>
			<dd><?= htmlspecialchars($session['track']) ?></dd>
		</div>
		<?php endif; ?>
		<div class="meta-chip">
			<dt>Laps</dt>
			<dd><?= count($laps) ?></dd>
		</div>
		<div class="report-nav">
			<a href="report_fleet.php?date=<?= urlencode($session['session_date']) ?>&session=<?= urlencode($session['session_name']) ?>"
			   class="btn btn-sm btn-outline">Fleet View</a>
			<a href="report_trend.php?car=<?= urlencode($session['car_alias']) ?>"
			   class="btn btn-sm btn-outline">Trend View</a>
			<button onclick="window.print()" class="btn btn-sm btn-outline">Print / PDF</button>
		</div>
	</div>

	<?php
	// ── Shared chart config ────────────────────────────────────────────────────
	$lap_labels_json = json_encode($lap_labels, JSON_UNESCAPED_UNICODE);

	function chart_dataset(string $label, string $color, array $data, bool $fleet = false): array {
		$base = [
			'label'       => $label,
			'data'        => $data,
			'borderColor' => $color,
			'borderWidth' => $fleet ? 1 : 2,
			'pointRadius' => $fleet ? 0 : 3,
			'tension'     => 0.3,
		];
		if ($fleet) {
			$base['borderDash']       = [4, 4];
			$base['backgroundColor']  = 'transparent';
		} else {
			$base['backgroundColor']  = $color . '22';
			$base['fill']             = false;
		}
		return $base;
	}

	function render_chart(string $id, array $datasets, string $ylabel = ''): void {
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
						legend: {
							labels: { color: '#7a7a88', font: { size: 11 }, boxWidth: 18 }
						},
						tooltip: {
							backgroundColor: '#1c1c21',
							borderColor: '#2a2a31',
							borderWidth: 1,
							titleColor: '#e8e8ec',
							bodyColor: '#7a7a88',
						}
					},
					scales: {
						x: {
							ticks: { color: '#50505c', font: { size: 11 } },
							grid:  { color: '#1c1c21' },
							title: { display: true, text: 'Lap', color: '#50505c', font: { size: 11 } }
						},
						y: {
							ticks: { color: '#50505c', font: { size: 11 } },
							grid:  { color: '#1c1c21' },
							title: { display: ''.length > 0, text: '{$ylabel}', color: '#50505c', font: { size: 11 } }
						}
					}
				}
			});
		}());
		</script>
		JS;
	}

	function warnings_html(array $warnings): string {
		if (empty($warnings)) return '';
		$html = '<div class="warnings-block">';
		foreach ($warnings as $w) {
			$detail = 'Lap ' . $w['lap'] . ' — actual: ' . number_format($w['actual'], 2);
			$html .= '<div class="warning-item">'
				. '<span class="warn-icon">&#9432;</span>'
				. '<span class="warn-msg">' . htmlspecialchars($w['rule']['message']) . '</span>'
				. '<span class="warn-detail">' . htmlspecialchars($detail) . '</span>'
				. '</div>';
		}
		return $html . '</div>';
	}

	// Tire colours: FL=blue, FR=red, RL=teal, RR=orange
	$tc = ['FL' => '#4a9eff', 'FR' => '#d42020', 'RL' => '#22d4e0', 'RR' => '#f07820'];
	?>

	<script>const labelsGlobal = <?= $lap_labels_json ?>;</script>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 1 — TIRES
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Tires</h2>
		<?= warnings_html($section_warnings['tires']) ?>

		<div class="chart-grid">

			<!-- Tire temperatures -->
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_tire_temp"></canvas>
				</div>
			</div>
			<?php
			$ds = [];
			foreach (['FL','FR','RL','RR'] as $corner) {
				$key = $corner . '_WS_TEMPERATURE_Avg';
				$ds[] = chart_dataset($corner . ' Temp (°C)', $tc[$corner],
					lap_series($laps, $key));
				$ds[] = chart_dataset($corner . ' Avg Fleet', $tc[$corner],
					fleet_series($fleet_avgs, $lap_labels, $key), true);
			}
			render_chart('ch_tire_temp', $ds, '°C');
			?>

			<!-- Tire pressures -->
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_tire_psi"></canvas>
				</div>
			</div>
			<?php
			$ds = [];
			foreach (['FL','FR','RL','RR'] as $corner) {
				$key = $corner . '_PSI_Avg';
				$ds[] = chart_dataset($corner . ' PSI', $tc[$corner],
					lap_series($laps, $key));
				$ds[] = chart_dataset($corner . ' PSI Fleet', $tc[$corner],
					fleet_series($fleet_avgs, $lap_labels, $key), true);
			}
			render_chart('ch_tire_psi', $ds, 'PSI');
			?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 2 — FUEL
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Fuel</h2>
		<?= warnings_html($section_warnings['fuel']) ?>
		<div class="chart-grid">
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_fuel_lap"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Fuel / lap (L)', '#3ecf72', lap_series($laps, 'FuelConsumptionL_Change')),
				chart_dataset('Fleet avg', '#3ecf72', fleet_series($fleet_avgs, $lap_labels, 'FuelConsumptionL_Change'), true),
			];
			render_chart('ch_fuel_lap', $ds, 'L');
			?>

			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_fuel_total"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Total fuel used (L)', '#f5c518', lap_series($laps, 'NVRAM_TotalFuelConsumption_End')),
				chart_dataset('Fleet avg', '#f5c518', fleet_series($fleet_avgs, $lap_labels, 'NVRAM_TotalFuelConsumption_End'), true),
			];
			render_chart('ch_fuel_total', $ds, 'L');
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 3 — PERFORMANCE
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Performance</h2>
		<?= warnings_html($section_warnings['performance']) ?>
		<div class="chart-grid">
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_speed"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Max speed (km/h)', '#4a9eff', lap_series($laps, 'VehicleSpeedVSOSig_Max')),
				chart_dataset('Fleet max speed', '#4a9eff', fleet_series($fleet_avgs, $lap_labels, 'VehicleSpeedVSOSig_Max'), true),
				chart_dataset('Min speed (km/h)', '#a880f0', lap_series($laps, 'VehicleSpeedVSOSig_Min')),
				chart_dataset('Fleet min speed', '#a880f0', fleet_series($fleet_avgs, $lap_labels, 'VehicleSpeedVSOSig_Min'), true),
			];
			render_chart('ch_speed', $ds, 'km/h');
			?>

			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_laptime"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Best lap time (s)', '#f07820', lap_series($laps, 'BestLapTime_Min')),
				chart_dataset('Fleet best lap', '#f07820', fleet_series($fleet_avgs, $lap_labels, 'BestLapTime_Min'), true),
			];
			render_chart('ch_laptime', $ds, 's');
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 4 — ENGINE TEMPS
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Engine Temps</h2>
		<?= warnings_html($section_warnings['engine']) ?>
		<div class="chart-grid">
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_eng_water"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Water temp (°C)', '#d42020', lap_series($laps, 'EngineWaterTemp_Avg')),
				chart_dataset('Fleet avg', '#d42020', fleet_series($fleet_avgs, $lap_labels, 'EngineWaterTemp_Avg'), true),
				chart_dataset('Oil temp (°C)', '#f07820', lap_series($laps, 'EngineOilTemperature_Avg')),
				chart_dataset('Fleet avg', '#f07820', fleet_series($fleet_avgs, $lap_labels, 'EngineOilTemperature_Avg'), true),
			];
			render_chart('ch_eng_water', $ds, '°C');
			?>

			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_intake_temp"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Intake air SX (°C)', '#22d4e0', lap_series($laps, 'IntkAirTempMnfld_SX_Avg')),
				chart_dataset('Fleet avg', '#22d4e0', fleet_series($fleet_avgs, $lap_labels, 'IntkAirTempMnfld_SX_Avg'), true),
				chart_dataset('Intake air DX (°C)', '#3ecf72', lap_series($laps, 'IntkAirTempMnfld_DX_Avg')),
				chart_dataset('Fleet avg', '#3ecf72', fleet_series($fleet_avgs, $lap_labels, 'IntkAirTempMnfld_DX_Avg'), true),
			];
			render_chart('ch_intake_temp', $ds, '°C');
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 5 — LIFE DATA
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Life Data</h2>
		<?= warnings_html($section_warnings['life']) ?>
		<div class="chart-grid">
			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_abs"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('ABS interventions', '#f5c518', lap_series($laps, 'ManABS_4_FBO_Change')),
				chart_dataset('Man1 interventions', '#4a9eff', lap_series($laps, 'Man1_4_FBO_Change')),
				chart_dataset('Man2 interventions', '#a880f0', lap_series($laps, 'Man2_4_FBO_Change')),
			];
			render_chart('ch_abs', $ds, 'count');
			?>

			<div class="chart-wrap">
				<div class="chart-canvas-wrap">
					<canvas id="ch_low_fuel"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Low fuel warning', '#e03030', lap_series($laps, 'LowFuelFilteredWarningSts_Max')),
			];
			render_chart('ch_low_fuel', $ds, 'state');
			?>
		</div>
	</section>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
