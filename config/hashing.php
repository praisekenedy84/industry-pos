<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],
    'argon' => [
        'memory' => env('HASH_MEMORY', 65536),
        'threads' => env('HASH_THREADS', 1),
        'time' => env('HASH_TIME', 4),
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
