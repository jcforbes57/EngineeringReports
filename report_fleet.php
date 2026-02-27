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

// Load laps per car
$fleet_laps = [];
foreach ($sessions as $s) {
	$fleet_laps[$s['car_alias']] = get_laps((int)$s['id']);
}

// Union lap key set (run-lap composites) sorted by run then lap
$all_laps_set = [];
foreach ($fleet_laps as $laps) {
	foreach ($laps as $l) {
		$lk = $l['run_number'] . '-' . $l['lap_number'];
		$all_laps_set[$lk] = true;
	}
}
uksort($all_laps_set, function ($a, $b) {
	list($ar, $al) = explode('-', $a, 2);
	list($br, $bl) = explode('-', $b, 2);
	return $ar !== $br ? (int)$ar - (int)$br : (int)$al - (int)$bl;
});
$lap_keys = array_keys($all_laps_set);

// Sequential integer labels (1, 2, 3…) — run boundaries shown via annotations
$lap_labels      = range(1, count($lap_keys));
$lap_labels_json = json_encode($lap_labels, JSON_UNESCAPED_UNICODE);

// Run groups: derive from first car (all cars share the same session run structure)
$first_laps      = !empty($fleet_laps) ? reset($fleet_laps) : [];
$max_run_fleet   = !empty($first_laps) ? max(array_column($first_laps, 'run_number')) : 1;
$run_groups      = [];
if ($max_run_fleet > 1 && !empty($first_laps)) {
	$lk_to_idx = array_flip($lap_keys);
	$cur_run   = null;
	$grp_start = 0;
	foreach ($first_laps as $lap) {
		$lk  = $lap['run_number'] . '-' . $lap['lap_number'];
		$idx = $lk_to_idx[$lk] ?? null;
		if ($idx === null) continue;
		if ($lap['run_number'] !== $cur_run) {
			if ($cur_run !== null)
				$run_groups[] = ['label' => 'Run ' . $cur_run, 'start' => $grp_start, 'end' => $idx - 1];
			$cur_run   = $lap['run_number'];
			$grp_start = $idx;
		}
	}
	$run_groups[] = ['label' => 'Run ' . $cur_run, 'start' => $grp_start, 'end' => count($lap_keys) - 1];
}

// ── Car palette — color by car, dash style differentiates in multi-corner charts ──
// 9 distinct colors; 4 line-dash patterns cycling per car index.
$car_colors = ['#1599d4','#3ecf72','#f07820','#a880f0','#22d4e0','#f5c518','#e03c3c','#e4e8ee','#ff70b0'];
$car_dashes = [[], [6,3], [2,4], [8,2,2,2]]; // solid, dashed, dotted, dash-dot
$car_color_map = [];
$car_idx_map   = [];
foreach (array_values($sessions) as $i => $s) {
	$car_color_map[$s['car_alias']] = $car_colors[$i % count($car_colors)];
	$car_idx_map[$s['car_alias']]   = $i;
}

// Tire corner colours (same as single-car report)
$tc = ['FL' => '#4ab8ff', 'FR' => '#e03c3c', 'RL' => '#22d4e0', 'RR' => '#f07820'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function fleet_lap_series(array $laps, string $key, array $lap_keys): array
{
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

/**
 * Build one dataset per car for a single channel key.
 * Color = car color, dash = car index (for multi-car distinguishing).
 */
function fleet_car_ds(string $label, string $color, array $data, int $car_idx): array
{
	global $car_dashes;
	return [
		'label'           => $label,
		'data'            => $data,
		'borderColor'     => $color,
		'backgroundColor' => $color . '22',
		'borderWidth'     => 2,
		'pointRadius'     => 2,
		'tension'         => 0,
		'fill'            => false,
		'borderDash'      => $car_dashes[$car_idx % count($car_dashes)],
	];
}

/**
 * Build datasets for a single channel key across all cars.
 * $label_suffix is appended after the car alias (e.g. 'Water Temp').
 */
function fleet_all_cars_ds(string $key, string $label_suffix = ''): array
{
	global $sessions, $fleet_laps, $car_color_map, $car_idx_map, $lap_keys;
	$ds = [];
	foreach ($sessions as $s) {
		$alias = $s['car_alias'];
		$lbl   = $label_suffix ? $alias . ' ' . $label_suffix : $alias;
		$ds[]  = fleet_car_ds($lbl, $car_color_map[$alias],
		             fleet_lap_series($fleet_laps[$alias], $key, $lap_keys),
		             $car_idx_map[$alias]);
	}
	return $ds;
}

/**
 * Build tire-corner datasets: color = corner color, dash = car index.
 * Lets the engineer distinguish both corner (by color) and car (by dash).
 * $key_tpl: e.g. '{CORNER}_WS_TEMPERATURE_Avg'
 */
function fleet_tire_ds(string $key_tpl): array
{
	global $sessions, $fleet_laps, $car_idx_map, $tc, $lap_keys, $car_dashes;
	$corners = ['FL','FR','RL','RR'];
	$ds = [];
	foreach ($sessions as $s) {
		$alias = $s['car_alias'];
		$cidx  = $car_idx_map[$alias];
		$dash  = $car_dashes[$cidx % count($car_dashes)];
		foreach ($corners as $corner) {
			$key = str_replace('{CORNER}', $corner, $key_tpl);
			$ds[] = [
				'label'           => $alias . ' ' . $corner,
				'data'            => fleet_lap_series($fleet_laps[$alias], $key, $lap_keys),
				'borderColor'     => $tc[$corner],
				'backgroundColor' => $tc[$corner] . '22',
				'borderWidth'     => 2,
				'pointRadius'     => 2,
				'tension'         => 0,
				'fill'            => false,
				'borderDash'      => $dash,
			];
		}
	}
	return $ds;
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
	<script>
	// Motorsport annotations — run-group brackets only (pit/fast-lap are per-car)
	const motorsportPlugin = {
		id: 'motorsport',
		afterDraw(chart) {
			const md = (typeof motorsportGlobal !== 'undefined') ? motorsportGlobal : null;
			if (!md || !md.runGroups || md.runGroups.length <= 1) return;
			const ctx    = chart.ctx;
			const xScale = chart.scales.x;
			if (!xScale) return;
			const ca = chart.chartArea;

			function getX(idx) {
				var meta = chart.getDatasetMeta(0);
				if (!meta || !meta.data || !meta.data[idx]) return null;
				return meta.data[idx].x;
			}

			ctx.save();
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
				ctx.strokeStyle = '#4e5566';
				ctx.beginPath();
				ctx.moveTo(x1, yLine + 5); ctx.lineTo(x1, yLine);
				ctx.lineTo(x2, yLine);     ctx.lineTo(x2, yLine + 5);
				ctx.stroke();
				ctx.fillStyle = '#7a8394';
				ctx.fillText(g.label, (x1 + x2) / 2, yText);
			});
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
			<dt>Session</dt>
			<dd><?= htmlspecialchars($session_name) ?></dd>
		</div>
		<div class="meta-chip">
			<dt>Date</dt>
			<dd class="mono"><?= htmlspecialchars($session_date) ?></dd>
		</div>
		<div class="meta-chip">
			<dt>Cars</dt>
			<dd><?= count($sessions) ?></dd>
		</div>
		<div class="report-nav">
			<a href="index.php" class="btn btn-sm btn-outline">Dashboard</a>
			<button onclick="window.print()" class="btn btn-sm btn-outline">Print / PDF</button>
		</div>
	</div>

	<!-- ── Car legend strip ──────────────────────────────────────────────────── -->
	<div class="fleet-legend">
		<?php foreach ($sessions as $s):
			$alias = $s['car_alias'];
			$cidx  = $car_idx_map[$alias];
		?>
		<div class="fleet-legend-item">
			<span class="fleet-legend-swatch">
				<svg width="28" height="12" viewBox="0 0 28 12">
					<?php
					// Draw the line style (solid / dashed / dotted / dash-dot)
					$d = $car_dashes[$cidx % count($car_dashes)];
					$da = $d ? 'stroke-dasharray="' . implode(' ', $d) . '"' : '';
					?>
					<line x1="0" y1="6" x2="28" y2="6"
					      stroke="<?= htmlspecialchars($car_color_map[$alias]) ?>"
					      stroke-width="2.5" <?= $da ?>/>
				</svg>
			</span>
			<span class="car-badge"><?= htmlspecialchars($alias) ?></span>
			<?php if ($s['driver']): ?>
			<span class="fleet-legend-driver"><?= htmlspecialchars($s['driver']) ?></span>
			<?php endif; ?>
			<a href="report_single.php?session_id=<?= (int)$s['id'] ?>" class="link-action">↗ Report</a>
		</div>
		<?php endforeach; ?>
	</div>

	<script>
	const labelsGlobal     = <?= $lap_labels_json ?>;
	const motorsportGlobal = <?= json_encode(['runGroups' => $run_groups], JSON_UNESCAPED_UNICODE) ?>;
	</script>

	<?php
	$bottom_pad = (!empty($run_groups) && count($run_groups) > 1) ? 28 : 0;
	$flt_ci     = 0; // unique canvas ID counter

	// $scales_extra: keyed by axis id; 'y' is merged into default left axis.
	function fleet_render_chart(string $id, array $datasets, string $ylabel = '', array $scales_extra = []): void
	{
		global $bottom_pad;
		$ds_json = json_encode($datasets, JSON_UNESCAPED_UNICODE);

		$y_cfg = array_merge([
			'ticks' => ['color' => '#4e5566', 'font' => ['size' => 11]],
			'grid'  => ['color' => '#1a1d23'],
			'title' => ['display' => strlen($ylabel) > 0, 'text' => $ylabel,
			             'color' => '#4e5566', 'font' => ['size' => 11]],
		], $scales_extra['y'] ?? []);

		$scales = [
			'x' => [
				'ticks' => ['color' => '#4e5566', 'font' => ['size' => 11]],
				'grid'  => ['color' => '#1a1d23'],
				'title' => ['display' => true, 'text' => 'Lap',
				             'color' => '#4e5566', 'font' => ['size' => 11]],
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
						legend: { labels: { color: '#7a8394', font: { size: 11 }, boxWidth: 18 } },
						tooltip: { backgroundColor: '#1a1d23', borderColor: '#272c35', borderWidth: 1,
						           titleColor: '#e4e8ee', bodyColor: '#7a8394' }
					},
					scales: {$scales_json}
				}
			});
		}());
		</script>
		JS;
	}

	// Convenience macro: canvas + script in one call
	function fleet_chart(string $ylabel, array $datasets, array $scales_extra = []): void
	{
		global $flt_ci;
		$id = 'flt_' . $flt_ci++;
		echo '<div class="chart-wrap-full"><div class="chart-canvas-wrap"><canvas id="' . $id . '"></canvas></div></div>';
		fleet_render_chart($id, $datasets, $ylabel, $scales_extra);
	}
	?>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 1 — TIRES
	     Tire corner colour = corner identity; line dash = car identity.
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Tires</h2>
		<div class="chart-col">

			<p class="chart-sublabel">Tire Temperatures — all corners (colour = corner, dash = car)</p>
			<?php fleet_chart('°C', fleet_tire_ds('{CORNER}_WS_TEMPERATURE_Avg')); ?>

			<p class="chart-sublabel">Tire Pressures — all corners</p>
			<?php fleet_chart('PSI', fleet_tire_ds('{CORNER}_PSI_Avg'), ['y' => ['max' => 35]]); ?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 2 — FUEL
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Fuel</h2>
		<div class="chart-col">

			<p class="chart-sublabel">Fuel per Lap (L)</p>
			<?php fleet_chart('L / lap', fleet_all_cars_ds('FuelConsumptionL_Change')); ?>

			<p class="chart-sublabel">Total Fuel Used (L)</p>
			<?php fleet_chart('L total', fleet_all_cars_ds('NVRAM_TotalFuelConsumption_End')); ?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 3 — PERFORMANCE
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Performance</h2>
		<div class="chart-col">

			<p class="chart-sublabel">Lap Times (sec)</p>
			<?php
			// Compute a sensible y-axis max from all cars' lap times
			$_all_lt = [];
			foreach ($sessions as $_s) {
				foreach (fleet_lap_series($fleet_laps[$_s['car_alias']], 'LapTimeSeconds_End', $lap_keys) as $_v) {
					if ($_v !== null && $_v > 30) $_all_lt[] = $_v;
				}
			}
			$_lt_scale = [];
			if (count($_all_lt) >= 3) {
				sort($_all_lt);
				$_p90      = $_all_lt[(int)floor(0.90 * (count($_all_lt) - 1))];
				$_lt_scale = ['y' => [
					'max'   => (int)ceil($_p90 * 1.12),
					'ticks' => [
						'color'    => '#4e5566',
						'font'     => ['size' => 11],
						'callback' => 'LAPTIME_CB',
					],
				]];
			}
			unset($_all_lt, $_p90);
			// We can't inject a JS function into json_encode — output the chart manually
			$_lap_ds  = fleet_all_cars_ds('LapTimeSeconds_End');
			$_ds_json = json_encode($_lap_ds, JSON_UNESCAPED_UNICODE);
			$_bp      = $bottom_pad;
			$_lap_id  = 'flt_' . $flt_ci++;
			$_ymax    = isset($_lt_scale['y']['max']) ? (int)$_lt_scale['y']['max'] : 'undefined';
			echo '<div class="chart-wrap-full"><div class="chart-canvas-wrap"><canvas id="' . $_lap_id . '"></canvas></div></div>';
			?>
			<script>
			(function(){
				var ctx = document.getElementById('<?= $_lap_id ?>').getContext('2d');
				new Chart(ctx, {
					type: 'line',
					data: { labels: labelsGlobal, datasets: <?= $_ds_json ?> },
					options: {
						responsive: true,
						maintainAspectRatio: false,
						layout: { padding: { bottom: <?= $_bp ?> } },
						interaction: { mode: 'index', intersect: false },
						plugins: {
							legend: { labels: { color: '#7a8394', font: { size: 11 }, boxWidth: 18 } },
							tooltip: {
								backgroundColor: '#1a1d23', borderColor: '#272c35', borderWidth: 1,
								titleColor: '#e4e8ee', bodyColor: '#7a8394',
								callbacks: {
									label: function(ctx) {
										var v = ctx.parsed.y;
										if (v === null || v <= 0) return ctx.dataset.label + ': —';
										var m = Math.floor(v/60);
										var s = (v - m*60).toFixed(3);
										if (parseFloat(s) < 10) s = '0' + s;
										return ctx.dataset.label + ': ' + m + ':' + s;
									}
								}
							}
						},
						scales: {
							x: { ticks: { color:'#4e5566', font:{size:11} }, grid: { color:'#1a1d23' },
							     title: { display:true, text:'Lap', color:'#4e5566', font:{size:11} } },
							y: { max: <?= $_ymax ?>,
							     ticks: {
							         color:'#4e5566', font:{size:11},
							         callback: function(v) {
							             if (!v || v <= 0) return v;
							             var m = Math.floor(v/60);
							             var s = (v - m*60).toFixed(1);
							             if (parseFloat(s) < 10) s = '0' + s;
							             return m + ':' + s;
							         }
							     },
							     grid: { color:'#1a1d23' },
							     title: { display:true, text:'min:sec', color:'#4e5566', font:{size:11} } }
						}
					}
				});
			}());
			</script>
			<?php

			echo '<p class="chart-sublabel">Speed (km/h)</p>';
			$_speed_ds = array_merge(
				fleet_all_cars_ds('VehicleSpeedVSOSig_Max', 'Max'),
				fleet_all_cars_ds('VehicleSpeedVSOSig_Min', 'Min')
			);
			fleet_chart('km/h', $_speed_ds);
			?>
		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 4 — ENGINE TEMPS
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Engine Temps</h2>
		<div class="chart-col">

			<p class="chart-sublabel">Water & Oil Temperature (°C)</p>
			<?php
			$_eng_ds = array_merge(
				fleet_all_cars_ds('EngineWaterTemp_Avg', 'Water'),
				fleet_all_cars_ds('EngineOilTemperature_Avg', 'Oil')
			);
			fleet_chart('°C', $_eng_ds);
			?>

			<p class="chart-sublabel">Intake Air Temperature (°C)</p>
			<?php
			$_intk_ds = array_merge(
				fleet_all_cars_ds('IntkAirTempMnfld_SX_Avg', 'Intake SX'),
				fleet_all_cars_ds('IntkAirTempMnfld_DX_Avg', 'Intake DX')
			);
			fleet_chart('°C', $_intk_ds);
			?>

		</div>
	</section>

	<!-- ═══════════════════════════════════════════════════════════════════════
	     SECTION 5 — DRIVER SETTINGS
	     ═══════════════════════════════════════════════════════════════════════ -->
	<section class="chart-section">
		<h2>Driver Settings</h2>
		<div class="chart-col">

			<p class="chart-sublabel">ManABS</p>
			<?php fleet_chart('setting', fleet_all_cars_ds('ManABS_4_FBO_Avg'), ['y' => ['min' => 0, 'max' => 5, 'ticks' => ['stepSize' => 1]]]); ?>

			<p class="chart-sublabel">Man1 (TC)</p>
			<?php fleet_chart('setting', fleet_all_cars_ds('Man1_4_FBO_End'), ['y' => ['min' => 0, 'max' => 5, 'ticks' => ['stepSize' => 1]]]); ?>

			<p class="chart-sublabel">Man2 (TC)</p>
			<?php fleet_chart('setting', fleet_all_cars_ds('Man2_4_FBO_End'), ['y' => ['min' => 0, 'max' => 5, 'ticks' => ['stepSize' => 1]]]); ?>

		</div>
	</section>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
