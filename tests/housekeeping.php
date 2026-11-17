<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/server/inc/core.php';
housekeeping();
echo "Housekeeping OK\n";
