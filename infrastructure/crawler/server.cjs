const http = require('node:http');
const crypto = require('node:crypto');
const puppeteer = require('puppeteer');
const token = process.env.CRAWLER_RENDERER_TOKEN;
if (!token || token.length < 32) throw new Error('Set a renderer token of at least 32 characters');
let busy = false;

http.createServer(async (req, res) => {
    const actual = Buffer.from(req.headers.authorization || '');
    const expected = Buffer.from(`Bearer ${token}`);
    if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) {
        res.writeHead(401).end(); return;
    }
    if (req.method !== 'POST' || req.url !== '/render') { res.writeHead(404).end(); return; }
    if (busy) { res.writeHead(429).end(); return; }
    busy = true;
    let browser;
    let deadline;
    try {
        let body = '';
        for await (const chunk of req) {
            body += chunk;
            if (body.length > 8192) throw new Error('Request too large');
        }
        const {url, action} = JSON.parse(body);
        const parsed = new URL(url);
        if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password
            || !['html', 'screenshot'].includes(action)) throw new Error('Invalid render request');
        browser = await puppeteer.launch({
            headless: true,
            args: ['--proxy-server=http://proxy:8080', '--proxy-bypass-list=<-loopback>',
                '--disable-quic', '--disable-extensions', '--disable-background-networking'],
        });
        deadline = setTimeout(() => browser.close(), 120000);
        const page = await browser.newPage();
        await page.setViewport({width: 1440, height: 900});
        await page.setRequestInterception(true);
        page.on('request', request => {
            const protocol = new URL(request.url()).protocol;
            if (['http:', 'https:', 'data:', 'blob:'].includes(protocol)) request.continue();
            else request.abort();
        });
        await page.goto(url, {waitUntil: 'domcontentloaded', timeout: 90000});
        await page.waitForNetworkIdle({idleTime: 500, timeout: 10000}).catch(() => {});
        const result = action === 'html' ? await page.content()
            : await page.screenshot({encoding: 'base64', type: 'png'});
        if (result.length > 15_000_000) throw new Error('Render too large');
        res.writeHead(200, {'Content-Type': 'application/json'}).end(JSON.stringify({result}));
    } catch {
        res.writeHead(422).end(JSON.stringify({error: 'Website could not be rendered safely'}));
    } finally {
        clearTimeout(deadline);
        if (browser) await browser.close().catch(() => {});
        busy = false;
    }
}).listen(3000, '0.0.0.0');
