<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\KycLiveFrameRequest;
use App\Http\Requests\Api\V1\KycStartRequest;
use App\Http\Requests\Api\V1\KycUploadRequest;
use App\Models\User;
use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Application\Kyc\KycSettings;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function status(Request $request): JsonResponse
    {
        $row = $this->kyc->latest($this->user($request));
        $unavailable = false;
        if ($row !== null && config('kyc.enabled') && in_array($row->status, [KycStatus::InProgress, KycStatus::PendingUpload, KycStatus::Submitted, KycStatus::Processing], true)) {
            try {
                $row = $this->kyc->reconcile($row);
            } catch (KycException) {
                $unavailable = true;
            }
        }

        return response()->json(['data' => [
            ...$this->present($row), 'enabled' => (bool) config('kyc.enabled'), 'service_unavailable' => $unavailable,
            'documents' => config('kyc.documents'),
            'consent' => ['version' => config('kyc.consent_version'), 'text' => config('kyc.consent_text'), 'privacy_url' => config('kyc.privacy_url'), 'terms_url' => config('kyc.terms_url'), 'consent_url' => config('kyc.consent_url'), 'retention_days' => config('kyc.retention_days')],
        ]]);
    }

    public function start(KycStartRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->kyc->start($this->user($request), $request->validated(), $request->routeIs('api.v1.kyc.resubmit')))], 201);
    }

    public function show(Request $request, string $verification): JsonResponse
    {
        return response()->json(['data' => $this->present($this->kyc->owned($this->user($request), $verification))]);
    }

    public function upload(KycUploadRequest $request, string $verification): JsonResponse
    {
        $row = $this->kyc->owned($this->user($request), $verification);
        /** @var UploadedFile $file */
        $file = $request->file('image');

        return response()->json(['data' => $this->present($this->kyc->upload($row, (string) $request->validated('kind'), $file))]);
    }

    public function submit(Request $request, string $verification): JsonResponse
    {
        return response()->json(['data' => $this->present($this->kyc->submit($this->kyc->owned($this->user($request), $verification)))], 202);
    }

    public function liveStart(Request $request, string $verification): JsonResponse
    {
        return response()->json(['data' => $this->kyc->live($this->kyc->owned($this->user($request), $verification))]);
    }

    public function liveFrame(KycLiveFrameRequest $request, string $verification): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('image');

        return response()->json(['data' => $this->kyc->live($this->kyc->owned($this->user($request), $verification), $file, (string) $request->validated('token'))]);
    }

    public function cancel(Request $request, string $verification): JsonResponse
    {
        return response()->json(['data' => $this->present($this->kyc->cancel($this->kyc->owned($this->user($request), $verification)))]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string, mixed> */
    private function present(?KycVerification $row): array
    {
        return [
            'id' => $row?->id, 'status' => $row?->status->value ?? KycStatus::NotStarted->value,
            'document_type' => $row?->document_type, 'evidence' => $row->evidence ?? [],
            'review_mode' => $row->review_mode ?? app(KycSettings::class)->mode(),
            'assurance_profile' => $row->assurance_profile ?? config('kyc.assurance_profile'),
            'live_capture_required' => ($row->assurance_profile ?? config('kyc.assurance_profile')) === 'optical_v1',
            'reason_code' => $row?->reason_code, 'expires_at' => $row?->expires_at?->toIso8601String(),
            'can_resubmit' => $row?->status->canResubmit() ?? false,
        ];
    }
}
