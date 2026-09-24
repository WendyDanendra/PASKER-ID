<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$pdo->exec("UPDATE employer_profiles SET assigned_to = NULL, assigned_at = NULL, assignment_reason = NULL WHERE verification_status = 'PENDING'");
$rows = $pdo->query("SELECT user_id, owner_name, verification_status, assigned_to FROM employer_profiles WHERE verification_status = 'PENDING'")->fetchAll(PDO::FETCH_ASSOC);

echo "Reset completed:\n";
print_r($rows);
