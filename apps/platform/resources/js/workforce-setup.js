const form = document.getElementById('workforce-setup');
const message = document.getElementById('setup-message');
const submit = document.getElementById('setup-submit');
const completed = document.getElementById('setup-completed');
const manualFields = document.getElementById('manual-setup-fields');
let emailToken = new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '';

// Fragments never reach HTTP access logs; remove the credential from visible history immediately.
window.history.replaceState(null, '', window.location.pathname);

if (/^[a-f0-9]{64}$/.test(emailToken)) {
    manualFields.hidden = true;
    manualFields.disabled = true;
    message.textContent = 'Choose a password you will use only for this administrator account.';
} else {
    emailToken = '';
}
submit.disabled = false;

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submit.disabled) return;
    submit.disabled = true;
    message.textContent = 'Saving your private password…';
    const data = new FormData(form);
    if (emailToken) {
        data.set('enrollment_token', emailToken);
    }

    try {
        const response = await fetch(form.action, {
            method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data,
        });
        const payload = await response.json();
        if (!response.ok) {
            const fields = payload.error?.fields ?? payload.errors ?? {};
            message.textContent = Object.values(fields).flat().join(' ') || payload.error?.message || 'Setup could not be completed. Please try again.';
            submit.disabled = false;
            return;
        }
        emailToken = '';
        form.reset();
        form.hidden = true;
        completed.hidden = false;
        message.textContent = 'Your administrator account is ready to sign in.';
    } catch {
        message.textContent = 'The connection was interrupted. Retry, or try signing in if your password was already saved.';
        submit.disabled = false;
    }
});
