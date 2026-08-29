<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

admin_clear_cookie();
header('Location: login.php');
exit;
