<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $title }} · Power Solutions</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <a href="#notice" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:bg-white focus:p-4">Skip to notice</a>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-6">
            <span class="text-lg font-semibold tracking-tight">Power Solutions <span class="font-normal text-slate-500">/ Identity testing</span></span>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold tracking-wide text-amber-900">STAGING ONLY</span>
        </div>
    </header>
    <main id="notice" class="mx-auto grid max-w-5xl gap-10 px-6 py-12 md:grid-cols-[180px_minmax(0,1fr)] md:py-16">
        <nav aria-label="Staging KYC notices" class="flex flex-wrap gap-2 self-start md:flex-col">
            @foreach (['privacy' => 'Privacy notice', 'terms' => 'Participation terms', 'consent' => 'Consent notice'] as $slug => $label)
                <a href="{{ route('kyc.staging-policy', ['page' => $slug]) }}"
                   @if ($page === $slug) aria-current="page" @endif
                   @class(['rounded-lg px-3 py-3 text-sm font-medium underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700', 'bg-slate-900 text-white' => $page === $slug, 'text-slate-600' => $page !== $slug])>{{ $label }}</a>
            @endforeach
        </nav>
        <article class="min-w-0 space-y-8 leading-7">
            <div>
                <p class="text-xs font-semibold tracking-wide text-teal-800">TEST NOTICE · {{ $version }}</p>
                <h1 class="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{{ $title }}</h1>
                <p class="mt-5 border-l-4 border-amber-400 bg-amber-50 p-4 text-sm text-amber-950">For invited adult staging testers only. This notice covers a voluntary technical test, not production identity verification or production terms. Test outcomes must not be used for real financial or access decisions.</p>
            </div>

            @if ($page === 'privacy')
                <section class="space-y-3"><h2 class="text-xl font-semibold">What the test processes</h2>
                    <p>The test processes the identity details you enter, uploaded identity document images, and front-camera frames. It checks document text and structure, compares the document portrait with your reference selfie, and evaluates a live camera challenge. Optical checks do not confirm government issuance.</p>
                    <p>Only the reference selfie is retained from live capture. Other live frames and face features are processed in memory; face embeddings are not stored as biometric templates.</p>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">Where the information goes</h2>
                    <p>The staging application uses its private, self-hosted KYC processor and encrypted evidence storage. Identity evidence is not sent to a third-party verification provider. Authorized staging reviewers can inspect evidence for their tenant; sensitive evidence access is audited.</p>
                    <p>The application keeps verification identifiers, consent version and time, status, check results, and review/audit records. Avoid putting identity details or evidence in screenshots, support messages, or bug reports.</p>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">Retention and withdrawal</h2>
                    <p>This application is configured for {{ $retentionDays }} days of evidence retention. The processor must use the same setting. Evidence is scheduled for deletion after that period; cancellation requests earlier erasure. Background cleanup can retry after a failure, so cancellation is not a promise of immediate deletion.</p>
                    <p>Verification and audit records can remain after evidence deletion. Live-store deletion does not immediately remove separately retained backups. Before using real personal data, ask the person coordinating your test to confirm the test-end cleanup and backup retention arrangements.</p>
                    <p>Use the KYC cancellation control to withdraw. If it is unavailable, contact the administrator who invited you, using your verification reference rather than sending document images.</p>
                </section>
            @elseif ($page === 'terms')
                <section class="space-y-3"><h2 class="text-xl font-semibold">A voluntary test</h2>
                    <p>Participate only if you are an invited adult tester. You can decline or stop the test. Start with approved synthetic fixtures where possible. Use real identity documents or camera captures only for your own identity, with your informed agreement and the test coordinator's authorization. Never upload another person's identity documents.</p>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">What a result means</h2>
                    <p>This is an experimental optical verification workflow. It can fail, misclassify a document, or require another capture. It does not establish government issuance, certified liveness, or measured biometric accuracy. Automatic approval is disabled for this staging notice; an authorized reviewer handles the result.</p>
                    <p>Do not rely on staging results for real payments, charging eligibility, financial services, or production account access.</p>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">Responsible testing</h2>
                    <p>Use only your assigned test account and tenant. Do not share credentials or evidence, attempt to access another tenant's records, or perform disruptive testing. Report problems to the person coordinating your test using a verification reference and a description without personal data.</p>
                    <p>The environment may be unavailable or reset. These participation instructions do not replace production policies, establish legal compliance, or waive participant rights.</p>
                </section>
            @else
                <section class="space-y-3"><h2 class="text-xl font-semibold">Read before starting</h2>
                    <p>Read the privacy notice and participation terms linked here before selecting the consent checkbox in the app. Opening this page does not record consent.</p>
                    <blockquote class="rounded-xl border border-teal-200 bg-teal-50 p-5 text-teal-950">{{ $consent }}</blockquote>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">Your choice is recorded</h2>
                    <p>Starting an attempt records your consent version and timestamp against that attempt. Document upload and the live camera challenge follow your consent. Review is manual; a staging result is not proof of government-issued identity.</p>
                </section>
                <section class="space-y-3"><h2 class="text-xl font-semibold">You can stop</h2>
                    <p>You may decline before starting, stop capture, or use the KYC cancellation control to withdraw and request evidence erasure. If cancellation is unavailable, contact the administrator who invited you. See the privacy notice for retention and cleanup limits.</p>
                </section>
            @endif
            <footer class="border-t border-slate-200 pt-6 text-sm text-slate-500">Staging test notice · 2 October 2026 · {{ $version }}</footer>
        </article>
    </main>
</body>
</html>
