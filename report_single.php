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

// Build lap labels for display and composite keys for fleet_avgs lookup.
// Labels are sequential integers (1, 2, 3…); run boundaries are drawn below
// the x-axis by the motorsport annotation plugin.
$max_run    = max(array_column($laps, 'run_number'));
$lap_labels = range(1, count($laps));
// Composite keys used to look up fleet averages (always "run-lap" format)
$lap_keys = array_map(fn($l) => $l['run_number'] . '-' . $l['lap_number'], $laps);

// Fleet sessions for the same session_name + date (to overlay averages).
// Exclude the current session so the fleet avg represents peer cars only.
// If this is the only car, $fleet_ids is empty and no overlay lines appear.
$fleet = get_fleet_sessions($session['session_name'], $session['session_date']);
$fleet_ids = array_values(array_filter(array_column($fleet, 'id'), fn($id) => $id !== $session_id));
$fleet_avg_keys = [
	// Tire temps
	'FL_WS_TEMPERATURE_Avg','FR_WS_TEMPERATURE_Avg','RL_WS_TEMPERATURE_Avg','RR_WS_TEMPERATURE_Avg',
	// Tire pressures
	'FL_PSI_Avg','FR_PSI_Avg','RL_PSI_Avg','RR_PSI_Avg',
	// Fuel
	'FuelConsumptionL_Change','NVRAM_TotalFuelConsumption_End',
	// Performance — LapTimeSeconds_End = actual per-lap time; BestLapTime_End = running best
	'VehicleSpeedVSOSig_Max','VehicleSpeedVSOSig_Min','LapTimeSeconds_End','BestLapTime_End',
	// Engine
	'EngineWaterTemp_Avg','EngineOilTemperature_Avg','IntkAirTempMnfld_SX_Avg','IntkAirTempMnfld_DX_Avg',
];
$fleet_avgs = compute_fleet_averages($fleet_ids, $fleet_avg_keys);

// Helper: extract a channel series from laps
function lap_series(array $laps, string $key): array {
	return array_map(fn($l) => isset($l[$key]) && is_numeric($l[$key]) ? (float)$l[$key] : null, $laps);
}
// Helper: extract fleet avg series aligned to lap composite keys
function fleet_series(array $fleet_avgs, array $lap_keys, string $key): array {
	return array_map(fn($lk) => $fleet_avgs[$lk][$key] ?? null, $lap_keys);
}

// ── Annotation data: run-group spans, pit laps, fast lap ──────────────────────
// Run groups — only meaningful when there are multiple runs in the session
$run_groups = [];
if ($max_run > 1) {
	$cur_run = null; $grp_start = 0;
	foreach ($laps as $i => $lap) {
		if ($lap['run_number'] !== $cur_run) {
			if ($cur_run !== null)
				$run_groups[] = ['label' => 'Run ' . $cur_run, 'start' => $grp_start, 'end' => $i - 1];
			$cur_run   = $lap['run_number'];
			$grp_start = $i;
		}
	}
	$run_groups[] = ['label' => 'Run ' . $cur_run, 'start' => $grp_start, 'end' => count($laps) - 1];
}
// Pit laps — any lap where minimum vehicle speed reached 0 kph (car stopped)
$pit_laps = [];
foreach ($laps as $i => $lap) {
	$ms = $lap['VehicleSpeedVSOSig_Min'] ?? null;
	if ($ms !== null && is_numeric($ms) && (float)$ms == 0.0) $pit_laps[] = $i;
}
// Fast lap — lap with the minimum actual lap time (LapTimeSeconds_End).
// BestLapTime_End is driver-managed and unreliable for detection; using the
// actual per-lap time directly gives an unambiguous smallest-number = fastest result.
// Pit laps (min speed == 0) are excluded before the channel data is nulled.
$fast_lap_idx = null;
$_min_lt  = PHP_FLOAT_MAX;
$_pit_set = array_flip($pit_laps); // O(1) index lookup
foreach (lap_series($laps, 'LapTimeSeconds_End') as $i => $t) {
	if (isset($_pit_set[$i])) continue; // never pick a pit/box lap as fastest
	if ($t !== null && $t > 30 && $t < $_min_lt) { $_min_lt = $t; $fast_lap_idx = $i; }
}
unset($_min_lt, $_pit_set);

// Null out all channel data for pit laps (min speed == 0).
// They appear as line gaps on all charts and are skipped by warning evaluation.
// run_number and lap_number are kept so the x-axis label and annotations still work.
if (!empty($pit_laps)) {
	$pit_lap_set = array_flip($pit_laps);
	foreach ($laps as $i => &$lap) {
		if (isset($pit_lap_set[$i])) {
			foreach (array_keys($lap) as $k) {
				if ($k !== 'run_number' && $k !== 'lap_number') $lap[$k] = null;
			}
		}
	}
	unset($lap);
}

// ── Warning evaluation ────────────────────────────────────────────────────────
$section_warnings = [];
foreach (['tires','fuel','performance','engine','life'] as $sec) {
	$rules = get_warning_rules($sec);
	$section_warnings[$sec] = evaluate_warnings($rules, $laps, $fleet_avgs, $fast_lap_idx);
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
	<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2/dist/chartjs-plugin-datalabels.min.js"></script>
	<script>
	Chart.register(ChartDataLabels);

	// Motorsport annotations plugin — draws per-chart annotations inside
	// the plot area (Box / Fast Lap) and run-group brackets below the x-axis.
	const motorsportPlugin = {
		id: 'motorsport',
		afterDraw(chart) {
			// Read page-level global directly — Chart.js v4's options resolver
			// deep-merges plugin configs and can mangle array values (runGroups etc.).
			const md = (typeof motorsportGlobal !== 'undefined') ? motorsportGlobal : null;
			if (!md) return;
			const ctx    = chart.ctx;
			const xScale = chart.scales.x;
			if (!xScale) return;
			const ca = chart.chartArea;

			// getPixelForIndex() was removed in Chart.js v4.
			// Use the first dataset's rendered element x-coordinate instead —
			// this is always correct regardless of scale type or label format.
			function getX(idx) {
				var meta = chart.getDatasetMeta(0);
				if (!meta || !meta.data || !meta.data[idx]) return null;
				return meta.data[idx].x;
			}

			ctx.save();

			// ── Pit-lap "Box" marker (bottom of chart area, red) ──────────
			if (md.pitLaps && md.pitLaps.length) {
				ctx.font         = 'bold 9px Helvetica,Arial,sans-serif';
				ctx.fillStyle    = '#e05050';
				ctx.textAlign    = 'center';
				ctx.textBaseline = 'bottom';
				md.pitLaps.forEach(function(i) {
					var x = getX(i);
					if (x !== null) ctx.fillText('Box', x, ca.bottom - 2);
				});
			}

			// ── Fast-lap: full-height dashed green line + label at top of chart ──
			if (md.fastLap !== null && md.fastLap >= 0) {
				var fx = getX(md.fastLap);
				if (fx !== null) {
					ctx.strokeStyle = 'rgba(62,207,114,0.55)';
					ctx.lineWidth   = 1.5;
					ctx.setLineDash([5, 4]);
					ctx.beginPath();
					ctx.moveTo(fx, ca.top);
					ctx.lineTo(fx, ca.bottom);
					ctx.stroke();
					ctx.setLineDash([]);
					ctx.fillStyle    = '#3ecf72';
					ctx.font         = 'bold 10px Helvetica,Arial,sans-serif';
					ctx.textAlign    = 'center';
					ctx.textBaseline = 'top';
					ctx.fillText('\u2605 Fast', fx, ca.top + 4);
				}
			}

			// ── Run-group bracket + label below x-axis ─────────────────────
			if (md.runGroups && md.runGroups.length > 1) {
				var yLine = xScale.bottom + 3;
				var yText = xScale.bottom + 18;
				ctx.lineWidth    = 1;
				ctx.textAlign    = 'center';
				ctx.textBaseline = 'top';
				ctx.font         = '9px Helvetica,Arial,sans-serif';
				md.runGroups.forEach(function(g) {
					var x1 = getX(g.start);
					var x2 = getX(g.end);
					if (x1 === null || x2 === null) return;
					x1 += 2; x2 -= 2;
					ctx.strokeStyle = '#50505c';
					ctx.beginPath();
					ctx.moveTo(x1, yLine + 5); ctx.lineTo(x1, yLine);
					ctx.lineTo(x2, yLine);     ctx.lineTo(x2, yLine + 5);
					ctx.stroke();
					ctx.fillStyle = '#7a7a88';
					ctx.fillText(g.label, (x1 + x2) / 2, yText);
				});
			}

			ctx.restore();
		}
	};
	Chart.register(motorsportPlugin);
	</script>
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

	// $extra allows overriding/extending individual dataset properties (e.g. yAxisID, type)
	function chart_dataset(string $label, string $color, array $data, bool $fleet = false, array $extra = []): array {
		$base = [
			'label'       => $label,
			'data'        => $data,
			'borderColor' => $color,
			'borderWidth' => $fleet ? 1 : 2,
			'pointRadius' => $fleet ? 0 : 3,
			'tension'     => 0,
		];
		if ($fleet) {
			$base['borderDash']      = [4, 4];
			$base['backgroundColor'] = 'transparent';
			$base['datalabels']      = ['display' => false];
		} else {
			$base['backgroundColor'] = $color . '22';
			$base['fill']            = false;
			$base['datalabels']      = ['color' => $color];
		}
		return array_merge($base, $extra);
	}

	// $scales_extra: keyed by axis id ('y','y1','y2'…).
	//   'y' is merged into the default left axis; any other key adds an extra axis.
	// $int_labels: when true the data-label formatter always uses toFixed(0) (integer values).
	// $formatter_override: raw JS function string; when non-empty overrides int_labels/default formatter.
	function render_chart(string $id, array $datasets, string $ylabel = '', array $scales_extra = [], bool $int_labels = false, string $formatter_override = ''): void {
		global $run_groups;
		// Reserve space below x-axis for run-group brackets when there are multiple runs
		$bottom_pad = (!empty($run_groups) && count($run_groups) > 1) ? 28 : 0;

		$ds_json = json_encode($datasets, JSON_UNESCAPED_UNICODE);
		if ($formatter_override !== '') {
			$formatter_js = $formatter_override;
		} elseif ($int_labels) {
			$formatter_js = 'function(v){if(v===null||v===undefined)return null;var n=parseFloat(v);return isNaN(n)?null:n.toFixed(0);}';
		} else {
			$formatter_js = 'function(value){if(value===null||value===undefined)return null;var v=parseFloat(value);if(isNaN(v))return null;if(Math.abs(v)>=100)return v.toFixed(0);if(Math.abs(v)>=10)return v.toFixed(1);return v.toFixed(2);}';
		}

		$y_cfg = array_merge([
			'ticks' => ['color' => '#50505c', 'font' => ['size' => 11]],
			'grid'  => ['color' => '#1c1c21'],
			'title' => ['display' => strlen($ylabel) > 0, 'text' => $ylabel,
			             'color' => '#50505c', 'font' => ['size' => 11]],
		], $scales_extra['y'] ?? []);

		$scales = [
			'x' => [
				'ticks' => ['color' => '#50505c', 'font' => ['size' => 11]],
				'grid'  => ['color' => '#1c1c21'],
				'title' => ['display' => true, 'text' => 'Lap',
				             'color' => '#50505c', 'font' => ['size' => 11]],
			],
			'y' => $y_cfg,
		];
		foreach ($scales_extra as $axis => $cfg) {
			if ($axis !== 'y') $scales[$axis] = $cfg;
		}
		$scales_json = json_encode($scales, JSON_UNESCAPED_UNICODE);

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
					layout: { padding: { bottom: {$bottom_pad} } },
					interaction: { mode: 'index', intersect: false },
					plugins: {
						datalabels: {
							display: true,
							clamp: true,
							font: { size: 10, weight: '600' },
							anchor: 'end',
							align: function(ctx) {
								return ctx.datasetIndex % 2 === 0 ? 'top' : 'bottom';
							},
							offset: function(ctx) {
								return 4 + Math.floor(ctx.datasetIndex / 2) * 14;
							},
							padding: 0,
							formatter: {$formatter_js}
						},
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
					scales: {$scales_json}
				}
			});
		}());
		</script>
		JS;
	}

	function warnings_html(array $warnings): string {
		if (empty($warnings)) return '';

		// Collapse consecutive laps for the same rule + run into a single entry.
		// Group by rule id + run number so we only merge within the same run.
		$groups = [];
		foreach ($warnings as $w) {
			$gk = $w['rule']['id'] . '-' . ($w['run'] ?? 1);
			$groups[$gk][] = $w;
		}

		$collapsed = [];
		foreach ($groups as $hits) {
			usort($hits, fn($a, $b) => ($a['run'] ?? 1) <=> ($b['run'] ?? 1) ?: $a['lap'] <=> $b['lap']);
			$start = $hits[0];
			$end   = $hits[0];
			for ($i = 1; $i < count($hits); $i++) {
				$h = $hits[$i];
				if (($h['run'] ?? 1) === ($end['run'] ?? 1) && $h['lap'] === $end['lap'] + 1) {
					$end = $h; // extend range
				} else {
					$start['lap_end'] = $end['lap'];
					$collapsed[] = $start;
					$start = $h;
					$end   = $h;
				}
			}
			$start['lap_end'] = $end['lap'];
			$collapsed[] = $start;
		}

		$html = '<div class="warnings-block">';
		foreach ($collapsed as $w) {
			$run    = $w['run'] ?? 1;
			$prefix = $run > 1 ? 'Run ' . $run . ' ' : '';
			if ($w['lap_end'] !== $w['lap']) {
				$detail = $prefix . 'Laps ' . $w['lap'] . '–' . $w['lap_end'];
			} else {
				$detail = $prefix . 'Lap ' . $w['lap'] . ' — actual: ' . number_format($w['actual'], 2);
			}
			$html .= '<div class="warning-item">'
				. '<span class="warn-icon">&#9888;</span>'
				. '<span class="warn-msg">' . htmlspecialchars($w['rule']['message']) . '</span>'
				. '<span class="warn-detail">' . htmlspecialchars($detail) . '</span>'
				. '</div>';
		}
		return $html . '</div>';
	}

	// Tire colours: FL=blue, FR=red, RL=teal, RR=orange
	$tc = ['FL' => '#4ab8ff', 'FR' => '#e03c3c', 'RL' => '#22d4e0', 'RR' => '#f07820'];
	?>

	<script>
	const labelsGlobal    = <?= $lap_labels_json ?>;
	const motorsportGlobal = <?= json_encode(
		['runGroups' => $run_groups, 'pitLaps' => $pit_laps, 'fastLap' => $fast_lap_idx],
		JSON_UNESCAPED_UNICODE
	) ?>;
	</script>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 1 — TIRES
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Tires</h2>
		<?= warnings_html($section_warnings['tires']) ?>

		<div class="chart-col">

			<!-- Tire temperatures — full width -->
			<div class="chart-wrap-full">
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
					fleet_series($fleet_avgs, $lap_keys, $key), true);
			}
			render_chart('ch_tire_temp', $ds, '°C', ['y' => ['max' => 120]]);
			?>

			<!-- Tire pressures — full width -->
			<div class="chart-wrap-full">
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
					fleet_series($fleet_avgs, $lap_keys, $key), true);
			}
			render_chart('ch_tire_psi', $ds, 'PSI', ['y' => ['min' => 10, 'max' => 40]]);
			?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 2 — FUEL
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Fuel</h2>
		<?= warnings_html($section_warnings['fuel']) ?>

		<!-- Single combined chart: fuel/lap (left axis) + total fuel (right axis)
		     + low fuel warning as a binary bar indicator (hidden axis) -->
		<div class="chart-wrap-full">
			<div class="chart-canvas-wrap">
				<canvas id="ch_fuel"></canvas>
			</div>
		</div>
		<?php
		// Fuel/lap Y-max: slightly above the mode so outliers don't squash the range.
		$_fuel_vals = array_filter(lap_series($laps, 'FuelConsumptionL_Change'),
			fn($v) => $v !== null && $v > 0);
		$_fuel_y_max = null;
		if ($_fuel_vals) {
			$_f_counts = [];
			foreach ($_fuel_vals as $_fv) {
				$_fk = (string)round($_fv, 1);
				$_f_counts[$_fk] = ($_f_counts[$_fk] ?? 0) + 1;
			}
			arsort($_f_counts);
			$_fuel_y_max = round((float)array_key_first($_f_counts) * 1.25, 2);
		}
		$_fuel_y = ['title' => ['display' => true, 'text' => 'L / lap',
		             'color' => '#50505c', 'font' => ['size' => 11]]];
		if ($_fuel_y_max !== null) $_fuel_y['max'] = $_fuel_y_max;
		$fuel_scales = [
			'y'  => $_fuel_y,
			'y1' => [
				'type'     => 'linear',
				'position' => 'right',
				'ticks'    => ['color' => '#50505c', 'font' => ['size' => 11]],
				'grid'     => ['drawOnChartArea' => false],
				'title'    => ['display' => true, 'text' => 'Total (L)',
				               'color' => '#50505c', 'font' => ['size' => 11]],
			],
			// Hidden axis for the 0/1 low-fuel indicator bars
			'y2' => [
				'type'    => 'linear',
				'display' => false,
				'min'     => 0,
				'max'     => 1.4,
			],
		];
		$ds = [
			chart_dataset('Fuel / lap (L)',      '#3ecf72', lap_series($laps, 'FuelConsumptionL_Change'),
				false, ['yAxisID' => 'y', 'order' => 1]),
			chart_dataset('Fleet avg (L/lap)',   '#3ecf72', fleet_series($fleet_avgs, $lap_keys, 'FuelConsumptionL_Change'),
				true,  ['yAxisID' => 'y', 'order' => 1]),
			chart_dataset('Total fuel (L)',      '#f5c518', lap_series($laps, 'NVRAM_TotalFuelConsumption_End'),
				false, ['yAxisID' => 'y1', 'order' => 1]),
			chart_dataset('Fleet avg total (L)', '#f5c518', fleet_series($fleet_avgs, $lap_keys, 'NVRAM_TotalFuelConsumption_End'),
				true,  ['yAxisID' => 'y1', 'order' => 1]),
			// Low fuel warning — binary bar, rendered behind the lines
			array_merge(chart_dataset('Low fuel warning', '#e03030', lap_series($laps, 'LowFuelFilteredWarningSts_Max')), [
				'type'            => 'bar',
				'yAxisID'         => 'y2',
				'backgroundColor' => 'rgba(224, 48, 48, 0.35)',
				'borderColor'     => 'rgba(224, 48, 48, 0.6)',
				'borderWidth'     => 1,
				'barPercentage'   => 0.95,
				'order'           => 2,
				'tension'         => null,
				'fill'            => null,
				'datalabels'      => ['display' => false],
			]),
		];
		render_chart('ch_fuel', $ds, '', $fuel_scales);
		?>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 3 — PERFORMANCE
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Performance</h2>
		<?= warnings_html($section_warnings['performance']) ?>
		<div class="chart-col">
			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap">
					<canvas id="ch_speed"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Max speed (km/h)', '#4a9eff', lap_series($laps, 'VehicleSpeedVSOSig_Max')),
				chart_dataset('Fleet max speed', '#4a9eff', fleet_series($fleet_avgs, $lap_keys, 'VehicleSpeedVSOSig_Max'), true),
				chart_dataset('Min speed (km/h)', '#a880f0', lap_series($laps, 'VehicleSpeedVSOSig_Min')),
				chart_dataset('Fleet min speed', '#a880f0', fleet_series($fleet_avgs, $lap_keys, 'VehicleSpeedVSOSig_Min'), true),
			];
			render_chart('ch_speed', $ds, 'km/h');
			?>

			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap">
					<canvas id="ch_laptime"></canvas>
				</div>
			</div>
			<?php
			// Y-axis: min = best lap −10%, max = best lap +30%.
			// Slower laps (out/pit) exit the top of the chart intentionally.
			$_lt_vals = array_filter(lap_series($laps, 'LapTimeSeconds_End'),
				fn($v) => $v !== null && $v > 30);
			$_lt_scale = [];
			if ($_lt_vals) {
				$_best     = min($_lt_vals);
				$_lt_scale = ['y' => [
					'min' => (int)floor($_best * 0.90),
					'max' => (int)ceil($_best  * 1.30),
				]];
			}

			// LapTimeSeconds_End = actual per-lap time; BestLapTime_End = running best (stepped line)
			$ds = [
				chart_dataset('Lap time',       '#4a9eff', lap_series($laps, 'LapTimeSeconds_End')),
				chart_dataset('Fleet avg',      '#4a9eff', fleet_series($fleet_avgs, $lap_keys, 'LapTimeSeconds_End'), true),
				chart_dataset('Best (running)', '#f07820', lap_series($laps, 'BestLapTime_End'),
					false, ['stepped' => 'after', 'datalabels' => ['display' => false]]),
			];
			// mm:ss.xxx formatter — always 3 decimal places on seconds
			$_laptime_fmt = 'function(value){if(value===null||value===undefined)return null;var v=parseFloat(value);if(isNaN(v)||v<=0)return null;var m=Math.floor(v/60);var s=(v-m*60).toFixed(3);if(parseFloat(s)<10)s=\'0\'+s;return m+\':\'+s;}';
			render_chart('ch_laptime', $ds, 'min:sec', $_lt_scale, false, $_laptime_fmt);
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 4 — ENGINE TEMPS
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Engine Temps</h2>
		<?= warnings_html($section_warnings['engine']) ?>
		<div class="chart-col">
			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap">
					<canvas id="ch_eng_water"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Water temp (°C)', '#e03c3c', lap_series($laps, 'EngineWaterTemp_Avg')),
				chart_dataset('Fleet avg', '#e03c3c', fleet_series($fleet_avgs, $lap_keys, 'EngineWaterTemp_Avg'), true),
				chart_dataset('Oil temp (°C)', '#f07820', lap_series($laps, 'EngineOilTemperature_Avg')),
				chart_dataset('Fleet avg', '#f07820', fleet_series($fleet_avgs, $lap_keys, 'EngineOilTemperature_Avg'), true),
			];
			render_chart('ch_eng_water', $ds, '°C');
			?>

			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap">
					<canvas id="ch_intake_temp"></canvas>
				</div>
			</div>
			<?php
			$ds = [
				chart_dataset('Intake air SX (°C)', '#22d4e0', lap_series($laps, 'IntkAirTempMnfld_SX_Avg')),
				chart_dataset('Fleet avg', '#22d4e0', fleet_series($fleet_avgs, $lap_keys, 'IntkAirTempMnfld_SX_Avg'), true),
				chart_dataset('Intake air DX (°C)', '#3ecf72', lap_series($laps, 'IntkAirTempMnfld_DX_Avg')),
				chart_dataset('Fleet avg', '#3ecf72', fleet_series($fleet_avgs, $lap_keys, 'IntkAirTempMnfld_DX_Avg'), true),
			];
			render_chart('ch_intake_temp', $ds, '°C');
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 5 — DRIVER SETTINGS
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Driver Settings</h2>
		<?php
		// Settings are constant within a lap so _Change is always 0 — use _Avg.
		// Separate charts per setting to prevent overlap and aid comparison.
		$settings_scale = ['y' => ['min' => 0, 'max' => 5, 'ticks' => ['stepSize' => 1]]];
		?>
		<div class="chart-col">

			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap"><canvas id="ch_manaabs"></canvas></div>
			</div>
			<?php
			render_chart('ch_manaabs',
				[chart_dataset('ManABS', '#f5c518', lap_series($laps, 'ManABS_4_FBO_Avg'))],
				'setting', $settings_scale, true);
			?>

			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap"><canvas id="ch_man1"></canvas></div>
			</div>
			<?php
			render_chart('ch_man1',
				[chart_dataset('Man1 (TC)', '#4a9eff', lap_series($laps, 'Man1_4_FBO_End'))],
				'setting', $settings_scale, true);
			?>

			<div class="chart-wrap-full">
				<div class="chart-canvas-wrap"><canvas id="ch_man2"></canvas></div>
			</div>
			<?php
			render_chart('ch_man2',
				[chart_dataset('Man2 (TC)', '#a880f0', lap_series($laps, 'Man2_4_FBO_End'))],
				'setting', $settings_scale, true);
			?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 6 — LIFE DATA
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Life Data</h2>
		<?= warnings_html($section_warnings['life']) ?>
		<?php
		// ── Odometer + LTC_Max stat tiles ─────────────────────────────────
		// Show the last-lap End value and session delta (last − first lap).
		$first_lap = $laps[0];
		$last_lap  = $laps[count($laps) - 1];

		$stat_items = [
			['label' => 'Odometer',          'key' => 'Odometer_End', 'unit' => 'km', 'dp' => 1],
			['label' => 'ABS Life (LTC_Max)', 'key' => 'LTC_Max_End',  'unit' => '',   'dp' => 1],
		];
		?>
		<div class="stat-tiles-wrap">
			<?php foreach ($stat_items as $item):
				$val   = isset($last_lap[$item['key']])  && is_numeric($last_lap[$item['key']])  ? (float)$last_lap[$item['key']]  : null;
				$start = isset($first_lap[$item['key']]) && is_numeric($first_lap[$item['key']]) ? (float)$first_lap[$item['key']] : null;
				$delta = ($val !== null && $start !== null) ? $val - $start : null;
			?>
			<div class="stat-tile">
				<div class="stat-tile-label"><?= htmlspecialchars($item['label']) ?></div>
				<div class="stat-tile-value">
					<?= $val !== null ? number_format($val, $item['dp']) : '—' ?>
					<?php if ($item['unit']): ?><span class="stat-tile-unit"><?= htmlspecialchars($item['unit']) ?></span><?php endif; ?>
				</div>
				<?php if ($delta !== null): ?>
				<div class="stat-tile-delta <?= $delta > 0.05 ? 'up' : ($delta < -0.05 ? 'down' : 'neutral') ?>">
					<?= $delta > 0.05 ? '↑' : ($delta < -0.05 ? '↓' : '') ?><?= number_format(abs($delta), $item['dp']) ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</section>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
