#!/usr/bin/env node
/**
 * LightPath Training Portal — Campagne Automatisée de Tests E2E via Microsoft @playwright/mcp (JSON-RPC stdio)
 * Exécution : node tests/e2e/run_playwright_campaign.mjs
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PORTAL_ROOT = resolve(__dirname, '..', '..');
const PUBLIC_DIR = join(PORTAL_ROOT, 'public');
const MCP_CONFIG_PATH = join(PORTAL_ROOT, 'config', 'playwright-mcp.config.json');
const REPORT_DIR = join(PORTAL_ROOT, 'storage', 'tmp');

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
 * Client JSON-RPC 2.0 léger pilotant @playwright/mcp via stdio
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
            }, 30000);
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
        env: { ...process.env, LMS_DATA_DIR: tempDataDir },
        stdio: 'ignore',
        windowsHide: true,
    });

    const mcp = new PlaywrightMcpStdioClient(MCP_CONFIG_PATH);

    try {
        await waitForHttpReady(`${baseUrl}/index.php?page=login`);

        // 1. Protocole MCP, Découverte des Outils & Sandboxing Réseau (allowedOrigins)
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

        // Vérification active du filtrage d'origine allowedOrigins (blocage d'une URL externe non-localhost)
        const blockedExternalNav = await mcp.callTool('browser_navigate', { url: 'https://example.com/' });
        assert(
            blockedExternalNav.isError && blockedExternalNav.text.includes('ERR_BLOCKED_BY_CLIENT'),
            'Sandboxing MCP allowedOrigins actif : navigation externe https://example.com bloquée (ERR_BLOCKED_BY_CLIENT)'
        );

        // 2. Smoke Test, En-têtes de Sécurité & Conformité DOM Login (Desktop & Mobile 375x812)
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

        await mcp.callTool('browser_resize', { width: 375, height: 812 });
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=login` });
        const loginMobileMetrics = await mcp.evaluateJson(`() => {
            const card = document.querySelector('form#login-form')?.closest('div[style*="grid"]');
            return {
                scrollWidth: document.documentElement.scrollWidth,
                innerWidth: window.innerWidth,
                cardRight: card ? Math.round(card.getBoundingClientRect().right) : 0
            };
        }`);
        assert(
            loginMobileMetrics.scrollWidth <= loginMobileMetrics.innerWidth && loginMobileMetrics.cardRight <= loginMobileMetrics.innerWidth,
            `Page Login responsive sur Mobile 375x812 sans troncature (cardRight=${loginMobileMetrics.cardRight} <= ${loginMobileMetrics.innerWidth})`
        );

        await mcp.callTool('browser_resize', { width: 1440, height: 900 });
        const loginConsole = await mcp.callTool('browser_console_messages', { level: 'debug' });
        assert(
            loginConsole.text.includes('Errors: 0, Warnings: 0') && !loginConsole.text.includes('autocomplete attributes'),
            'Zéro erreur console et zéro avertissement DOM autocomplete sur la page de connexion'
        );

        // Edge Case 2a : Rejet d'un mot de passe invalide (sur LMS_DATA_DIR isolé)
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

        // 3. Authentification Admin & Audit des 18 Routes LMS
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

        // Vérification que LMS_DATA_DIR temporaire est bien utilisé (isolation des données de test)
        assert(
            existsSync(join(tempDataDir, 'users.json')) && existsSync(join(tempDataDir, 'audit_logs.json')),
            'Isolation LMS_DATA_DIR vérifiée : users.json et audit_logs.json créés dans le répertoire temporaire E2E'
        );

        // Edge Case 3a & 3b : Rejet CSRF (HTTP 403) et blocage Path Traversal (HTTP 404)
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
                out.push({ page: p, status: r.status, imgsNoAlt, btnsNoLabel, passNoAuto });
            }
            return out;
        }`);

        const all200 = routesAudit.every((r) => r.status === 200);
        const totalImgsNoAlt = routesAudit.reduce((acc, r) => acc + r.imgsNoAlt, 0);
        const totalBtnsNoLabel = routesAudit.reduce((acc, r) => acc + r.btnsNoLabel, 0);
        const totalPassNoAuto = routesAudit.reduce((acc, r) => acc + r.passNoAuto, 0);

        assert(all200 && routesAudit.length === 18, '18 / 18 routes applicatives LMS répondent en HTTP 200 OK');
        assert(totalImgsNoAlt === 0, '0 image sans attribut alt sur les 18 vues du portail');
        assert(totalBtnsNoLabel === 0, '0 bouton sans libellé accessible sur les 18 vues du portail');
        assert(totalPassNoAuto === 0, '0 champ mot de passe sans attribut autocomplete sur les 18 vues du portail');

        // 4. Parcours Interactifs : Module 01, Diaporama 58 Slides, Barres Latérales, Thème Dark/Light
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

        assert(slidesAudit.totalSlides === 58 && slidesAudit.outlineCount === 58, 'Module 01 charge exactement 58 diapositives Studio 16:9 et 58 entrées de sommaire');
        assert(slidesAudit.activeSlideIdx === '3' && slidesAudit.activeImgWidth === 1440, 'Navigation au clic vers la Slide 4 avec rendu PNG haute définition (1440 px)');
        assert(slidesAudit.pdfVisible && slidesAudit.htmlHidden, 'Bascule fluide entre Diaporama HTML5 et Lecteur PDF Continu en colonne principale');
        assert(slidesAudit.leftCollapsed && slidesAudit.rightCollapsed, 'Réduction/extension en 1 clic des barres verticales gauche et droite fonctionnelle');
        assert(slidesAudit.isLight && slidesAudit.lightLogo.includes('lightpath_logo_dark_text.png'), 'Bascule Thème Sombre / Clair et permutation automatique des logos transparents');

        // 5. Campagne Multi-Viewports Responsives (Mobile 375x812, Tablette 768x1024, Desktop 1440x900) + Bounding Boxes + Chaîne 200 chars sans espace
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
            'index.php?page=profile',
        ];

        for (const vp of viewports) {
            await mcp.callTool('browser_resize', { width: vp.width, height: vp.height });
            for (const targetPage of responsivePages) {
                await mcp.callTool('browser_navigate', { url: `${baseUrl}/${targetPage}` });
                const metrics = await mcp.evaluateJson(`() => {
                    const selectors = '.app-content, .hero-banner, .lms-card, .lms-table-wrap, .toc-box, .docker-cockpit, .catalog-track-banner, .topbar-search';
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

        // Edge Case 5a : Injection d'une chaîne continue de 220 caractères sans espace sur Mobile 375x812
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
