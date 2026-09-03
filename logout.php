<?php
require __DIR__ . '/includes/bootstrap.php';
logout_user();
flash('success', 'Kamu sudah keluar dari sistem.');
redirect('login.php');
