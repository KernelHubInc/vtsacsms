<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Private administrator setup · Power Solutions</title>
    @vite(['resources/css/app.css', 'resources/js/workforce-setup.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
    <main class="mx-auto max-w-xl px-5 py-10 sm:py-16">
        <img src="{{ asset('branding/power-solutions-logo-white.png?v=20261103-theme') }}" alt="Power Solutions" class="mb-9 h-10 max-w-full">
        <section class="rounded-3xl bg-white p-7 shadow-xl sm:p-10" aria-labelledby="setup-title">
            <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-teal-700">Your administrator account</p>
            <h1 id="setup-title" class="text-3xl font-semibold tracking-tight">Choose your private password</h1>
            <p class="mt-4 text-sm leading-6 text-slate-600">Use the temporary password your administrator gave you or open your personal setup link. Either credential works once and expires after 24 hours.</p>
            <div id="setup-message" role="status" aria-live="polite" class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6">Enter the email address for your CSMS account and your temporary password.</div>
            <form id="workforce-setup" method="post" action="{{ route('workforce-enrollment.complete') }}" class="mt-6 space-y-5">
                @csrf
                <fieldset id="manual-setup-fields" class="space-y-5">
                    <div>
                        <label for="setup-email" class="mb-2 block text-sm font-semibold">Account email</label>
                        <input id="setup-email" name="email" type="email" autocomplete="username" maxlength="254" required class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                    </div>
                    <div>
                        <label for="enrollment-token" class="mb-2 block text-sm font-semibold">Temporary password</label>
                        <input id="enrollment-token" name="enrollment_token" type="password" autocomplete="off" maxlength="37" required class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                    </div>
                </fieldset>
                <div>
                    <label for="name" class="mb-2 block text-sm font-semibold">Display name</label>
                    <input id="name" name="name" maxlength="160" autocomplete="name" required class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                </div>
                <div>
                    <label for="password" class="mb-2 block text-sm font-semibold">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="password-help" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                    <p id="password-help" class="mt-2 text-sm leading-5 text-slate-500">At least 12 characters, with uppercase and lowercase letters, a number and a symbol.</p>
                </div>
                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200">
                </div>
                <button id="setup-submit" type="submit" disabled class="w-full rounded-xl bg-blue-900 px-5 py-3 font-semibold text-white transition hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:opacity-50">Set my password</button>
            </form>
            <div id="setup-completed" hidden class="mt-6">
                <p class="text-sm leading-6 text-slate-600">Your password is set. Sign out of any other account, then sign in with your account email and the password you just chose.</p>
                <a href="{{ url('/admin/login') }}" class="mt-5 inline-block rounded-xl bg-blue-900 px-5 py-3 font-semibold text-white hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">Open administration</a>
            </div>
            <p class="mt-6 text-xs leading-5 text-slate-500">Never send your password or setup link in chat. If you did not expect this access, leave this page and contact the account owner.</p>
        </section>
    </main>
</body>
</html>
