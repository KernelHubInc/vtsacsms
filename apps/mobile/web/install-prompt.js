(() => {
  const offer = document.getElementById('install-offer');
  const detail = document.getElementById('install-offer-detail');
  const action = document.getElementById('install-offer-action');
  const close = document.getElementById('install-offer-close');
  const dismissKey = 'power-solutions-install-dismissed-until';
  const dismissDuration = 7 * 24 * 60 * 60 * 1000;
  let pendingInstall = null;
  let installationHandled = false;

  if (!offer || !detail || !action || !close) return;

  const isInstalled = () =>
    window.matchMedia?.('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

  const isDismissed = () => {
    try {
      return Number(window.localStorage.getItem(dismissKey)) > Date.now();
    } catch {
      return false;
    }
  };

  const dismiss = () => {
    offer.hidden = true;
    pendingInstall = null;
    try {
      window.localStorage.setItem(dismissKey, String(Date.now() + dismissDuration));
    } catch {
      // The offer is still dismissed for this page when storage is unavailable.
    }
  };

  const show = (message, canPrompt) => {
    if (installationHandled || isInstalled() || isDismissed()) return;
    detail.textContent = message;
    action.hidden = !canPrompt;
    offer.hidden = false;
  };

  window.addEventListener('beforeinstallprompt', (event) => {
    if (isInstalled() || isDismissed()) return;
    event.preventDefault();
    pendingInstall = event;
    show('Install the web app for quick access to stations and charging activity.', true);
  });

  action.addEventListener('click', async () => {
    const prompt = pendingInstall;
    if (!prompt) return;
    pendingInstall = null;
    installationHandled = true;
    offer.hidden = true;
    try {
      const choice = await prompt.prompt();
      if (choice?.outcome === 'dismissed') dismiss();
    } catch {
      // The browser owns install eligibility; keep its normal menu available.
    }
  });

  close.addEventListener('click', dismiss);
  window.addEventListener('appinstalled', () => {
    pendingInstall = null;
    installationHandled = true;
    offer.hidden = true;
  });

  const userAgent = window.navigator.userAgent || '';
  const isiOS = /iPad|iPhone|iPod/.test(userAgent) ||
    (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
  if (isiOS) {
    show('Tap Share in your browser, then choose Add to Home Screen.', false);
  } else if (/Android/.test(userAgent)) {
    window.setTimeout(() => {
      if (!pendingInstall && !installationHandled && offer.hidden) {
        show('Open your browser menu and choose Install app or Add to Home screen.', false);
      }
    }, 4000);
  }
})();
