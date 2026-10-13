<?php

return [
    'central_hosts' => array_filter(array_map('trim', explode(',', env('CENTRAL_HOSTS', 'localhost,127.0.0.1')))),
    'timezone' => 'Africa/Dar_es_Salaam',
    'currency' => 'TZS',
    'idle_timeout' => 30,
    'pos_idle_timeout' => 15,
    'absolute_session_hours' => 12,
    'impersonation_minutes' => 60,
    'password_min' => 10,
];
