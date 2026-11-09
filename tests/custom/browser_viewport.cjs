const fs = require('node:fs'),
    path = require('node:path'),
    { spawn } = require('node:child_process'),
    { pathToFileURL } = require('node:url');
const [fixture, requested, prefix] = process.argv.slice(2),
    width = Number(requested);
const profile = fs.mkdtempSync(path.join(path.dirname(fixture), 'viewport-'));
const chrome = spawn(
    '/usr/bin/google-chrome',
    [
        '--headless',
        '--disable-gpu',
        '--disable-background-networking',
        '--disable-sync',
        '--no-first-run',
        '--no-default-browser-check',
        '--remote-debugging-pipe',
        '--user-data-dir=' + profile,
        'about:blank',
    ],
    { stdio: ['ignore', 'ignore', 'pipe', 'pipe', 'pipe'] },
);
let serial = 0,
    buffer = '',
    errors = '';
const pending = new Map(),
    listeners = [];
chrome.stderr.on('data', (v) => (errors += v));
chrome.stdio[4].on('data', (chunk) => {
    buffer += chunk;
    let pos;
    while ((pos = buffer.indexOf('\0')) >= 0) {
        const raw = buffer.slice(0, pos);
        buffer = buffer.slice(pos + 1);
        if (!raw) continue;
        const m = JSON.parse(raw);
        if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id);
            pending.delete(m.id);
            m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
        } else for (const f of listeners) f(m);
    }
});
function send(method, params = {}, sessionId) {
    return new Promise((resolve, reject) => {
        const id = ++serial;
        pending.set(id, { resolve, reject });
        chrome.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
    });
}
function event(method, sessionId) {
    return new Promise((resolve) => {
        listeners.push((m) => {
            if (m.method === method && m.sessionId === sessionId) resolve(m.params);
        });
    });
}
const timeout = setTimeout(() => {
    process.stderr.write('Viewport timeout ' + errors.slice(-300));
    chrome.kill();
    process.exitCode = 1;
}, 25000);
(async () => {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    await send('Page.enable', {}, sessionId);
    await send('Runtime.enable', {}, sessionId);
    await send(
        'Emulation.setDeviceMetricsOverride',
        { width, height: 1000, deviceScaleFactor: 1, mobile: false },
        sessionId,
    );
    const loaded = event('Page.loadEventFired', sessionId);
    await send('Page.navigate', { url: pathToFileURL(fixture).href }, sessionId);
    await loaded;
    const check = await send(
        'Runtime.evaluate',
        {
            expression:
                'new Promise((resolve,reject)=>{let n=0;const t=setInterval(()=>{if(document.body?.hasAttribute("data-check")){clearInterval(t);resolve(JSON.parse(document.body.getAttribute("data-check")))}else if(++n>100){clearInterval(t);reject(new Error("Page checks did not complete"))}},30)})',
            awaitPromise: true,
            returnByValue: true,
        },
        sessionId,
    );
    if (check.exceptionDetails) throw new Error(JSON.stringify(check.exceptionDetails));
    if (check.result.value.width !== width) throw new Error('Wrong viewport width');
    const dom = await send(
        'Runtime.evaluate',
        { expression: 'document.documentElement.outerHTML', returnByValue: true },
        sessionId,
    );
    fs.writeFileSync(prefix + '.html', dom.result.value, { mode: 0o600 });
    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
    fs.writeFileSync(prefix + '.png', Buffer.from(shot.data, 'base64'), { mode: 0o600 });
    await send('Target.closeTarget', { targetId });
})()
    .catch((e) => {
        process.stderr.write(e.stack + '\n');
        process.exitCode = 1;
    })
    .finally(() => {
        clearTimeout(timeout);
        chrome.kill();
        setTimeout(() => fs.rmSync(profile, { recursive: true, force: true }), 300);
    });
