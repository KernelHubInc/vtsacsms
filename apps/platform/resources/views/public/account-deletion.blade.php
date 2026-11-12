<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Request deletion of your Power Solutions EV charging app account and associated data.">
    <meta name="referrer" content="no-referrer">
    <title>Request account deletion · Power Solutions</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
    <main class="mx-auto max-w-xl px-5 py-10 sm:py-16">
        <a href="{{ route('home') }}" aria-label="Power Solutions home">
            <img src="{{ asset('branding/power-solutions-logo-white.png?v=20261103-theme') }}" alt="Power Solutions" class="mb-8 h-10 max-w-full">
        </a>
        <section class="rounded-3xl bg-white p-7 shadow-xl sm:p-10" aria-labelledby="deletion-title">
            <h1 id="deletion-title" class="text-3xl font-semibold tracking-tight">Request account deletion</h1>
            <p class="mt-4 text-sm leading-6 text-slate-600">
                Use this form to request deletion of your Power Solutions EV charging app account and its associated data. You can submit a request even if you have removed the app.
            </p>
            @if ($received)
                <div role="status" class="mt-6 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm leading-6 text-teal-950">
                    Your request was received. The support team will review it and contact you at your account email.
                </div>
            @else
                @if ($awaitingCode)
                    <form method="post" action="{{ route('public.account-deletion.confirm') }}" class="mt-6 space-y-4">
                        @csrf
                        <p role="status" class="text-sm leading-6 text-slate-600">If the email matches an eligible account, we sent a verification code. Enter it here within 15 minutes.</p>
                        <label for="account-deletion-code" class="block text-sm font-semibold">Eight-digit verification code</label>
                        <input id="account-deletion-code" name="account_deletion_code" inputmode="numeric" pattern="[0-9]{8}" autocomplete="one-time-code" maxlength="8" required
                            @error('account_deletion_code') aria-invalid="true" aria-describedby="deletion-code-error" @enderror
                            class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                        @error('account_deletion_code')<p id="deletion-code-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                        <button type="submit" class="rounded-xl bg-blue-900 px-5 py-3 font-semibold text-white hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">Submit deletion request</button>
                    </form>
                @endif
                <form method="post" action="{{ route('public.account-deletion.send') }}" class="mt-6 space-y-4 {{ $awaitingCode ? 'border-t border-slate-200 pt-5' : '' }}">
                    @csrf
                    <label for="deletion-email" class="block text-sm font-semibold">Account email</label>
                    <input id="deletion-email" name="email" type="email" autocomplete="email" maxlength="254" required
                        @error('email') aria-invalid="true" aria-describedby="deletion-email-error" @enderror
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                    @error('email')<p id="deletion-email-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    <button type="submit" class="rounded-xl {{ $awaitingCode ? 'border border-slate-300 text-slate-900 hover:bg-slate-50' : 'bg-blue-900 text-white hover:bg-blue-800' }} px-5 py-3 font-semibold focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">
                        {{ $awaitingCode ? 'Request a new code' : 'Send verification code' }}
                    </button>
                </form>
            @endif
            <div class="mt-7 space-y-3 border-t border-slate-200 pt-5 text-sm leading-6 text-slate-600">
                <h2 class="font-semibold text-slate-900">What happens next</h2>
                <p>Support reviews the verified request for your login, driver profile, saved vehicles and other associated data. The team will explain any records it needs to retain for legal, security or fraud-prevention reasons.</p>
                <a class="font-semibold text-blue-900 underline" href="{{ route('public.privacy-policy') }}">Privacy policy</a>
            </div>
        </section>
    </main>
</body>
</html>
