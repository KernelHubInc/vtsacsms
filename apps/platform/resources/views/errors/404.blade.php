<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#12366b">
    <title>Page not found | Power Solutions</title>
    <link rel="icon" href="{{ asset('branding/power-solutions-favicon.png') }}">
    {{-- Keep error recovery independent of the asset build and application layout. --}}
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #12366b; background: #f5f8fb; }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 280px; }
        .error-page { min-height: 100vh; min-height: 100svh; max-width: 1440px; margin: auto; display: flex; flex-direction: column; padding: 32px clamp(24px, 5vw, 72px); }
        header { display: flex; justify-content: space-between; align-items: center; gap: 24px; }
        .brand { display: inline-flex; border-radius: 8px; }
        .brand img { display: block; width: 216px; max-width: 100%; height: auto; }
        main { display: grid; grid-template-columns: 1.05fr 1fr; gap: clamp(32px, 5vw, 80px); align-items: center; margin: auto 0; padding: 64px 0; }
        h1 { max-width: 600px; margin: 0; font-size: clamp(40px, 4.8vw, 68px); line-height: 1.06; font-weight: 750; letter-spacing: -.055em; text-wrap: balance; }
        h1 span { color: #177ba7; }
        .message { max-width: 390px; margin: 24px 0 32px; font-size: 17px; line-height: 1.8; color: #52657c; text-wrap: pretty; }
        .home-link { display: inline-flex; align-items: center; justify-content: center; gap: 20px; min-height: 54px; padding: 14px 22px; border: 2px solid #12366b; border-radius: 8px; background: #12366b; color: #fff; text-decoration: none; font-size: 15px; font-weight: 650; }
        .home-link:hover { background: #1c4b83; border-color: #1c4b83; }
        a:focus-visible { outline: 3px solid #177ba7; outline-offset: 5px; }
        .home-link svg { width: 19px; height: 19px; flex-shrink: 0; }
        .route-card { position: relative; min-width: 0; overflow: hidden; border-radius: 24px 24px 80px 24px; background: #102c51; color: #fff; padding: 28px; }
        .illustration { display: block; width: 100%; height: auto; margin: 12px 0 0; }
        footer { display: flex; justify-content: space-between; gap: 16px; padding-top: 20px; border-top: 1px solid #d9e3ec; color: #66758a; font-size: 12px; line-height: 1.5; }
        @media (max-width: 760px) { .error-page { padding: 24px; } .brand img { width: 185px; } main { grid-template-columns: 1fr; gap: 36px; padding: 48px 0; } h1 { max-width: 480px; font-size: clamp(40px, 8vw, 60px); } .message { font-size: 16px; } .route-card { padding: 24px; } .illustration { max-height: 240px; } footer { flex-wrap: wrap; } }
    </style>
</head>
<body>
    <div class="error-page">
        <header>
            <a class="brand" href="{{ url('/') }}" aria-label="Power Solutions homepage">
                <img src="{{ asset('branding/power-solutions-logo-horizontal.svg') }}" alt="Power Solutions" width="670" height="150">
            </a>
        </header>
        <main id="main-content">
            <section aria-labelledby="error-title">
                <h1 id="error-title">Page <span>not found.</span></h1>
                <p class="message">Error 404. The link may have changed, or the address may be incorrect. You can return to the homepage to continue.</p>
                <a class="home-link" href="{{ url('/') }}">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6L5 12L11 18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Go back to homepage
                </a>
            </section>
            <div class="route-card" aria-hidden="true">
                <svg class="illustration" viewBox="0 0 440 350" fill="none" focusable="false">
                    <path d="M-20 150L230 20L470 145L220 280Z" fill="#15385e"/>
                    <path d="M-20 205L220 330L470 200M-20 177L220 302L470 172" stroke="#21486d"/>
                    <path d="M-20 150L230 20L470 145L220 280Z M40 120L280 248M104 87L344 215M168 54L408 182M40 182L290 52M100 214L350 84M160 246L410 116" stroke="#2a4c6f"/>
                    <path d="M103 206L167 173L232 207L357 142" stroke="#102c51" stroke-width="24" stroke-linejoin="round"/>
                    <path d="M103 206L167 173L232 207L289 177" stroke="#57ddd2" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M303 170L357 142" stroke="#95b9cf" stroke-width="3" stroke-linecap="round" stroke-dasharray="3 9"/>
                    <ellipse cx="103" cy="206" rx="16" ry="9" fill="#57ddd2" fill-opacity=".2"/>
                    <circle cx="103" cy="205" r="6" fill="#57ddd2"/>
                    <path d="M167 173V140" stroke="#57ddd2" stroke-width="2"/>
                    <path d="M167 140C167 140 142 117 142 101A25 25 0 0 1 192 101C192 117 167 140 167 140Z" fill="#57ddd2"/>
                    <path d="M171 86L158 104H167L163 116L177 98H168L171 86Z" fill="#12366b"/>
                    <ellipse cx="357" cy="142" rx="17" ry="10" stroke="#95b9cf" stroke-width="2" stroke-dasharray="3 5"/>
                    <path d="M357 128V93" stroke="#95b9cf" stroke-width="2" stroke-dasharray="3 5"/>
                    <rect x="333" y="42" width="48" height="48" rx="12" fill="#21486d" stroke="#7095b3"/>
                    <path d="M350 59L364 73M364 59L350 73" stroke="#b7d1e4" stroke-width="2" stroke-linecap="round"/>
                    <path d="M40 272L54 279M54 279L68 272M54 279V291" stroke="#2589be" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
        </main>
        <footer><span>Power Solutions</span></footer>
    </div>
</body>
</html>
