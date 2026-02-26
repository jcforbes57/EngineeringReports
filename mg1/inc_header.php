<?php
// Shared site header — included by all page files.
// Expects MG1_VERSION to be defined (via db.php).
$_current_page = basename($_SERVER['PHP_SELF']);
?>
<header class="site-header">
	<div class="header-inner">
		<a href="index.php" class="site-logo">
			<span class="logo-mg1">MG1</span>
			<span class="logo-sub">Engineering Reports</span>
		</a>
		<nav class="site-nav">
			<a href="index.php"      <?= $_current_page === 'index.php'       ? 'class="active"' : '' ?>>Dashboard</a>
			<a href="upload.php"     <?= $_current_page === 'upload.php'      ? 'class="active"' : '' ?>>Upload</a>
			<a href="admin_rules.php"<?= $_current_page === 'admin_rules.php' ? 'class="active"' : '' ?>>Warning Rules</a>
		</nav>
	</div>
</header>
