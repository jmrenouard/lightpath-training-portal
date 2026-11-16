#!/usr/bin/env node
/**
 * LightPath Training Portal — Automated E2E Test Campaign via @playwright/mcp (JSON-RPC stdio)
 * Usage: node tests/e2e/run_playwright_campaign.mjs
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PORTAL_ROOT = resolve(__dirname, '..', '..');
const EXPLICIT_COURSE_ROOT = process.env.LMS_COURSE_ROOT ? resolve(process.env.LMS_COURSE_ROOT) : '';
const ADJACENT_TRAINING_ROOT = resolve(PORTAL_ROOT, '..', 'training_postgresql');
const TRAINING_ROOT = (EXPLICIT_COURSE_ROOT && existsSync(EXPLICIT_COURSE_ROOT))
    ? EXPLICIT_COURSE_ROOT
    : ADJACENT_TRAINING_ROOT;
const HAS_EXTERNAL_TRAINING_ROOT = existsSync(TRAINING_ROOT);
const PUBLIC_DIR = join(PORTAL_ROOT, 'public');
const MCP_CONFIG_PATH = join(PORTAL_ROOT, 'config', 'playwright-mcp.config.json');
const REPORT_DIR = join(PORTAL_ROOT, 'storage', 'tmp');

function collectHtmlDeliverables(dir, baseDir = dir, list = []) {
    if (!existsSync(dir)) return list;
    for (const entry of readdirSync(dir)) {
        if (
            entry === '.git' ||
            entry === 'node_modules' ||
            entry === '.pytest_cache' ||
            entry === '02_supports_historiques_et_sources'
        ) {
            continue;
        }
        const full = join(dir, entry);
        const st = statSync(full);
        if (st.isDirectory()) {
            collectHtmlDeliverables(full, baseDir, list);
        } else if (entry.endsWith('.html') && existsSync(full.replace(/\.html$/, '.md'))) {
            list.push(relative(baseDir, full).replace(/\\/g, '/'));
        }
    }
    return list.sort();
}

function findFreePort() {
    return new Promise((resolvePort, reject) => {
        const srv = net.createServer();
        srv.listen(0, '127.0.0.1', () => {
            const addr = srv.address();
            const port = typeof addr === 'object' && addr ? addr.port : 18090;
            srv.close(() => resolvePort(port));
        });
        srv.on('error', reject);
    });
}

async function waitForHttpReady(url, timeoutMs = 8000) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        try {
            const res = await fetch(url);
            if (res.status >= 200 && res.status < 500) return true;
        } catch (_) {}
        await new Promise((r) => setTimeout(r, 150));
    }
    throw new Error(`Serveur PHP non joignable sur ${url} après ${timeoutMs} ms`);
}

/**
 * Lightweight JSON-RPC 2.0 client driving @playwright/mcp over stdio
 */
class PlaywrightMcpStdioClient {
    constructor(configPath) {
        this.configPath = configPath;
        this.proc = null;
        this.nextId = 1;
        this.pending = new Map();
        this.buffer = '';
    }

    async start() {
        const isWin = process.platform === 'win32';
        const globalCli = isWin
            ? join(process.env.APPDATA || '', 'npm', 'node_modules', '@playwright', 'mcp', 'cli.js')
            : '';

        const cmd = existsSync(globalCli) ? process.execPath : (isWin ? 'npx.cmd' : 'npx');
        const args = existsSync(globalCli)
            ? [globalCli, '--config', this.configPath, '--browser=chrome', '--headless', '--isolated', '--block-service-workers']
            : ['-y', '@playwright/mcp@latest', '--config', this.configPath, '--browser=chrome', '--headless', '--isolated', '--block-service-workers'];

        this.proc = spawn(cmd, args, {
            cwd: PORTAL_ROOT,
            stdio: ['pipe', 'pipe', 'pipe'],
            windowsHide: true,
        });

        this.proc.stdout.setEncoding('utf8');
        this.proc.stdout.on('data', (chunk) => {
            this.buffer += chunk;
            let idx;
            while ((idx = this.buffer.indexOf('\n')) !== -1) {
                const line = this.buffer.slice(0, idx).trim();
                this.buffer = this.buffer.slice(idx + 1);
                if (!line) continue;
                try {
                    const msg = JSON.parse(line);
                    if (msg.id !== undefined && this.pending.has(msg.id)) {
                        const { resolveReq, rejectReq } = this.pending.get(msg.id);
                        this.pending.delete(msg.id);
                        if (msg.error) rejectReq(new Error(JSON.stringify(msg.error)));
                        else resolveReq(msg.result);
                    }
                } catch (_) {}
            }
        });

        const initRes = await this.request('initialize', {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'lightpath-lms-e2e-runner', version: '1.1.0' },
        });
        this.notify('notifications/initialized', {});
        return initRes;
    }

    request(method, params = {}) {
        const id = this.nextId++;
        const payload = JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n';
        return new Promise((resolveReq, rejectReq) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                rejectReq(new Error(`Timeout MCP sur ${method}`));
            }, 120000);
            this.pending.set(id, {
                resolveReq: (val) => {
                    clearTimeout(timer);
                    resolveReq(val);
                },
                rejectReq: (err) => {
                    clearTimeout(timer);
                    rejectReq(err);
                },
            });
            this.proc.stdin.write(payload);
        });
    }

    notify(method, params = {}) {
        const payload = JSON.stringify({ jsonrpc: '2.0', method, params }) + '\n';
        this.proc.stdin.write(payload);
    }

    async callTool(name, args = {}) {
        const res = await this.request('tools/call', { name, arguments: args });
        const text = (res?.content || [])
            .filter((c) => c.type === 'text')
            .map((c) => c.text)
            .join('\n');
        return { raw: res, text, isError: Boolean(res?.isError) };
    }

    async evaluateJson(fnString) {
        const { text, isError } = await this.callTool('browser_evaluate', { function: fnString });
        if (isError) {
            throw new Error(`Erreur browser_evaluate: ${text}`);
        }
        const match = text.match(/### Result\s*\r?\n([\s\S]*?)(?:\r?\n### |\s*$)/);
        const jsonPart = match ? match[1].trim() : text.trim();
        return JSON.parse(jsonPart);
    }

    async stop() {
        if (!this.proc) return;
        try {
            await this.callTool('browser_close', {});
        } catch (_) {}
        this.proc.kill();
        this.proc = null;
    }
}

async function main() {
    let passed = 0;
    let failed = 0;
    const assertions = [];

    const assert = (cond, message, detail = '') => {
        if (cond) {
            passed++;
            assertions.push({ status: 'PASS', message, detail });
            console.log(`  [PASS] ${message}`);
        } else {
            failed++;
            assertions.push({ status: 'FAIL', message, detail });
            console.error(`  [FAIL] ${message}${detail ? ' — ' + detail : ''}`);
        }
    };

    console.log('========================================================================');
    console.log(' LIGHTPATH TRAINING PORTAL — CAMPAGNE E2E PLAYWRIGHT MCP (@playwright/mcp)');
    console.log('========================================================================\n');

    const port = await findFreePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    const tempDataDir = mkdtempSync(join(tmpdir(), 'lms-e2e-data-'));

    const phpProc = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', PUBLIC_DIR], {
        cwd: PORTAL_ROOT,
        env: {
            ...process.env,
            LMS_DATA_DIR: tempDataDir,
            LMS_INIT_ADMIN_PASSWORD: 'LightPath2026!',
            LMS_INIT_TRAINER_PASSWORD: 'Formateur2026!',
            LMS_INIT_STUDENT_PASSWORD: 'Stagiaire2026!',
        },
        stdio: 'ignore',
        windowsHide: true,
    });

    const mcp = new PlaywrightMcpStdioClient(MCP_CONFIG_PATH);

    try {
        await waitForHttpReady(`${baseUrl}/index.php?page=login`);

        // 1. MCP Protocol Handshake, Tool Discovery & Network Sandboxing (allowedOrigins)
        console.log('--- 1. Qualification & Durcissement du Serveur MCP @playwright/mcp ---');
        const initInfo = await mcp.start();
        assert(
            Boolean(initInfo?.serverInfo?.name),
            `Handshake JSON-RPC stdio réussi avec ${initInfo?.serverInfo?.name} v${initInfo?.serverInfo?.version}`
        );

        const toolsListRes = await mcp.request('tools/list', {});
        const toolNames = (toolsListRes?.tools || []).map((t) => t.name);
        for (const reqTool of [
            'browser_navigate',
            'browser_snapshot',
            'browser_click',
            'browser_evaluate',
            'browser_console_messages',
            'browser_network_requests',
            'browser_resize',
            'browser_take_screenshot',
        ]) {
            assert(toolNames.includes(reqTool), `Outil MCP officiel disponible : ${reqTool}`);
        }

        // Active check of allowedOrigins filtering (blocking external non-localhost URL)
        const blockedExternalNav = await mcp.callTool('browser_navigate', { url: 'https://example.com/' });
        assert(
            blockedExternalNav.isError && blockedExternalNav.text.includes('ERR_BLOCKED_BY_CLIENT'),
            'Sandboxing MCP allowedOrigins actif : navigation externe https://example.com bloquée (ERR_BLOCKED_BY_CLIENT)'
        );

        // 2. Smoke Test, Security Headers & Login DOM Conformance (Desktop & Mobile 375x812)
        console.log('\n--- 2. Smoke Test, En-têtes HTTP de Sécurité & Conformité DOM Login ---');
        const loginHttp = await fetch(`${baseUrl}/index.php?page=login`);
        assert(loginHttp.status === 200, 'Page de connexion HTTP 200 OK');
        assert(
            loginHttp.headers.get('x-content-type-options') === 'nosniff',
            'En-tête X-Content-Type-Options: nosniff actif'
        );
        assert(
            loginHttp.headers.get('x-frame-options') === 'SAMEORIGIN',
            'En-tête X-Frame-Options: SAMEORIGIN actif'
        );
        assert(
            (loginHttp.headers.get('permissions-policy') || '').includes('unload=*'),
            'En-tête Permissions-Policy: unload=* actif'
        );
        const loginCspHeader = loginHttp.headers.get('content-security-policy') || '';
        const scriptSrcDir = (loginCspHeader.match(/script-src\s+([^;]+)/i) || [''])[0];
        const styleSrcDir = (loginCspHeader.match(/style-src\s+([^;]+)/i) || [''])[0];
        assert(
            scriptSrcDir.includes("'sha256-") && !scriptSrcDir.includes('unsafe-inline') && styleSrcDir.includes("'self'") && !styleSrcDir.includes('unsafe-inline'),
            'Strict CSP header active: SHA-256 script-src and style-src self (0 unsafe-inline)'
        );

        await mcp.callTool('browser_resize', { width: 375, height: 812 });
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=login` });
        const loginMobileMetrics = await mcp.evaluateJson(`() => {
            const card = document.querySelector('form#login-form')?.closest('.login-split-grid, .login-form-col');
            return {
                scrollWidth: document.documentElement.scrollWidth,
                innerWidth: window.innerWidth,
                cardRight: card ? Math.round(card.getBoundingClientRect().right) : 0,
                inlineStyles: document.querySelectorAll('[style]').length
            };
        }`);
        assert(
            loginMobileMetrics.scrollWidth <= loginMobileMetrics.innerWidth
            && loginMobileMetrics.cardRight <= loginMobileMetrics.innerWidth
            && loginMobileMetrics.inlineStyles === 0,
            `Page Login responsive sur Mobile 375x812 sans troncature ni style= inline (cardRight=${loginMobileMetrics.cardRight} <= ${loginMobileMetrics.innerWidth})`
        );

        await mcp.callTool('browser_resize', { width: 1440, height: 900 });
        const loginConsole = await mcp.callTool('browser_console_messages', { level: 'debug' });
        assert(
            loginConsole.text.includes('Errors: 0, Warnings: 0') && !loginConsole.text.includes('autocomplete attributes'),
            'Zéro erreur console et zéro avertissement DOM autocomplete sur la page de connexion'
        );

        // Edge Case 2a: Invalid password rejection (isolated LMS_DATA_DIR)
        await mcp.evaluateJson(`() => {
            document.getElementById('login-email').value = 'jmrenouard@lightpath.fr';
            document.getElementById('login-password').value = 'WrongPassword123!';
            document.getElementById('login-form').submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 350));
        const badLoginInfo = await mcp.evaluateJson(`() => ({
            url: window.location.href,
            errText: (document.querySelector('.flash-error')?.innerText || '').trim()
        })`);
        assert(
            badLoginInfo.url.includes('page=login') && badLoginInfo.errText.includes('Identifiants incorrects'),
            'Rejet E2E d\'une tentative de connexion avec mot de passe erroné (bannière flash-error)'
        );

        // 3. Admin Authentication & 18-Route LMS Audit
        console.log('\n--- 3. Authentification RBAC, Edge Cases Sécurité & Audit des 18 Routes LMS ---');
        await mcp.evaluateJson(`() => {
            document.getElementById('login-email').value = 'jmrenouard@lightpath.fr';
            document.getElementById('login-password').value = 'LightPath2026!';
            const form = document.getElementById('login-form');
            if (form) form.submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 400));

        const dashInfo = await mcp.evaluateJson(`() => ({
            url: window.location.href,
            title: document.title,
            tracksCount: document.querySelectorAll('.track-roadmap-card, .sidebar-track-group').length
        })`);
        assert(dashInfo.url.includes('page=dashboard'), 'Redirection post-login vers le Tableau de Bord réussie');
        assert(dashInfo.tracksCount >= 4, 'Les 4 parcours certifiants PostgreSQL 18 sont affichés');

        // Verify temporary LMS_DATA_DIR is active (test data isolation)
        assert(
            existsSync(join(tempDataDir, 'users.json')) && existsSync(join(tempDataDir, 'audit_logs.json')),
            'Isolation LMS_DATA_DIR vérifiée : users.json et audit_logs.json créés dans le répertoire temporaire E2E'
        );

        // Edge Case 3a & 3b: CSRF Rejection (HTTP 403) & Path Traversal Block (HTTP 404)
        const securityEdgeCases = await mcp.evaluateJson(`async () => {
            const csrfRes = await fetch('${baseUrl}/index.php?action=api_save_note', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'forged_token' },
                body: JSON.stringify({ csrf_token: 'forged_token', item_id: 'mod_01', note: 'hack' })
            });
            const traversalRes = await fetch('${baseUrl}/index.php?action=download&type=module&id=../../../etc/passwd&format=md');
            return { csrfStatus: csrfRes.status, traversalStatus: traversalRes.status };
        }`);
        assert(securityEdgeCases.csrfStatus === 403, 'Edge Case Sécurité : Rejet HTTP 403 sur jeton CSRF forgé (api_save_note)');
        assert(securityEdgeCases.traversalStatus === 404, 'Edge Case Sécurité : Rejet HTTP 404 sur tentative de Path Traversal (action=download)');

        const routesAudit = await mcp.evaluateJson(`async () => {
            const pages = [
                'index.php?page=dashboard',
                'index.php?page=catalog',
                'index.php?page=catalog&tab=labs',
                'index.php?page=catalog&tab=cheatsheets',
                'index.php?page=catalog&tab=referentiel',
                'index.php?page=catalog&tab=tools',
                'index.php?page=module&id=module_01_introduction_postgresql_18',
                'index.php?page=module&id=module_01_introduction_postgresql_18&mode=slides',
                'index.php?page=lab&id=lab_01_introduction_setup_postgresql_18',
                'index.php?page=lab&id=lab_01_introduction_setup_postgresql_18&mode=corrige',
                'index.php?page=documents',
                'index.php?page=certificate',
                'index.php?page=admin&tab=cohort',
                'index.php?page=admin&tab=users',
                'index.php?page=admin&tab=docker',
                'index.php?page=admin&tab=courses',
                'index.php?page=admin&tab=audit',
                'index.php?page=admin&tab=system',
                'index.php?page=profile'
            ];
            const out = [];
            for (const p of pages) {
                const r = await fetch('${baseUrl}/' + p);
                const html = await r.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const imgsNoAlt = doc.querySelectorAll('img:not([alt])').length;
                const btnsNoLabel = Array.from(doc.querySelectorAll('button')).filter(b => !b.textContent.trim() && !b.getAttribute('aria-label') && !b.getAttribute('title')).length;
                const passNoAuto = doc.querySelectorAll('input[type="password"]:not([autocomplete])').length;
                const inlineStyles = doc.querySelectorAll('[style]').length;
                const inlineHandlers = doc.querySelectorAll('[onclick],[onchange],[onsubmit],[oninput],[onkeydown]').length;
                out.push({ page: p, status: r.status, imgsNoAlt, btnsNoLabel, passNoAuto, inlineStyles, inlineHandlers });
            }
            return out;
        }`);

        const all200 = routesAudit.every((r) => r.status === 200);
        const totalImgsNoAlt = routesAudit.reduce((acc, r) => acc + r.imgsNoAlt, 0);
        const totalBtnsNoLabel = routesAudit.reduce((acc, r) => acc + r.btnsNoLabel, 0);
        const totalPassNoAuto = routesAudit.reduce((acc, r) => acc + r.passNoAuto, 0);
        const totalInlineStyles = routesAudit.reduce((acc, r) => acc + r.inlineStyles, 0);
        const totalInlineHandlers = routesAudit.reduce((acc, r) => acc + r.inlineHandlers, 0);

        assert(all200 && routesAudit.length === 19, '19 / 19 routes applicatives LMS répondent en HTTP 200 OK');
        assert(totalImgsNoAlt === 0, '0 image sans attribut alt sur les 19 vues du portail');
        assert(totalBtnsNoLabel === 0, '0 bouton sans libellé accessible sur les 19 vues du portail');
        assert(totalPassNoAuto === 0, '0 champ mot de passe sans attribut autocomplete sur les 19 vues du portail');
        assert(totalInlineStyles === 0, '0 inline style= attribute across all 19 portal routes (strict style-src CSP)');
        assert(totalInlineHandlers === 0, '0 inline on*= event handler attribute across all 19 portal routes');

        // 4. Interactive Journeys: Module 01, 58-Slide Viewer, Sidebars, Dark/Light Theme
        console.log('\n--- 4. Tests Interactifs : Diaporama 16:9 (58 Slides), Sidebars, Thème & Lightbox ---');
        await mcp.callTool('browser_navigate', {
            url: `${baseUrl}/index.php?page=module&id=module_01_introduction_postgresql_18&mode=slides`,
        });

        const slidesAudit = await mcp.evaluateJson(`async () => {
            const totalSlides = document.querySelectorAll('.lms-slide-frame').length;
            const outlineItems = document.querySelectorAll('.slide-outline-item');
            if (outlineItems[3]) outlineItems[3].click();
            await new Promise(r => setTimeout(r, 350));

            const activeSlide = document.querySelector('.lms-slide-frame.active');
            const activeImg = activeSlide ? activeSlide.querySelector('img[data-slide-src]') : null;

            const btnPdf = document.getElementById('btn-slide-engine-pdf');
            const btnHtml = document.getElementById('btn-slide-engine-html');
            if (btnPdf) btnPdf.click();
            const stagePdf = document.getElementById('slide-stage-pdf');
            const stageHtml = document.getElementById('slide-stage-html');
            const pdfVisible = stagePdf ? !stagePdf.hidden && window.getComputedStyle(stagePdf).display !== 'none' : false;
            const htmlHidden = stageHtml ? stageHtml.hidden && window.getComputedStyle(stageHtml).display === 'none' : false;
            if (btnHtml) btnHtml.click();

            const btnLeft = document.getElementById('btn-toggle-left-sidebar');
            const btnRight = document.getElementById('btn-toggle-right-sidebar');
            if (btnLeft) btnLeft.click();
            const leftCollapsed = document.body.classList.contains('left-sidebar-collapsed');
            if (btnLeft) btnLeft.click();

            if (btnRight) btnRight.click();
            const rightCollapsed = document.body.classList.contains('right-sidebar-collapsed');
            if (btnRight) btnRight.click();

            const themeBtn = document.getElementById('btn-toggle-theme');
            themeBtn.click();
            const isLight = document.body.classList.contains('theme-light');
            const lightLogo = document.querySelector('.brand-logo').getAttribute('src');
            themeBtn.click();

            return {
                totalSlides,
                outlineCount: outlineItems.length,
                activeSlideIdx: activeSlide ? activeSlide.getAttribute('data-slide-index') : null,
                activeImgWidth: activeImg ? activeImg.naturalWidth : 0,
                pdfVisible,
                htmlHidden,
                leftCollapsed,
                rightCollapsed,
                isLight,
                lightLogo
            };
        }`);

        if (HAS_EXTERNAL_TRAINING_ROOT) {
            assert(slidesAudit.totalSlides === 58 && slidesAudit.outlineCount === 58, 'Module 01 charge exactement 58 diapositives Studio 16:9 et 58 entrées de sommaire');
            assert(slidesAudit.activeSlideIdx === '3' && slidesAudit.activeImgWidth === 1440, 'Navigation au clic vers la Slide 4 avec rendu PNG haute définition (1440 px)');
        } else {
            assert(slidesAudit.totalSlides >= 12 && slidesAudit.outlineCount === slidesAudit.totalSlides, `Demo Module 01 loads ${slidesAudit.totalSlides} interactive 16:9 slides`);
            assert(slidesAudit.activeSlideIdx === '3', 'Demo Module 01 navigates to Slide 4 on click');
        }
        assert(slidesAudit.pdfVisible && slidesAudit.htmlHidden, 'Bascule fluide entre Diaporama HTML5 et Lecteur PDF Continu en colonne principale');
        assert(slidesAudit.leftCollapsed && slidesAudit.rightCollapsed, 'Réduction/extension en 1 clic des barres verticales gauche et droite fonctionnelle');
        assert(slidesAudit.isLight && slidesAudit.lightLogo.includes('lightpath_logo_dark_text.png'), 'Bascule Thème Sombre / Clair et permutation automatique des logos transparents');

        // Verify standalone html_preview iframe CSP (0 console errors) and savepoint v-mode HTML pattern validity.
        await mcp.callTool('browser_navigate', {
            url: `${baseUrl}/index.php?page=module&id=module_01_introduction_postgresql_18&mode=html_preview`,
        });
        const previewAudit = await mcp.evaluateJson(`async () => {
            const iframe = document.querySelector('iframe.preview-iframe-full');
            if (!iframe) return { hasIframe: false };
            const src = iframe.getAttribute('src') || '';
            const res = await fetch('${baseUrl}/' + src);
            const csp = res.headers.get('content-security-policy') || '';
            return {
                hasIframe: true,
                status: res.status,
                hasUnloadHash: csp.includes("'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='"),
                hasMermaidHash: csp.includes("'sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA='"),
                noUnsafeInlineScript: !/script-src[^;]*unsafe-inline/i.test(csp),
            };
        }`);
        const previewConsole = await mcp.callTool('browser_console_messages', { level: 'debug' });
        assert(
            previewAudit.hasIframe
            && previewAudit.status === 200
            && previewAudit.hasUnloadHash
            && (!HAS_EXTERNAL_TRAINING_ROOT || previewAudit.hasMermaidHash)
            && previewAudit.noUnsafeInlineScript
            && previewConsole.text.includes('Errors: 0')
            && !previewConsole.text.includes('Content Security Policy'),
            'Module 01 html_preview iframe emits strict SHA-256 CSP without script unsafe-inline and zero console errors',
            JSON.stringify(previewAudit)
        );

        await mcp.callTool('browser_navigate', {
            url: `${baseUrl}/index.php?page=lab&id=lab_01_introduction_setup_postgresql_18`,
        });
        const savepointPatternAudit = await mcp.evaluateJson(`() => {
            const inp = document.getElementById('lab-savepoint-name');
            if (!inp) return { found: false };
            const pat = inp.getAttribute('pattern') || '';
            let vModeOk = false;
            let vModeErr = '';
            try {
                const re = new RegExp('^(?:' + pat + ')$', 'v');
                vModeOk = re.test('checkpoint_1') && !re.test('../bad');
            } catch (e) {
                vModeErr = String(e);
            }
            return {
                found: true,
                valid: inp.checkValidity(),
                vModeOk,
                vModeErr,
            };
        }`);
        const labConsole = await mcp.callTool('browser_console_messages', { level: 'debug' });
        assert(
            savepointPatternAudit.found
            && savepointPatternAudit.valid
            && savepointPatternAudit.vModeOk
            && labConsole.text.includes('Errors: 0')
            && !labConsole.text.includes('Invalid character in character class'),
            'Lab #lab-savepoint-name pattern passes checkValidity() and ES v-mode compilation with zero console errors',
            savepointPatternAudit.vModeErr || ''
        );

        // 5. Multi-Viewport Responsive Audit (Mobile 375x812, Tablet 768x1024, Desktop 1440x900) + Bounding Boxes + 255-char unspaced string
        console.log('\n--- 5. Audit Responsive Multi-Viewports & Bounding Boxes (375x812, 768x1024, 1440x900) ---');
        const viewports = [
            { name: 'Mobile', width: 375, height: 812 },
            { name: 'Tablet', width: 768, height: 1024 },
            { name: 'Desktop', width: 1440, height: 900 },
        ];

        const responsivePages = [
            'index.php?page=dashboard',
            'index.php?page=catalog',
            'index.php?page=module&id=module_01_introduction_postgresql_18',
            'index.php?page=lab&id=lab_01_introduction_setup_postgresql_18',
            'index.php?page=documents',
            'index.php?page=certificate',
            'index.php?page=admin&tab=users',
            'index.php?page=admin&tab=docker',
            'index.php?page=admin&tab=system',
            'index.php?page=profile',
        ];

        for (const vp of viewports) {
            await mcp.callTool('browser_resize', { width: vp.width, height: vp.height });
            for (const targetPage of responsivePages) {
                await mcp.callTool('browser_navigate', { url: `${baseUrl}/${targetPage}` });
                const metrics = await mcp.evaluateJson(`() => {
                    const selectors = '.app-content, .hero-banner, .lms-card, .lms-table-wrap, .toc-box, .docker-cockpit, .catalog-track-banner, .topbar-search, .admin-split-grid';
                    let maxRight = 0;
                    let culprit = '';
                    for (const el of document.querySelectorAll(selectors)) {
                        const r = Math.round(el.getBoundingClientRect().right);
                        if (r > maxRight) {
                            maxRight = r;
                            culprit = el.className || el.tagName;
                        }
                    }
                    return {
                        scrollWidth: document.documentElement.scrollWidth,
                        innerWidth: window.innerWidth,
                        maxRight,
                        culprit
                    };
                }`);
                assert(
                    metrics.scrollWidth <= metrics.innerWidth && metrics.maxRight <= metrics.innerWidth,
                    `Viewport ${vp.name} (${vp.width}x${vp.height}) sans débordement sur ${targetPage} (scrollWidth=${metrics.scrollWidth}, maxRight=${metrics.maxRight} <= ${metrics.innerWidth})`,
                    metrics.culprit
                );
            }
        }

        // Edge Case 5a: Inject continuous 255-character unspaced string on Mobile 375x812
        await mcp.callTool('browser_resize', { width: 375, height: 812 });
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=module&id=module_01_introduction_postgresql_18` });
        const longStringMetrics = await mcp.evaluateJson(`() => {
            const unspaced = 'POSTGRESQL18_SHARED_BUFFERS_AUTOVACUUM_MAX_WORKERS_'.repeat(5);
            const noteArea = document.getElementById('user-note-textarea');
            if (noteArea) noteArea.value = unspaced;
            const calloutBody = document.querySelector('.lms-callout-body, .lms-p');
            if (calloutBody) {
                const span = document.createElement('p');
                span.className = 'lms-p';
                span.textContent = unspaced;
                calloutBody.appendChild(span);
            }
            const articleRight = Math.round(document.querySelector('.reader-article').getBoundingClientRect().right);
            return {
                scrollWidth: document.documentElement.scrollWidth,
                innerWidth: window.innerWidth,
                articleRight
            };
        }`);
        assert(
            longStringMetrics.scrollWidth <= longStringMetrics.innerWidth && longStringMetrics.articleRight <= longStringMetrics.innerWidth,
            `Edge Case Mobile 375x812 : Chaîne continue de 255 caractères sans espace contenue sans débordement (articleRight=${longStringMetrics.articleRight} <= ${longStringMetrics.innerWidth})`
        );

        // 6. Comprehensive E2E Audit via @playwright/mcp: Tracks, Modules (Reader + Slides), Labs, Enterprise Views & HTML Deliverables
        console.log('\n--- 6. Audit Exhaustif E2E : Parcours, Modules, Labs, Vues Enterprise & Livrables HTML ---');
        await mcp.callTool('browser_resize', { width: 1440, height: 900 });
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=catalog` });

        // 6.1 Audit Tracks (Modules + Labs) + Cheatsheets / Referentiel / Tools Tabs + Enterprise Views
        const tracksAndEnterpriseAudit = await mcp.evaluateJson(`async () => {
            const hasRawTokens = (root) => {
                if (!root) return false;
                const clone = root.cloneNode(true);
                clone.querySelectorAll('pre, code, script, style, textarea').forEach(e => e.remove());
                const txt = clone.textContent || '';
                return txt.includes('**') || txt.includes('\`');
            };
            const isFullCourse = ${JSON.stringify(HAS_EXTERNAL_TRAINING_ROOT)};
            const trackIds = isFullCourse
                ? ['cursus-complet', 'performance-tuning', 'ingenierie-specialisee', 'fondamentaux-sql']
                : ['cursus-complet'];
            let trackPagesOk = 0;
            let trackRawErrors = [];
            for (const tid of trackIds) {
                for (const tab of ['modules', 'labs']) {
                    const url = '${baseUrl}/index.php?page=catalog&tab=' + tab + '&track=' + tid;
                    const r = await fetch(url);
                    const html = await r.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const cards = doc.querySelectorAll('.lms-card').length;
                    if (r.status === 200 && cards > 0) trackPagesOk++;
                    if (hasRawTokens(doc.querySelector('.app-content'))) trackRawErrors.push(tab + ':' + tid);
                }
            }

            const extraPages = [
                'index.php?page=catalog&tab=cheatsheets',
                'index.php?page=catalog&tab=referentiel',
                'index.php?page=catalog&tab=tools',
                isFullCourse
                    ? 'index.php?page=documents&tab=cheatsheets&id=cheatsheet_mod_01_introduction_postgresql_18'
                    : 'index.php?page=documents&tab=cheatsheets&id=cheatsheet_mod_01_architecture',
                isFullCourse
                    ? 'index.php?page=documents&tab=referentiel&id=programme_detaille'
                    : 'index.php?page=documents&tab=referentiel&id=programme_demo_open_source',
                'index.php?page=flashcards',
                'index.php?page=flashcards&tab=glossary',
                'index.php?page=diagnostic',
                'index.php?page=exam',
                'index.php?page=search&q=pg_stat_io',
                'index.php?page=attendance',
                'index.php?page=satisfaction'
            ];
            let extraOk = 0;
            let extraRawErrors = [];
            for (const ep of extraPages) {
                const r = await fetch('${baseUrl}/' + ep);
                const html = await r.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                if (r.status === 200) extraOk++;
                if (hasRawTokens(doc.querySelector('.app-content'))) extraRawErrors.push(ep);
            }

            const moduleLinks = Array.from(new Set(
                Array.from(document.querySelectorAll('.sidebar-module-link'))
                    .map(a => new URL(a.href, window.location.origin).searchParams.get('id'))
                    .filter(Boolean)
            ));

            const labPageRes = await fetch('${baseUrl}/index.php?page=catalog&tab=labs&track=all');
            const labPageDoc = new DOMParser().parseFromString(await labPageRes.text(), 'text/html');
            const labLinks = Array.from(new Set(
                Array.from(labPageDoc.querySelectorAll('a[href*="page=lab&id="]'))
                    .map(a => new URL(a.getAttribute('href'), window.location.origin).searchParams.get('id'))
                    .filter(Boolean)
            ));

            return {
                expectedTrackPages: trackIds.length * 2,
                trackPagesOk,
                trackRawErrors,
                extraOk,
                extraTotal: extraPages.length,
                extraRawErrors,
                moduleIds: moduleLinks,
                labIds: labLinks
            };
        }`);

        assert(
            tracksAndEnterpriseAudit.trackPagesOk === tracksAndEnterpriseAudit.expectedTrackPages && tracksAndEnterpriseAudit.trackRawErrors.length === 0,
            `${tracksAndEnterpriseAudit.trackPagesOk} / ${tracksAndEnterpriseAudit.expectedTrackPages} vues de parcours répondent en HTTP 200 avec 0 token Markdown/backtick brut`,
            tracksAndEnterpriseAudit.trackRawErrors.join(', ')
        );
        assert(
            tracksAndEnterpriseAudit.extraOk === tracksAndEnterpriseAudit.extraTotal && tracksAndEnterpriseAudit.extraRawErrors.length === 0,
            `${tracksAndEnterpriseAudit.extraOk} / ${tracksAndEnterpriseAudit.extraTotal} vues Enterprise (Cheatsheets, Référentiel, Tools, Reader Document, Flashcards, Glossaire, Diagnostic, Examen, Recherche, Émargement, Satisfaction) répondent en HTTP 200 avec 0 token brut`,
            tracksAndEnterpriseAudit.extraRawErrors.join(', ')
        );
        if (HAS_EXTERNAL_TRAINING_ROOT) {
            assert(
                tracksAndEnterpriseAudit.moduleIds.length === 34 && tracksAndEnterpriseAudit.labIds.length === 34,
                `Découverte complète depuis le DOM LMS : ${tracksAndEnterpriseAudit.moduleIds.length} modules et ${tracksAndEnterpriseAudit.labIds.length} ateliers`
            );
        } else {
            assert(
                tracksAndEnterpriseAudit.moduleIds.length >= 2 && tracksAndEnterpriseAudit.labIds.length >= 1,
                `Demo course discovery from LMS DOM: ${tracksAndEnterpriseAudit.moduleIds.length} modules and ${tracksAndEnterpriseAudit.labIds.length} labs`
            );
        }

        // 6.2 Comprehensive audit of discovered Modules (mode=reader and mode=slides)
        const minSlidesRequired = HAS_EXTERNAL_TRAINING_ROOT ? 30 : 12;
        const modulesAudit = await mcp.evaluateJson(`async () => {
            const moduleIds = ${JSON.stringify(tracksAndEnterpriseAudit.moduleIds)};
            const minSlidesReq = ${minSlidesRequired};
            const hasRawTokens = (root) => {
                if (!root) return false;
                const clone = root.cloneNode(true);
                clone.querySelectorAll('pre, code, script, style, textarea, select, option').forEach(e => e.remove());
                const txt = clone.textContent || '';
                return txt.includes('**') || txt.includes('\`');
            };
            let readerOk = 0;
            let slidesOk = 0;
            let totalSlidesCount = 0;
            let minSlides = 999;
            let readerIssues = [];
            let slidesIssues = [];

            for (const id of moduleIds) {
                const rReader = await fetch('${baseUrl}/index.php?page=module&id=' + id + '&mode=reader');
                const docReader = new DOMParser().parseFromString(await rReader.text(), 'text/html');
                const h1 = (docReader.querySelector('h1')?.textContent || '').trim();
                const metaGrid = docReader.querySelectorAll('.lms-doc-meta-grid').length;
                const tocLinks = docReader.querySelectorAll('.toc-link').length;
                const quizCards = docReader.querySelectorAll('.quiz-question-box').length;
                const rawR = hasRawTokens(docReader.querySelector('.app-content'));
                if (rReader.status === 200 && h1.includes('Module') && metaGrid >= 1 && tocLinks >= 3 && quizCards >= 3 && !rawR) {
                    readerOk++;
                } else {
                    readerIssues.push('reader:' + id + '(st=' + rReader.status + ',h1=' + h1 + ',meta=' + metaGrid + ',toc=' + tocLinks + ',quiz=' + quizCards + ',raw=' + rawR + ')');
                }

                const rSlides = await fetch('${baseUrl}/index.php?page=module&id=' + id + '&mode=slides');
                const docSlides = new DOMParser().parseFromString(await rSlides.text(), 'text/html');
                const slideFrames = docSlides.querySelectorAll('.lms-slide-frame').length;
                const outlineItems = docSlides.querySelectorAll('.slide-outline-item').length;
                const rawS = hasRawTokens(docSlides.querySelector('.app-content'));
                totalSlidesCount += slideFrames;
                if (slideFrames < minSlides) minSlides = slideFrames;
                if (rSlides.status === 200 && slideFrames >= minSlidesReq && slideFrames === outlineItems && !rawS) {
                    slidesOk++;
                } else {
                    slidesIssues.push('slides:' + id + '(st=' + rSlides.status + ',slides=' + slideFrames + ',outline=' + outlineItems + ',raw=' + rawS + ')');
                }
            }
            return { readerOk, slidesOk, totalSlidesCount, minSlides, readerIssues, slidesIssues };
        }`);

        assert(
            modulesAudit.readerOk === tracksAndEnterpriseAudit.moduleIds.length,
            `${modulesAudit.readerOk} / ${tracksAndEnterpriseAudit.moduleIds.length} modules en mode Lecture de Cours validés (H1 propre, grille métadonnées, sommaire TOC, Quiz >= 3 QCM, 0 token brut)`,
            modulesAudit.readerIssues.slice(0, 5).join('; ')
        );
        if (HAS_EXTERNAL_TRAINING_ROOT) {
            assert(
                modulesAudit.slidesOk === 34 && modulesAudit.totalSlidesCount === 1686 && modulesAudit.minSlides >= 33,
                `34 / 34 modules en mode Diaporama 16:9 validés (${modulesAudit.totalSlidesCount} diapositives Studio au total, min=${modulesAudit.minSlides} slides/module, 0 token brut)`,
                modulesAudit.slidesIssues.slice(0, 5).join('; ')
            );
        } else {
            assert(
                modulesAudit.slidesOk === tracksAndEnterpriseAudit.moduleIds.length && modulesAudit.minSlides >= 12,
                `${modulesAudit.slidesOk} / ${tracksAndEnterpriseAudit.moduleIds.length} demo modules in 16:9 Slideshow mode validated (${modulesAudit.totalSlidesCount} total slides, min=${modulesAudit.minSlides})`,
                modulesAudit.slidesIssues.slice(0, 5).join('; ')
            );
        }

        // 6.3 Comprehensive audit of all discovered Hands-on Labs (mode=enonce and mode=corrige)
        const labsAudit = await mcp.evaluateJson(`async () => {
            const labIds = ${JSON.stringify(tracksAndEnterpriseAudit.labIds)};
            const hasRawTokens = (root) => {
                if (!root) return false;
                const clone = root.cloneNode(true);
                clone.querySelectorAll('pre, code, script, style, textarea, select, option').forEach(e => e.remove());
                const txt = clone.textContent || '';
                return txt.includes('**') || txt.includes('\`');
            };
            let enonceOk = 0;
            let corrigeOk = 0;
            let issues = [];

            for (const id of labIds) {
                const rEnonce = await fetch('${baseUrl}/index.php?page=lab&id=' + id + '&mode=enonce');
                const docEnonce = new DOMParser().parseFromString(await rEnonce.text(), 'text/html');
                const h1E = (docEnonce.querySelector('h1')?.textContent || '').trim();
                const detailsE = docEnonce.querySelectorAll('.reader-article details').length;
                const rawE = hasRawTokens(docEnonce.querySelector('.app-content'));

                const rCorrige = await fetch('${baseUrl}/index.php?page=lab&id=' + id + '&mode=corrige');
                const docCorrige = new DOMParser().parseFromString(await rCorrige.text(), 'text/html');
                const openDetailsC = docCorrige.querySelectorAll('.reader-article details[open]').length;
                const articleTextC = docCorrige.querySelector('.reader-article')?.textContent || '';
                const rawC = hasRawTokens(docCorrige.querySelector('.app-content'));

                if (rEnonce.status === 200 && h1E.length > 5 && detailsE >= 1 && !rawE) {
                    enonceOk++;
                } else {
                    issues.push('enonce:' + id + '(st=' + rEnonce.status + ',details=' + detailsE + ',raw=' + rawE + ')');
                }

                if (rCorrige.status === 200 && (openDetailsC >= 1 || articleTextC.includes('Corrigé') || articleTextC.includes('Solution')) && !rawC) {
                    corrigeOk++;
                } else {
                    issues.push('corrige:' + id + '(st=' + rCorrige.status + ',openDetails=' + openDetailsC + ',raw=' + rawC + ')');
                }
            }
            return { enonceOk, corrigeOk, issues };
        }`);

        assert(
            labsAudit.enonceOk === tracksAndEnterpriseAudit.labIds.length && labsAudit.corrigeOk === tracksAndEnterpriseAudit.labIds.length,
            `${labsAudit.enonceOk} / ${tracksAndEnterpriseAudit.labIds.length} ateliers pratiques validés en mode Énoncé et en mode Corrigé Complet (${labsAudit.corrigeOk}/${tracksAndEnterpriseAudit.labIds.length}) avec 0 token brut`,
            labsAudit.issues.slice(0, 5).join('; ')
        );

        // 6.4 Comprehensive audit of compiled HTML deliverables via @playwright/mcp
        const demoCourseRoot = join(PORTAL_ROOT, 'examples', 'demo-course');
        const deliverablesRoot = HAS_EXTERNAL_TRAINING_ROOT ? TRAINING_ROOT : demoCourseRoot;
        const htmlDeliverables = collectHtmlDeliverables(deliverablesRoot);
        if (HAS_EXTERNAL_TRAINING_ROOT) {
            assert(
                htmlDeliverables.length === 172,
                `Découverte des ${htmlDeliverables.length} livrables HTML compilés dans training_postgresql`
            );
        } else {
            assert(
                htmlDeliverables.length >= 1,
                `Demo course discovers ${htmlDeliverables.length} compiled HTML deliverable(s)`
            );
        }

        const deliverablesAudit = await mcp.evaluateJson(`async () => {
            const relPaths = ${JSON.stringify(htmlDeliverables)};
            let okCount = 0;
            let rawErrors = [];
            let statusErrors = [];

            for (const rel of relPaths) {
                const url = '${baseUrl}/index.php?action=course_asset&course=postgresql-18&path=' + encodeURIComponent(rel);
                const r = await fetch(url);
                if (r.status !== 200) {
                    statusErrors.push(rel + ':' + r.status);
                    continue;
                }
                const html = await r.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const clone = doc.body.cloneNode(true);
                clone.querySelectorAll('pre, code, script, style').forEach(e => e.remove());
                const txt = clone.textContent || '';
                const prohibitedVendorRe = new RegExp('noble' + 'prog', 'i');
                const hasRaw = txt.includes('**') || txt.includes('\`') || prohibitedVendorRe.test(txt);
                const hasBrand = html.includes('LightPath') && html.includes('Jean-Marie Renouard');
                if (!hasRaw && hasBrand) {
                    okCount++;
                } else {
                    rawErrors.push(rel + '(raw=' + hasRaw + ',brand=' + hasBrand + ')');
                }
            }
            return { okCount, total: relPaths.length, statusErrors, rawErrors };
        }`);

        assert(
            deliverablesAudit.okCount === htmlDeliverables.length && deliverablesAudit.statusErrors.length === 0 && deliverablesAudit.rawErrors.length === 0,
            `${deliverablesAudit.okCount} / ${htmlDeliverables.length} livrables HTML compilés audités via le navigateur MCP : HTTP 200, charte LightPath, 0 forbidden vendor leak, 0 token '**' ou backtick brut`,
            [...deliverablesAudit.statusErrors, ...deliverablesAudit.rawErrors].slice(0, 5).join('; ')
        );

        // Live Chromium navigation (browser_navigate) across representative standalone HTML deliverables
        const sampleDeliverables = HAS_EXTERNAL_TRAINING_ROOT
            ? [
                'GUIDE_PARCOURS.html',
                '01_programme_et_referentiel/programme_formation_postgresql_18.html',
                '03_supports_de_cours/module_01_introduction_postgresql_18.html',
                '03_supports_de_cours/module_10_integrating_postgresql_with_applications.html',
                '04_ateliers_et_tp/lab_01_introduction_setup_postgresql_18.html',
                '05_cheatsheets/modules/cheatsheet_mod_01_introduction_postgresql_18.html',
            ]
            : ['03_supports_de_cours/module_01_introduction_postgresql_18.html'];
        let liveDeliverablesOk = 0;
        const sampleIssues = [];
        for (const rel of sampleDeliverables) {
            await mcp.callTool('browser_navigate', {
                url: `${baseUrl}/index.php?action=course_asset&course=postgresql-18&path=${encodeURIComponent(rel)}`,
            });
            const check = await mcp.evaluateJson(`() => {
                const clone = document.body.cloneNode(true);
                clone.querySelectorAll('pre, code, script, style').forEach(e => e.remove());
                const txt = clone.textContent || '';
                return {
                    hasH1: Boolean(document.querySelector('h1, h2, .cover-title')),
                    noRaw: !txt.includes('**') && !txt.includes('\`'),
                    scrollWidth: document.documentElement.scrollWidth,
                    innerWidth: window.innerWidth
                };
            }`);
            if (check.hasH1 && check.noRaw && check.scrollWidth <= check.innerWidth) {
                liveDeliverablesOk++;
            } else {
                sampleIssues.push(`${rel}(h1=${check.hasH1},noRaw=${check.noRaw},sw=${check.scrollWidth}/${check.innerWidth})`);
            }
        }
        assert(
            liveDeliverablesOk === sampleDeliverables.length,
            `Rendu visuel direct Chromium (browser_navigate) validé sur ${liveDeliverablesOk}/${sampleDeliverables.length} livrables HTML autonomes (H1, 0 débordement, 0 token brut)`,
            sampleIssues.join('; ')
        );

        mkdirSync(REPORT_DIR, { recursive: true });
        const reportPath = join(REPORT_DIR, 'playwright_campaign_report.json');
        writeFileSync(
            reportPath,
            JSON.stringify(
                {
                    timestamp: new Date().toISOString(),
                    mcpServer: initInfo?.serverInfo,
                    passed,
                    failed,
                    assertions,
                },
                null,
                2
            ),
            'utf8'
        );

        console.log('\n========================================================================');
        console.log(` RÉSULTATS CAMPAGNE PLAYWRIGHT MCP : ${passed} passés, ${failed} échoués`);
        console.log('========================================================================');
        process.exitCode = failed > 0 ? 1 : 0;
    } finally {
        await mcp.stop();
        phpProc.kill();
        try {
            rmSync(tempDataDir, { recursive: true, force: true });
        } catch (_) {}
        try {
            rmSync(join(PORTAL_ROOT, '.playwright-mcp'), { recursive: true, force: true });
        } catch (_) {}
    }
}

main().catch((err) => {
    console.error('Erreur fatale dans la campagne Playwright MCP :', err);
    process.exit(1);
});
