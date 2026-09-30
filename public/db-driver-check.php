<?php

header('Content-Type: application/json');

echo json_encode([
    'ini' => php_ini_loaded_file(),
    'drivers' => PDO::getAvailableDrivers(),
    'binary' => PHP_BINARY,
]);
