<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Demo\DemoEnvironment;
use App\Models\User;
use App\Modules\Identity\Application\StagingDemoCredentials;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\TenantSecurityTestCase;

final class StagingDemoCredentialsTest extends TenantSecurityTestCase
{
    private function seedAccounts(): void
    {
        $tenant = Tenant::factory()->create(['id' => DemoEnvironment::TENANT_ID, 'name' => 'Demo', 'slug' => 'demo', 'status' => 'active']);
        foreach (app(StagingDemoCredentials::class)->accounts() as $account) {
            $user = User::factory()->create(['public_id' => $account['public_id'], 'email' => $account['old_email']]);
            $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        }
    }

    public function test_rotation_preserves_subjects_and_memberships_and_revokes_sessions_without_logging_passwords(): void
    {
        $this->seedAccounts();
        $customer = $this->createUser();
        $membershipIds = DB::table('memberships')->pluck('id')->all();
        $password = 'Synthetic-rotation-only-2026!';
        $credentials = app(StagingDemoCredentials::class);
        $user = User::query()->where('email', $credentials->accounts()[0]['old_email'])->firstOrFail();
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->assertSame(19, $credentials->rotate($password));
        foreach ($credentials->accounts() as $account) {
            $subject = User::query()->where('public_id', $account['public_id'])->firstOrFail();
            $this->assertSame($account['email'], $subject->email);
            $this->assertTrue(Hash::check($password, $subject->password));
        }
        $this->assertSame($membershipIds, DB::table('memberships')->pluck('id')->all());
        $this->assertSame($customer->email, $customer->fresh()->email);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertSame(19, DB::table('audit_events')->where('action', 'identity.demo_credentials.rotated')->count());
        $this->assertStringNotContainsString($password, DB::table('audit_events')->get()->toJson());
        $this->assertSame(19, $credentials->rotate($password));
        $this->assertSame(20, User::query()->count());
    }

    public function test_collision_rolls_back_every_account_and_production_is_rejected(): void
    {
        $this->seedAccounts();
        User::factory()->create(['email' => 'driver@evcspowersolutions.com']);
        try {
            app(StagingDemoCredentials::class)->rotate('Synthetic-rotation-only-2026!');
            $this->fail('Email collision was allowed.');
        } catch (LogicException) {
            $this->assertSame(19, User::query()->where('email', 'like', '%@demo.vsta.local')->count());
            $this->assertSame(0, DB::table('audit_events')->where('action', 'identity.demo_credentials.rotated')->count());
        }
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(LogicException::class);
            app(StagingDemoCredentials::class)->rotate('Synthetic-rotation-only-2026!');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
