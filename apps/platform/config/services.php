<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ocpp_gateway' => [
        'url' => env('OCPP_GATEWAY_INTERNAL_URL', 'http://ocpp-gateway:9000'),
        'token' => env('OCPP_GATEWAY_INTERNAL_TOKEN'),
        'timeout_seconds' => (int) env('OCPP_GATEWAY_COMMAND_TIMEOUT_SECONDS', 20),
        'start_expiry_seconds' => (int) env('CHARGING_START_EXPIRY_SECONDS', 120),
        'event_stream' => env('OCPP_GATEWAY_EVENT_STREAM', 'vtsa:local:ocpp:events:v1'),
        'authorization_stream' => env('OCPP_GATEWAY_AUTHORIZATION_STREAM', 'vtsa:local:ocpp:authorization-requests:v1'),
        'redis_key_prefix' => env('OCPP_GATEWAY_REDIS_KEY_PREFIX', 'vtsa:local:ocpp'),
        'consumer_group' => env('OCPP_GATEWAY_CONSUMER_GROUP', 'vtsa-platform-v1'),
    ],

    'integration_events' => [
        'stream' => env('INTEGRATION_EVENT_STREAM', 'vtsa:local:platform:events:v1'),
    ],

];
