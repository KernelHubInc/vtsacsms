<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The complete suite exercises these contracts even when the local M1 runtime profile hides their routes.
        config()->set([
            'features.remote_charging' => true,
            'features.real_payments' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && $this->app->bound(CurrentTenant::class)) {
            $this->app->make(CurrentTenant::class)->clear();
        }

        parent::tearDown();
    }
}
