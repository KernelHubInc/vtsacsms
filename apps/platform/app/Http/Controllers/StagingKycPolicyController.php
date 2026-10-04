<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class StagingKycPolicyController extends Controller
{
    public const VERSION = 'staging-optical-2026-10-02-v1';

    public const CONSENT = 'I voluntarily agree to this staging KYC test: processing my identity details, document images and live camera frames for optical document checks, face comparison, liveness checks and authorized manual review. Optical checks do not confirm government issuance. Only the reference selfie is retained from the live capture; other live frames are processed in memory. I have read the staging privacy, participation terms and consent notices and understand how to withdraw.';

    public function __invoke(string $page): Response
    {
        abort_unless(app()->environment(['staging', 'demo']), 404);

        $title = match ($page) {
            'privacy' => 'Privacy for the staging KYC test',
            'terms' => 'Staging test participation terms',
            'consent' => 'Consent to staging identity checks',
            default => abort(404),
        };

        return response()->view('kyc.staging-policy', [
            'page' => $page,
            'title' => $title,
            'version' => self::VERSION,
            'consent' => self::CONSENT,
            'retentionDays' => (int) config('kyc.retention_days'),
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
