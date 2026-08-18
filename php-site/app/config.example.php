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
    // Existing string password hashes remain valid as manager accounts.
    // Add the closeuse account with role "closer" when she joins the team.
    'admin_users' => [
        'MKD' => ['password_hash' => 'PASTE_A_PASSWORD_HASH_HERE', 'role' => 'manager'],
        'ICE' => ['password_hash' => 'PASTE_A_PASSWORD_HASH_HERE', 'role' => 'manager'],
        'CLOSEUSE' => ['password_hash' => 'PASTE_A_PASSWORD_HASH_HERE', 'role' => 'closer'],
    ],
    'app_key' => 'REPLACE_WITH_A_RANDOM_64_CHARACTER_SECRET',
    'base_url' => '',
];
