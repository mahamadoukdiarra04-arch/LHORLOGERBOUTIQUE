<?php
// Local test configuration only. No production account or external delivery.
return [
    'db' => ['host' => '127.0.0.1;port=3308', 'name' => 'lhorloger_quick_test', 'user' => 'root', 'password' => '', 'charset' => 'utf8mb4'],
    'admin_users' => ['QA' => ['password_hash' => password_hash('local-quick-qa-only', PASSWORD_DEFAULT), 'role' => 'manager']],
    'app_key' => str_repeat('local-quick-test-', 4),
    'base_url' => '',
    'meta_capi' => ['pixel_id' => '', 'access_token' => ''],
];
