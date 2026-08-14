<?php
/**
 * Copy this file to config.php on the server, then fill in the values from
 * Hostinger > Databases > MySQL Databases. Keep config.php out of Git.
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'CHANGE_ME',
        'user' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    // Generate each value with password_hash('a-strong-password', PASSWORD_DEFAULT).
    'admin_users' => [
        'MKD' => 'PASTE_A_PASSWORD_HASH_HERE',
        'ICE' => 'PASTE_A_PASSWORD_HASH_HERE',
    ],
    'app_key' => 'REPLACE_WITH_A_RANDOM_64_CHARACTER_SECRET',
    'base_url' => '',
];
