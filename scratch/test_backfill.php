<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$pdo = db();
$pdo->exec("UPDATE employer_profiles SET entity_type = 'Individu' WHERE entity_type IS NULL OR entity_type = ''");
$pdo->exec("UPDATE employer_profiles SET domicile_city_id = city WHERE (domicile_city_id IS NULL OR domicile_city_id = '') AND city IS NOT NULL AND city != ''");
$pdo->exec("UPDATE users SET city = domicile_city_id WHERE city IS NULL OR city = ''");
echo "Backfill successful! Profiles updated: " . $pdo->query("SELECT COUNT(*) FROM employer_profiles WHERE entity_type = 'Individu'")->fetchColumn() . "\n";
