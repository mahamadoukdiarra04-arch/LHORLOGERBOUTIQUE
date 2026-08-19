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
    // These initial accounts are imported once into the database. Manage all
    // subsequent access from Administration > Utilisateurs.
    'admin_users' => [
        'MKD' => ['password_hash' => 'PASTE_A_PASSWORD_HASH_HERE', 'role' => 'manager'],
        'ICE' => ['password_hash' => 'PASTE_A_PASSWORD_HASH_HERE', 'role' => 'manager'],
    ],
    'app_key' => 'REPLACE_WITH_A_RANDOM_64_CHARACTER_SECRET',
    'base_url' => '',
];
