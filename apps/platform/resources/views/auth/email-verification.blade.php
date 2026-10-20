<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $verified ? 'Email verified' : 'Verification link unavailable' }} · Power Solutions</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-lg px-6 py-20 sm:py-28">
        <p class="mb-10 text-sm font-semibold tracking-wide text-teal-700">POWER SOLUTIONS</p>
        <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="mb-4 text-sm font-medium text-teal-700">ACCOUNT VERIFICATION</p>
            <h1 class="text-3xl font-semibold tracking-tight">{{ $verified ? 'Email verified' : 'Verification link unavailable' }}</h1>
            <p class="mt-5 leading-relaxed text-slate-600">
                @if ($verified)
                    Your email address is confirmed. Return to the Power Solutions app and sign in, or tap “I've verified my email” if you are already signed in.
                @else
                    This link is invalid or has expired. Return to the Power Solutions app, sign in, and request a new verification email.
                @endif
            </p>
            <p class="mt-8 border-t border-slate-100 pt-5 text-sm text-slate-500">You can close this tab.</p>
        </div>
    </main>
</body>
</html>
