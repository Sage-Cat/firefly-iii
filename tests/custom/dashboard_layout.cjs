const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { spawn } = require("node:child_process");
const { pathToFileURL } = require("node:url");
const WebSocket = globalThis.WebSocket || require("ws");
const args = process.argv.slice(2);
const option = (name, fallback) => (args.includes(name) ? args[args.indexOf(name) + 1] : fallback);
const view = fs.readFileSync(option("--view", "resources/views/index.blade.php"), "utf8");
const note = fs.readFileSync(option("--note", "resources/views/reports/dashboard-capital-note.blade.php"), "utf8");
const library = fs.readFileSync(option("--chart", "node_modules/chart.js/dist/chart.umd.min.js"), "utf8");
const output = path.resolve(option("--out", "storage/framework/testing/chart-layout"));
fs.mkdirSync(output, { recursive: true, mode: 0o700 });
const profile = fs.mkdtempSync(path.join(output, "browser-"));
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const block = view.slice(view.indexOf("<!--ACCOUNTS -->"), view.indexOf("<!--BUDGETS -->"));
assert.ok(block.includes("accounts-chart"), "Dashboard chart view missing");
const styles = (note.match(/<style[\s\S]*?<\/style>/g) || []).join("").replace(/ nonce="[^"]*"/g, "");
const notes =
    styles +
    "<p>Daily net worth in EUR for the last 30 days.</p><p>Earlier incomplete history remains unknown.</p><p>Manual token valuation is retained.</p>";
const content = block.replace("@include('reports.dashboard-capital-note')", notes).replace(/\{\{[\s\S]*?\}\}/g, "");
const html =
    '<!doctype html><meta charset="utf-8"><style>body{margin:20px;font:16px sans-serif}canvas{box-sizing:border-box}.card{border:1px solid #999;padding:16px}.card-body{padding:16px}</style>' +
    content +
    "<script>" +
    library.replace(/<\/script/gi, "<\\/script") +
    "</script><script>" +
    `
window.errors=[];window.addEventListener('error',e=>window.errors.push(e.message));
window.chart=new Chart(document.getElementById('accounts-chart'),{type:'line',data:{labels:Array.from({length:30},(_,i)=>String(i+1)),datasets:[{label:'EUR',data:Array.from({length:30},(_,i)=>i<28?null:1000+i),borderColor:'#267abd'}]},options:{responsive:true,maintainAspectRatio:false,animation:false}});
window.growth=false;window.maximumHeight=0;window.layoutReady=true;
window.guard=setInterval(()=>{const height=document.getElementById('accounts-chart').getBoundingClientRect().height;window.maximumHeight=Math.max(window.maximumHeight,height);if(height>2000){window.growth=true;clearInterval(window.guard);window.chart.destroy();}},30);
` +
    "</script>";
const fixture = path.join(output, "fixture.html");
fs.writeFileSync(fixture, html, { mode: 0o600 });
let chrome, socket;
(async () => {
    chrome = spawn(
        option("--chrome", "/usr/bin/google-chrome"),
        [
            "--headless",
            "--disable-gpu",
            "--disable-background-networking",
            "--no-first-run",
            "--no-default-browser-check",
            "--remote-debugging-address=127.0.0.1",
            "--remote-debugging-port=0",
            "--user-data-dir=" + profile,
            pathToFileURL(fixture).href,
        ],
        { stdio: ["ignore", "ignore", "pipe"] },
    );
    let stderr = "";
    chrome.stderr.on("data", (x) => (stderr += x));
    const portFile = path.join(profile, "DevToolsActivePort");
    for (let i = 0; i < 100 && !fs.existsSync(portFile); i++) await delay(50);
    assert.ok(fs.existsSync(portFile), "Test browser did not start: " + stderr.slice(-300));
    const port = fs.readFileSync(portFile, "utf8").split("\n")[0];
    let target;
    for (let i = 0; i < 100 && !target; i++) {
        target = (await (await fetch("http://127.0.0.1:" + port + "/json/list")).json()).find(
            (t) => t.type === "page" && t.url === pathToFileURL(fixture).href,
        );
        if (!target) await delay(50);
    }
    assert.ok(target, "Test page not found");
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener("open", resolve, { once: true });
        socket.addEventListener("error", reject, { once: true });
    });
    let serial = 0;
    const pending = new Map();
    socket.addEventListener("message", (event) => {
        const m = JSON.parse(event.data);
        if (pending.has(m.id)) {
            const p = pending.get(m.id);
            pending.delete(m.id);
            m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result);
        }
    });
    const send = (method, params = {}) =>
        new Promise((resolve, reject) => {
            const id = ++serial;
            pending.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params }));
        });
    const evaluate = async (expression) => {
        const r = await send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
        assert.ok(!r.exceptionDetails, JSON.stringify(r.exceptionDetails));
        return r.result.value;
    };
    await send("Runtime.enable");
    await evaluate(
        'new Promise((resolve,reject)=>{let n=0;const t=setInterval(()=>{if(window.layoutReady){clearInterval(t);resolve(true)}else if(++n>100){clearInterval(t);reject(new Error("Chart did not load"))}},30)})',
    );
    if (args.includes("--expect-growth")) {
        await delay(1800);
        assert.equal(await evaluate("window.growth"), true, "Original resize loop was not reproduced");
        console.log("Reproduced original unbounded chart growth.");
    } else {
        const results = [];
        for (const [width, height, dpr] of [
            [1440, 900, 1],
            [768, 1024, 1],
            [390, 844, 1],
            [1440, 900, 2],
        ]) {
            await send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: dpr, mobile: false });
            await delay(400);
            const samples = await evaluate(
                `(async()=>{const out=[];for(let i=0;i<12;i++){window.chart.update('none');const c=document.getElementById('accounts-chart'),p=c.parentElement;out.push({height:c.getBoundingClientRect().height,parent:p.getBoundingClientRect().height,width:c.getBoundingClientRect().width,page:document.documentElement.scrollHeight});await new Promise(r=>setTimeout(r,100));}return out})()`,
            );
            const expected = width <= 767 ? 280 : 360;
            assert.ok(
                samples.every((x) => Math.abs(x.height - expected) < 1 && Math.abs(x.parent - expected) < 1),
                "Unstable chart dimensions: " + JSON.stringify(samples),
            );
            assert.ok(
                samples.every((x) => x.width > 0 && x.width <= width && x.page < 2000),
                "Page grew or overflowed",
            );
            assert.ok(
                Math.max(...samples.map((x) => x.page)) - Math.min(...samples.map((x) => x.page)) < 2,
                "Page height kept growing",
            );
            assert.equal(await evaluate("window.growth"), false);
            assert.deepEqual(await evaluate("window.errors"), []);
            results.push({ width, height, dpr, chartHeight: expected, samples: samples.length });
            if ((width === 1440 && dpr === 1) || width === 390) {
                const shot = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: false });
                fs.writeFileSync(
                    path.join(output, width === 390 ? "mobile.png" : "desktop.png"),
                    Buffer.from(shot.data, "base64"),
                );
            }
        }
        fs.writeFileSync(path.join(output, "verified.json"), JSON.stringify(results, null, 2), { mode: 0o600 });
        console.log("Passed 48 live Chart.js layout samples across desktop, tablet, mobile and high-DPI resize.");
    }
})()
    .catch((error) => {
        console.error(error.message);
        process.exitCode = 1;
    })
    .finally(async () => {
        if (socket) socket.close();
        if (chrome) {
            chrome.kill("SIGTERM");
            await delay(200);
        }
        fs.rmSync(profile, { recursive: true, force: true });
    });
