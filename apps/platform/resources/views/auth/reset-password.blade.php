<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $completed ? 'Password updated' : 'Reset password' }} · Power Solutions</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
    <main class="mx-auto max-w-lg px-5 py-10 sm:py-16">
        <img src="{{ asset('branding/power-solutions-logo-horizontal-dark.svg') }}" alt="Power Solutions" class="mx-auto mb-10 h-10 max-w-full">
        <section class="rounded-3xl bg-white p-7 shadow-xl sm:p-10">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $completed ? 'Password updated' : 'Choose a new password' }}</h1>
            @if ($completed)
                <p class="mt-5 leading-7 text-slate-600">Return to the Power Solutions app and sign in with your new password. Existing signed-in sessions have been revoked. You can set up quick unlock again after signing in.</p>
            @else
                <p class="mt-4 leading-6 text-slate-600">Use a password you do not use for other accounts.</p>
                @if ($error || $errors->any())
                    <div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        @if ($error)<p>{{ $error }}</p>@endif
                        @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                    </div>
                @endif
                <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold">Email address</label>
                        <input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email', $email) }}" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-blue-600 focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold">New password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-blue-600 focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-blue-600 focus:ring-2 focus:ring-blue-200">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-blue-700 px-5 py-3 font-semibold text-white hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-600">Update password</button>
                </form>
                <p class="mt-6 text-sm leading-6 text-slate-500">Link expired? Open the app, choose Forgot password, and request another email.</p>
            @endif
        </section>
    </main>
</body>
</html>
