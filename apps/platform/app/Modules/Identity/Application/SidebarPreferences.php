<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class SidebarPreferences
{
    public function __construct(
        private CurrentTenant $tenant,
        private PanelAccessService $access,
    ) {}

    public function canCustomize(User $user, string $panel): bool
    {
        $key = $this->key($user, $panel);

        return $key !== null && $this->access->tenantIdFor($user, $panel) === $key['tenant_id'];
    }

    /** @return array<string, list<string>> */
    public function load(User $user, string $panel): array
    {
        $key = $this->key($user, $panel);
        if ($key === null) {
            return [];
        }

        $layout = DB::table('identity_sidebar_preferences')->where($key)->value('layout');

        return is_string($layout) ? json_decode($layout, true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** @param array<string, list<string>> $layout */
    public function save(User $user, string $panel, array $layout): void
    {
        $key = $this->key($user, $panel);
        abort_unless($key !== null && $this->access->tenantIdFor($user, $panel) === $key['tenant_id'], 403);

        $now = now('UTC');
        DB::table('identity_sidebar_preferences')->upsert([
            [...$key, 'layout' => json_encode($layout, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now],
        ], ['tenant_id', 'subject_id', 'panel'], ['layout', 'updated_at']);
    }

    public function reset(User $user, string $panel): void
    {
        $key = $this->key($user, $panel);
        abort_unless($key !== null && $this->access->tenantIdFor($user, $panel) === $key['tenant_id'], 403);

        DB::table('identity_sidebar_preferences')->where($key)->delete();
    }

    /** @return array{tenant_id: string, subject_id: string, panel: string}|null */
    private function key(User $user, string $panel): ?array
    {
        $context = $this->tenant->getOrNull();
        $subject = $user->public_id;
        if ($context === null || $subject === null || $context->actorId !== $subject || ! $user->isEnabled() || ! in_array($panel, ['admin', 'operator'], true)) {
            return null;
        }

        return ['tenant_id' => $context->tenantId, 'subject_id' => $subject, 'panel' => $panel];
    }
}
