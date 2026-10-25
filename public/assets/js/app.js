/**
 * LightPath Academy LMS — Client-Side Interactive Engine (Vanilla ES2022, Zero CDN)
 * Inclut : ScrollSpy temps réel, Lecteur Diaporama 16:9, Lightbox Schémas, Mermaid local & Console SQL Docker
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // ------------------------------------------------------------------------
    // 1. Bascule Thème Sombre / Clair
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 2. Initialisation Mermaid Vectoriel Local (Fallback pour diagrammes non cachés)
    // ------------------------------------------------------------------------
    if (window.mermaid && document.querySelectorAll('.mermaid').length > 0) {
        try {
            window.mermaid.initialize({
                startOnLoad: true,
                theme: 'neutral',
                securityLevel: 'strict',
                fontFamily: 'Plus Jakarta Sans, Open Sans, sans-serif'
            });
        } catch (_) {
            // Fallback silencieux
        }
    }

    // ------------------------------------------------------------------------
    // 3. Lightbox Plein Écran pour Diagrammes Mermaid & Infographies Studio
    // ------------------------------------------------------------------------
    const lightbox = document.getElementById('diagram-lightbox');
    const lightboxImg = document.getElementById('diagram-lightbox-img');
    const lightboxCaption = document.getElementById('diagram-lightbox-caption');
    const closeLightboxBtn = document.getElementById('btn-close-lightbox');

    const openLightbox = (src, captionText) => {
        if (!lightbox || !lightboxImg || !src) return;
        lightboxImg.src = src;
        if (lightboxCaption) {
            lightboxCaption.textContent = captionText || 'Schéma d\'Architecture PostgreSQL 18';
        }
        lightbox.hidden = false;
    };

    const closeLightbox = () => {
        if (!lightbox) return;
        lightbox.hidden = true;
        if (lightboxImg) lightboxImg.src = '';
    };

    document.querySelectorAll('.lms-zoomable-img').forEach((img) => {
        img.addEventListener('click', () => {
            openLightbox(img.getAttribute('src'), img.getAttribute('alt'));
        });
    });

    document.querySelectorAll('.btn-zoom-diagram').forEach((btn) => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.lms-mermaid-card');
            const img = card ? card.querySelector('img') : null;
            if (img) {
                openLightbox(img.getAttribute('src'), img.getAttribute('alt'));
            }
        });
    });

    document.querySelectorAll('.btn-toggle-mermaid-src').forEach((btn) => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.lms-mermaid-card');
            const pre = card ? card.querySelector('.lms-mermaid-source') : null;
            if (pre) {
                pre.hidden = !pre.hidden;
                btn.textContent = pre.hidden ? 'Code Mermaid' : 'Masquer Code';
            }
        });
    });

    if (closeLightboxBtn) {
        closeLightboxBtn.addEventListener('click', closeLightbox);
    }
    if (lightbox) {
        const backdrop = lightbox.querySelector('.diagram-lightbox-backdrop');
        if (backdrop) backdrop.addEventListener('click', closeLightbox);
    }

    // ------------------------------------------------------------------------
    // 4. ScrollSpy Temps Réel du Sommaire & Barre de Progression de Lecture
    // ------------------------------------------------------------------------
    const articleEl = document.getElementById('reader-article-content');
    const tocNav = document.getElementById('toc-scrollspy-nav');
    const topProgressBar = document.getElementById('reading-progress-bar');
    const tocReadBar = document.getElementById('toc-read-bar');
    const tocReadPct = document.getElementById('toc-read-pct');

    // Centrer automatiquement l'étape courante dans le Stepper de Parcours
    const currentStepperItem = document.querySelector('.curriculum-stepper-list .stepper-item.is-current');
    if (currentStepperItem) {
        const stepperContainer = currentStepperItem.closest('.curriculum-stepper-list');
        if (stepperContainer) {
            stepperContainer.scrollTop = Math.max(0, currentStepperItem.offsetTop - stepperContainer.clientHeight / 2 + 18);
        }
    }

    if (articleEl && tocNav) {
        const spyLinks = Array.from(tocNav.querySelectorAll('[data-scrollspy-target]'));
        const trackedTargets = spyLinks
            .map((link) => {
                const targetId = link.getAttribute('data-scrollspy-target');
                const el = targetId ? document.getElementById(targetId) : null;
                return el ? { link, el } : null;
            })
            .filter(Boolean);

        let ticking = false;
        const updateScrollSpy = () => {
            ticking = false;
            const scrollY = window.scrollY || window.pageYOffset;
            const viewportH = window.innerHeight;
            const docRect = articleEl.getBoundingClientRect();
            const docTop = docRect.top + scrollY;
            const docHeight = Math.max(1, articleEl.offsetHeight);

            const rawPct = Math.round(((scrollY + viewportH * 0.35 - docTop) / docHeight) * 100);
            const pct = Math.max(0, Math.min(100, rawPct));

            if (topProgressBar) topProgressBar.style.width = `${pct}%`;
            if (tocReadBar) tocReadBar.style.width = `${pct}%`;
            if (tocReadPct) tocReadPct.textContent = `${pct}% lu`;

            if (trackedTargets.length === 0) return;

            const threshold = scrollY + 135;
            let activeItem = trackedTargets[0];
            for (const item of trackedTargets) {
                const top = item.el.getBoundingClientRect().top + scrollY;
                if (top <= threshold) {
                    activeItem = item;
                } else {
                    break;
                }
            }

            spyLinks.forEach((l) => l.classList.remove('active'));
            if (activeItem && activeItem.link) {
                activeItem.link.classList.add('active');
                const navRect = tocNav.getBoundingClientRect();
                const linkRect = activeItem.link.getBoundingClientRect();
                if (linkRect.top < navRect.top + 12 || linkRect.bottom > navRect.bottom - 12) {
                    tocNav.scrollTop += (linkRect.top - navRect.top) - tocNav.clientHeight / 2;
                }
            }
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateScrollSpy);
                ticking = true;
            }
        }, { passive: true });

        updateScrollSpy();
    }

    // ------------------------------------------------------------------------
    // 5. Moteur Diaporama Interactif 16:9 (Slide Deck Player)
    // ------------------------------------------------------------------------
    const slideDeckWrapper = document.getElementById('lms-slide-deck-wrapper');
    if (slideDeckWrapper) {
        const slides = Array.from(slideDeckWrapper.querySelectorAll('.lms-slide-frame'));
        const outlineItems = Array.from(slideDeckWrapper.querySelectorAll('.slide-outline-item'));
        const prevBtn = document.getElementById('btn-slide-prev');
        const nextBtn = document.getElementById('btn-slide-next');
        const jumpSelect = document.getElementById('slide-jump-select');
        const fullscreenBtn = document.getElementById('btn-slide-fullscreen');
        const progressFill = document.getElementById('slide-progress-fill');
        const sidebarCounter = document.getElementById('slide-sidebar-counter');
        const btnEngineHtml = document.getElementById('btn-slide-engine-html');
        const btnEnginePdf = document.getElementById('btn-slide-engine-pdf');
        const stageHtml = document.getElementById('slide-stage-html');
        const stagePdf = document.getElementById('slide-stage-pdf');
        const htmlControls = document.getElementById('slide-html-controls');

        let currentSlide = 0;
        const totalSlides = slides.length;

        const goToSlide = (index) => {
            if (totalSlides === 0) return;
            currentSlide = Math.max(0, Math.min(totalSlides - 1, index));

            slides.forEach((s, i) => {
                s.classList.toggle('active', i === currentSlide);
            });

            outlineItems.forEach((item, i) => {
                const isActive = i === currentSlide;
                item.classList.toggle('active', isActive);
                if (isActive) {
                    const listBox = item.closest('.toc-box');
                    if (listBox) {
                        listBox.scrollTop = Math.max(0, item.offsetTop - listBox.clientHeight / 2 + 20);
                    }
                }
            });

            if (jumpSelect) jumpSelect.value = String(currentSlide);
            if (sidebarCounter) sidebarCounter.textContent = `${currentSlide + 1} / ${totalSlides}`;
            if (progressFill) {
                const pct = ((currentSlide + 1) / totalSlides) * 100;
                progressFill.style.width = `${pct.toFixed(1)}%`;
            }
            if (prevBtn) prevBtn.disabled = currentSlide === 0;
            if (nextBtn) nextBtn.disabled = currentSlide === totalSlides - 1;
        };

        if (prevBtn) prevBtn.addEventListener('click', () => goToSlide(currentSlide - 1));
        if (nextBtn) nextBtn.addEventListener('click', () => goToSlide(currentSlide + 1));
        if (jumpSelect) {
            jumpSelect.addEventListener('change', () => {
                goToSlide(parseInt(jumpSelect.value, 10) || 0);
            });
        }
        outlineItems.forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.getAttribute('data-slide-jump') || '0', 10);
                if (stagePdf && !stagePdf.hidden && btnEngineHtml) {
                    btnEngineHtml.click();
                }
                goToSlide(idx);
            });
        });

        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', () => {
                if (!document.fullscreenElement) {
                    slideDeckWrapper.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            });
        }

        if (btnEngineHtml && btnEnginePdf && stageHtml && stagePdf) {
            btnEngineHtml.addEventListener('click', () => {
                stageHtml.hidden = false;
                stagePdf.hidden = true;
                if (htmlControls) htmlControls.style.display = 'flex';
                btnEngineHtml.className = 'btn btn-sm btn-primary';
                btnEnginePdf.className = 'btn btn-sm btn-outline';
            });
            btnEnginePdf.addEventListener('click', () => {
                stageHtml.hidden = true;
                stagePdf.hidden = false;
                if (htmlControls) htmlControls.style.display = 'none';
                btnEnginePdf.className = 'btn btn-sm btn-primary';
                btnEngineHtml.className = 'btn btn-sm btn-outline';
            });
        }

        // Interaction Quiz à l'intérieur des slides
        slideDeckWrapper.querySelectorAll('.slide-quiz-opt').forEach((opt) => {
            opt.addEventListener('click', () => {
                const card = opt.closest('.slide-quiz-card');
                if (!card) return;
                const correct = card.getAttribute('data-correct');
                const chosen = opt.getAttribute('data-letter');
                card.querySelectorAll('.slide-quiz-opt').forEach((o) => {
                    const l = o.getAttribute('data-letter');
                    o.classList.remove('revealed-correct', 'revealed-wrong');
                    if (l === correct) o.classList.add('revealed-correct');
                    else if (l === chosen) o.classList.add('revealed-wrong');
                });
                const ansBox = card.querySelector('.slide-quiz-answer-box');
                if (ansBox) ansBox.hidden = false;
            });
        });

        slideDeckWrapper.querySelectorAll('.btn-reveal-slide-quiz').forEach((btn) => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.slide-quiz-card');
                if (!card) return;
                const correct = card.getAttribute('data-correct');
                card.querySelectorAll('.slide-quiz-opt').forEach((o) => {
                    if (o.getAttribute('data-letter') === correct) {
                        o.classList.add('revealed-correct');
                    }
                });
                const ansBox = card.querySelector('.slide-quiz-answer-box');
                if (ansBox) ansBox.hidden = false;
            });
        });

        // Navigation clavier (← / → / Espace / F)
        document.addEventListener('keydown', (e) => {
            const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (lightbox && !lightbox.hidden) {
                if (e.key === 'Escape') closeLightbox();
                return;
            }
            if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
                e.preventDefault();
                goToSlide(currentSlide + 1);
            } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                e.preventDefault();
                goToSlide(currentSlide - 1);
            } else if (e.key.toLowerCase() === 'f' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                if (fullscreenBtn) fullscreenBtn.click();
            }
        });

        goToSlide(0);
    }

    // Fermer la lightbox avec Échap hors mode slides
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && lightbox && !lightbox.hidden) {
            closeLightbox();
        }
    });

    // ------------------------------------------------------------------------
    // 6. Raccourci Recherche Globale Ctrl+K
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 7. Copie de code 1-clic & Exécution SQL directe dans le Lab
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 8. Marquer un Module ou un Lab comme terminé
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 9. Sauvegarde des notes personnelles de l'apprenant
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 10. Quiz QCM Interactif Auto-Corrigé
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 11. Pilotage des Conteneurs Docker par Utilisateur (Start / Stop / Reset)
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 12. Console SQL Interactive & Oracle Qualiopi 1-Clic
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 13. Boutons Chaos Engineering DBA (Admin / Formateur)
    // ------------------------------------------------------------------------
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

    // ------------------------------------------------------------------------
    // 14. Rafraîchissement Asynchrone Non-Bloquant du Statut Docker (1 fois / 60s)
    // ------------------------------------------------------------------------
    const lastDockerCheck = parseInt(sessionStorage.getItem('lp_docker_checked_at') || '0', 10);
    if (csrfToken && (Date.now() - lastDockerCheck > 60000)) {
        sessionStorage.setItem('lp_docker_checked_at', String(Date.now()));
        fetch('index.php?action=api_docker_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ csrf_token: csrfToken })
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.status) return;
                const st = data.status;
                const dot = document.getElementById('topbar-docker-dot');
                const txt = document.getElementById('topbar-docker-text');
                if (dot) {
                    dot.className = 'status-dot ' + (st.running ? 'online' : 'offline');
                }
                if (txt) {
                    txt.textContent = `🐳 ${st.container_name} (Port ${st.port} : ${st.running ? 'ACTIF' : 'PRÊT'})`;
                }
            })
            .catch(() => {});
    }
});
