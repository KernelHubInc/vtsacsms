<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Support\Domain\Models\SupportTicketImage;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class SupportImageController extends Controller
{
    public function __invoke(Request $request, string $panel, string $image): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tenant = app(PanelAccessService::class)->tenantIdFor($actor, $panel);
        abort_if($tenant === null, 403);

        return app(CurrentTenant::class)->run(new TenantContext($tenant, ActorType::Human, $actor->public_id,
            (string) $request->attributes->get('correlation_id')), function () use ($actor, $image): Response {
                $record = SupportTicketImage::query()->with('ticket')->whereKey($image)->whereNull('purged_at')->firstOrFail();
                Gate::forUser($actor)->authorize('view', $record->ticket);
                abort_if($record->content === null, 404);
                app(AuditRecorder::class)->record(new AuditEntry('support.image.viewed', 'support_ticket', (string) $record->ticket_id,
                    AuditResult::Succeeded, metadata: ['image_id' => $record->getKey()]));

                return response($record->content)->header('Content-Type', (string) $record->mime_type)
                    ->header('Content-Disposition', 'inline; filename="support-image.'.($record->mime_type === 'image/png' ? 'png' : 'jpg').'"')
                    ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff')
                    ->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'none'; sandbox");
            });
    }
}
