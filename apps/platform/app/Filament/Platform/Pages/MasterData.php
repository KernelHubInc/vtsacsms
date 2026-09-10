<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Foundation\Database\ArchivableMasterModel;
use App\Models\User;
use App\Modules\Assets\Domain\Models\AssetClass;
use App\Modules\Assets\Domain\Models\ChargerManufacturer;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\NetworkProvider;
use App\Modules\Assets\Domain\Models\OcppSecurityProfile;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Assets\Domain\Models\VehicleManufacturer;
use App\Modules\Locations\Domain\Models\Barangay;
use App\Modules\Locations\Domain\Models\City;
use App\Modules\Locations\Domain\Models\Country;
use App\Modules\Locations\Domain\Models\Province;
use App\Modules\Locations\Domain\Models\Region;
use App\Modules\Locations\Domain\Models\SiteAmenity;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\WithPagination;
use UnitEnum;

final class MasterData extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Platform governance';

    protected static ?string $navigationLabel = 'Master lists';

    protected string $view = 'filament.platform.pages.master-data';

    #[Url]
    public string $list = 'countries';

    #[Validate('required|string|max:60')]
    public string $code = '';

    #[Validate('required|string|max:160')]
    public string $name = '';

    #[Validate('nullable|string|max:26')]
    public string $parentId = '';

    public string $secondaryCode = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $archive = 'active';

    public string $editingId = '';

    #[Validate('required|string|max:60')]
    public string $editCode = '';

    #[Validate('required|string|max:160')]
    public string $editName = '';

    #[Validate('nullable|string|max:26')]
    public string $editParentId = '';

    public string $editSecondaryCode = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && (
                app(AuthorizationService::class)->allows($user, PermissionKey::AssetView)
                || app(AuthorizationService::class)->allows($user, PermissionKey::LocationView)
            );
    }

    public function createRecord(): void
    {
        $this->authorizeManagement();
        $definition = $this->lists()[$this->list];
        $rules = [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'parentId' => ['nullable', 'string', 'max:26'],
        ];
        if (isset($definition['code_length'])) {
            $rules['code'][] = 'size:'.$definition['code_length'];
        }
        if (isset($definition['secondary_code_attribute'])) {
            $rules['secondaryCode'] = ['required', 'string', 'max:60'];
            if (isset($definition['secondary_code_length'])) {
                $rules['secondaryCode'][] = 'size:'.$definition['secondary_code_length'];
            }
        }
        $data = $this->validate($rules);
        $class = $this->modelClass();
        $attributes = [
            $definition['code_attribute'] ?? 'code' => mb_strtoupper($data['code']),
            'name' => $data['name'],
        ];
        if (isset($definition['secondary_code_attribute'])) {
            $attributes[$definition['secondary_code_attribute']] = mb_strtoupper($data['secondaryCode']);
        }
        if (isset($definition['parent_foreign'], $definition['parent_model'])) {
            $parentId = $data['parentId'] ?? null;
            if (! is_string($parentId) || ! $definition['parent_model']::query()->whereKey($parentId)->exists()) {
                $this->addError('parentId', 'Select a valid parent record.');

                return;
            }
            $attributes[$definition['parent_foreign']] = $parentId;
        }

        try {
            $record = $class::query()->create($attributes);
        } catch (QueryException) {
            $this->addError('code', 'That code already exists in this master list.');

            return;
        }

        app(AuditRecorder::class)->record(new AuditEntry(
            'master_data.record.created',
            'master_data',
            (string) $record->getKey(),
            AuditResult::Succeeded,
            after: [
                'list' => $this->list,
                'code' => $record->getAttribute($definition['code_attribute'] ?? 'code'),
                'name' => $record->getAttribute('name'),
            ],
        ));
        $this->reset(['code', 'secondaryCode', 'name', 'parentId']);
        Notification::make()->title('Master-data record created')->success()->send();
    }

    public function archiveRecord(string $id): void
    {
        $this->authorizeManagement();
        $record = $this->modelClass()::query()->findOrFail($id);
        $before = ['archived_at' => $record->getAttribute('archived_at')];
        $record->archive();
        app(AuditRecorder::class)->record(new AuditEntry(
            'master_data.record.archived',
            'master_data',
            (string) $record->getKey(),
            AuditResult::Succeeded,
            before: $before,
            after: ['archived_at' => $record->getAttribute('archived_at'), 'list' => $this->list],
        ));
        Notification::make()->title('Master-data record archived')->success()->send();
    }

    public function restoreRecord(string $id): void
    {
        $this->authorizeManagement();
        $record = $this->modelClass()::query()->findOrFail($id);
        $before = ['archived_at' => $record->getAttribute('archived_at'), 'list' => $this->list];

        try {
            $record->forceFill(['archived_at' => null])->save();
        } catch (QueryException) {
            Notification::make()
                ->title('Record could not be restored')
                ->body('Its code conflicts with another record. Resolve the conflict before restoring it.')
                ->danger()
                ->send();

            return;
        }

        app(AuditRecorder::class)->record(new AuditEntry(
            'master_data.record.restored',
            'master_data',
            (string) $record->getKey(),
            AuditResult::Succeeded,
            before: $before,
            after: ['archived_at' => null, 'list' => $this->list],
        ));
        Notification::make()->title('Master-data record restored')->success()->send();
    }

    public function beginEdit(string $id): void
    {
        $this->authorizeManagement();
        $record = $this->modelClass()::query()->findOrFail($id);
        $definition = $this->lists()[$this->list];

        $this->editingId = (string) $record->getKey();
        $this->editCode = (string) $record->getAttribute($definition['code_attribute'] ?? 'code');
        $this->editSecondaryCode = isset($definition['secondary_code_attribute'])
            ? (string) $record->getAttribute($definition['secondary_code_attribute'])
            : '';
        $this->editName = (string) $record->getAttribute('name');
        $this->editParentId = isset($definition['parent_foreign'])
            ? (string) ($record->getAttribute($definition['parent_foreign']) ?? '')
            : '';
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editCode', 'editSecondaryCode', 'editName', 'editParentId']);
        $this->resetValidation();
    }

    public function updateRecord(): void
    {
        $this->authorizeManagement();
        $definition = $this->lists()[$this->list];
        $rules = [
            'editCode' => ['required', 'string', 'max:60'],
            'editName' => ['required', 'string', 'max:160'],
            'editParentId' => ['nullable', 'string', 'max:26'],
        ];
        if (isset($definition['code_length'])) {
            $rules['editCode'][] = 'size:'.$definition['code_length'];
        }
        if (isset($definition['secondary_code_attribute'])) {
            $rules['editSecondaryCode'] = ['required', 'string', 'max:60'];
            if (isset($definition['secondary_code_length'])) {
                $rules['editSecondaryCode'][] = 'size:'.$definition['secondary_code_length'];
            }
        }
        $data = $this->validate($rules);
        $record = $this->modelClass()::query()->findOrFail($this->editingId);
        $attributes = [
            $definition['code_attribute'] ?? 'code' => mb_strtoupper($data['editCode']),
            'name' => $data['editName'],
        ];
        if (isset($definition['secondary_code_attribute'])) {
            $attributes[$definition['secondary_code_attribute']] = mb_strtoupper($data['editSecondaryCode']);
        }
        if (isset($definition['parent_foreign'], $definition['parent_model'])) {
            $parentId = $data['editParentId'] ?? null;
            if (! is_string($parentId)
                || $parentId === ''
                || hash_equals((string) $record->getKey(), $parentId)
                || ! $definition['parent_model']::query()->available()->whereKey($parentId)->exists()) {
                $this->addError('editParentId', 'Select a valid active parent record.');

                return;
            }
            $attributes[$definition['parent_foreign']] = $parentId;
        }
        $before = $record->attributesToArray();

        try {
            $record->fill($attributes)->save();
        } catch (QueryException) {
            $this->addError('editCode', 'That code already exists in this master list.');

            return;
        }

        app(AuditRecorder::class)->record(new AuditEntry(
            'master_data.record.updated',
            'master_data',
            (string) $record->getKey(),
            AuditResult::Succeeded,
            before: $before,
            after: $record->fresh()->attributesToArray(),
        ));
        $this->cancelEdit();
        Notification::make()->title('Master-data record updated')->success()->send();
    }

    public function updatedList(): void
    {
        $this->resetPage('masterDataPage');
        $this->cancelEdit();
    }

    public function updatedSearch(): void
    {
        $this->resetPage('masterDataPage');
    }

    public function updatedArchive(): void
    {
        $this->resetPage('masterDataPage');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $class = $this->modelClass();
        $definition = $this->lists()[$this->list];
        $codeAttribute = $definition['code_attribute'] ?? 'code';
        $query = $class::query()
            ->when($this->search !== '', function ($query) use ($codeAttribute, $definition): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function ($query) use ($codeAttribute, $definition, $term): void {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term]);

                    if ($codeAttribute === 'iso_alpha_2') {
                        $query->orWhereRaw('LOWER(iso_alpha_2) LIKE ?', [$term]);
                    } else {
                        $query->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                    }

                    if (($definition['secondary_code_attribute'] ?? null) === 'iso_alpha_3') {
                        $query->orWhereRaw('LOWER(iso_alpha_3) LIKE ?', [$term]);
                    }
                });
            })
            ->when($this->archive === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($this->archive === 'archived', fn ($query) => $query->whereNotNull('archived_at'));

        return [
            'lists' => $this->lists(),
            'records' => $query->orderBy('name')->paginate(25, ['*'], 'masterDataPage'),
            'parents' => isset($this->lists()[$this->list]['parent_model'])
                ? $this->lists()[$this->list]['parent_model']::query()
                    ->available()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                : collect(),
            'canManage' => $this->canManage(),
        ];
    }

    /** @return class-string<ArchivableMasterModel> */
    private function modelClass(): string
    {
        $lists = $this->lists();
        if (! isset($lists[$this->list])) {
            $this->list = 'countries';
        }

        return $lists[$this->list]['model'];
    }

    /**
     * @return array<string, array{
     *   label:string,model:class-string<ArchivableMasterModel>,permission:PermissionKey,
     *   parent_label?:string,parent_model?:class-string<ArchivableMasterModel>,parent_foreign?:string,
     *   code_attribute?:string,code_label?:string,code_length?:int,
     *   secondary_code_attribute?:string,secondary_code_label?:string,secondary_code_length?:int
     * }>
     */
    private function lists(): array
    {
        return [
            'countries' => [
                'label' => 'Countries',
                'model' => Country::class,
                'permission' => PermissionKey::LocationManage,
                'code_attribute' => 'iso_alpha_2',
                'code_label' => 'ISO alpha-2',
                'code_length' => 2,
                'secondary_code_attribute' => 'iso_alpha_3',
                'secondary_code_label' => 'ISO alpha-3',
                'secondary_code_length' => 3,
            ],
            'regions' => ['label' => 'Regions', 'model' => Region::class, 'permission' => PermissionKey::LocationManage, 'parent_label' => 'Country', 'parent_model' => Country::class, 'parent_foreign' => 'country_id'],
            'provinces' => ['label' => 'Provinces', 'model' => Province::class, 'permission' => PermissionKey::LocationManage, 'parent_label' => 'Region', 'parent_model' => Region::class, 'parent_foreign' => 'region_id'],
            'cities' => ['label' => 'Cities', 'model' => City::class, 'permission' => PermissionKey::LocationManage, 'parent_label' => 'Province', 'parent_model' => Province::class, 'parent_foreign' => 'province_id'],
            'barangays' => ['label' => 'Barangays', 'model' => Barangay::class, 'permission' => PermissionKey::LocationManage, 'parent_label' => 'City', 'parent_model' => City::class, 'parent_foreign' => 'city_id'],
            'site_amenities' => ['label' => 'Site amenities', 'model' => SiteAmenity::class, 'permission' => PermissionKey::LocationManage],
            'charger_manufacturers' => ['label' => 'Charger manufacturers', 'model' => ChargerManufacturer::class, 'permission' => PermissionKey::AssetManage],
            'connector_standards' => ['label' => 'Connector standards', 'model' => ConnectorStandard::class, 'permission' => PermissionKey::AssetManage],
            'current_types' => ['label' => 'Charging current types', 'model' => ChargingCurrentType::class, 'permission' => PermissionKey::AssetManage],
            'asset_classes' => ['label' => 'Asset classes', 'model' => AssetClass::class, 'permission' => PermissionKey::AssetManage],
            'network_providers' => ['label' => 'Network providers', 'model' => NetworkProvider::class, 'permission' => PermissionKey::AssetManage],
            'ocpp_versions' => ['label' => 'OCPP versions', 'model' => OcppVersion::class, 'permission' => PermissionKey::AssetManage],
            'ocpp_security_profiles' => ['label' => 'OCPP security profiles', 'model' => OcppSecurityProfile::class, 'permission' => PermissionKey::AssetManage],
            'vehicle_manufacturers' => ['label' => 'Vehicle manufacturers', 'model' => VehicleManufacturer::class, 'permission' => PermissionKey::AssetManage],
        ];
    }

    private function authorizeManagement(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function canManage(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->allows(
            $user,
            $this->lists()[$this->list]['permission'] ?? PermissionKey::AssetManage,
        );
    }
}
