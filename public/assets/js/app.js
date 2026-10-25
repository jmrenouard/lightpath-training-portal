/**
 * LightPath Academy LMS — Client-Side Interactive Engine (Vanilla ES2022, Zero CDN)
 * Inclut : ScrollSpy temps réel, Lecteur Diaporama 16:9, Lightbox Schémas, Mermaid local & Console SQL Docker
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    // ------------------------------------------------------------------------
    // 1. Bascule Thème Sombre / Clair & Logos Adaptatifs (Dark / Light)
    // ------------------------------------------------------------------------
    const themeBtn = document.getElementById('btn-toggle-theme');
    const syncThemeLogos = () => {
        const isLight = document.body.classList.contains('theme-light');
        document.querySelectorAll('.theme-aware-logo').forEach((img) => {
            const targetSrc = isLight
                ? img.getAttribute('data-light-src')
                : img.getAttribute('data-dark-src');
            if (targetSrc && img.getAttribute('src') !== targetSrc) {
                img.setAttribute('src', targetSrc);
            }
        });
    };

    if (localStorage.getItem('lp_lms_theme') === 'light') {
        document.body.classList.add('theme-light');
    }
    syncThemeLogos();

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            document.body.classList.toggle('theme-light');
            const isLight = document.body.classList.contains('theme-light');
            localStorage.setItem('lp_lms_theme', isLight ? 'light' : 'dark');
            syncThemeLogos();
        });
    }

    // ------------------------------------------------------------------------
    // 1b. Filtre Rapide & Centrage du Module Actif dans la Barre Latérale Gauche
    // ------------------------------------------------------------------------
    const sidebarFilterInput = document.getElementById('sidebar-module-filter');
    if (sidebarFilterInput) {
        sidebarFilterInput.addEventListener('input', () => {
            const q = sidebarFilterInput.value.trim().toLowerCase();
            document.querySelectorAll('.sidebar-track-group').forEach((group) => {
                let visibleCount = 0;
                group.querySelectorAll('.sidebar-module-link').forEach((link) => {
                    const hay = (link.getAttribute('data-search-text') || '').toLowerCase();
                    const match = q === '' || hay.includes(q);
                    link.style.display = match ? '' : 'none';
                    if (match) visibleCount++;
                });
                if (q !== '') {
                    group.open = visibleCount > 0;
                }
            });
        });
    }

    const activeSidebarMod = document.querySelector('.sidebar-module-link.active');
    if (activeSidebarMod) {
        const sidebarNav = activeSidebarMod.closest('.sidebar-nav');
        if (sidebarNav) {
            sidebarNav.scrollTop = Math.max(0, activeSidebarMod.offsetTop - sidebarNav.clientHeight / 2 + 24);
        }
    }

    // ------------------------------------------------------------------------
    // 2. Chargement Paresseux (Lazy) de Mermaid Vectoriel Local (uniquement si requis)
    // ------------------------------------------------------------------------
    if (document.querySelectorAll('.mermaid').length > 0) {
        const initMermaid = () => {
            if (!window.mermaid) return;
            try {
                window.mermaid.initialize({
                    startOnLoad: true,
                    theme: 'neutral',
                    securityLevel: 'strict',
                    fontFamily: 'Plus Jakarta Sans, Open Sans, sans-serif'
                });
                if (typeof window.mermaid.run === 'function') {
                    window.mermaid.run();
                }
            } catch (_) {}
        };
        if (window.mermaid) {
            initMermaid();
        } else {
            const s = document.createElement('script');
            s.src = 'assets/js/mermaid.min.js';
            s.onload = initMermaid;
            document.body.appendChild(s);
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
    // 4b. Réduction / Extension des Barres Verticales Gauche & Droite (RCA-LMS-14)
    // ------------------------------------------------------------------------
    const btnToggleLeft = document.getElementById('btn-toggle-left-sidebar');
    const btnCollapseLeftInline = document.getElementById('btn-collapse-left-inline');
    const labelToggleLeft = document.getElementById('label-toggle-left-sidebar');
    const btnToggleRight = document.getElementById('btn-toggle-right-sidebar');
    const labelToggleRight = document.getElementById('label-toggle-right-sidebar');
    const btnFloatingRestoreRight = document.getElementById('btn-floating-restore-right');
    const rightPanelToggleBtns = document.querySelectorAll('.btn-toggle-right-panel');

    const applyLeftSidebarState = (collapsed) => {
        document.body.classList.toggle('left-sidebar-collapsed', collapsed);
        if (labelToggleLeft) {
            labelToggleLeft.textContent = collapsed ? 'Afficher Menu Gauche' : 'Menu Gauche';
        }
        if (btnToggleLeft) {
            btnToggleLeft.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    };

    const applyRightSidebarState = (collapsed) => {
        document.body.classList.toggle('right-sidebar-collapsed', collapsed);
        if (labelToggleRight) {
            labelToggleRight.textContent = collapsed ? 'Afficher Menu Droite' : 'Menu Droite';
        }
        if (btnToggleRight) {
            btnToggleRight.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    };

    const savedLeftCollapsed = localStorage.getItem('lp_lms_left_sidebar') === 'collapsed';
    const savedRightCollapsed = localStorage.getItem('lp_lms_right_sidebar') === 'collapsed';
    applyLeftSidebarState(savedLeftCollapsed);
    applyRightSidebarState(savedRightCollapsed);

    const toggleLeftSidebar = () => {
        const nextState = !document.body.classList.contains('left-sidebar-collapsed');
        applyLeftSidebarState(nextState);
        localStorage.setItem('lp_lms_left_sidebar', nextState ? 'collapsed' : 'expanded');
    };

    const toggleRightSidebar = () => {
        const nextState = !document.body.classList.contains('right-sidebar-collapsed');
        applyRightSidebarState(nextState);
        localStorage.setItem('lp_lms_right_sidebar', nextState ? 'collapsed' : 'expanded');
    };

    if (btnToggleLeft) btnToggleLeft.addEventListener('click', toggleLeftSidebar);
    if (btnCollapseLeftInline) btnCollapseLeftInline.addEventListener('click', toggleLeftSidebar);
    if (btnToggleRight) btnToggleRight.addEventListener('click', toggleRightSidebar);
    if (btnFloatingRestoreRight) {
        btnFloatingRestoreRight.addEventListener('click', () => {
            applyRightSidebarState(false);
            localStorage.setItem('lp_lms_right_sidebar', 'expanded');
        });
    }
    rightPanelToggleBtns.forEach((btn) => {
        btn.addEventListener('click', toggleRightSidebar);
    });

    // Raccourcis clavier Alt+G (barre gauche) et Alt+D (barre droite)
    document.addEventListener('keydown', (e) => {
        if (e.altKey && !e.ctrlKey && !e.metaKey) {
            const k = e.key.toLowerCase();
            if (k === 'g') {
                e.preventDefault();
                toggleLeftSidebar();
            } else if (k === 'd') {
                e.preventDefault();
                toggleRightSidebar();
            }
        }
    });

    // ------------------------------------------------------------------------
    // 4c. Bouton Afficher / Masquer les Solutions dans les Ateliers (RCA-LMS-15)
    // ------------------------------------------------------------------------
    const btnToggleLabSolutions = document.getElementById('btn-toggle-lab-solutions');
    if (btnToggleLabSolutions) {
        btnToggleLabSolutions.addEventListener('click', () => {
            const article = document.getElementById('reader-article-content');
            const detailsList = article ? Array.from(article.querySelectorAll('details.lms-details')) : [];
            if (detailsList.length === 0) {
                // Si aucun bloc repliable n'est présent dans l'énoncé courant, basculer vers l'onglet Corrigé Complet
                const corrigeTab = document.querySelector('.view-mode-tab[href*="mode=corrige"]');
                if (corrigeTab) {
                    window.location.href = corrigeTab.getAttribute('href');
                }
                return;
            }
            const anyClosed = detailsList.some((d) => !d.open);
            detailsList.forEach((d) => {
                d.open = anyClosed;
            });
            btnToggleLabSolutions.textContent = anyClosed
                ? '🙈 Masquer les Solutions'
                : '👁️ Afficher / Masquer les Solutions';
        });
    }

    // ------------------------------------------------------------------------
    // 5. Moteur Diaporama Interactif 16:9 (Slide Deck Player — RCA-LMS-11 & 12)
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

        const ensureSlideImageLoaded = (idx) => {
            if (idx < 0 || idx >= totalSlides) return;
            const frame = slides[idx];
            if (!frame) return;
            const img = frame.querySelector('img[data-slide-src]');
            if (img) {
                const targetSrc = img.getAttribute('data-slide-src');
                if (targetSrc && img.getAttribute('src') !== targetSrc) {
                    img.setAttribute('src', targetSrc);
                }
            }
        };

        const switchSlideEngine = (mode) => {
            if (!stageHtml || !stagePdf) return;
            if (mode === 'pdf') {
                stageHtml.hidden = true;
                stageHtml.style.display = 'none';
                stagePdf.hidden = false;
                stagePdf.style.display = 'block';
                if (htmlControls) htmlControls.style.display = 'none';
                if (btnEnginePdf) {
                    btnEnginePdf.className = 'btn btn-sm btn-primary';
                    btnEnginePdf.setAttribute('aria-pressed', 'true');
                }
                if (btnEngineHtml) {
                    btnEngineHtml.className = 'btn btn-sm btn-outline';
                    btnEngineHtml.setAttribute('aria-pressed', 'false');
                }
                const pdfIframe = document.getElementById('slide-pdf-iframe');
                if (pdfIframe) {
                    const dataSrc = pdfIframe.getAttribute('data-src');
                    if (dataSrc) {
                        const pageHash = `#page=${currentSlide + 1}`;
                        const targetUrl = dataSrc + pageHash;
                        if (pdfIframe.getAttribute('src') !== targetUrl) {
                            pdfIframe.setAttribute('src', targetUrl);
                        }
                    }
                }
            } else {
                stagePdf.hidden = true;
                stagePdf.style.display = 'none';
                stageHtml.hidden = false;
                stageHtml.style.display = 'flex';
                if (htmlControls) htmlControls.style.display = 'flex';
                if (btnEngineHtml) {
                    btnEngineHtml.className = 'btn btn-sm btn-primary';
                    btnEngineHtml.setAttribute('aria-pressed', 'true');
                }
                if (btnEnginePdf) {
                    btnEnginePdf.className = 'btn btn-sm btn-outline';
                    btnEnginePdf.setAttribute('aria-pressed', 'false');
                }
            }
        };

        const goToSlide = (index) => {
            if (totalSlides === 0) return;
            currentSlide = Math.max(0, Math.min(totalSlides - 1, index));

            // Charger immédiatement la diapositive courante et pré-charger la suivante/précédente
            ensureSlideImageLoaded(currentSlide);
            ensureSlideImageLoaded(currentSlide + 1);
            ensureSlideImageLoaded(currentSlide - 1);

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
        slideDeckWrapper.querySelectorAll('.slide-canvas-prev').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                goToSlide(currentSlide - 1);
            });
        });
        slideDeckWrapper.querySelectorAll('.slide-canvas-next').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                goToSlide(currentSlide + 1);
            });
        });

        if (jumpSelect) {
            jumpSelect.addEventListener('change', () => {
                const idx = parseInt(jumpSelect.value, 10) || 0;
                if (stagePdf && !stagePdf.hidden) {
                    switchSlideEngine('html');
                }
                goToSlide(idx);
            });
        }
        outlineItems.forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.getAttribute('data-slide-jump') || '0', 10);
                if (stagePdf && !stagePdf.hidden) {
                    switchSlideEngine('html');
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
            btnEngineHtml.addEventListener('click', () => switchSlideEngine('html'));
            btnEnginePdf.addEventListener('click', () => switchSlideEngine('pdf'));
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
            } else if (e.key.toLowerCase() === 'f' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                if (fullscreenBtn) fullscreenBtn.click();
            }
        });

        // Support d'ancre #slide-N au chargement
        let initialSlideIdx = 0;
        const hashMatch = window.location.hash.match(/^#slide-(\d+)$/i);
        if (hashMatch) {
            initialSlideIdx = Math.max(0, parseInt(hashMatch[1], 10) - 1);
        }
        goToSlide(initialSlideIdx);
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
    // 14. Rafraîchissement Manuel du Statut Docker (uniquement sur clic explicite, zéro blocage navigation)
    // ------------------------------------------------------------------------
    const refreshDockerBtn = document.getElementById('btn-refresh-docker');
    if (refreshDockerBtn && csrfToken) {
        refreshDockerBtn.addEventListener('click', () => {
            refreshDockerBtn.disabled = true;
            fetch('index.php?action=api_docker_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ csrf_token: csrfToken })
            })
                .then((r) => r.json())
                .then((data) => {
                    refreshDockerBtn.disabled = false;
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
                .catch(() => {
                    refreshDockerBtn.disabled = false;
                });
        });
    }
});
