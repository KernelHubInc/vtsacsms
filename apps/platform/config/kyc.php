<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('KYC_ENABLED', false),
    'automatic_verification_enabled' => (bool) env('KYC_AUTOMATIC_VERIFICATION_ENABLED', false),
    'assurance_profile' => env('KYC_ASSURANCE_PROFILE', 'optical_v1'),
    'required_for_charging' => (bool) env('KYC_REQUIRED_FOR_CHARGING', false),
    'required_for_payment' => (bool) env('KYC_REQUIRED_FOR_PAYMENT', false),
    'required_for_wallet' => (bool) env('KYC_REQUIRED_FOR_WALLET', false),
    'url' => env('KYC_SERVICE_URL', 'http://127.0.0.1:8090'),
    'request_secret' => env('KYC_REQUEST_SECRET', ''),
    'callback_secret' => env('KYC_CALLBACK_SECRET', ''),
    'connect_timeout' => 3,
    'timeout' => 30,
    'max_file_kb' => 6144,
    'validity_days' => (int) env('KYC_VALIDITY_DAYS', 365),
    'consent_version' => env('KYC_CONSENT_VERSION', 'development-v1'),
    'consent_text' => env('KYC_CONSENT_TEXT', 'I consent to processing my identity information, identity document images and live camera frames for optical document checks, face comparison, liveness checks and authorized manual review. Optical checks do not confirm government issuance. Only a reference selfie is retained; other live frames are processed in memory.'),
    'privacy_url' => env('KYC_PRIVACY_URL'),
    'terms_url' => env('KYC_TERMS_URL'),
    'consent_url' => env('KYC_CONSENT_URL'),
    'retention_days' => (int) env('KYC_RETENTION_DAYS', 30),
    'documents' => [
        'philsys' => ['label' => 'Philippine National ID', 'front' => true, 'back' => true, 'expiration_date' => false, 'document_number' => true, 'nationality' => false, 'birth_date' => true, 'issuing_country' => true],
        'drivers_license' => ['label' => 'Driver’s license', 'front' => true, 'back' => true, 'expiration_date' => true, 'document_number' => true, 'nationality' => false, 'birth_date' => true, 'issuing_country' => true],
        'passport' => ['label' => 'Passport', 'front' => true, 'back' => false, 'expiration_date' => true, 'document_number' => true, 'nationality' => true, 'birth_date' => true, 'issuing_country' => true],
    ],
];
