<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Identity\Infrastructure\Kyc\KycSignature;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class KycCallbackController
{
    public function __invoke(Request $request, CurrentTenant $tenant, KycService $kyc): JsonResponse
    {
        $body = $request->getContent();
        $secret = (string) config('kyc.callback_secret');
        $timestamp = (string) $request->header('X-KYC-Timestamp');
        $nonce = (string) $request->header('X-KYC-Nonce');
        if (strlen($body) > 32768 || strlen($secret) < 32 || ! ctype_digit($timestamp)
            || abs(now('UTC')->getTimestamp() - (int) $timestamp) > 300
            || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1 || $request->getQueryString() !== null
            || ! hash_equals(KycSignature::sign($secret, 'POST', '/'.$request->path(), $timestamp, $nonce, $body), (string) $request->header('X-KYC-Signature'))) {
            throw new KycException('SERVICE_AUTHENTICATION_FAILED', 401);
        }
        if (! Cache::add('kyc:callback:'.$nonce, true, 610)) {
            throw new KycException('SERVICE_REPLAY_REJECTED', 401);
        }
        $validator = Validator::make($request->json()->all(), [
            'event_id' => ['required', 'ulid'], 'event_type' => ['required', 'in:identity.kyc.processed.v1'],
            'schema_version' => ['required', 'integer', 'in:1'], 'occurred_at' => ['required', 'date'],
            'tenant_id' => ['required', 'ulid'], 'aggregate_type' => ['required', 'in:kyc_verification'],
            'aggregate_id' => ['required', 'ulid'], 'correlation_id' => ['required', 'ulid'],
            'causation_id' => ['required', 'ulid'], 'data' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            throw new KycException('INVALID_CALLBACK');
        }
        $data = $validator->validated();
        $tenant->run(new TenantContext($data['tenant_id'], ActorType::Service, null, $data['correlation_id']), function () use ($kyc, $data): void {
            $row = KycVerification::query()->findOrFail((string) $data['aggregate_id']);
            $kyc->apply($row, $data['data'], $data['event_id']);
        });

        return response()->json(['received' => true])->header('Cache-Control', 'no-store');
    }
}
