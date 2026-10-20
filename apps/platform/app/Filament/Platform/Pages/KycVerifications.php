<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

final class KycVerifications extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected string $view = 'filament.platform.pages.kyc-verifications';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $selected = null;

    public string $reason = '';

    public ?string $evidenceKind = null;

    public function applyFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->resetPage(pageName: 'cursor');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) config('kyc.enabled') && $user instanceof User
            && app(CurrentTenant::class)->has()
            && app(AuthorizationService::class)->allows($user, PermissionKey::KycView);
    }

    public function selectVerification(string $id): void
    {
        abort_unless(self::canAccess(), 403);
        KycVerification::query()->findOrFail($id);
        $this->selected = $id;
        $this->reason = '';
        $this->evidenceKind = null;
    }

    public function decide(string $status): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $next = KycStatus::tryFrom($status);
        abort_if($next === null, 422);
        /** @var User $user */
        $user = auth()->user();
        try {
            app(KycService::class)->review($user, KycVerification::query()->findOrFail($this->selected), $next, $this->reason);
            $this->reason = '';
            Notification::make()->title('Review recorded')->success()->send();
        } catch (KycException $exception) {
            Notification::make()->title('Review could not be completed')->body($exception->errorCode)->danger()->send();
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        request()->attributes->set('kyc_sensitive_response', true);
        $query = KycVerification::query()->latest('id');
        if (KycStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }
        if ($this->search !== '') {
            $query->where(function ($query): void {
                $query->where('id', 'like', substr($this->search, 0, 26).'%')
                    ->orWhere('subject_id', 'like', substr($this->search, 0, 26).'%');
            });
        }
        $selected = $this->selected === null ? null : KycVerification::query()->findOrFail($this->selected);
        /** @var User $user */
        $user = auth()->user();
        $details = null;
        $image = null;
        if ($selected !== null && app(AuthorizationService::class)->allows($user, PermissionKey::KycSensitiveView)) {
            try {
                $details = app(KycService::class)->details($user, $selected);
                if ($this->evidenceKind !== null) {
                    $image = app(KycService::class)->image($user, $selected, $this->evidenceKind);
                }
            } catch (KycException) {
                $details = ['unavailable' => true];
            }
        }
        $tenant = app(CurrentTenant::class)->get()->tenantId;

        return [
            'records' => $query->cursorPaginate(20), 'selection' => $selected,
            'statuses' => KycStatus::cases(), 'details' => $details, 'evidenceImage' => $image,
            'canReview' => app(AuthorizationService::class)->allows($user, PermissionKey::KycReview),
            'subject' => $selected === null ? null : User::query()->where('public_id', $selected->subject_id)->first(['public_id', 'name']),
            'timeline' => $selected === null ? [] : DB::table('kyc_events')->where('tenant_id', $tenant)->where('verification_id', $selected->id)->orderByDesc('occurred_at')->limit(100)->get(),
            'audit' => $selected === null ? [] : DB::table('audit_events')->where('tenant_id', $tenant)->where('target_id', $selected->id)->orderByDesc('occurred_at')->limit(30)->get(['action', 'actor_id', 'occurred_at', 'content_hash']),
        ];
    }
}
