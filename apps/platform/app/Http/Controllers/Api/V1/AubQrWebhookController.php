<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AubQrWebhookController
{
    public function __invoke(Request $request, CurrentTenant $tenant, QrTopups $topups): Response
    {
        // Turning off new collection must not discard confirmations for already-created orders.
        $id = (string) config('wallet.aub.tenant_id');
        abort_unless(config('wallet.aub.approved') && DB::table('tenants')->where('id', $id)->where('status', 'active')->exists(), 404);
        $tenant->run(new TenantContext($id, ActorType::Service, null, (string) $request->attributes->get('correlation_id')),
            fn () => $topups->notify($request->getContent()));

        return response('success', 200)->header('Content-Type', 'text/plain')->header('Cache-Control', 'no-store');
    }
}
