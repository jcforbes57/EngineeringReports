<?php
// ─── MG1 Engineering Report System — index.php (Dashboard) ───────────────────

require_once __DIR__ . '/db.php';

$db = get_db();

// All sessions newest-date first, then by session name, then car alias
$sessions = $db->query(
	"SELECT s.*,
	        COUNT(l.id) AS lap_count
	 FROM sessions s
	 LEFT JOIN laps l ON l.session_id = s.id
	 GROUP BY s.id
	 ORDER BY s.session_date DESC, s.session_name ASC, s.car_alias ASC"
)->fetchAll();

// Group: date → session_name → [car rows]
$by_date = [];
foreach ($sessions as $s) {
	$by_date[$s['session_date']][$s['session_name']][] = $s;
}

// Most recent date/session — these are the only ones expanded by default
$first_date    = !empty($by_date) ? array_key_first($by_date) : null;
$first_session = $first_date      ? array_key_first($by_date[$first_date]) : null;
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

	<?php foreach ($by_date as $date => $date_sessions):
		$is_first_date = ($date === $first_date);
		$total_cars    = array_sum(array_map('count', $date_sessions));
		$sn_count      = count($date_sessions);
	?>
	<details class="dash-date" <?= $is_first_date ? 'open' : '' ?>>
		<summary class="dash-date-summary">
			<span class="dash-chevron"></span>
			<span class="dash-date-name"><?= date('l, j F Y', strtotime($date)) ?></span>
			<span class="dash-date-meta">
				<?= $sn_count ?> session<?= $sn_count !== 1 ? 's' : '' ?>
				&middot;
				<?= $total_cars ?> car<?= $total_cars !== 1 ? 's' : '' ?>
			</span>
		</summary>

		<div class="dash-date-body">

		<?php foreach ($date_sessions as $sn => $cars):
			$is_first_session = ($is_first_date && $sn === $first_session);
			$car_count        = count($cars);
		?>
		<details class="dash-session" <?= $is_first_session ? 'open' : '' ?>>
			<summary class="dash-session-summary">
				<span class="dash-chevron"></span>
				<span class="dash-session-name"><?= htmlspecialchars($sn) ?></span>
				<span class="dash-session-meta"><?= $car_count ?> car<?= $car_count !== 1 ? 's' : '' ?></span>
				<?php if ($car_count > 1): ?>
				<a href="report_fleet.php?date=<?= urlencode($date) ?>&amp;session=<?= urlencode($sn) ?>"
				   class="btn btn-sm btn-outline dash-fleet-btn"
				   onclick="event.stopPropagation()">Fleet View</a>
				<?php endif; ?>
			</summary>

			<div class="dash-session-body">
				<table class="data-table">
					<thead>
						<tr>
							<th>Car</th>
							<th>Driver</th>
							<th>Track</th>
							<th class="num">Laps</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($cars as $s): ?>
						<tr>
							<td><span class="car-badge"><?= htmlspecialchars($s['car_alias']) ?></span></td>
							<td><?= $s['driver'] ? htmlspecialchars($s['driver']) : '<span class="muted">—</span>' ?></td>
							<td><?= $s['track']  ? htmlspecialchars($s['track'])  : '<span class="muted">—</span>' ?></td>
							<td class="num mono"><?= (int)$s['lap_count'] ?></td>
							<td class="dash-actions">
								<a href="report_single.php?session_id=<?= (int)$s['id'] ?>"
								   class="btn btn-sm btn-primary">Report</a>
								<a href="report_trend.php?car=<?= urlencode($s['car_alias']) ?>"
								   class="btn btn-sm btn-outline">Trend</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>

		<?php endforeach; ?>

		</div><!-- .dash-date-body -->
	</details>

	<?php endforeach; ?>

	<?php endif; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

</body>
</html>
