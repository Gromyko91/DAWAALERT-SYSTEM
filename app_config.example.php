<?php

return [
    'database' => [
        'host' => 'localhost',
        'user' => 'root',
        'password' => '',
        'name' => 'dawa_alert'
    ],
    'app' => [
        'base_url' => 'http://localhost/dawa_alert'
    ],
    'mail' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'your-email@gmail.com',
        'password' => 'your-app-password',
        'from_email' => 'your-email@gmail.com',
        'from_name' => 'Dawa Alert'
    ],
    'africastalking' => [
        'username' => 'sandbox',
        'api_key' => 'your-africas-talking-api-key',
        'sms_endpoint' => 'https://api.sandbox.africastalking.com/version1/messaging'
    ]
];
