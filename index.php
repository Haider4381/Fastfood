<?php
// Redirect project root -> /public/
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // e.g. /fastfoodpos
$target = ($base === '' ? '' : $base) . '/public/';
header('Location: ' . $target, true, 302);
exit;
?>