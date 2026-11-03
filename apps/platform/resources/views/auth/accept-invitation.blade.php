<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer"><title>Operator invitation · Power Solutions</title>@vite(['resources/css/app.css'])</head>
<body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
    <main class="mx-auto max-w-lg px-5 py-12">
        <img src="{{ asset('branding/power-solutions-logo-white.png?v=20261103-theme') }}" alt="Power Solutions" class="mx-auto mb-8 h-10 max-w-full">
        <section class="rounded-3xl bg-white p-7 shadow-xl sm:p-10">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $completed ? 'Your invitation is accepted' : 'Welcome to your operator workspace' }}</h1>
            @if ($completed)
                <p class="mt-4 leading-7 text-slate-600">Sign in with your own email address and password to open the workspace. Your administrator controls the sites and tools you can access.</p>
                <a href="{{ url('/operator/login') }}" class="mt-6 inline-block rounded-xl bg-blue-800 px-5 py-3 font-semibold text-white">Sign in to the operator portal</a>
            @else
                <p class="mt-4 text-sm leading-6 text-slate-600">New to Power Solutions? Enter your name and choose a password. If you already have an account, leave these fields blank; your existing password stays the same.</p>
                @if($errors->any())<div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
                <form method="post" action="{{ route('operator-invitation.accept') }}" class="mt-6 space-y-5">
                    @csrf<input type="hidden" name="token" value="{{ $token }}">
                    <div><label for="name" class="mb-2 block text-sm font-semibold">Full name</label><input id="name" name="name" maxlength="160" autocomplete="name" value="{{ old('name') }}" class="w-full rounded-xl border border-slate-300 px-4 py-3"></div>
                    <div><label for="password" class="mb-2 block text-sm font-semibold">Password for a new account</label><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="requirements" class="w-full rounded-xl border border-slate-300 px-4 py-3"><p id="requirements" class="mt-2 text-sm text-slate-500">At least 8 characters, with uppercase and lowercase letters, a number and a symbol.</p></div>
                    <div><label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-4 py-3"></div>
                    <button type="submit" class="w-full rounded-xl bg-blue-800 px-5 py-3 font-semibold text-white hover:bg-blue-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-500">Accept invitation</button>
                </form>
                <p class="mt-5 text-sm text-slate-500">Expired or incorrect invitation? Ask your administrator to send a new link.</p>
            @endif
        </section>
    </main>
</body></html>
