<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class OcppAuthorizationService
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationTokenService $tokens,
    ) {}

    /** @param array<string, mixed> $request
     * @return array{status: string, expires_at: string|null, parent_token: null}
     */
    public function authorize(array $request): array
    {
        if (! hash_equals($this->tenant->get()->tenantId, (string) ($request['tenant_id'] ?? ''))) {
            return $this->invalid();
        }
        $chargerId = $request['charger_id'] ?? null;
        $plainText = $this->plainTextToken($request['token'] ?? null);
        if (! is_string($chargerId) || $chargerId === '' || $plainText === null) {
            return $this->invalid();
        }

        return DB::transaction(fn (): array => $this->tokens->authorize($plainText, $chargerId));
    }

    private function plainTextToken(mixed $token): ?string
    {
        if (! is_array($token)) {
            return null;
        }
        if (is_string($token['id_tag'] ?? null)) {
            return $token['id_tag'];
        }
        $idToken = $token['id_token'] ?? null;

        return is_array($idToken) && is_string($idToken['id_token'] ?? null) ? $idToken['id_token'] : null;
    }

    /** @return array{status: string, expires_at: null, parent_token: null} */
    private function invalid(): array
    {
        return ['status' => 'Invalid', 'expires_at' => null, 'parent_token' => null];
    }
}
