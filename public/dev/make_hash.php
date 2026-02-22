<?php
// URL usage: /fastfoodpos/public/dev/make_hash.php?pin=1234
$pin = isset($_GET['pin']) ? (string)$_GET['pin'] : '1234';
header('Content-Type: text/plain; charset=utf-8');
echo password_hash($pin, PASSWORD_BCRYPT);