<?php
// ─── MG1 Engineering Report System — index.php (Dashboard) ───────────────────

require_once __DIR__ . '/db.php';

$db = get_db();

// All sessions, with lap counts, newest first
$sessions = $db->query(
	"SELECT s.*,
	        COUNT(l.id) AS lap_count
	 FROM sessions s
	 LEFT JOIN laps l ON l.session_id = s.id
	 GROUP BY s.id
	 ORDER BY s.session_date DESC, s.session_name ASC"
)->fetchAll();

// Group by session_date for display
$by_date = [];
foreach ($sessions as $s) {
	$by_date[$s['session_date']][] = $s;
}

// All distinct session_dates+session_names for fleet links (same date, same session)
$fleet_groups = [];
foreach ($by_date as $date => $day) {
	$by_sn = [];
	foreach ($day as $s) {
		$by_sn[$s['session_name']][] = $s;
	}
	// Only sessions with >1 car qualify for a fleet comparison
	foreach ($by_sn as $sn => $cars) {
		if (count($cars) > 1) {
			$fleet_groups[$date][$sn] = count($cars);
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Dashboard — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<div class="page-head">
		<h1>Dashboard</h1>
		<a href="upload.php" class="btn btn-primary">&#43; Upload CSV</a>
	</div>

	<?php if (empty($sessions)): ?>
	<div class="empty-state">
		<p>No sessions loaded yet.</p>
		<a href="upload.php" class="btn btn-primary">Upload your first CSV</a>
	</div>

	<?php else: ?>

		<?php foreach ($by_date as $date => $day_sessions): ?>
		<section class="day-group">

			<div class="day-group-header">
				<h2 class="day-date"><?= date('l, j F Y', strtotime($date)) ?></h2>
				<?php if (!empty($fleet_groups[$date])): ?>
				<div class="fleet-links">
					<?php foreach ($fleet_groups[$date] as $sn => $car_count): ?>
					<a href="report_fleet.php?date=<?= urlencode($date) ?>&amp;session=<?= urlencode($sn) ?>"
					   class="btn btn-sm btn-outline">
						Fleet: <?= htmlspecialchars($sn) ?> (<?= $car_count ?> cars)
					</a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<div class="session-grid">
				<?php foreach ($day_sessions as $s): ?>
				<div class="session-card">
					<div class="session-card-top">
						<span class="car-badge"><?= htmlspecialchars($s['car_alias']) ?></span>
						<span class="session-tag"><?= htmlspecialchars($s['session_name']) ?></span>
					</div>
					<dl class="session-meta">
						<?php if ($s['driver']): ?>
						<div class="meta-row">
							<dt>Driver</dt>
							<dd><?= htmlspecialchars($s['driver']) ?></dd>
						</div>
						<?php endif; ?>
						<?php if ($s['track']): ?>
						<div class="meta-row">
							<dt>Track</dt>
							<dd><?= htmlspecialchars($s['track']) ?></dd>
						</div>
						<?php endif; ?>
						<div class="meta-row">
							<dt>Laps</dt>
							<dd><?= (int)$s['lap_count'] ?></dd>
						</div>
					</dl>
					<div class="session-card-actions">
						<a href="report_single.php?session_id=<?= (int)$s['id'] ?>"
						   class="btn btn-sm btn-primary">Report</a>
						<a href="report_trend.php?car=<?= urlencode($s['car_alias']) ?>"
						   class="btn btn-sm btn-outline">Trend</a>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

		</section>
		<?php endforeach; ?>

	<?php endif; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
