<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role('employer');
redirect('dashboard.php?open_profile=1');
