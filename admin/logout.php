<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';

logOut();
header('Location: login.php');
exit;
