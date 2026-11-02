<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('NOTICE_PUSH_ENABLED', false),
    'project_id' => env('NOTICE_PUSH_PROJECT_ID', ''),
    'credentials' => env('NOTICE_PUSH_CREDENTIALS', ''),
    'android_package' => env('NOTICE_PUSH_ANDROID_PACKAGE', 'com.vtsa.vtsa_mobile'),
];
