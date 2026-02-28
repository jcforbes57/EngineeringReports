<?php
// ─── One-shot DB migration runner ────────────────────────────────────────────
// Access this page once from the browser to apply pending schema changes.
// It deletes itself after a successful run.

require_once __DIR__ . '/db.php';
$db = get_db();

$steps = [];

// ── Step 1: Extend lap_filter ENUM ───────────────────────────────────────────
try {
	$col = $db->query("SHOW COLUMNS FROM warning_rules LIKE 'lap_filter'")->fetch(PDO::FETCH_ASSOC);
	if ($col && strpos($col['Type'], 'multiple_consecutive') === false) {
		$db->exec(
			"ALTER TABLE warning_rules
			   MODIFY lap_filter
			     ENUM('any','first','last','fast_lap','multiple','multiple_consecutive')
			     NOT NULL DEFAULT 'any'"
		);
		$steps[] = ['ok', "lap_filter ENUM extended — added 'multiple' and 'multiple_consecutive'."];
	} else {
		$steps[] = ['skip', "lap_filter ENUM already up to date — skipped."];
	}
} catch (\PDOException $e) {
	$steps[] = ['err', "Failed to extend lap_filter ENUM: " . $e->getMessage()];
}

// ── Self-delete on full success ───────────────────────────────────────────────
$all_ok = !in_array('err', array_column($steps, 0));
if ($all_ok) {
	@unlink(__FILE__);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration — MG1 Reports</title>
<link rel="stylesheet" href="mg1.css">
</head>
<body>
<?php include __DIR__ . '/inc_header.php'; ?>
<main class="container" style="max-width:640px;margin-top:2rem">
<h1>Database Migration</h1>
<?php foreach ($steps as [$status, $msg]): ?>
<p style="padding:.6rem 1rem;border-radius:6px;margin:.5rem 0;
    background:<?= $status==='ok' ? '#1a2e1a' : ($status==='skip' ? '#1a1d23' : '#2e1a1a') ?>;
    color:<?= $status==='ok' ? '#3ecf72' : ($status==='skip' ? '#7a8394' : '#e03c3c') ?>">
    <?= $status==='ok' ? '✓' : ($status==='skip' ? '—' : '✗') ?>
    <?= htmlspecialchars($msg) ?>
</p>
<?php endforeach; ?>
<?php if ($all_ok): ?>
<p style="margin-top:1.5rem;color:#7a8394">This file has been deleted. <a href="admin_rules.php">Return to Warning Rules →</a></p>
<?php else: ?>
<p style="margin-top:1.5rem;color:#e03c3c">One or more steps failed — fix the errors above and refresh to retry.</p>
<?php endif; ?>
</main>
</body>
</html>
