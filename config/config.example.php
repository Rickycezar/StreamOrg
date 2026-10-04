<?php
/**
 * StreamOrg configuration template.
 *
 * Copy to config/config.php and fill in real values:
 *     cp config/config.example.php config/config.php
 *
 * config/config.php is git-ignored and must never be committed.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 5432,

        'name'     => 'StreamOrg',
        'user'     => 'streamorg',
        'password' => 'CHANGE_ME',

        'sslmode' => 'prefer',
    ],

    'db_admin' => [
        'user'     => 'postgres',
        'password' => null,

        'maintenance_db' => 'postgres',
    ],

    'security' => [
        'app_key' => 'CHANGE_ME_generate_with_base64_of_32_random_bytes',
    ],

    'app' => [
        'name'     => 'StreamOrg',
        'env'      => 'production',
        'debug'    => false,
        'base_url' => 'http://localhost:8000',
        'timezone' => 'UTC',
        'contact_email' => 'streamorg@outlook.com',
        'domain_locales' => [
            'streamorg.com.br' => 'pt-BR',
            'streamorg.com'    => 'en',
        ],
    ],
];
