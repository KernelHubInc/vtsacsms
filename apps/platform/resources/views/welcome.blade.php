<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Power Solutions charging platform">
        <title>Power Solutions</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-canvas text-foreground antialiased">
        <main class="relative isolate min-h-screen overflow-hidden">
            <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_10%_8%,color-mix(in_srgb,var(--vtsa-brand)_18%,transparent),transparent_35%),radial-gradient(circle_at_90%_84%,color-mix(in_srgb,var(--vtsa-accent)_16%,transparent),transparent_38%)]"></div>
            <div class="absolute inset-0 -z-10 opacity-[0.35] [background-image:linear-gradient(var(--vtsa-border)_1px,transparent_1px),linear-gradient(90deg,var(--vtsa-border)_1px,transparent_1px)] [background-size:56px_56px]"></div>

            <section class="mx-auto flex min-h-screen max-w-7xl flex-col justify-between px-6 py-8 sm:px-10 lg:px-16 lg:py-12">
                <header class="flex items-center justify-between border-b border-border pb-5">
                    <div class="flex items-center gap-3">
                        <span class="grid size-10 -rotate-3 place-items-center rounded-md bg-brand font-black text-white shadow-elevation-1">V</span>
                        <x-brand.logo class="h-12" />
                    </div>
                    <x-ui.status-chip label="Foundation online" tone="success" />
                </header>

                <div class="grid gap-12 py-20 lg:grid-cols-[1.3fr_.7fr] lg:items-end">
                    <div class="max-w-3xl">
                        <p class="mb-5 text-xs font-bold uppercase tracking-[0.24em] text-brand">Currentline design foundation</p>
                        <h1 class="text-balance text-5xl font-semibold leading-[0.98] tracking-[-0.05em] sm:text-7xl">Clear signals for connected charging.</h1>
                        <p class="mt-8 max-w-2xl text-pretty text-base leading-7 text-muted sm:text-lg">The shared visual language, accessible primitives, and operational state vocabulary are ready. Business modules remain intentionally untouched.</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            @if (app()->environment(['local', 'testing']))
                                <x-ui.button href="{{ route('design-system') }}">View component catalog</x-ui.button>
                            @endif
                            <x-ui.button href="{{ route('health.ready') }}" variant="secondary">Check readiness</x-ui.button>
                        </div>
                    </div>

                    <x-ui.card class="lg:mb-1">
                        <x-slot:eyebrow>Runtime surfaces</x-slot:eyebrow>
                        <dl class="grid gap-4 text-sm">
                            <div class="flex items-center justify-between gap-6"><dt>Liveness</dt><dd><a class="font-mono font-semibold text-brand hover:text-brand-strong" href="{{ route('health.live') }}">/health/live</a></dd></div>
                            <div class="flex items-center justify-between gap-6"><dt>Readiness</dt><dd><a class="font-mono font-semibold text-brand hover:text-brand-strong" href="{{ route('health.ready') }}">/health/ready</a></dd></div>
                            <div class="flex items-center justify-between gap-6"><dt>Administration</dt><dd><a class="font-mono font-semibold text-brand hover:text-brand-strong" href="/admin">/admin</a></dd></div>
                        </dl>
                    </x-ui.card>
                </div>

                <footer class="flex flex-col gap-2 border-t border-border pt-5 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
                    <span>Visual foundations only</span>
                    <span class="font-mono">UTC &middot; ULID &middot; minor units &middot; Wh &middot; W &middot; seconds</span>
                </footer>
            </section>
        </main>
    </body>
</html>
