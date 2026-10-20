<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Kyc;

use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Domain\KycStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class KycClient
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $payload): array
    {
        $base = rtrim((string) config('kyc.url'), '/');
        $secret = (string) config('kyc.request_secret');
        if (strlen($secret) < 32 || (! app()->environment(['local', 'testing']) && ! str_starts_with($base, 'https://'))) {
            throw new KycException('KYC_NOT_CONFIGURED', 503);
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $timestamp = (string) now('UTC')->timestamp;
            $nonce = bin2hex(random_bytes(16));
            try {
                $response = Http::connectTimeout((int) config('kyc.connect_timeout'))
                    ->timeout((int) config('kyc.timeout'))->withoutRedirecting()
                    ->withHeaders([
                        'X-KYC-Timestamp' => $timestamp, 'X-KYC-Nonce' => $nonce,
                        'X-KYC-Signature' => KycSignature::sign($secret, $method, $path, $timestamp, $nonce, $body),
                        'X-Correlation-ID' => (string) request()->attributes->get('correlation_id'),
                        'Accept' => 'application/json',
                    ])->withBody($body, 'application/json')->send($method, $base.$path);
            } catch (ConnectionException) {
                if ($attempt === 0) {
                    continue;
                }
                Log::warning('kyc.service_unavailable');
                throw new KycException('KYC_SERVICE_UNAVAILABLE', 503);
            }
            if ($response->serverError() && $attempt === 0) {
                continue;
            }
            if (! $response->successful()) {
                $code = $response->json('error.code');
                $allowed = ['FILE_TOO_LARGE', 'INVALID_IMAGE', 'INVALID_IMAGE_TYPE', 'IMAGE_RESOLUTION_TOO_LOW', 'DOCUMENT_IMAGE_TOO_BLURRY', 'EVIDENCE_INCOMPLETE', 'EVIDENCE_EXPIRED', 'IDEMPOTENCY_CONFLICT', 'INVALID_STATE_TRANSITION', 'VERIFICATION_NOT_FOUND', 'LIVE_CAPTURE_REQUIRED', 'LIVE_CAPTURE_NOT_SUPPORTED', 'LIVE_CHALLENGE_EXPIRED', 'LIVE_CHALLENGE_CONFLICT', 'LIVE_CAPTURE_TOO_FAST', 'LIVE_FRAME_REPLAY', 'LIVE_CHECK_FAILED'];
                throw new KycException(is_string($code) && in_array($code, $allowed, true) ? $code : 'KYC_SERVICE_UNAVAILABLE', $response->status() < 500 && in_array($code, $allowed, true) ? $response->status() : 503);
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new KycException('INVALID_SERVICE_RESPONSE', 503);
            }

            return $data;
        }
        throw new KycException('KYC_SERVICE_UNAVAILABLE', 503);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function challenge(array $data): array
    {
        $validator = Validator::make($data, [
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'action' => ['required', Rule::in(['center', 'left', 'right', 'complete'])],
            'step' => ['required', 'integer', 'between:0,9'],
            'total_steps' => ['required', 'integer', Rule::in([9])],
            'expires_at' => ['required', 'date'],
            'complete' => ['required', 'boolean'],
            'feedback' => ['required', Rule::in(['follow_prompt', 'hold_still', 'face_not_clear', 'complete'])],
        ]);
        if ($validator->fails() || ($data['complete'] !== ($data['action'] === 'complete')) || ($data['complete'] !== ($data['step'] === 9))) {
            throw new KycException('INVALID_SERVICE_RESPONSE', 503);
        }

        return $validator->validated();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function snapshot(array $data): array
    {
        $validator = Validator::make($data, [
            'id' => ['required', 'ulid'], 'tenant_id' => ['required', 'ulid'], 'subject_id' => ['required', 'ulid'],
            'status' => ['required', Rule::enum(KycStatus::class)], 'version' => ['required', 'integer', 'min:1'],
            'evidence_deleted' => ['required', 'boolean'], 'evidence' => ['present', 'array', 'max:3'],
            'evidence.*' => ['array:kind,width,height,bytes,sharpness'],
            'evidence.*.kind' => ['required', Rule::in(['front', 'back', 'selfie'])],
            'evidence.*.width' => ['required', 'integer', 'min:1'], 'evidence.*.height' => ['required', 'integer', 'min:1'],
            'evidence.*.bytes' => ['required', 'integer', 'min:1'], 'evidence.*.sharpness' => ['required', 'numeric', 'min:0'],
            'result' => ['nullable', 'array:provider,reason_code,checks,duration_ms', 'required_array_keys:provider,reason_code,checks,duration_ms'],
            'result.provider' => ['required_with:result', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'result.reason_code' => ['required_with:result', 'regex:/^[A-Z0-9_]{1,80}$/'],
            'result.checks' => ['array:ocr,document,document_data,optical_document,face_match,liveness'],
            'result.checks.*' => [Rule::in(['passed', 'failed', 'unavailable', 'mock'])],
            'result.duration_ms' => ['required_with:result', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            throw new KycException('INVALID_SERVICE_RESPONSE', 503);
        }

        return $validator->validated();
    }
}
