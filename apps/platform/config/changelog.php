<?php

declare(strict_types=1);

return [
    // Publish only verified, customer-safe changes. Dates use Asia/Manila.
    'releases' => [
        [
            'id' => '2026-11-changelog',
            'date' => '2026-11-27',
            'environments' => ['staging', 'production'],
            'title' => 'A clearer view of platform updates',
            'changes' => [
                ['type' => 'Added', 'text' => 'A changelog with dated updates for this environment, accessible from the website footer and the admin and operator sidebars.'],
            ],
        ],
        [
            'id' => '2026-11-support-alerts',
            'date' => '2026-11-27',
            'environments' => ['staging'],
            'title' => 'Email alerts for Level 3 support',
            'changes' => [
                ['type' => 'Added', 'text' => 'Escalating a ticket to Level 3 queues an email for eligible, verified Superadmin accounts. The email links to the protected support inbox.'],
                ['type' => 'Improved', 'text' => 'Repeated escalation at the same level does not create another alert.'],
            ],
        ],
        [
            'id' => '2026-11-support-permissions',
            'date' => '2026-11-26',
            'environments' => ['staging', 'production'],
            'title' => 'Support ticket controls for administrators',
            'changes' => [
                ['type' => 'Fixed', 'text' => 'Admin and Superadmin roles can update tickets, reply in the conversation and escalate to Level 3 within their authorized scope.'],
            ],
        ],
    ],
];
