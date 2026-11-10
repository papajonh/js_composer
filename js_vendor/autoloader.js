/**
 * autoloader.js — lives inside js_vendor/
 *
 * Resolves loader.php relative to THIS file, so it works no matter
 * where the calling HTML page is (root, /public, /app, etc).
 *
 * Usage (from any HTML page):
 *   import autoloader from '/js_v/js_vendor/autoloader.js';
 *   autoloader(['add()', 'multiply()']);
 *   autoloader(['jquery.min', 'withJquery()']);
 */

// loader.php is in the SAME folder as this file
const LOADER_URL = new URL('./loader.php', import.meta.url).href;

const loaded = new Set();   // names already injected by this instance
const pending = new Map();   // in-flight requests (dedup)
const VERSION = '1.0.0';

/**
 * Extracts the "lookup name" from a requested name.
 *   "add()"        → "add"
 *   "add"          → "add"
 *   "jquery.min"   → "jquery.min"
 *   "lib:jquery"   → "jquery"
 */
function getWindowKey(name) {
    return name
        .replace(/^lib:/, '')          // remove "lib:" prefix if present
        .replace(/\s*\(\s*\)\s*$/, '') // remove trailing "()"
        .trim();
}

/**
 * Checks if a requested name already exists in window.
 * Functions → typeof === 'function'
 * Libs      → typeof === 'object' (or 'function' if the lib exports a function)
 */
function existsInWindow(name) {
    const key = getWindowKey(name);
    const value = window[key];
    if (value === undefined) return false;
    if (typeof value === 'function') return true;
    if (typeof value === 'object' && value !== null) return true;
    return false;
}

export default function autoloader(names, options = {}) {
    // --- 1. Validate input ---
    if (!Array.isArray(names) || names.length === 0) {
        throw new Error('autoloader: expected a non-empty array of strings');
    }

    for (const name of names) {
        if (typeof name !== 'string' || name.trim() === '') {
            throw new Error(`autoloader: invalid name: ${JSON.stringify(name)}`);
        }
    }

    // --- 2. Filter out what's already loaded (checked against window, not just the Set) ---
    const missing = [...new Set(names.filter((n) => {
        if (loaded.has(n)) return false;   // already loaded by this instance
        if (existsInWindow(n)) return false; // already on window (e.g. loaded by another instance)
        return true;                        // really missing
    }))];

    if (missing.length === 0) return;

    // --- 3. Build a deterministic cache key ---
    const key = [...missing].sort().join('\u0000');

    // --- 4. Deduplicate in-flight requests ---
    let request = pending.get(key);
    if (!request) {
        request = fetchAndInject(missing, options);
        pending.set(key, request);
    }

    // --- 5. Synchronously wait for the response ---
    request();
    pending.delete(key);
}

// Expose reset for debugging / testing
autoloader.reset = () => {
    loaded.clear();
    pending.clear();
};

// ---------------------------------------------------------------------

function fetchAndInject(names, options) {
    return function run() {
        const params = new URLSearchParams();
        params.append('fn', names.join(','));
        if (options.group) params.append('group', options.group);
        params.append('v', options.version ?? VERSION);

        const url = `${LOADER_URL}?${params.toString()}`;

        // Synchronous XHR (fetch is async-only, and we want autoloader() to be sync)
        const xhr = new XMLHttpRequest();
        xhr.open('GET', url, false);
        xhr.send(null);

        if (xhr.status !== 200) {
            throw new Error(`autoloader: loader.php returned ${xhr.status}: ${xhr.responseText}`);
        }

        const jsCode = xhr.responseText;
        if (!jsCode || !jsCode.trim()) {
            throw new Error('autoloader: empty response from loader.php');
        }

        // Execute in global scope
        try {
            const runner = new Function(jsCode);
            runner.call(window);
        } catch (err) {
            throw new Error(`autoloader: failed to execute loader response: ${err.message}`);
        }

        // Mark as loaded
        for (const name of names) loaded.add(name);
    };
}