<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
mark_notifications_read((int) $user['id']);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
