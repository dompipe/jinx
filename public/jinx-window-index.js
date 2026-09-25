/*
 * JINX browser window index runtime.
 *
 * Original project runtime for registering server-fed JINX window indexes in
 * the browser, applying live update frames, and writing patches into DOM zones.
 */
(function (window, document) {
  'use strict';

  const ROOT_NAME = '__JINX_WINDOW_INDEX__';
  const EVENT_REGISTERED = 'jinx-window-index-registered';
  const EVENT_FRAME = 'jinx-window-frame';
  const EVENT_PATCHED = 'jinx-window-patched';

  function now() {
    return Date.now ? Date.now() : new Date().getTime();
  }

  function asObject(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  }

  function asArray(value) {
    return Array.isArray(value) ? value : [];
  }

  function cleanKey(value, fallback) {
    const text = String(value == null ? '' : value).trim();
    return text === '' ? fallback : text;
  }

  function ensureRoot() {
    const root = window[ROOT_NAME] || {};
    root.version = typeof root.version === 'number' ? root.version : 0;
    root.index_version = typeof root.index_version === 'number' ? root.index_version : 0;
    root.fingerprint = typeof root.fingerprint === 'string' ? root.fingerprint : '';
    root.defaults = asObject(root.defaults);
    root.frames = asObject(root.frames);
    root.windows = asObject(root.windows);
    root.history = asArray(root.history);
    root.limits = asObject(root.limits);
    if (typeof root.limits.maxHistory !== 'number') {
      root.limits.maxHistory = 256;
    }
    window[ROOT_NAME] = root;
    return root;
  }

  function remember(root, entry) {
    root.history.push(Object.assign({ at: now() }, entry));
    const max = Math.max(0, root.limits.maxHistory | 0);
    if (max > 0 && root.history.length > max) {
      root.history.splice(0, root.history.length - max);
    }
  }

  function event(name, detail) {
    if (typeof window.CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent(name, { detail: detail }));
      return;
    }
    const ev = document.createEvent('CustomEvent');
    ev.initCustomEvent(name, false, false, detail);
    window.dispatchEvent(ev);
  }

  function registerIndex(index) {
    const root = ensureRoot();
    const incoming = asObject(index);

    if (typeof incoming.index_version === 'number' && incoming.index_version >= root.index_version) {
      root.index_version = incoming.index_version;
    }
    if (typeof incoming.fingerprint === 'string' && incoming.fingerprint !== '') {
      root.fingerprint = incoming.fingerprint;
    }
    if (incoming.defaults && typeof incoming.defaults === 'object') {
      root.defaults = Object.assign(root.defaults, incoming.defaults);
    }
    if (incoming.frames && typeof incoming.frames === 'object') {
      Object.keys(incoming.frames).forEach(function (windowId) {
        root.frames[windowId] = incoming.frames[windowId];
      });
    }

    root.version++;
    remember(root, { op: 'registerIndex', index_version: root.index_version, fingerprint: root.fingerprint });
    event(EVENT_REGISTERED, { root: snapshot() });
    return root;
  }

  function safeSelector(selector) {
    if (typeof selector !== 'string' || selector.trim() === '') {
      return null;
    }
    return selector;
  }

  function targetsForPatch(patch, frame) {
    const selector = safeSelector(patch.selector);
    if (selector !== null) {
      return Array.prototype.slice.call(document.querySelectorAll(selector));
    }

    const windowId = cleanKey(patch.window_id || frame.window_id, 'window-default').replace(/[^A-Za-z0-9_-]/g, '-');
    const zone = cleanKey(patch.zone || patch.target || 'main', 'main').replace(/"/g, '\\"');
    return Array.prototype.slice.call(document.querySelectorAll('[data-jinx-window="' + windowId + '"][data-jinx-zone="' + zone + '"]'));
  }

  function applyPatch(patch, frame) {
    patch = asObject(patch);
    const op = cleanKey(patch.op, 'replaceText');
    const targets = targetsForPatch(patch, frame);
    const value = patch.value == null ? '' : String(patch.value);

    targets.forEach(function (el) {
      if (op === 'replaceText') {
        el.textContent = value;
      } else if (op === 'replaceHTML') {
        el.innerHTML = value;
      } else if (op === 'appendHTML') {
        el.insertAdjacentHTML('beforeend', value);
      } else if (op === 'setAttribute') {
        const name = cleanKey(patch.name, 'data-jinx-value');
        el.setAttribute(name, value);
      } else if (op === 'removeAttribute') {
        const removeName = cleanKey(patch.name, 'data-jinx-value');
        el.removeAttribute(removeName);
      } else if (op === 'toggleClass') {
        const className = cleanKey(patch.name || patch.className, 'jinx-active');
        el.classList.toggle(className, Boolean(patch.enabled));
      }
    });

    return targets.length;
  }

  function applyFrame(frame) {
    const root = ensureRoot();
    frame = asObject(frame);
    const windowId = cleanKey(frame.window_id, 'window-default');
    const pageKey = cleanKey(frame.page_key, 'page.default');

    if (typeof frame.index_version === 'number' && frame.index_version >= root.index_version) {
      root.index_version = frame.index_version;
    }
    if (typeof frame.resident_index_fingerprint === 'string' && frame.resident_index_fingerprint !== '') {
      root.fingerprint = frame.resident_index_fingerprint;
    }

    root.frames[windowId] = frame;
    root.windows[windowId] = {
      page_key: pageKey,
      arrangement: asObject(frame.arrangement),
      updated_at: now(),
      index_version: root.index_version,
      fingerprint: root.fingerprint
    };
    root.defaults[pageKey] = asObject(frame.arrangement);

    let count = 0;
    asArray(frame.patches).forEach(function (patch) {
      count += applyPatch(patch, frame);
    });

    root.version++;
    remember(root, { op: 'applyFrame', window_id: windowId, page_key: pageKey, patched: count, index_version: root.index_version });
    event(EVENT_FRAME, { frame: frame, patched: count, root: snapshot() });
    event(EVENT_PATCHED, { window_id: windowId, patched: count });
    return { frame: frame, patched: count, root: root };
  }

  function liveUpdate(frameOrJson) {
    if (typeof frameOrJson === 'string') {
      return applyFrame(JSON.parse(frameOrJson));
    }
    return applyFrame(frameOrJson);
  }

  function mount(windowId, pageKey, arrangement) {
    const frame = {
      kind: 'JINX_WINDOW_INDEX_FRAME',
      version: 1,
      window_id: cleanKey(windowId, 'window-default'),
      page_key: cleanKey(pageKey, 'page.default'),
      arrangement: asObject(arrangement),
      patches: []
    };

    const components = asObject(frame.arrangement.components);
    frame.patches.push({ op: 'replaceText', zone: 'title', value: frame.arrangement.title || '' });
    Object.keys(components).forEach(function (zone) {
      const component = asObject(components[zone]);
      if (Object.prototype.hasOwnProperty.call(component, 'text')) {
        frame.patches.push({ op: 'replaceText', zone: zone, value: component.text });
      }
    });

    return applyFrame(frame);
  }

  function snapshot() {
    const root = ensureRoot();
    return {
      version: root.version,
      index_version: root.index_version,
      fingerprint: root.fingerprint,
      defaults: root.defaults,
      frames: root.frames,
      windows: root.windows,
      history: root.history.slice()
    };
  }

  ensureRoot();

  window.JINXWindowIndex = {
    root: ensureRoot,
    registerIndex: registerIndex,
    applyFrame: applyFrame,
    liveUpdate: liveUpdate,
    mount: mount,
    snapshot: snapshot
  };
})(window, document);
