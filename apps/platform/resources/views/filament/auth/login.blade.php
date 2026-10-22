@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $isOperator = filament()->getCurrentPanel()?->getId() === 'operator';
    $workspace = $isOperator ? 'Operator workspace' : 'Administration';
    $role = $isOperator ? 'operator' : 'administrator';
    $isChallenge = filled($this->userUndertakingMultiFactorAuthentication);
@endphp

<div class="ps-admin-login">
    {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_PAGE_START, scopes: $this->getRenderHookScopes()) }}

    <aside class="ps-admin-login-brand" aria-label="Power Solutions">
        <div class="ps-admin-login-identity">
            <img src="{{ asset('branding/power-solutions-mark.svg') }}" alt="" width="40" height="40">
            <div>
                <strong>Power Solutions</strong>
                <span>{{ $workspace }}</span>
            </div>
        </div>

        <div class="ps-admin-login-story">
            <div class="ps-admin-login-message">
                <span class="ps-admin-login-accent" aria-hidden="true"></span>
                <h2>Charging operations, <br>in focus.</h2>
                <p>Sign in to manage your charging operations.</p>
            </div>
            <img class="ps-admin-login-photo" src="{{ asset('branding/admin-login/charging-destination.png') }}" alt="An electric vehicle charging outside a destination" width="568" height="312">
            <div class="ps-admin-login-signature"><span>Places. People. Power.</span></div>
        </div>

        <p class="ps-admin-login-brand-copyright">© {{ now()->year }} Power Solutions</p>
    </aside>

    <div class="ps-admin-login-panel">
        <a class="ps-admin-login-website" href="{{ url('/') }}">
            <span>Power Solutions website</span>
            <img src="{{ asset('branding/admin-login/arrow-up-right.svg') }}" alt="" width="14" height="14">
        </a>

        <main class="ps-admin-login-main" aria-labelledby="ps-admin-login-heading">
            <div class="ps-admin-login-intro">
                <h1 id="ps-admin-login-heading">{{ $isChallenge ? 'Verify your sign in' : 'Sign in' }}</h1>
                <p>{{ $isChallenge ? 'Complete the security check to continue.' : "Use your {$role} account to continue." }}</p>
            </div>

            <div class="ps-admin-login-form">
                {{ $this->content }}
            </div>

            <div class="ps-admin-login-access">
                <img src="{{ asset('branding/admin-login/lock-keyhole.svg') }}" alt="" width="14" height="16">
                <span>For authorized {{ $isOperator ? 'operators' : 'administrators' }} only.</span>
            </div>
        </main>

        <p class="ps-admin-login-panel-copyright">Power Solutions {{ $workspace }}</p>
        <p class="ps-admin-login-mobile-copyright">© {{ now()->year }} Power Solutions</p>
    </div>

    <x-filament-actions::modals />

    {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_PAGE_END, scopes: $this->getRenderHookScopes()) }}
</div>
