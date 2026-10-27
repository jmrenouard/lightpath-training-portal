#!/usr/bin/env node
/**
 * LightPath Training Portal — Test de Défi Adversarial (Challenger 2 - Milestones 3 & 4)
 * Vérification empirique approfondie :
 *  1. Tenue responsive stricte (375x812, 768x1024, 1440x900) sur toutes les vues critiques
 *     (diagnostic formulaire & résultat, catalogue par parcours modules & labs, dashboard, reader, slides, lab enonce & corrige)
 *  2. Audit fin de tout élément débordant (bounding box right > innerWidth)
 *  3. Parcours complet Diagnostic Qualiopi -> Recommandation -> Navigation Catalogue avec filtre de parcours
 *     (Cas Bas <45%, Moyen 45-74%, Haut >=75%, et Retake)
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PORTAL_ROOT = resolve(__dirname, '..', '..');
const PUBLIC_DIR = join(PORTAL_ROOT, 'public');
const MCP_CONFIG_PATH = join(PORTAL_ROOT, 'config', 'playwright-mcp.config.json');

function findFreePort() {
    return new Promise((resolvePort, reject) => {
        const srv = net.createServer();
        srv.listen(0, '127.0.0.1', () => {
            const addr = srv.address();
            const port = typeof addr === 'object' && addr ? addr.port : 18095;
            srv.close(() => resolvePort(port));
        });
        srv.on('error', reject);
    });
}

async function waitForHttpReady(url, timeoutMs = 10000) {
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
            clientInfo: { name: 'challenger-e2e-runner', version: '1.0.0' },
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
            }, 60000);
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

async function runAdversarialCampaign() {
    let passed = 0;
    let failed = 0;
    const failures = [];

    const assert = (cond, title, detail = '') => {
        if (cond) {
            passed++;
            console.log(`  [PASS] ${title}`);
        } else {
            failed++;
            failures.push({ title, detail });
            console.error(`  [FAIL] ${title}${detail ? ' — ' + detail : ''}`);
        }
    };

    console.log('========================================================================');
    console.log(' CHALLENGER 2 — AUDIT ADVERSARIAL RESPONSIVE & FLOW DIAGNOSTIC LMS');
    console.log('========================================================================\n');

    const port = await findFreePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    const tempDataDir = mkdtempSync(join(tmpdir(), 'challenger-lms-'));

    const phpProc = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', PUBLIC_DIR], {
        cwd: PORTAL_ROOT,
        env: { ...process.env, LMS_DATA_DIR: tempDataDir },
        stdio: 'ignore',
        windowsHide: true,
    });

    const mcp = new PlaywrightMcpStdioClient(MCP_CONFIG_PATH);

    try {
        await waitForHttpReady(`${baseUrl}/index.php?page=login`);
        await mcp.start();

        // 1. Authentification Apprenant
        console.log('--- Phase 1 : Authentification Session Stagiaire ---');
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=login` });
        await mcp.evaluateJson(`() => {
            document.getElementById('login-email').value = 'jmrenouard@lightpath.fr';
            document.getElementById('login-password').value = 'LightPath2026!';
            document.getElementById('login-form').submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 450));

        const authCheck = await mcp.evaluateJson(`() => ({
            url: window.location.href,
            logged: !window.location.href.includes('page=login')
        })`);
        assert(authCheck.logged, 'Authentification réussie, session active');

        // 2. Audit Responsive Initial : Formulaire Diagnostic (15 QCM) & Vues Critiques sur 3 Viewports
        console.log('\n--- Phase 2 : Audit Responsive Formulaire Diagnostic (15 QCM) & Vues Critiques ---');
        const viewports = [
            { name: 'Mobile', width: 375, height: 812 },
            { name: 'Tablet', width: 768, height: 1024 },
            { name: 'Desktop', width: 1440, height: 900 }
        ];

        const criticalViews = [
            { path: 'index.php?page=diagnostic', label: 'Diagnostic (Formulaire 15 QCM)' },
            { path: 'index.php?page=catalog&track=all', label: 'Catalogue (Vue Globale Modules)' },
            { path: 'index.php?page=catalog&tab=labs&track=all', label: 'Catalogue (Vue Globale Labs)' },
            { path: 'index.php?page=catalog&track=fondamentaux-sql', label: 'Catalogue (Parcours 4 SQL Modules)' },
            { path: 'index.php?page=catalog&track=cursus-complet', label: 'Catalogue (Parcours 1 Cursus Modules)' },
            { path: 'index.php?page=catalog&track=performance-tuning', label: 'Catalogue (Parcours 2 Perf Modules)' },
            { path: 'index.php?page=catalog&track=ingenierie-specialisee', label: 'Catalogue (Parcours 3 Ingénierie Modules)' },
            { path: 'index.php?page=catalog&tab=labs&track=fondamentaux-sql', label: 'Catalogue (Parcours 4 SQL Labs)' },
            { path: 'index.php?page=catalog&tab=labs&track=performance-tuning', label: 'Catalogue (Parcours 2 Perf Labs)' },
            { path: 'index.php?page=dashboard', label: 'Tableau de Bord Apprenant' },
            { path: 'index.php?page=module&id=module_01_introduction_postgresql_18&mode=reader', label: 'Module 01 (Mode Reader)' },
            { path: 'index.php?page=module&id=module_01_introduction_postgresql_18&mode=slides', label: 'Module 01 (Mode Diaporama 16:9)' },
            { path: 'index.php?page=lab&id=lab_01_introduction_setup_postgresql_18&mode=enonce', label: 'Lab 01 (Mode Énoncé)' },
            { path: 'index.php?page=lab&id=lab_01_introduction_setup_postgresql_18&mode=corrige', label: 'Lab 01 (Mode Corrigé)' },
        ];

        for (const vp of viewports) {
            await mcp.callTool('browser_resize', { width: vp.width, height: vp.height });
            for (const v of criticalViews) {
                await mcp.callTool('browser_navigate', { url: `${baseUrl}/${v.path}` });
                const check = await mcp.evaluateJson(`() => {
                    const winW = window.innerWidth;
                    const scrollW = document.documentElement.scrollWidth;
                    const culprits = [];
                    
                    // Fonction vérifiant si un élément est contenu dans un conteneur défilable légitime (pre, .lms-table-wrap)
                    const isInsideScrollContainer = (el) => {
                        let cur = el.parentElement;
                        while (cur && cur !== document.body) {
                            const style = window.getComputedStyle(cur);
                            if (cur.tagName === 'PRE' || cur.classList.contains('lms-table-wrap') || cur.classList.contains('code-block-wrap') || style.overflowX === 'auto' || style.overflowX === 'scroll' || style.overflow === 'hidden') {
                                return true;
                            }
                            cur = cur.parentElement;
                        }
                        return false;
                    };

                    document.querySelectorAll('*').forEach(el => {
                        if (el.offsetWidth === 0 || el.offsetHeight === 0) return;
                        if (isInsideScrollContainer(el)) return;
                        const r = el.getBoundingClientRect();
                        if (r.right > winW + 1) {
                            culprits.push({
                                tag: el.tagName,
                                id: el.id,
                                class: (el.className || '').toString().slice(0, 40),
                                right: Math.round(r.right),
                                diff: Math.round(r.right - winW)
                            });
                        }
                    });
                    return {
                        scrollW,
                        winW,
                        pageOverflow: scrollW > winW,
                        culprits
                    };
                }`);
                assert(
                    !check.pageOverflow && check.culprits.length === 0,
                    `[${vp.name} ${vp.width}x${vp.height}] Zéro débordement page & éléments sur ${v.label} (scrollW=${check.scrollW} <= ${check.winW})`,
                    check.culprits.length > 0 ? JSON.stringify(check.culprits[0]) : ''
                );
            }
        }

        // 3. Stress-Test Adversarial : Parcours Diagnostic -> Recommandation -> Catalogue Filtré
        console.log('\n--- Phase 3 : Stress-Test Adversarial du Flux Diagnostic Qualiopi ---');

        // Récupérer les identifiants et réponses des 15 QCM
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=diagnostic` });
        const diagMeta = await mcp.evaluateJson(`() => {
            const boxes = Array.from(document.querySelectorAll('.quiz-question-box'));
            const questions = [];
            for (const b of boxes) {
                const inputs = Array.from(b.querySelectorAll('input[type="radio"]'));
                if (inputs.length > 0) {
                    const name = inputs[0].name;
                    const match = name.match(/answers\\[([^\\]]+)\\]/);
                    const qid = match ? match[1] : '';
                    const options = inputs.map(i => i.value);
                    questions.push({ qid, options });
                }
            }
            const csrf = (document.querySelector('input[name="csrf_token"]')?.value || '');
            return { questions, csrf };
        }`);
        assert(diagMeta.questions.length === 15, `15 questions QCM détectées sur le formulaire (CSRF présent: ${Boolean(diagMeta.csrf)})`);

        // Test Scénario A : Score Faible (< 45%) -> Recommandation Parcours 4 (fondamentaux-sql)
        console.log('\n--- Scénario A : Diagnostic Score Faible (<45%) -> Recommandation Parcours 4 ---');
        await mcp.evaluateJson(`async () => {
            const form = document.querySelector('form.lms-card');
            const inputs = form.querySelectorAll('input[type="radio"]');
            const groups = {};
            inputs.forEach(inp => {
                if (!groups[inp.name]) groups[inp.name] = [];
                groups[inp.name].push(inp);
            });
            for (const name in groups) {
                const opts = groups[name];
                opts[opts.length - 1].checked = true;
            }
            form.submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 700));

        const resA = await mcp.evaluateJson(`() => {
            const card = document.querySelector('.lms-card');
            const pct = parseInt(document.querySelector('.kpi-value')?.textContent || '0', 10);
            const badge = document.querySelector('.badge-completed')?.textContent || '';
            const cta = Array.from(document.querySelectorAll('.lms-card a.btn-primary')).find(a => a.textContent.includes('Parcours Recommandé'));
            const href = cta ? cta.getAttribute('href') : '';
            const title = (card?.querySelector('span[style*="font-weight: 700"]')?.textContent || '').trim();
            return { pct, badge, href, title };
        }`);
        assert(resA.pct < 45, `Score faible calculé : ${resA.pct}% (< 45%)`);
        assert(resA.href.includes('track=fondamentaux-sql'), `Lien CTA cible bien 'track=fondamentaux-sql' (href: ${resA.href})`);
        assert(resA.title.includes('Parcours 4'), `Titre de parcours recommandé conforme : "${resA.title}"`);

        // Vérification responsive du résultat de diagnostic (carte scores + barres compétences)
        for (const vp of viewports) {
            await mcp.callTool('browser_resize', { width: vp.width, height: vp.height });
            const cardOverflow = await mcp.evaluateJson(`() => {
                const winW = window.innerWidth;
                const scrollW = document.documentElement.scrollWidth;
                const card = document.querySelector('.lms-card');
                const cardRight = card ? Math.round(card.getBoundingClientRect().right) : 0;
                return { scrollW, winW, cardRight };
            }`);
            assert(
                cardOverflow.scrollW <= cardOverflow.winW && cardOverflow.cardRight <= cardOverflow.winW,
                `[${vp.name} ${vp.width}x${vp.height}] Résultat Diagnostic responsive sans débordement (cardRight=${cardOverflow.cardRight} <= ${cardOverflow.winW})`
            );
        }

        // Clic sur le CTA et vérification de la vue Catalogue résultante
        console.log('\n--- Vérification Clic CTA -> Catalogue Parcours 4 (fondamentaux-sql) ---');
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/${resA.href}` });
        const catalogA = await mcp.evaluateJson(`() => {
            const activePill = document.querySelector('.track-filter-pill.active');
            const pillText = activePill ? activePill.textContent.trim() : '';
            const cards = document.querySelectorAll('.card-grid .lms-card');
            const trackSection = document.querySelector('.catalog-track-section');
            const banner = document.querySelector('.catalog-track-banner');
            return {
                pillText,
                cardsCount: cards.length,
                hasSection: Boolean(trackSection),
                bannerText: banner ? banner.textContent.trim() : ''
            };
        }`);
        assert(catalogA.pillText.includes('Parcours 4') || catalogA.pillText.includes('Fondamentaux'), `Pillule de filtre Parcours 4 active dans le catalogue ("${catalogA.pillText}")`);
        assert(catalogA.cardsCount === 5, `Exactement 5 modules affichés pour Parcours 4 (trouvés: ${catalogA.cardsCount})`);
        assert(catalogA.bannerText.includes('Parcours 4'), `Bannière de parcours affichée pour Parcours 4`);

        // Test Scénario B : Retake -> Score Intermédiaire (8/15 = 53%) -> Recommandation Parcours 1 (cursus-complet)
        console.log('\n--- Scénario B : Retake & Score Intermédiaire (53%) -> Recommandation Parcours 1 ---');
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=diagnostic&retake=1` });
        const retakeFormCheck = await mcp.evaluateJson(`() => {
            const form = document.querySelector('form.lms-card');
            const inputs = form ? form.querySelectorAll('input[type="radio"]') : [];
            return { hasForm: Boolean(form), inputsCount: inputs.length };
        }`);
        assert(retakeFormCheck.hasForm && retakeFormCheck.inputsCount > 0, 'Mode Retake actif : formulaire complet affiché');

        // Soumettre 8 bonnes réponses et 7 mauvaises
        await mcp.evaluateJson(`() => {
            const answers = {
                'diag_01': 'B', 'diag_02': 'B', 'diag_03': 'A', 'diag_04': 'A',
                'diag_05': 'C', 'diag_06': 'B', 'diag_07': 'C', 'diag_08': 'A',
                'diag_09': 'D', 'diag_10': 'D', 'diag_11': 'D', 'diag_12': 'D',
                'diag_13': 'D', 'diag_14': 'D', 'diag_15': 'D'
            };
            for (const qid in answers) {
                const radio = document.querySelector('input[name="answers[' + qid + ']"][value="' + answers[qid] + '"]');
                if (radio) radio.checked = true;
            }
            document.querySelector('form.lms-card').submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 700));

        const resB = await mcp.evaluateJson(`() => {
            const pct = parseInt(document.querySelector('.kpi-value')?.textContent || '0', 10);
            const cta = Array.from(document.querySelectorAll('.lms-card a.btn-primary')).find(a => a.textContent.includes('Parcours Recommandé'));
            const href = cta ? cta.getAttribute('href') : '';
            const title = (document.querySelector('.lms-card span[style*="font-weight: 700"]')?.textContent || '').trim();
            return { pct, href, title };
        }`);
        assert(resB.pct >= 45 && resB.pct < 75, `Score intermédiaire calculé : ${resB.pct}% (attendu 45% à 74%)`);
        assert(resB.href.includes('track=cursus-complet'), `Lien CTA cible bien 'track=cursus-complet' (href: ${resB.href})`);
        assert(resB.title.includes('Parcours 1'), `Titre de parcours recommandé conforme : "${resB.title}"`);

        // Navigation CTA vers Catalogue Parcours 1
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/${resB.href}` });
        const catalogB = await mcp.evaluateJson(`() => {
            const cards = document.querySelectorAll('.card-grid .lms-card');
            const activePill = document.querySelector('.track-filter-pill.active');
            return {
                cardsCount: cards.length,
                pillText: activePill ? activePill.textContent.trim() : ''
            };
        }`);
        assert(catalogB.pillText.includes('Parcours 1') || catalogB.pillText.includes('Cursus'), `Pillule de filtre Parcours 1 active ("${catalogB.pillText}")`);
        assert(catalogB.cardsCount === 20, `Exactement 20 modules affichés pour Parcours 1 (trouvés: ${catalogB.cardsCount})`);

        // Test Scénario C : Retake -> Score Avancé (15/15 = 100%) -> Recommandation Parcours 2 (performance-tuning)
        console.log('\n--- Scénario C : Retake & Score Parfait (100%) -> Recommandation Parcours 2 ---');
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/index.php?page=diagnostic&retake=1` });
        await mcp.evaluateJson(`() => {
            const answers = {
                'diag_01': 'B', 'diag_02': 'B', 'diag_03': 'A', 'diag_04': 'A',
                'diag_05': 'C', 'diag_06': 'B', 'diag_07': 'C', 'diag_08': 'A',
                'diag_09': 'A', 'diag_10': 'B', 'diag_11': 'B', 'diag_12': 'B',
                'diag_13': 'A', 'diag_14': 'A', 'diag_15': 'A'
            };
            for (const qid in answers) {
                const radio = document.querySelector('input[name="answers[' + qid + ']"][value="' + answers[qid] + '"]');
                if (radio) radio.checked = true;
            }
            document.querySelector('form.lms-card').submit();
            return true;
        }`);
        await new Promise((r) => setTimeout(r, 700));

        const resC = await mcp.evaluateJson(`() => {
            const pct = parseInt(document.querySelector('.kpi-value')?.textContent || '0', 10);
            const cta = Array.from(document.querySelectorAll('.lms-card a.btn-primary')).find(a => a.textContent.includes('Parcours Recommandé'));
            const href = cta ? cta.getAttribute('href') : '';
            const title = (document.querySelector('.lms-card span[style*="font-weight: 700"]')?.textContent || '').trim();
            return { pct, href, title };
        }`);
        assert(resC.pct === 100, `Score parfait calculé : ${resC.pct}% (100%)`);
        assert(resC.href.includes('track=performance-tuning'), `Lien CTA cible bien 'track=performance-tuning' (href: ${resC.href})`);
        assert(resC.title.includes('Parcours 2'), `Titre de parcours recommandé conforme : "${resC.title}"`);

        // Navigation CTA vers Catalogue Parcours 2
        await mcp.callTool('browser_navigate', { url: `${baseUrl}/${resC.href}` });
        const catalogC = await mcp.evaluateJson(`() => {
            const cards = document.querySelectorAll('.card-grid .lms-card');
            const activePill = document.querySelector('.track-filter-pill.active');
            return {
                cardsCount: cards.length,
                pillText: activePill ? activePill.textContent.trim() : ''
            };
        }`);
        assert(catalogC.pillText.includes('Parcours 2') || catalogC.pillText.includes('Performance'), `Pillule de filtre Parcours 2 active ("${catalogC.pillText}")`);
        assert(catalogC.cardsCount === 5, `Exactement 5 modules affichés pour Parcours 2 (trouvés: ${catalogC.cardsCount})`);

        // 4. Console log check & clean exit
        const finalConsole = await mcp.callTool('browser_console_messages', { level: 'error' });
        assert(finalConsole.text.includes('Errors: 0'), `Zéro erreur console JavaScript sur l'ensemble des scénarios exécutés (${finalConsole.text.trim()})`);

        console.log('\n========================================================================');
        console.log(` RÉSULTATS CAMPAGNE ADVERSARIALE : ${passed} passés, ${failed} échoués`);
        console.log('========================================================================');
        process.exitCode = failed > 0 ? 1 : 0;
    } finally {
        await mcp.stop();
        phpProc.kill();
        try {
            rmSync(tempDataDir, { recursive: true, force: true });
        } catch (_) {}
    }
}

runAdversarialCampaign().catch((err) => {
    console.error('Erreur fatale lors de la campagne adversariale :', err);
    process.exit(1);
});
