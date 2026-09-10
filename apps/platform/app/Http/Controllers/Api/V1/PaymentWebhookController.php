<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Payments\Application\PaymentWebhookService;
use App\Modules\Payments\Application\Providers\ProviderException;
use App\Modules\Payments\Application\WebhookHandlingResult;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $tenant, string $configuration, CurrentTenant $currentTenant, PaymentWebhookService $webhooks): JsonResponse
    {
        $valid = DB::table('payment_provider_configs')->join('tenants', 'tenants.id', '=', 'payment_provider_configs.tenant_id')
            ->where('payment_provider_configs.id', $configuration)->where('payment_provider_configs.tenant_id', $tenant)
            ->where('payment_provider_configs.is_active', true)->where('tenants.status', TenantStatus::Active->value)->exists();
        if (! $valid) {
            return response()->json(['error' => ['code' => 'resource_not_found', 'message' => 'Webhook destination was not found.', 'correlation_id' => $request->attributes->get('correlation_id')]], 404);
        }
        $context = new TenantContext($tenant, ActorType::Service, null, (string) $request->attributes->get('correlation_id'));
        try {
            $result = $currentTenant->run($context, function () use ($configuration, $request, $webhooks): WebhookHandlingResult {
                $provider = PaymentProviderConfig::query()->findOrFail($configuration);

                $signature = $request->header('Stripe-Signature') ?? $request->header('X-Payment-Signature', '');

                return $webhooks->handle($provider, $request->getContent(), (string) $signature);
            });
        } catch (ProviderException) {
            return response()->json(['error' => ['code' => 'invalid_webhook', 'message' => 'Webhook verification failed.',
                'correlation_id' => $request->attributes->get('correlation_id')]], 400);
        }

        return response()->json(['data' => ['outcome' => $result->outcome]], 202);
    }
}
