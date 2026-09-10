<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\Models\MapSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MapSettingsManager
{
    public function __construct(
        private MapConfigurationResolver $resolver,
        private AuditRecorder $audit,
    ) {}

    public function update(MapSettingsData $settings): MapSetting
    {
        $data = $settings->toArray();
        $this->assertTileConfiguration($data['tile_url_template'], $data['tile_attribution']);

        $setting = DB::transaction(function () use ($data): MapSetting {
            $setting = MapSetting::query()->firstOrCreate(
                ['scope' => 'platform'],
                ['default_provider' => MapProvider::OpenStreetMap->value],
            );
            $before = $setting->only(array_keys($data));
            $setting->fill($data)->save();
            $after = $setting->fresh()->only(array_keys($data));

            $this->audit->record(new AuditEntry(
                action: 'integrations.map_settings.updated',
                targetType: 'map_setting',
                targetId: (string) $setting->getKey(),
                result: AuditResult::Succeeded,
                changes: $this->changes($before, $after),
                before: $before,
                after: $after,
            ));

            return $setting;
        });

        $this->resolver->forget();

        return $setting;
    }

    private function assertTileConfiguration(string $template, string $attribution): void
    {
        $validScheme = str_starts_with($template, 'https://')
            || (app()->environment('local', 'testing') && str_starts_with($template, 'http://localhost'));
        $placeholdersPresent = collect(['{z}', '{x}', '{y}'])->every(
            fn (string $placeholder): bool => str_contains($template, $placeholder),
        );

        if (! $validScheme || ! $placeholdersPresent || str_contains(mb_strtolower($template), 'javascript:')) {
            throw ValidationException::withMessages([
                'mapSettings.tile_url_template' => 'Use an HTTPS tile URL containing {z}, {x}, and {y} placeholders.',
            ]);
        }

        if (trim($attribution) === '') {
            throw ValidationException::withMessages([
                'mapSettings.tile_attribution' => 'Tile attribution is required.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{before:mixed, after:mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
            }
        }

        return $changes;
    }
}
