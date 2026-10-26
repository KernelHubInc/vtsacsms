import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('./install-prompt.js', import.meta.url), 'utf8');

function loadOffer({ userAgent = '', installed = false, storedUntil = null } = {}) {
  const elements = Object.fromEntries(
    ['install-offer', 'install-offer-detail', 'install-offer-action', 'install-offer-close']
      .map((id) => [id, { hidden: id === 'install-offer', textContent: '', handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; } }]),
  );
  const handlers = {};
  const timers = [];
  const storage = new Map(storedUntil ? [['power-solutions-install-dismissed-until', storedUntil]] : []);
  const window = {
    navigator: { userAgent, standalone: false, platform: '', maxTouchPoints: 0 },
    matchMedia: () => ({ matches: installed }),
    localStorage: { getItem: (key) => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
    addEventListener: (name, handler) => { handlers[name] = handler; },
    setTimeout: (handler) => timers.push(handler),
  };

  runInNewContext(script, { document: { getElementById: (id) => elements[id] }, window, Date });
  return { elements, handlers, timers, storage };
}

test('offers install only after browser eligibility and prompts on a tap', async () => {
  const { elements, handlers } = loadOffer({ userAgent: 'Android Chrome' });
  let prevented = false;
  let prompted = false;
  handlers.beforeinstallprompt({ preventDefault: () => { prevented = true; }, prompt: async () => { prompted = true; return { outcome: 'accepted' }; } });
  assert.equal(prevented, true);
  assert.equal(elements['install-offer'].hidden, false);
  assert.equal(elements['install-offer-action'].hidden, false);
  assert.equal(prompted, false);
  await elements['install-offer-action'].handlers.click();
  assert.equal(prompted, true);
  assert.equal(elements['install-offer'].hidden, true);
});

test('respects dismissal and does not suppress the browser offer', () => {
  const { elements, handlers, storage } = loadOffer();
  handlers.beforeinstallprompt({ preventDefault: () => {} });
  elements['install-offer-close'].handlers.click();
  assert.ok(Number(storage.get('power-solutions-install-dismissed-until')) > Date.now());
  let prevented = false;
  handlers.beforeinstallprompt({ preventDefault: () => { prevented = true; } });
  assert.equal(prevented, false);
  assert.equal(elements['install-offer'].hidden, true);
});

test('explains the iPhone installation path without a fake install button', () => {
  const { elements } = loadOffer({ userAgent: 'iPhone Safari' });
  assert.equal(elements['install-offer'].hidden, false);
  assert.equal(elements['install-offer-action'].hidden, true);
  assert.match(elements['install-offer-detail'].textContent, /Share.*Add to Home Screen/);
});

test('does not offer installation in standalone mode', () => {
  const { elements, handlers } = loadOffer({ userAgent: 'iPhone Safari', installed: true });
  handlers.beforeinstallprompt({ preventDefault: () => { throw new Error('should not intercept'); } });
  assert.equal(elements['install-offer'].hidden, true);
});

test('shows manual Android instructions only if no install event arrived', () => {
  const { elements, timers } = loadOffer({ userAgent: 'Android Firefox' });
  assert.equal(elements['install-offer'].hidden, true);
  timers[0]();
  assert.equal(elements['install-offer'].hidden, false);
  assert.equal(elements['install-offer-action'].hidden, true);
});
