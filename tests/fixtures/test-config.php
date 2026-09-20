<?php

declare(strict_types=1);

/** Fixed configuration for the test suite. Never used by a real install. */
return [
    'app' => [
        'name'      => 'Test Panel',
        'version'   => '1.0.0',
        'env'       => 'testing',
        'debug'     => true,
        'url'       => 'https://panel.test',
        'domain'    => 'panel.test',
        'base_path' => '',
        'timezone'  => 'UTC',
        'key'       => 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=',
    ],
    'brand' => [
        'name' => 'Test Panel',
        'org'  => 'Test Org',
    ],
    'security' => [
        'password_min_length' => 10,
        'lockout_threshold'   => 5,
    ],
    'network' => [
        'default_cidr' => '10.50.0.0/16',
    ],
    'logging' => ['level' => 'error'],
];
