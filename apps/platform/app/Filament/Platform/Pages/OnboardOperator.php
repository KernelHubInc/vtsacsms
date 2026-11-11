<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\OperatorOnboarding;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class OnboardOperator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Onboard operator';

    protected static ?string $title = 'Onboard an operator';

    protected string $view = 'filament.platform.pages.onboard-operator';

    #[Locked]
    public int $step = 1;

    #[Locked]
    public string $requestId = '';

    #[Locked]
    public ?string $completedOrganization = null;

    public string $mode = 'new';

    public string $name = '';

    public string $code = '';

    public ?string $organization_id = null;

    public string $email = '';

    public ?string $role_id = null;

    public string $scope = 'organization';

    public ?string $site_id = null;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && app(OperatorOnboarding::class)->allowed(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->requestId = (string) Str::ulid();
    }

    public function next(): void
    {
        abort_unless(self::canAccess(), 403);
        $rules = match ($this->step) {
            1 => ['mode' => 'required|in:new,existing', 'name' => 'required_if:mode,new|nullable|string|max:180', 'code' => ['required_if:mode,new', 'nullable', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'], 'organization_id' => 'required_if:mode,existing|nullable|ulid'],
            2 => ['email' => 'required|email|max:254', 'role_id' => 'required|ulid'],
            default => ['scope' => 'required|in:organization,site', 'site_id' => 'required_if:scope,site|nullable|ulid'],
        };
        $this->validate(array_map(fn ($rule): array => is_array($rule) ? $rule : explode('|', $rule), $rules));
        $this->step = min(4, $this->step + 1);
    }

    public function updatedMode(): void
    {
        $this->scope = 'organization';
        $this->site_id = null;
        $this->resetValidation();
    }

    public function updatedOrganizationId(): void
    {
        $this->site_id = null;
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->resetValidation();
    }

    public function finish(): void
    {
        abort_unless(self::canAccess(), 403);
        if ($this->completedOrganization !== null) {
            return;
        }
        try {
            $result = app(OperatorOnboarding::class)->create(auth()->user(), $this->requestId, $this->only(['mode', 'name', 'code', 'organization_id', 'email', 'role_id', 'scope', 'site_id']));
            $this->completedOrganization = $result['organization_id'];
        } catch (ValidationException $error) {
            $field = array_key_first($error->errors());
            $this->step = match ($field) {
                'name', 'code', 'mode', 'organization_id' => 1,
                'email', 'role_id' => 2,
                default => 3,
            };
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);

        return [
            'organizations' => Organization::query()->where('type', OrganizationType::ChargePointOperator)->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'roles' => app(OperatorOnboarding::class)->roles(auth()->user()),
            'sites' => $this->mode === 'existing' && $this->organization_id !== null
                ? app(AccessibleSitesQuery::class)->for(auth()->user())->where('operator_organization_id', $this->organization_id)->orderBy('name')->pluck('name', 'id') : collect(),
        ];
    }
}
