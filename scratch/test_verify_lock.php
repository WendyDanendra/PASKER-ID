<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$stmt = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = 2');
$stmt->execute();
$p = $stmt->fetch();

$verificationStatus = $p['verification_status'] ?? 'NOT_SUBMITTED';
if (!empty($p['verified']) && (int)$p['verified'] === 1) {
    $verificationStatus = 'ACTIVE_VERIFIED';
}
$isProfileVerified = (!empty($p['verified']) && (int)$p['verified'] === 1) && in_array($verificationStatus, ['APPROVED', 'ACTIVE_VERIFIED'], true);

echo "User ID: 2\n";
echo "DB verified column: " . json_encode($p['verified']) . "\n";
echo "DB status column: " . json_encode($p['verification_status']) . "\n";
echo "Final isProfileVerified: " . ($isProfileVerified ? 'TRUE' : 'FALSE') . "\n";
