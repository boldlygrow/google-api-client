<?php

return [
    'customer' => env('GOOGLE_API_CUSTOMER', 'my_customer'),
    'domain' => env('GOOGLE_API_DOMAIN'),
    'exceptions' => env('GOOGLE_API_EXCEPTIONS', true),
    'key_path' => env('GOOGLE_API_KEY_PATH'),
    'subject_email' => env('GOOGLE_API_SUBJECT_EMAIL'),
];
