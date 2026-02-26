<?php
// ─── MG1 Engineering Report System — upload.php ───────────────────────────
// CSV upload handler + upload history.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/parse_csv.php';

$errors    = [];
$success   = null;
$upload_id = null;

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$file_err = $_FILES['csv_file']['error'] ?? -1;

	if (!isset($_FILES['csv_file']) || $file_err !== UPLOAD_ERR_OK) {
		$errors[] = 'Upload failed: ' . upload_err_message($file_err);
	} else {
		$file = $_FILES['csv_file'];
		$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

		if ($ext !== 'csv') {
			$errors[] = 'Only .csv files are accepted.';
		} elseif ($file['size'] === 0) {
			$errors[] = 'Uploaded file is empty.';
		} elseif ($file['size'] > 20 * 1024 * 1024) {
			$errors[] = 'File too large (maximum 20 MB).';
		}

		if (empty($errors)) {
			// Ensure upload directory exists
			if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
				$errors[] = 'Cannot create upload directory.';
			}
		}

		if (empty($errors)) {
			$safe_name   = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
			$stored_path = UPLOAD_DIR . $safe_name;

			if (!move_uploaded_file($file['tmp_name'], $stored_path)) {
				$errors[] = 'Failed to save file to disk.';
			} else {
				$db = get_db();

				// Record the upload immediately so we can track errors too
				$stmt = $db->prepare(
					"INSERT INTO uploads (filename, original_name, status) VALUES (?, ?, 'processing')"
				);
				$stmt->execute([$safe_name, $file['name']]);
				$upload_id = (int)$db->lastInsertId();

				try {
					// ── Parse CSV ─────────────────────────────────────────────
					$parsed   = parse_wintax_csv($stored_path);
					$ses_meta = extract_session_meta($parsed);

					if (!$ses_meta['car_alias']) {
						throw new \RuntimeException(
							"CarAlias not found. Ensure WinTax export includes the three appended columns (CarAlias, SessionName, SessionDate)."
						);
					}
					if (!$ses_meta['session_date']) {
						throw new \RuntimeException(
							"Session date could not be parsed from '{$ses_meta['session_name']}'. Check the CSV header."
						);
					}
					if (!$ses_meta['session_name']) {
						throw new \RuntimeException("Session name is missing from the CSV.");
					}

					// ── Upsert session ────────────────────────────────────────
					// ON DUPLICATE KEY: update track/driver, and return existing id via LAST_INSERT_ID trick.
					$stmt = $db->prepare(
						"INSERT INTO sessions (car_alias, session_name, session_date, track, driver)
						 VALUES (?, ?, ?, ?, ?)
						 ON DUPLICATE KEY UPDATE
						   track   = VALUES(track),
						   driver  = VALUES(driver),
						   id      = LAST_INSERT_ID(id)"
					);
					$stmt->execute([
						$ses_meta['car_alias'],
						$ses_meta['session_name'],
						$ses_meta['session_date'],
						$ses_meta['track'],
						$ses_meta['driver'],
					]);
					$session_id = (int)$db->lastInsertId();

					// ── Delete stale laps (re-upload) ─────────────────────────
					$db->prepare("DELETE FROM laps WHERE session_id = ?")->execute([$session_id]);

					// ── Insert laps ───────────────────────────────────────────
					$lap_stmt = $db->prepare(
						"INSERT INTO laps (session_id, run_number, lap_number, data) VALUES (?, ?, ?, ?)"
					);
					$lap_col   = $parsed['lap_col'];
					$lap_count = 0;

					// Build a normalised run map from Run_Info if present.
					// Raw WinTax run numbers (e.g. 300, 301) are mapped to 1-based
					// integers so run 1 = first run seen in this upload, etc.
					$run_col = in_array('Run_Info', $parsed['columns']) ? 'Run_Info' : null;
					$run_map = [];
					if ($run_col !== null) {
						$seq = 0;
						foreach ($parsed['laps'] as $lap_data) {
							$raw = (string)($lap_data[$run_col] ?? '');
							if ($raw !== '' && !isset($run_map[$raw])) {
								$run_map[$raw] = ++$seq;
							}
						}
					}

					$db->beginTransaction();
					$prev_lap_num = PHP_INT_MAX;
					$fallback_run = 1;
					foreach ($parsed['laps'] as $idx => $lap_data) {
						$lap_num = resolve_lap_number($lap_data, $lap_col, $idx);

						if ($run_col !== null) {
							$raw     = (string)($lap_data[$run_col] ?? '');
							$run_num = $run_map[$raw] ?? 1;
						} else {
							// Fallback: detect counter reset by lap number decreasing
							if ($idx > 0 && $lap_num < $prev_lap_num) {
								$fallback_run++;
							}
							$run_num = $fallback_run;
						}
						$prev_lap_num = $lap_num;

						$lap_stmt->execute([
							$session_id,
							$run_num,
							$lap_num,
							json_encode($lap_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
						]);
						$lap_count++;
					}
					$db->commit();

					// ── Mark upload complete ──────────────────────────────────
					$db->prepare(
						"UPDATE uploads SET status='complete', session_id=?, row_count=? WHERE id=?"
					)->execute([$session_id, $lap_count, $upload_id]);

					$success = [
						'car_alias'    => $ses_meta['car_alias'],
						'session_name' => $ses_meta['session_name'],
						'session_date' => $ses_meta['session_date'],
						'driver'       => $ses_meta['driver'],
						'track'        => $ses_meta['track'],
						'lap_count'    => $lap_count,
						'session_id'   => $session_id,
					];

				} catch (\Throwable $e) {
					if ($db->inTransaction()) {
						$db->rollBack();
					}
					$msg      = $e->getMessage();
					$errors[] = $msg;
					$db->prepare(
						"UPDATE uploads SET status='error', error_message=? WHERE id=?"
					)->execute([$msg, $upload_id]);
				}
			}
		}
	}
}

// ── Fetch recent uploads for history table ────────────────────────────────────
$db             = get_db();
$recent_uploads = $db->query(
	"SELECT u.*,
	        s.car_alias, s.session_name, s.session_date, s.track, s.driver
	 FROM uploads u
	 LEFT JOIN sessions s ON u.session_id = s.id
	 ORDER BY u.uploaded_at DESC
	 LIMIT 25"
)->fetchAll();

// ── Helper ────────────────────────────────────────────────────────────────────
function upload_err_message(int $code): string
{
	switch ($code) {
		case UPLOAD_ERR_INI_SIZE:   return 'File exceeds server upload_max_filesize limit';
		case UPLOAD_ERR_FORM_SIZE:  return 'File exceeds the form MAX_FILE_SIZE limit';
		case UPLOAD_ERR_PARTIAL:    return 'File was only partially uploaded';
		case UPLOAD_ERR_NO_FILE:    return 'No file was selected';
		case UPLOAD_ERR_NO_TMP_DIR: return 'Server temporary directory is missing';
		case UPLOAD_ERR_CANT_WRITE: return 'Server failed to write file to disk';
		case UPLOAD_ERR_EXTENSION:  return 'A PHP extension blocked the upload';
		default:                    return "Unknown error (code $code)";
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Upload CSV — MG1 Reports</title>
	<link rel="stylesheet" href="mg1.css">
</head>
<body>

<?php include __DIR__ . '/inc_header.php'; ?>

<main class="container">

	<div class="page-head">
		<h1>Upload Run Summary CSV</h1>
	</div>

	<?php if (!empty($errors)): ?>
	<div class="alert alert-error">
		<?php foreach ($errors as $e): ?>
		<p><?= htmlspecialchars($e) ?></p>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ($success): ?>
	<div class="alert alert-success">
		<p><strong>Import successful</strong> — <?= (int)$success['lap_count'] ?> laps loaded.</p>
		<p class="alert-detail">
			<?= htmlspecialchars($success['car_alias']) ?> &middot;
			<?= htmlspecialchars($success['session_name']) ?> &middot;
			<?= htmlspecialchars($success['session_date']) ?>
			<?php if ($success['driver']): ?>
			&middot; <?= htmlspecialchars($success['driver']) ?>
			<?php endif; ?>
			<?php if ($success['track']): ?>
			&middot; <?= htmlspecialchars($success['track']) ?>
			<?php endif; ?>
		</p>
		<a href="report_single.php?session_id=<?= (int)$success['session_id'] ?>" class="btn btn-primary">
			View Report &rarr;
		</a>
	</div>
	<?php endif; ?>

	<div class="card upload-card">
		<h2>Select file</h2>
		<p class="hint">
			Export from WinTax: <em>Run Summary &rarr; Export to CSV</em>.
			The file must include the three appended columns (CarAlias, SessionName, SessionDate).
		</p>
		<form method="post" enctype="multipart/form-data" id="upload-form">
			<div class="file-drop-zone" id="drop-zone">
				<span class="drop-icon">&#8659;</span>
				<span class="drop-text">Drop CSV here or <label for="csv_file" class="file-label">browse</label></span>
				<input type="file" id="csv_file" name="csv_file" accept=".csv" required>
				<span class="drop-filename" id="drop-filename"></span>
			</div>
			<div class="form-actions">
				<button type="submit" class="btn btn-primary" id="upload-btn">Upload &amp; Import</button>
			</div>
		</form>
	</div>

	<?php if (!empty($recent_uploads)): ?>
	<section class="upload-history">
		<h2>Upload History</h2>
		<table class="data-table">
			<thead>
				<tr>
					<th>Time</th>
					<th>Original File</th>
					<th>Car</th>
					<th>Session</th>
					<th>Date</th>
					<th>Track</th>
					<th class="num">Laps</th>
					<th>Status</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($recent_uploads as $row): ?>
				<tr>
					<td class="mono"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['uploaded_at']))) ?></td>
					<td class="filename"><?= htmlspecialchars($row['original_name'] ?? $row['filename']) ?></td>
					<td class="mono"><?= htmlspecialchars($row['car_alias'] ?? '—') ?></td>
					<td><?= htmlspecialchars($row['session_name'] ?? '—') ?></td>
					<td class="mono"><?= htmlspecialchars($row['session_date'] ?? '—') ?></td>
					<td><?= htmlspecialchars($row['track'] ?? '—') ?></td>
					<td class="num"><?= $row['row_count'] !== null ? (int)$row['row_count'] : '—' ?></td>
					<td><span class="badge badge-<?= $row['status'] ?>"><?= $row['status'] ?></span></td>
					<td>
						<?php if ($row['session_id']): ?>
						<a href="report_single.php?session_id=<?= (int)$row['session_id'] ?>" class="link-action">Report</a>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ($row['status'] === 'error' && $row['error_message']): ?>
				<tr class="error-detail-row">
					<td colspan="9">
						<span class="error-msg"><?= htmlspecialchars($row['error_message']) ?></span>
					</td>
				</tr>
				<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

</main>

<?php include __DIR__ . '/inc_footer.php'; ?>

<script>
// ── Drag-and-drop file select ─────────────────────────────────────────────────
(function () {
	const zone   = document.getElementById('drop-zone');
	const input  = document.getElementById('csv_file');
	const label  = document.getElementById('drop-filename');
	const btn    = document.getElementById('upload-btn');

	function setFile(file) {
		if (!file) return;
		label.textContent = file.name;
		zone.classList.add('has-file');
	}

	input.addEventListener('change', () => setFile(input.files[0]));

	['dragenter', 'dragover'].forEach(evt => {
		zone.addEventListener(evt, e => { e.preventDefault(); zone.classList.add('drag-over'); });
	});
	['dragleave', 'drop'].forEach(evt => {
		zone.addEventListener(evt, e => { e.preventDefault(); zone.classList.remove('drag-over'); });
	});
	zone.addEventListener('drop', e => {
		const file = e.dataTransfer.files[0];
		if (file) {
			const dt   = new DataTransfer();
			dt.items.add(file);
			input.files = dt.files;
			setFile(file);
		}
	});

	document.getElementById('upload-form').addEventListener('submit', () => {
		btn.disabled    = true;
		btn.textContent = 'Importing…';
	});
}());
</script>

</body>
</html>
