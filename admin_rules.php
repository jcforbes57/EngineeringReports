<?php
// ─── MG1 Engineering Report System — admin_rules.php ─────────────────────────
// CRUD interface for warning rules.

require_once __DIR__ . '/db.php';

$db      = get_db();
$msg     = null;
$edit    = null;
$errors  = [];

$sections  = ['tires', 'fuel', 'performance', 'engine', 'life'];
$stats     = ['Avg', 'Change', 'End', 'Max', 'Min', 'Info'];
$operators = ['>', '<', '>=', '<=', '=', '!='];
$compare_opts = [
	'absolute'      => 'Absolute value',
	'fleet_avg'     => 'Fleet average (+ threshold offset)',
	'prev_session'  => 'Previous session',
	'other_channel' => 'Another channel',
];
$lap_filters = ['any' => 'Any lap', 'first' => 'First lap only', 'last' => 'Last lap only', 'fast_lap' => 'Fast lap only'];

// ── Actions ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete' && isset($_GET['id'])) {
	$db->prepare("DELETE FROM warning_rules WHERE id = ?")->execute([(int)$_GET['id']]);
	$msg = ['type' => 'success', 'text' => 'Rule deleted.'];
}

if ($action === 'toggle' && isset($_GET['id'])) {
	$db->prepare("UPDATE warning_rules SET active = 1 - active WHERE id = ?")->execute([(int)$_GET['id']]);
}

if ($action === 'edit' && isset($_GET['id'])) {
	$edit = $db->prepare("SELECT * FROM warning_rules WHERE id = ?")->execute([(int)$_GET['id']])
		? $db->query("SELECT * FROM warning_rules WHERE id = " . (int)$_GET['id'])->fetch()
		: null;
	$stmt = $db->prepare("SELECT * FROM warning_rules WHERE id = ?");
	$stmt->execute([(int)$_GET['id']]);
	$edit = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'update'])) {
	$f = $_POST;

	// Validate
	if (empty($f['channel']))   $errors[] = 'Channel is required.';
	if (empty($f['stat']))      $errors[] = 'Stat type is required.';
	if (empty($f['operator']))  $errors[] = 'Operator is required.';
	if (empty($f['message']))   $errors[] = 'Message is required.';
	if (!in_array($f['chart_section'] ?? '', $sections)) $errors[] = 'Invalid section.';

	if (empty($errors)) {
		$vals = [
			$f['chart_section'],
			trim($f['channel']),
			$f['stat'],
			$f['operator'],
			$f['threshold'] !== '' ? (float)$f['threshold'] : null,
			$f['compare_to'],
			trim($f['compare_target'] ?? '') ?: null,
			$f['lap_filter'] ?? 'any',
			trim($f['message']),
			isset($f['active']) ? 1 : 0,
			(int)($f['sort_order'] ?? 0),
		];

		if ($action === 'update' && !empty($f['rule_id'])) {
			$vals[] = (int)$f['rule_id'];
			$db->prepare(
				"UPDATE warning_rules SET
				   chart_section=?, channel=?, stat=?, operator=?,
				   threshold=?, compare_to=?, compare_target=?, lap_filter=?,
				   message=?, active=?, sort_order=?
				 WHERE id=?"
			)->execute($vals);
			// POST-Redirect-GET: redirect to edit so the form reflects the saved state
			header('Location: admin_rules.php?action=edit&id=' . (int)$f['rule_id'] . '&msg=updated');
			exit;
		} else {
			$db->prepare(
				"INSERT INTO warning_rules
				   (chart_section, channel, stat, operator, threshold, compare_to, compare_target, lap_filter, message, active, sort_order)
				 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
			)->execute($vals);
			$new_id = (int)$db->lastInsertId();
			// POST-Redirect-GET: redirect to edit so the form reflects the saved state
			header('Location: admin_rules.php?action=edit&id=' . $new_id . '&msg=created');
			exit;
		}
	} else {
		// Validation failed — repopulate the form with submitted values so the
		// user doesn't lose their input (especially lap_filter, channel, etc.)
		$edit = array_merge($f, ['id' => isset($f['rule_id']) ? (int)$f['rule_id'] : null]);
	}
}

// Flash message from redirect (POST-Redirect-GET pattern)
if (!$msg && isset($_GET['msg'])) {
	if ($_GET['msg'] === 'created') $msg = ['type' => 'success', 'text' => 'Rule created.'];
	if ($_GET['msg'] === 'updated') $msg = ['type' => 'success', 'text' => 'Rule updated.'];
}

// ── Fetch all rules ───────────────────────────────────────────────────────────
$rules = $db->query(
	"SELECT * FROM warning_rules ORDER BY chart_section ASC, sort_order ASC, id ASC"
)->fetchAll();

// ── Available channel names from imported lap data ─────────────────────────
// Sample one lap row and strip stat suffixes to produce a sorted list of
// base channel names and a per-channel map of which stat types exist.
$available_channels = [];
$channel_stats      = [];
$sample = $db->query("SELECT data FROM laps ORDER BY id DESC LIMIT 1")->fetch();
if ($sample) {
	$sample_data = json_decode($sample['data'], true) ?? [];
	foreach (array_keys($sample_data) as $key) {
		if (preg_match('/^(.+)_(Avg|Change|End|Max|Min|Info)$/', $key, $m)) {
			$available_channels[$m[1]] = true;
			$channel_stats[$m[1]][]   = $m[2];
		}
	}
	ksort($available_channels);
	$available_channels = array_keys($available_channels);
	// Sort each channel's stat list in the canonical $stats order
	foreach ($channel_stats as &$_sl) {
		usort($_sl, fn($a, $b) => array_search($a, $stats) <=> array_search($b, $stats));
	}
	unset($_sl);
	ksort($channel_stats);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Warning Rules — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<div class="page-head">
		<h1>Warning Rules</h1>
	</div>

	<?php if ($msg): ?>
	<div class="alert alert-<?= $msg['type'] ?>">
		<p><?= htmlspecialchars($msg['text']) ?></p>
	</div>
	<?php endif; ?>

	<?php if (!empty($errors)): ?>
	<div class="alert alert-error">
		<?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- ── Add / Edit form ──────────────────────────────────────────────────── -->
	<div class="card" style="margin-bottom:32px">
		<h2 style="font-size:15px;margin-bottom:18px">
			<?= $edit ? 'Edit Rule #' . (int)$edit['id'] : 'New Rule' ?>
		</h2>
		<form method="post">
			<input type="hidden" name="action" value="<?= $edit ? 'update' : 'save' ?>">
			<?php if ($edit): ?>
			<input type="hidden" name="rule_id" value="<?= (int)$edit['id'] ?>">
			<?php endif; ?>

			<div class="form-grid">
				<div class="form-group">
					<label for="r_section">Section</label>
					<select id="r_section" name="chart_section" required>
						<?php foreach ($sections as $s): ?>
						<option value="<?= $s ?>" <?= ($edit['chart_section'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="r_lap">Apply to lap</label>
					<select id="r_lap" name="lap_filter">
						<?php foreach ($lap_filters as $v => $label): ?>
						<option value="<?= $v ?>" <?= ($edit['lap_filter'] ?? 'any') === $v ? 'selected' : '' ?>><?= $label ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="r_channel">Channel</label>
					<?php if (!empty($available_channels)): ?>
					<select id="r_channel" name="channel" required>
						<option value="">— select —</option>
						<?php
						// Always include the currently-saved channel even if it no longer
						// appears in the sample data (renamed or from a deleted session).
						$current_ch = $edit['channel'] ?? '';
						if ($current_ch !== '' && !in_array($current_ch, $available_channels, true)): ?>
						<option value="<?= htmlspecialchars($current_ch) ?>" selected>
							<?= htmlspecialchars($current_ch) ?> (not in current data)
						</option>
						<?php endif; ?>
						<?php foreach ($available_channels as $ch): ?>
						<option value="<?= htmlspecialchars($ch) ?>" <?= $current_ch === $ch ? 'selected' : '' ?>>
							<?= htmlspecialchars($ch) ?>
						</option>
						<?php endforeach; ?>
					</select>
					<?php else: ?>
					<input type="text" id="r_channel" name="channel" required
					       placeholder="e.g. FuelConsumptionL"
					       value="<?= htmlspecialchars($edit['channel'] ?? '') ?>">
					<small style="color:var(--text-muted);display:block;margin-top:4px">Import session data first to enable the channel picker.</small>
					<?php endif; ?>
				</div>

				<div class="form-group">
					<label for="r_stat">Stat</label>
					<select id="r_stat" name="stat" required>
						<?php foreach ($stats as $st): ?>
						<option value="<?= $st ?>" <?= ($edit['stat'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="r_op">Operator</label>
					<select id="r_op" name="operator" required>
						<?php foreach ($operators as $op): ?>
						<option value="<?= $op ?>" <?= ($edit['operator'] ?? '') === $op ? 'selected' : '' ?>><?= $op ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="r_thresh">Threshold value</label>
					<input type="number" id="r_thresh" name="threshold" step="any"
					       placeholder="e.g. 10"
					       value="<?= htmlspecialchars($edit['threshold'] ?? '') ?>">
				</div>

				<div class="form-group">
					<label for="r_cmp">Compare to</label>
					<select id="r_cmp" name="compare_to">
						<?php foreach ($compare_opts as $v => $label): ?>
						<option value="<?= $v ?>" <?= ($edit['compare_to'] ?? 'absolute') === $v ? 'selected' : '' ?>><?= $label ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="r_target">Compare target (channel key or blank)</label>
					<input type="text" id="r_target" name="compare_target"
					       placeholder="e.g. EngineWaterTemp_Avg"
					       value="<?= htmlspecialchars($edit['compare_target'] ?? '') ?>">
				</div>

				<div class="form-group form-full">
					<label for="r_msg">Warning message</label>
					<textarea id="r_msg" name="message" required
					          placeholder="Displayed inline on the report…"><?= htmlspecialchars($edit['message'] ?? '') ?></textarea>
				</div>

				<div class="form-group">
					<label for="r_order">Sort order</label>
					<input type="number" id="r_order" name="sort_order"
					       value="<?= (int)($edit['sort_order'] ?? 0) ?>">
				</div>

				<div class="form-group" style="justify-content:flex-end;flex-direction:row;align-items:center;gap:10px;padding-top:20px">
					<label style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0">
						<input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>
						Active
					</label>
				</div>
			</div>

			<div style="margin-top:20px;display:flex;gap:10px">
				<button type="submit" class="btn btn-primary">
					<?= $edit ? 'Save Changes' : 'Add Rule' ?>
				</button>
				<?php if ($edit): ?>
				<a href="admin_rules.php" class="btn btn-outline">Cancel</a>
				<?php endif; ?>
			</div>
		</form>
	</div>

	<!-- ── Rules table ──────────────────────────────────────────────────────── -->
	<?php if (empty($rules)): ?>
	<p class="muted">No rules defined yet.</p>
	<?php else: ?>
	<table class="data-table">
		<thead>
			<tr>
				<th>#</th>
				<th>Section</th>
				<th>Rule</th>
				<th>Compare</th>
				<th>Lap</th>
				<th>Message</th>
				<th class="num">Order</th>
				<th>Active</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rules as $r): ?>
			<tr>
				<td class="mono"><?= (int)$r['id'] ?></td>
				<td><?= htmlspecialchars($r['chart_section']) ?></td>
				<td class="mono">
					<?= htmlspecialchars($r['channel'] . '_' . $r['stat']) ?>
					<?= htmlspecialchars($r['operator']) ?>
					<?= $r['threshold'] !== null ? htmlspecialchars($r['threshold']) : '—' ?>
				</td>
				<td><?= htmlspecialchars($compare_opts[$r['compare_to']] ?? $r['compare_to']) ?></td>
				<td><?= htmlspecialchars($lap_filters[$r['lap_filter']] ?? $r['lap_filter']) ?></td>
				<td style="max-width:260px;white-space:normal"><?= htmlspecialchars($r['message']) ?></td>
				<td class="num"><?= (int)$r['sort_order'] ?></td>
				<td>
					<a href="admin_rules.php?action=toggle&id=<?= (int)$r['id'] ?>"
					   class="badge <?= $r['active'] ? 'badge-complete' : 'badge-pending' ?>">
						<?= $r['active'] ? 'On' : 'Off' ?>
					</a>
				</td>
				<td style="white-space:nowrap">
					<a href="admin_rules.php?action=edit&id=<?= (int)$r['id'] ?>" class="link-action">Edit</a>
					&nbsp;
					<a href="admin_rules.php?action=delete&id=<?= (int)$r['id'] ?>"
					   class="link-action" style="color:var(--error)"
					   onclick="return confirm('Delete this rule?')">Del</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

<script>
(function () {
	// Stat types available per channel, built from the most recent lap's JSON keys.
	var channelStats = <?= json_encode($channel_stats, JSON_UNESCAPED_UNICODE) ?>;
	var allStats     = <?= json_encode($stats,         JSON_UNESCAPED_UNICODE) ?>;

	var chSel   = document.getElementById('r_channel');
	var statSel = document.getElementById('r_stat');
	if (!chSel || !statSel) return;

	function refreshStats() {
		var ch  = chSel.value;
		// If we know which stats exist for this channel, use that list;
		// otherwise fall back to showing all stat types.
		var available = (ch && channelStats[ch]) ? channelStats[ch] : allStats;
		var current   = statSel.value; // preserve selection across refresh
		statSel.innerHTML = '';
		available.forEach(function (st) {
			var opt = document.createElement('option');
			opt.value = opt.textContent = st;
			if (st === current) opt.selected = true;
			statSel.appendChild(opt);
		});
		// If the previous stat is not in the filtered list, default to the first available.
		if (!statSel.value && statSel.options.length) {
			statSel.options[0].selected = true;
		}
	}

	chSel.addEventListener('change', refreshStats);
	refreshStats(); // gate on page load (important when editing an existing rule)
}());
</script>

</body>
</html>
