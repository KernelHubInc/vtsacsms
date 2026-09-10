<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class StationCsvService
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization, private AccessibleStationsQuery $stations, private AuditRecorder $audit) {}

    public function template(): string
    {
        return "site_code,name,charge_point_identity,serial_number,qr_identifier\n";
    }

    /** @return array{imported: int} */
    public function import(User $user, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to read CSV upload.');
        }
        $header = fgetcsv($handle);
        if ($header !== ['site_code', 'name', 'charge_point_identity', 'serial_number', 'qr_identifier']) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'CSV header does not match the station template.']);
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($rows) >= 1000) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'CSV is limited to 1,000 data rows.']);
            }
            if (count($row) !== count($header)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Every CSV row must contain exactly five columns.']);
            }
            $combined = array_combine($header, $row);
            $rows[] = $combined;
        }
        fclose($handle);
        Validator::make(['rows' => $rows], [
            'rows.*.charge_point_identity' => ['distinct'],
            'rows.*.serial_number' => ['distinct'],
            'rows.*.qr_identifier' => ['distinct'],
        ])->validate();
        $siteMap = Site::query()->whereIn('code', array_column($rows, 'site_code'))->get(['id', 'code'])
            ->filter(fn (Site $site): bool => $this->authorization->allows(
                $user,
                PermissionKey::AssetManage,
                new ResourceScope(ScopeType::Site, (string) $site->getKey()),
            ))->pluck('id', 'code');
        foreach ($rows as $index => $row) {
            Validator::make($row, ['site_code' => ['required', function ($attribute, $value, $fail) use ($siteMap): void {
                if (! $siteMap->has($value)) {
                    $fail('Site is not accessible.');
                }
            }], 'name' => 'required|string|max:160', 'charge_point_identity' => 'required|string|max:120|unique:charging_stations,charge_point_identity', 'serial_number' => ['required', 'string', 'max:160', Rule::unique('charging_stations', 'serial_number')->where('tenant_id', $this->tenant->get()->tenantId)], 'qr_identifier' => 'required|string|max:120|unique:charging_stations,qr_identifier'])->validateWithBag('row_'.($index + 2));
        }
        DB::transaction(function () use ($rows, $siteMap): void {
            foreach ($rows as $row) {
                ChargingStation::query()->create(['tenant_id' => $this->tenant->get()->tenantId, 'site_id' => $siteMap[$row['site_code']], 'name' => $row['name'], 'charge_point_identity' => $row['charge_point_identity'], 'serial_number' => $row['serial_number'], 'qr_identifier' => $row['qr_identifier'], 'lifecycle_status' => AssetLifecycleStatus::Draft]);
            }
        });
        $this->audit->record(new AuditEntry(
            action: 'assets.stations.imported',
            targetType: 'charging_station_import',
            targetId: null,
            result: AuditResult::Succeeded,
            after: ['row_count' => count($rows), 'format' => 'csv'],
        ));

        return ['imported' => count($rows)];
    }

    public function export(User $user): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Unable to create CSV stream.');
        }
        fputcsv($stream, ['station_id', 'site_id', 'name', 'charge_point_identity', 'serial_number', 'qr_identifier', 'lifecycle_status']);
        foreach ($this->stations->for($user)->orderBy('id')->get() as $station) {
            fputcsv($stream, array_map($this->safeCell(...), [$station->id, $station->site_id, $station->name, $station->charge_point_identity, $station->serial_number, $station->qr_identifier, $station->lifecycle_status->value]));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new RuntimeException('Unable to read CSV stream.');
        }
        $this->audit->record(new AuditEntry(
            action: 'reporting.stations.exported',
            targetType: 'charging_station_export',
            targetId: null,
            result: AuditResult::Succeeded,
            after: ['row_count' => $this->stations->for($user)->count(), 'format' => 'csv'],
        ));

        return $csv;
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return Str::startsWith($value, ['=', '+', '-', '@']) ? "'{$value}" : $value;
    }
}
