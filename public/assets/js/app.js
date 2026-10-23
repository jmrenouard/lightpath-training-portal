/**
 * LightPath Academy LMS — Client-Side Interactive Engine (Vanilla ES2022, Zero CDN)
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // 1. Bascule Thème Sombre / Clair
    const themeBtn = document.getElementById('btn-toggle-theme');
    if (localStorage.getItem('lp_lms_theme') === 'light') {
        document.body.classList.add('theme-light');
    }
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            document.body.classList.toggle('theme-light');
            const isLight = document.body.classList.contains('theme-light');
            localStorage.setItem('lp_lms_theme', isLight ? 'light' : 'dark');
        });
    }

    // 2. Raccourci Recherche Globale Ctrl+K
    const searchInput = document.getElementById('global-search-input');
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            if (searchInput) searchInput.focus();
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('[data-searchable]').forEach((card) => {
                const text = (card.getAttribute('data-searchable') || '').toLowerCase();
                card.style.display = (q === '' || text.includes(q)) ? '' : 'none';
            });
        });
    }

    // 3. Copie de code 1-clic
    document.querySelectorAll('.btn-copy-code').forEach((btn) => {
        btn.addEventListener('click', () => {
            const block = btn.closest('.lms-code-block');
            const codeEl = block ? block.querySelector('code') : null;
            if (!codeEl) return;
            navigator.clipboard.writeText(codeEl.innerText).then(() => {
                const orig = btn.innerText;
                btn.innerText = '✔ Copié !';
                setTimeout(() => { btn.innerText = orig; }, 1600);
            });
        });
    });

    // 4. Envoyer un bloc SQL de l'énoncé directement dans la Console SQL et l'exécuter
    document.querySelectorAll('.btn-run-sql').forEach((btn) => {
        btn.addEventListener('click', () => {
            const b64 = btn.getAttribute('data-sql-b64') || '';
            const sql = atob(b64);
            const editor = document.getElementById('lab-sql-editor');
            if (editor) {
                editor.value = sql;
                editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const execBtn = document.getElementById('btn-execute-sql');
                if (execBtn) execBtn.click();
            }
        });
    });

    // 5. Marquer un Module ou un Lab comme terminé
    document.querySelectorAll('.btn-toggle-complete').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const itemType = btn.getAttribute('data-type');
            const itemId = btn.getAttribute('data-id');
            const course = btn.getAttribute('data-course') || 'postgresql-18';
            const currentlyDone = btn.getAttribute('data-completed') === '1';

            btn.disabled = true;
            try {
                const res = await fetch(`index.php?action=api_toggle_complete&course=${encodeURIComponent(course)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        item_type: itemType,
                        item_id: itemId,
                        completed: !currentlyDone
                    })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    // 6. Sauvegarde des notes personnelles de l'apprenant
    const saveNoteBtn = document.getElementById('btn-save-note');
    if (saveNoteBtn) {
        saveNoteBtn.addEventListener('click', async () => {
            const itemId = saveNoteBtn.getAttribute('data-id');
            const course = saveNoteBtn.getAttribute('data-course') || 'postgresql-18';
            const noteArea = document.getElementById('user-personal-note');
            const statusSpan = document.getElementById('note-save-status');
            if (!noteArea) return;

            saveNoteBtn.disabled = true;
            const res = await fetch(`index.php?action=api_save_note&course=${encodeURIComponent(course)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    item_id: itemId,
                    note: noteArea.value
                })
            });
            const data = await res.json();
            saveNoteBtn.disabled = false;
            if (data.success && statusSpan) {
                statusSpan.innerText = `✔ Sauvegardé à ${data.saved_at}`;
            }
        });
    }

    // 7. Quiz QCM Interactif Auto-Corrigé
    const quizForm = document.getElementById('interactive-quiz-form');
    if (quizForm) {
        quizForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const moduleId = quizForm.getAttribute('data-module-id');
            const course = quizForm.getAttribute('data-course') || 'postgresql-18';
            const questions = quizForm.querySelectorAll('.quiz-question-box');
            let score = 0;
            const total = questions.length;

            questions.forEach((qBox) => {
                const correct = qBox.getAttribute('data-correct');
                const selected = qBox.querySelector('input[type="radio"]:checked');
                const feedback = qBox.querySelector('.quiz-feedback');
                const chosen = selected ? selected.value : '';

                if (chosen === correct) {
                    score++;
                    qBox.style.borderColor = '#10B981';
                } else {
                    qBox.style.borderColor = '#F43F5E';
                }
                if (feedback) {
                    feedback.style.display = 'block';
                }
            });

            const res = await fetch(`index.php?action=api_submit_quiz&course=${encodeURIComponent(course)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    module_id: moduleId,
                    score: score,
                    total: total
                })
            });
            const data = await res.json();
            const summaryBanner = document.getElementById('quiz-result-banner');
            if (summaryBanner && data.quiz_result) {
                const pct = data.quiz_result.percentage;
                summaryBanner.style.display = 'block';
                summaryBanner.className = 'flash-banner ' + (pct >= 66 ? 'flash-success' : 'flash-error');
                summaryBanner.innerHTML = pct >= 66
                    ? `🏆 Félicitations ! Score : ${score} / ${total} (${pct}%) — Module validé automatiquement dans votre parcours Qualiopi.`
                    : `⚠️ Score : ${score} / ${total} (${pct}%) — Consultez les explications ci-dessus et réessayez (seuil de validation : 66%).`;
            }
        });
    }

    // 8. Pilotage des Conteneurs Docker par Utilisateur (Start / Stop / Reset)
    document.querySelectorAll('.btn-docker-cmd').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const cmd = btn.getAttribute('data-cmd');
            const targetUserId = btn.getAttribute('data-user-id') || '';
            const statusLog = document.getElementById('docker-action-feedback');

            btn.disabled = true;
            const origText = btn.innerText;
            btn.innerText = '⏳ Opération Docker en cours...';
            if (statusLog) {
                statusLog.style.display = 'block';
                statusLog.innerText = `⏳ Exécution de la commande Docker (${cmd}) en cours...`;
            }

            try {
                const res = await fetch('index.php?action=api_docker_action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        docker_cmd: cmd,
                        target_user_id: targetUserId ? parseInt(targetUserId, 10) : undefined
                    })
                });
                const data = await res.json();
                if (statusLog) {
                    statusLog.innerText = (data.success ? '✅ ' : '⚠️ ') + (data.message || 'Opération terminée.');
                }
                setTimeout(() => window.location.reload(), 900);
            } catch (err) {
                btn.disabled = false;
                btn.innerText = origText;
            }
        });
    });

    // 9. Console SQL Interactive dans le Lab
    const execSqlBtn = document.getElementById('btn-execute-sql');
    if (execSqlBtn) {
        execSqlBtn.addEventListener('click', async () => {
            const editor = document.getElementById('lab-sql-editor');
            const outBox = document.getElementById('lab-sql-output');
            if (!editor || !outBox) return;

            execSqlBtn.disabled = true;
            outBox.innerText = '⏳ Exécution SQL sur votre instance PostgreSQL 18...';

            const res = await fetch('index.php?action=api_run_sql', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    sql: editor.value
                })
            });
            const data = await res.json();
            execSqlBtn.disabled = false;
            outBox.innerText = `[Instance: ${data.container || 'N/A'} | Durée: ${data.duration_ms || 0} ms]\n\n` + (data.output || '');
        });
    }

    // 10. Oracle de Validation Qualiopi en 1 Clic
    const oracleBtn = document.getElementById('btn-run-oracle');
    if (oracleBtn) {
        oracleBtn.addEventListener('click', async () => {
            const labId = oracleBtn.getAttribute('data-lab-id');
            const course = oracleBtn.getAttribute('data-course') || 'postgresql-18';
            const outBox = document.getElementById('lab-sql-output');

            oracleBtn.disabled = true;
            const origText = oracleBtn.innerText;
            oracleBtn.innerText = '⏳ Vérification Oracle en cours...';

            const res = await fetch(`index.php?action=api_run_oracle&course=${encodeURIComponent(course)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    lab_id: labId
                })
            });
            const data = await res.json();
            oracleBtn.disabled = false;
            oracleBtn.innerText = origText;

            if (outBox) {
                outBox.innerText = `[ORACLE QUALIOPI — ${data.bloom} : ${data.title}]\n`
                    + `Statut : ${data.passed ? '✅ VALIDÉ (Compétence acquise !)' : '❌ ÉCHEC OU INSTANCE ARRÊTÉE'}\n\n`
                    + (data.output || '');
            }
            if (data.passed) {
                setTimeout(() => window.location.reload(), 1500);
            }
        });
    }

    // 11. Boutons Chaos Engineering DBA (Admin / Formateur)
    document.querySelectorAll('.btn-chaos-trigger').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const scenario = btn.getAttribute('data-scenario');
            const chaosAction = btn.getAttribute('data-action');
            const logBox = document.getElementById('chaos-feedback-box');

            btn.disabled = true;
            const res = await fetch('index.php?action=api_chaos', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    scenario: scenario,
                    chaos_action: chaosAction
                })
            });
            const data = await res.json();
            btn.disabled = false;
            if (logBox) {
                logBox.style.display = 'block';
                logBox.innerText = (data.success ? '⚡ ' : '❌ ') + data.message;
            }
        });
    });
});
