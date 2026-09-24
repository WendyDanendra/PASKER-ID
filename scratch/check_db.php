<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$tables = db()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES:\n";
print_r($tables);

$users = db()->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
echo "USERS:\n";
print_r($users);
