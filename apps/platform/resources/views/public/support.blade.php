@extends('public.layout')
@section('content')
<section class="public-section">
    <div class="public-shell grid items-start gap-12 lg:grid-cols-[0.8fr_1.2fr]">
        <aside class="lg:sticky lg:top-28">
            <p class="mb-5 text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Power Solutions · Customer care</p>
            <h1 class="max-w-lg text-4xl font-semibold leading-tight tracking-tight text-[#12366b] sm:text-5xl">Let’s get you<br>back on track.</h1>
            <p class="mt-6 max-w-md text-lg leading-relaxed text-slate-600">Tell us what happened. We’ll give you a ticket reference and follow up by email.</p>
            <div class="mt-9 max-w-md border-l-2 border-teal-600 pl-5 text-sm leading-relaxed text-slate-600">
                <p class="font-semibold text-slate-900">Charging, payments or something else?</p>
                <p class="mt-2">Include the station name and the session or payment reference when you have one. Never include passwords, PINs or complete card details.</p>
            </div>
            <p class="mt-8 text-sm text-slate-500">For an immediate equipment emergency, use the charger’s local emergency instructions and the contact information at the site.</p>
        </aside>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            @if ($receipt)
                <div role="status">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-teal-50 text-2xl text-teal-700" aria-hidden="true">✓</span>
                    <h2 class="mt-5 text-2xl font-semibold text-[#12366b]">Your request is with us.</h2>
                    <p class="mt-3 leading-relaxed text-slate-600">We’ll send an acknowledgement and follow up at your verified email address.</p>
                    <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-slate-500">Your ticket reference</p>
                    <p class="mt-2 break-all rounded-xl bg-slate-50 p-4 font-mono text-sm text-[#12366b]">{{ $receipt['ticket_number'] }}</p>
                </div>
                <form method="post" action="{{ route('public.support.reset') }}" class="mt-7">@csrf<button class="button" type="submit">Submit another request</button></form>
            @elseif ($awaitingCode)
                <p class="text-xs font-bold uppercase tracking-wider text-teal-700">Step 2 of 2 · Verify your email</p>
                <h2 class="mt-3 text-2xl font-semibold text-[#12366b]">One last step.</h2>
                <p class="mt-3 leading-relaxed text-slate-600">Check your email for an eight-digit code. It expires in 15 minutes. Your ticket is created after you confirm the code.</p>
                <form method="post" enctype="multipart/form-data" action="{{ route('public.support.confirm') }}" class="mt-7 space-y-5">
                    @csrf
                    <div><label class="mb-2 block text-sm font-semibold" for="support-code">Verification code</label><input class="w-full rounded-xl border border-slate-300 px-4 py-3 text-xl tracking-[0.25em] focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-code" name="support_code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="one-time-code" required></div>
                    @error('support_code')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    <div>
                        <label class="mb-2 block text-sm font-semibold" for="support-images">Images <span class="font-normal text-slate-500">(optional)</span></label>
                        <input class="block w-full rounded-xl border border-slate-300 p-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-300" type="file" id="support-images" name="images[]" accept="image/jpeg,image/png" multiple>
                        <p class="mt-2 text-xs text-slate-500">Up to five JPEG or PNG images, 2 MB each, maximum 3000 × 3000 pixels. Images are private to authorized support staff. Avoid passwords, payment details, faces and identity documents.</p>
                        @foreach ($errors->get('images*') as $messages) @foreach ($messages as $message)<p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p>@endforeach @endforeach
                    </div>
                    <button class="button" type="submit">Verify and submit request</button>
                </form>
                <form method="post" action="{{ route('public.support.reset') }}" class="mt-6 border-t border-slate-100 pt-5">@csrf<button type="submit" class="text-sm font-semibold text-[#12366b] underline underline-offset-4">Change details or request a new code</button></form>
            @else
                <p class="text-xs font-bold uppercase tracking-wider text-teal-700">Step 1 of 2 · Your request</p>
                <h2 class="mt-3 text-2xl font-semibold text-[#12366b]">How can we help?</h2>
                @if ($errors->any())<div role="alert" class="mt-5 rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <form method="post" action="{{ route('public.support.send') }}" class="mt-7 space-y-5">
                    @csrf
                    <div><label class="mb-2 block text-sm font-semibold" for="support-email">Email address</label><input class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-email" name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" required><p class="mt-2 text-xs text-slate-500">We’ll verify this address before accepting your request. No app account is required.</p></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><label class="mb-2 block text-sm font-semibold" for="support-category">Category</label><select class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-category" name="category" required>@foreach ($categories as $key => $label)<option value="{{ $key }}" @selected(old('category', 'general') === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div><label class="mb-2 block text-sm font-semibold" for="support-station">Station <span class="font-normal text-slate-500">(optional)</span></label><select class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-station" name="station_id"><option value="">General enquiry</option>@foreach ($stations as $id => $name)<option value="{{ $id }}" @selected(old('station_id') === $id)>{{ $name }}</option>@endforeach</select></div>
                    </div>
                    <div><label class="mb-2 block text-sm font-semibold" for="support-subject">Subject</label><input class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-subject" name="subject" maxlength="160" value="{{ old('subject') }}" required></div>
                    <div><label class="mb-2 block text-sm font-semibold" for="support-description">What happened?</label><textarea class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-200" id="support-description" name="description" rows="6" minlength="10" maxlength="5000" required></textarea><p class="mt-2 text-xs text-slate-500">At least 10 characters. Describe the issue and include a reference when available.</p></div>
                    <div class="hidden" aria-hidden="true"><label for="support-company">Company website</label><input id="support-company" name="company_website" tabindex="-1" autocomplete="off"></div>
                    <p class="text-xs leading-relaxed text-slate-500">We use these details to handle your request under our <a class="font-semibold underline" href="{{ route('public.privacy-policy') }}">privacy policy</a>. This form does not request an account deletion or authorize a payment or refund.</p>
                    <p class="text-xs text-slate-500">You can add images on the email verification step.</p>
                    <button class="button" type="submit">Send verification code <span aria-hidden="true">→</span></button>
                </form>
            @endif
        </div>
    </div>
</section>
@endsection
