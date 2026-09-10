const getDialog = (id) => {
    const dialog = document.getElementById(id);

    return dialog instanceof HTMLDialogElement ? dialog : null;
};

const root = document.documentElement;
const storedTheme = window.localStorage.getItem('vtsa-theme');
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
const initialTheme = storedTheme ?? (prefersDark ? 'dark' : 'light');

root.classList.toggle('dark', initialTheme === 'dark');
root.dataset.theme = initialTheme;
document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
    toggle.setAttribute('aria-pressed', String(initialTheme === 'dark'));
});

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const opener = target?.closest('[data-dialog-open]');
    const confirmer = target?.closest('[data-dialog-confirm]');
    const toastDismiss = target?.closest('[data-toast-dismiss]');
    const themeToggle = target?.closest('[data-theme-toggle]');

    if (opener instanceof HTMLElement) {
        getDialog(opener.dataset.dialogOpen)?.showModal();
    }

    if (confirmer instanceof HTMLElement) {
        getDialog(confirmer.dataset.dialogConfirm)?.close('confirmed');
    }

    if (toastDismiss instanceof HTMLElement) {
        toastDismiss.closest('[role="status"], [role="alert"]')?.remove();
    }

    if (themeToggle instanceof HTMLElement) {
        const dark = !root.classList.contains('dark');
        root.classList.toggle('dark', dark);
        root.dataset.theme = dark ? 'dark' : 'light';
        window.localStorage.setItem('vtsa-theme', root.dataset.theme);
        themeToggle.setAttribute('aria-pressed', String(dark));
    }
});
