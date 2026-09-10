<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\Models\ApiToken;
use App\Modules\Identity\Domain\Models\AuthSession;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Organizations\Domain\Models\Membership;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $public_id
 * @property int $security_version
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $mobile_verified_at
 * @property bool $mfa_required
 * @property CarbonImmutable|null $mfa_enrolled_at
 */
#[Fillable([
    'public_id',
    'name',
    'email',
    'password',
    'security_version',
    'disabled_at',
    'activated_at',
    'mobile_number',
    'mobile_number_hash',
    'mobile_verified_at',
    'mfa_required',
    'mfa_enrolled_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'immutable_datetime',
            'mobile_number' => 'encrypted',
            'mobile_verified_at' => 'immutable_datetime',
            'mfa_required' => 'boolean',
            'mfa_enrolled_at' => 'immutable_datetime',
            'password' => 'hashed',
            'security_version' => 'integer',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** @return MorphMany<MobileAccessToken, $this> */
    public function mobileTokens(): MorphMany
    {
        /** @var MorphMany<MobileAccessToken, $this> $relation */
        $relation = $this->tokens();

        return $relation;
    }

    public function isEnabled(): bool
    {
        return $this->activated_at !== null && $this->disabled_at === null;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return app(PanelAccessService::class)->canAccess($this, $panel->getId());
    }
}
