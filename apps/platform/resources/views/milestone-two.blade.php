<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $feature->label() }} · Milestone 2 · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="public-site">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-20">
        <section class="w-full rounded-[2rem] border border-amber-300/40 bg-amber-50 p-8 shadow-sm dark:border-amber-500/30 dark:bg-amber-950/20 sm:p-12">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700 dark:text-amber-300">Milestone 2</p>
            <h1 class="mt-3 text-3xl font-semibold text-slate-950 dark:text-white">{{ $feature->label() }}</h1>
            <p class="mt-4 text-lg text-slate-700 dark:text-slate-200">{{ \App\Foundation\Features\FeatureFlags::MILESTONE_TWO_MESSAGE }}</p>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">No charger, payment, or external-provider request was made.</p>
            <a href="{{ route('home') }}" class="mt-8 inline-flex rounded-full bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-teal-300 dark:bg-white dark:text-slate-950">Return to the demo</a>
        </section>
    </main>
</body>
</html>
