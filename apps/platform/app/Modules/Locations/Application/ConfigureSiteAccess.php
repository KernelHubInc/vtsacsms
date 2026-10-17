<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ConfigureSiteAccess
{
    /** @param array<string, mixed> $data */
    public function hours(User $actor, array $data, ?SiteOperatingHour $existing = null): SiteOperatingHour
    {
        if ($existing !== null) {
            Gate::forUser($actor)->authorize('update', $existing);
            $data['site_id'] = $existing->site_id;
            $data['day_of_week'] = $existing->day_of_week;
        }
        if (in_array($data['is_closed'] ?? null, [true, 1, '1'], true)) {
            $data['opens_at'] = $data['closes_at'] = null;
        } elseif (in_array($data['is_open_all_day'] ?? null, [true, 1, '1'], true)) {
            $data['opens_at'] = '00:00';
            $data['closes_at'] = '24:00';
        }
        $data = Validator::make($data, [
            'site_id' => ['required', 'ulid'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'is_closed' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'required_if:is_closed,false,0', 'date_format:H:i'],
            'closes_at' => ['nullable', 'required_if:is_closed,false,0', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/', 'different:opens_at'],
        ])->validate();
        if ($data['is_closed']) {
            $data['opens_at'] = $data['closes_at'] = null;
        }

        return DB::transaction(function () use ($actor, $data, $existing): SiteOperatingHour {
            $site = Site::query()->lockForUpdate()->whereKey($data['site_id'])->firstOrFail();
            Gate::forUser($actor)->authorize('update', $site);
            if ($existing === null && $site->operatingHours()->where('day_of_week', $data['day_of_week'])->exists()) {
                throw ValidationException::withMessages(['day_of_week' => 'This day already has a schedule. Edit that row instead.']);
            }
            $hour = $existing === null ? new SiteOperatingHour : SiteOperatingHour::query()->lockForUpdate()->whereKey($existing->getKey())->firstOrFail();
            $before = $hour->exists ? $hour->attributesToArray() : [];
            $hour->fill($data + ['tenant_id' => $site->tenant_id])->save();
            app(AuditRecorder::class)->record(new AuditEntry(
                'locations.operating_hour.'.($existing === null ? 'created' : 'updated'), 'site_operating_hour',
                (string) $hour->getKey(), AuditResult::Succeeded, before: $before, after: $hour->attributesToArray(),
            ));

            return $hour;
        });
    }

    public function publish(User $actor, Site $site, bool $publish): void
    {
        Gate::forUser($actor)->authorize('update', $site);
        DB::transaction(function () use ($actor, $site, $publish): void {
            $site = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();
            Gate::forUser($actor)->authorize('update', $site);
            if ($publish) {
                if ($site->lifecycle_status !== SiteLifecycleStatus::Active || ! $site->is_public) {
                    throw ValidationException::withMessages(['publication' => 'Save the site as Active and Public before publishing.']);
                }
                Validator::make($site->attributesToArray(), [
                    'address_line_1' => ['required', 'string'],
                    'latitude' => ['required', 'numeric', 'between:-90,90'],
                    'longitude' => ['required', 'numeric', 'between:-180,180'],
                    'timezone' => ['required', 'timezone:all'],
                ])->validate();
            }
            $before = $site->attributesToArray();
            $site->published_at = $publish ? ($site->published_at !== null && ! $site->published_at->isFuture() ? $site->published_at : CarbonImmutable::now('UTC')) : null;
            if ($publish && ! $site->public_slug) {
                $site->public_slug = Str::limit(Str::slug($site->name), 100, '').'-'.strtolower((string) $site->getKey());
            }
            $site->save();
            app(AuditRecorder::class)->record(new AuditEntry(
                $publish ? 'locations.site.published' : 'locations.site.unpublished', 'site',
                (string) $site->getKey(), AuditResult::Succeeded, before: $before, after: $site->attributesToArray(),
            ));
        });
    }
}
