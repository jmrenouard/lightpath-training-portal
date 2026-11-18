/**
 * LightPath Academy LMS — Client-Side Interactive Engine (Vanilla ES2022, Zero CDN)
 * Includes: real-time ScrollSpy, 16:9 Slide Deck player, Diagram Lightbox, local Mermaid & Docker SQL console.
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const courseMeta = document.querySelector('meta[name="current-course"]');
    const currentCourse = (courseMeta ? courseMeta.getAttribute('content') : '')
        || new URLSearchParams(window.location.search).get('course')
        || (document.getElementById('course-switcher') ? document.getElementById('course-switcher').value : '')
        || 'postgresql-18';

    const buildApiUrl = (action, courseSlug = currentCourse) =>
        `index.php?action=${encodeURIComponent(action)}&course=${encodeURIComponent(courseSlug || currentCourse)}`;

    const postApiJson = (action, payload = {}, courseSlug = currentCourse) => fetch(buildApiUrl(action, courseSlug), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ csrf_token: csrfToken, ...payload })
    });

    // Hydrate dynamic progress widths and track accent variables via CSSOM (strict CSP style-src 'self' compliant)
    document.querySelectorAll('[data-progress]').forEach((el) => {
        const raw = parseFloat(el.getAttribute('data-progress') || '0');
        if (Number.isFinite(raw)) {
            const clamped = Math.max(0, Math.min(100, raw));
            el.style.width = `${clamped}%`;
        }
    });
    document.querySelectorAll('[data-accent]').forEach((el) => {
        const color = (el.getAttribute('data-accent') || '').trim();
        if (/^#[0-9a-fA-F]{3,8}$/.test(color) || /^rgb\(/i.test(color)) {
            el.style.setProperty('--track-accent', color);
            el.style.setProperty('--pill-accent', color);
        }
    });

    // Demo account quick-login buttons (delegated cleanly from login.php)
    const demoEmailInput = document.getElementById('login-email');
    const demoPassInput = document.getElementById('login-password');
    const demoLoginForm = document.getElementById('login-form');
    document.querySelectorAll('[data-demo-email]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (demoEmailInput && demoPassInput && demoLoginForm) {
                demoEmailInput.value = btn.getAttribute('data-demo-email') || '';
                demoPassInput.value = btn.getAttribute('data-demo-pass') || '';
                demoLoginForm.submit();
            } else {
                window.location.href = 'index.php?page=login&tab=login';
            }
        });
    });

    // ------------------------------------------------------------------------
    // 1. Dark / Light Theme Toggle & Adaptive Logos
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
    // 1b. Quick Sidebar Module Filter & Auto-Centering of Active Module
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

    const centerElementInScrollBox = (box, el) => {
        if (!box || !el) return;
        const boxRect = box.getBoundingClientRect();
        const elRect = el.getBoundingClientRect();
        if (boxRect.height <= 0) return;
        const delta = (elRect.top - boxRect.top) - (box.clientHeight / 2) + (elRect.height / 2);
        box.scrollTop = Math.max(0, box.scrollTop + delta);
    };

    const activeSidebarMod = document.querySelector('.sidebar-module-link.active');
    if (activeSidebarMod) {
        const trackModsBox = activeSidebarMod.closest('.sidebar-track-modules');
        if (trackModsBox) {
            centerElementInScrollBox(trackModsBox, activeSidebarMod);
        }
        const appSidebar = activeSidebarMod.closest('.app-sidebar');
        if (appSidebar) {
            centerElementInScrollBox(appSidebar, activeSidebarMod);
        }
    }

    // ------------------------------------------------------------------------
    // 2. Lazy Loading of Local Vector Mermaid Renderer (only when required)
    // ------------------------------------------------------------------------
    let mermaidInitialized = false;
    const initMermaid = () => {
        if (!window.mermaid) return;
        try {
            if (!mermaidInitialized) {
                window.mermaid.initialize({
                    startOnLoad: false,
                    theme: 'neutral',
                    securityLevel: 'strict',
                    fontFamily: 'Plus Jakarta Sans, Open Sans, sans-serif'
                });
                mermaidInitialized = true;
            }
            const visibleNodes = Array.from(document.querySelectorAll('.mermaid:not([data-processed])'))
                .filter((el) => el.offsetParent !== null);
            if (visibleNodes.length > 0 && typeof window.mermaid.run === 'function') {
                window.mermaid.run({ nodes: visibleNodes });
            }
        } catch (_) {}
    };

    if (document.querySelectorAll('.mermaid').length > 0) {
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
    // 3. Full-Screen Lightbox for Mermaid Diagrams & Studio Infographics
    // ------------------------------------------------------------------------
    const lightbox = document.getElementById('diagram-lightbox');
    const lightboxImg = document.getElementById('diagram-lightbox-img');
    const lightboxCaption = document.getElementById('diagram-lightbox-caption');
    const closeLightboxBtn = document.getElementById('btn-close-lightbox');
    const defaultLightboxCaption = currentCourse === 'postgresql-18'
        ? 'Schéma d\'Architecture PostgreSQL 18'
        : `Schéma Technique — ${currentCourse}`;

    const openLightbox = (src, captionText) => {
        if (!lightbox || !lightboxImg || !src) return;
        lightboxImg.src = src;
        if (lightboxCaption) {
            lightboxCaption.textContent = captionText || defaultLightboxCaption;
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
    // 4. Real-Time Table of Contents ScrollSpy & Reading Progress Bar
    // ------------------------------------------------------------------------
    const articleEl = document.getElementById('reader-article-content');
    const tocNav = document.getElementById('toc-scrollspy-nav');
    const topProgressBar = document.getElementById('reading-progress-bar');
    const tocReadBar = document.getElementById('toc-read-bar');
    const tocReadPct = document.getElementById('toc-read-pct');

    // Auto-center active step inside the track stepper on load and <details> toggle
    const currentStepperItem = document.querySelector('.curriculum-stepper-list .stepper-item.is-current');
    if (currentStepperItem) {
        const stepperContainer = currentStepperItem.closest('.curriculum-stepper-list');
        if (stepperContainer) {
            centerElementInScrollBox(stepperContainer, currentStepperItem);
            const parentDetails = stepperContainer.closest('details');
            if (parentDetails) {
                parentDetails.addEventListener('toggle', () => {
                    if (parentDetails.open) {
                        centerElementInScrollBox(stepperContainer, currentStepperItem);
                    }
                });
            }
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
    // 4b. Collapsible Left & Right Vertical Sidebars
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

    const savedLeftPref = localStorage.getItem('lp_lms_left_sidebar');
    const savedLeftCollapsed = savedLeftPref !== null ? (savedLeftPref === 'collapsed') : (window.innerWidth <= 1024);
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

    // Keyboard shortcuts Alt+G (left sidebar) and Alt+D (right sidebar)
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
    // 4c. Show / Hide Solutions Toggle in Practice Labs
    // ------------------------------------------------------------------------
    const btnToggleLabSolutions = document.getElementById('btn-toggle-lab-solutions');
    if (btnToggleLabSolutions) {
        btnToggleLabSolutions.addEventListener('click', () => {
            const article = document.getElementById('reader-article-content');
            const detailsList = article ? Array.from(article.querySelectorAll('details.lms-details')) : [];
            if (detailsList.length === 0) {
                // If no collapsible solution block exists in the current statement, switch to Solution tab
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
    // 5. Interactive 16:9 Slide Deck Player Engine
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
                        const pageNum = currentSlide + 1;
                        const sep = dataSrc.includes('?') ? '&' : '?';
                        const targetUrl = `${dataSrc}${sep}slide_page=${pageNum}#page=${pageNum}`;
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

            // Immediately load current slide and preload adjacent slides
            ensureSlideImageLoaded(currentSlide);
            ensureSlideImageLoaded(currentSlide + 1);
            ensureSlideImageLoaded(currentSlide - 1);

            slides.forEach((s, i) => {
                s.classList.toggle('active', i === currentSlide);
            });
            initMermaid();

            outlineItems.forEach((item, i) => {
                const isActive = i === currentSlide;
                item.classList.toggle('active', isActive);
                if (isActive) {
                    const listBox = item.closest('.slide-outline-box') || item.closest('.toc-box');
                    if (listBox) {
                        centerElementInScrollBox(listBox, item);
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
                if (stageHtml && !document.fullscreenElement) {
                    const rect = stageHtml.getBoundingClientRect();
                    if (rect.top < 56 || rect.top > window.innerHeight * 0.45) {
                        const targetY = Math.max(0, (window.scrollY || window.pageYOffset) + rect.top - 76);
                        window.scrollTo({ top: targetY, behavior: 'smooth' });
                    }
                }
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

        // Quiz interaction inside slide deck
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

        // Keyboard navigation (Left / Right / Space / F)
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

        // Anchor #slide-N or query parameter ?slide=N support on initial load
        let initialSlideIdx = 0;
        const urlParams = new URLSearchParams(window.location.search);
        const slideParam = parseInt(urlParams.get('slide') || '0', 10);
        if (slideParam >= 1) {
            initialSlideIdx = slideParam - 1;
        } else {
            const hashMatch = window.location.hash.match(/^#slide-(\d+)$/i);
            if (hashMatch) {
                initialSlideIdx = Math.max(0, parseInt(hashMatch[1], 10) - 1);
            }
        }
        goToSlide(initialSlideIdx);
    }

    // Close lightbox with Escape outside slide deck mode
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && lightbox && !lightbox.hidden) {
            closeLightbox();
        }
    });

    // ------------------------------------------------------------------------
    // 6. Global Quick Search Shortcut (Ctrl+K / Cmd+K)
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
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const q = searchInput.value.trim();
                if (q !== '') {
                    e.preventDefault();
                    window.location.href = `index.php?page=search&q=${encodeURIComponent(q)}&course=${encodeURIComponent(currentCourse)}`;
                }
            }
        });
    }

    // ------------------------------------------------------------------------
    // 7. One-Click Code Copy & Direct SQL Lab Execution (UTF-8 safe Base64 decode)
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
            let sql = '';
            try {
                const binary = atob(b64);
                const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
                sql = new TextDecoder('utf-8').decode(bytes);
            } catch (_) {
                sql = atob(b64);
            }
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
    // 8. Toggle Module or Lab Completion State
    // ------------------------------------------------------------------------
    document.querySelectorAll('.btn-toggle-complete').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const itemType = btn.getAttribute('data-type');
            const itemId = btn.getAttribute('data-id');
            const course = btn.getAttribute('data-course') || 'postgresql-18';
            const currentlyDone = btn.getAttribute('data-completed') === '1';

            btn.disabled = true;
            try {
                const res = await postApiJson('api_toggle_complete', {
                    item_type: itemType,
                    item_id: itemId,
                    completed: !currentlyDone
                }, course);
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
    // 9. Personal Study Notes Saving
    // ------------------------------------------------------------------------
    const saveNoteBtn = document.getElementById('btn-save-note');
    if (saveNoteBtn) {
        saveNoteBtn.addEventListener('click', async () => {
            const itemId = saveNoteBtn.getAttribute('data-id');
            const course = saveNoteBtn.getAttribute('data-course') || currentCourse;
            const noteArea = document.getElementById('user-personal-note');
            const statusSpan = document.getElementById('note-save-status');
            if (!noteArea) return;

            saveNoteBtn.disabled = true;
            const res = await postApiJson('api_save_note', {
                item_id: itemId,
                note: noteArea.value
            }, course);
            const data = await res.json();
            saveNoteBtn.disabled = false;
            if (data.success && statusSpan) {
                statusSpan.innerText = `✔ Sauvegardé à ${data.saved_at}`;
            }
        });
    }

    // ------------------------------------------------------------------------
    // 10. Interactive Self-Graded QCM Quiz
    // ------------------------------------------------------------------------
    const quizForm = document.getElementById('interactive-quiz-form');
    if (quizForm) {
        quizForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const moduleId = quizForm.getAttribute('data-module-id');
            const course = quizForm.getAttribute('data-course') || currentCourse;
            const questions = quizForm.querySelectorAll('.quiz-question-box');
            let score = 0;
            const total = questions.length;

            const answers = {};
            questions.forEach((qBox, idx) => {
                const correct = qBox.getAttribute('data-correct');
                const selected = qBox.querySelector('input[type="radio"]:checked');
                const feedback = qBox.querySelector('.quiz-feedback');
                const chosen = selected ? selected.value : '';
                const qIndex = idx + 1;
                if (chosen) {
                    answers[`q_${qIndex}`] = chosen;
                }

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

            const res = await postApiJson('api_submit_quiz', {
                module_id: moduleId,
                score: score,
                total: total,
                answers: answers
            }, course);
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
    // 11. Per-User Docker Container Lifecycle Controls (Start / Stop / Reset / Restart)
    // ------------------------------------------------------------------------
    document.querySelectorAll('.btn-docker-cmd').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const cmd = btn.getAttribute('data-cmd');
            const confirmMsg = btn.getAttribute('data-confirm');
            if ((confirmMsg || cmd === 'reset') && !window.confirm(confirmMsg || 'Réinitialiser le conteneur Docker à l\'état initial ?')) {
                return;
            }
            const targetUserId = btn.getAttribute('data-user-id') || '';
            const statusLog = document.getElementById('docker-action-feedback');

            btn.disabled = true;
            const origText = btn.innerText;
            btn.innerText = '⏳ Opération Docker en cours...';
            if (statusLog) {
                statusLog.hidden = false;
                statusLog.style.display = 'block';
                statusLog.innerText = `⏳ Exécution de la commande Docker (${cmd}) en cours...`;
            }

            try {
                const res = await postApiJson('api_docker_action', {
                    docker_cmd: cmd,
                    target_user_id: targetUserId ? parseInt(targetUserId, 10) : undefined
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
    // 12. Interactive SQL Console, Database Selector, Schema Explorer, EXPLAIN & Oracles
    // ------------------------------------------------------------------------
    const getSelectedLabDb = () => {
        const sel = document.getElementById('lab-sql-db-select');
        return sel ? sel.value : 'formation_pg';
    };

    const sqlHistorySelect = document.getElementById('lab-sql-history-select');
    const loadSqlHistory = () => {
        try {
            return JSON.parse(localStorage.getItem('lp_lms_sql_history') || '[]');
        } catch (_) {
            return [];
        }
    };
    const refreshSqlHistoryDropdown = () => {
        if (!sqlHistorySelect) return;
        const list = loadSqlHistory();
        sqlHistorySelect.innerHTML = '<option value="">— Requêtes récentes (' + list.length + ') —</option>';
        list.forEach((q, idx) => {
            const opt = document.createElement('option');
            opt.value = String(idx);
            const clean = q.replace(/\s+/g, ' ').trim();
            opt.textContent = (idx + 1) + '. ' + (clean.length > 52 ? clean.slice(0, 52) + '…' : clean);
            sqlHistorySelect.appendChild(opt);
        });
    };
    const pushSqlHistory = (sqlText) => {
        const trimmed = (sqlText || '').trim();
        if (!trimmed) return;
        const list = loadSqlHistory().filter((item) => item !== trimmed);
        list.unshift(trimmed);
        localStorage.setItem('lp_lms_sql_history', JSON.stringify(list.slice(0, 10)));
        refreshSqlHistoryDropdown();
    };
    if (sqlHistorySelect) {
        refreshSqlHistoryDropdown();
        sqlHistorySelect.addEventListener('change', () => {
            const idx = parseInt(sqlHistorySelect.value, 10);
            const list = loadSqlHistory();
            const editor = document.getElementById('lab-sql-editor');
            if (!Number.isNaN(idx) && list[idx] && editor) {
                editor.value = list[idx];
            }
        });
    }

    const execSqlBtn = document.getElementById('btn-execute-sql');
    const labSqlEditor = document.getElementById('lab-sql-editor');
    if (labSqlEditor && execSqlBtn) {
        labSqlEditor.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                execSqlBtn.click();
            }
        });
    }
    if (execSqlBtn) {
        execSqlBtn.addEventListener('click', async () => {
            const editor = document.getElementById('lab-sql-editor');
            const outBox = document.getElementById('lab-sql-output');
            if (!editor || !outBox) return;

            const db = getSelectedLabDb();
            pushSqlHistory(editor.value);
            execSqlBtn.disabled = true;
            outBox.innerText = `⏳ Exécution SQL sur votre instance PostgreSQL 18 (base : ${db})...`;

            try {
                const res = await postApiJson('api_run_sql', {
                    sql: editor.value,
                    database: db
                });
                const data = await res.json();
                outBox.innerText = `[Instance: ${data.container || 'N/A'} | Base: ${data.database || db} | Durée: ${data.duration_ms || 0} ms]\n\n` + (data.output || '');
            } catch (err) {
                outBox.innerText = `❌ Erreur réseau lors de l'exécution SQL : ${err && err.message ? err.message : 'connexion impossible'}`;
            } finally {
                execSqlBtn.disabled = false;
            }
        });
    }

    // Shared helper for secondary Lab Workbench actions (savepoints, pgbench, PG18 diagnostics, readiness)
    const runLabWorkbenchAction = async (btn, loadingMsg, action, payloadBuilder, formatOutput) => {
        const outBox = document.getElementById('lab-sql-output');
        if (!btn || !outBox) return;
        const origText = btn.innerText;
        btn.disabled = true;
        btn.innerText = '⏳ En cours...';
        outBox.innerText = loadingMsg;
        try {
            const payload = payloadBuilder();
            const res = await postApiJson(action, payload);
            const data = await res.json();
            outBox.innerText = formatOutput(data, payload);
        } catch (err) {
            outBox.innerText = `❌ Erreur réseau (${action}) : ${err && err.message ? err.message : 'requête échouée'}`;
        } finally {
            btn.disabled = false;
            btn.innerText = origText;
        }
    };

    const getSanitizedSavepointName = () => {
        const inp = document.getElementById('lab-savepoint-name');
        const raw = inp ? inp.value.trim() : 'checkpoint_1';
        const cleaned = raw.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 32);
        const finalName = cleaned || 'checkpoint_1';
        if (inp) inp.value = finalName;
        return finalName;
    };

    const btnSavepointCreate = document.getElementById('btn-savepoint-create');
    if (btnSavepointCreate) {
        btnSavepointCreate.addEventListener('click', () => {
            const db = getSelectedLabDb();
            const name = getSanitizedSavepointName();
            runLabWorkbenchAction(
                btnSavepointCreate,
                `⏳ Création du savepoint '${name}' sur la base ${db}...`,
                'api_lab_savepoint',
                () => ({ op: 'create', name, db }),
                (data) => {
                    const status = data.success ? '✅ SAVEPOINT CRÉÉ' : '❌ ÉCHEC SAVEPOINT';
                    return `[${status} | Nom: ${data.name || name} | Base: ${data.database || db} | Mode: ${data.mode || 'sandbox'}]\n\n`
                        + [data.message || '', data.output || ''].filter(Boolean).join('\n');
                }
            );
        });
    }

    const btnSavepointRestore = document.getElementById('btn-savepoint-restore');
    if (btnSavepointRestore) {
        btnSavepointRestore.addEventListener('click', () => {
            const db = getSelectedLabDb();
            const name = getSanitizedSavepointName();
            runLabWorkbenchAction(
                btnSavepointRestore,
                `⏳ Restauration du savepoint '${name}' sur la base ${db}...`,
                'api_lab_savepoint',
                () => ({ op: 'restore', name, db }),
                (data) => {
                    const status = data.success ? '✅ SAVEPOINT RESTAURÉ' : '❌ ÉCHEC RESTAURATION';
                    return `[${status} | Nom: ${data.name || name} | Base: ${data.database || db} | Mode: ${data.mode || 'sandbox'}]\n\n`
                        + [data.message || '', data.output || ''].filter(Boolean).join('\n');
                }
            );
        });
    }

    const btnRunPgbench = document.getElementById('btn-run-pgbench');
    if (btnRunPgbench) {
        btnRunPgbench.addEventListener('click', () => {
            const db = getSelectedLabDb();
            runLabWorkbenchAction(
                btnRunPgbench,
                `⏳ Exécution du benchmark borné pgbench (4 clients / 2s) sur ${db}...`,
                'api_lab_pgbench',
                () => ({ clients: 4, duration: 2, select_only: false, db }),
                (data) => {
                    const header = `[BENCHMARK PGBENCH | Base: ${data.database || db} | Clients: ${data.clients ?? 4} | Durée: ${data.duration_seconds ?? 2}s | Mode: ${data.mode || 'sandbox'}]`;
                    const metrics = (data.tps !== undefined)
                        ? `Débit : ${data.tps} TPS | Latence moyenne : ${data.latency_avg_ms ?? 'N/A'} ms | Transactions : ${data.transactions ?? 'N/A'}`
                        : '';
                    return [header, metrics, data.message || '', data.output || ''].filter(Boolean).join('\n\n');
                }
            );
        });
    }

    const btnPgDiag = document.getElementById('btn-pg-diag');
    if (btnPgDiag) {
        btnPgDiag.addEventListener('click', () => {
            const db = getSelectedLabDb();
            runLabWorkbenchAction(
                btnPgDiag,
                `⏳ Collecte de la télémétrie PostgreSQL 18 (pg_stat_activity, pg_settings, cache hit) sur ${db}...`,
                'api_lab_pg_diag',
                () => ({ db }),
                (data) => {
                    const header = `[DIAGNOSTIC LIVE PG18 | Base: ${data.database || db} | Container: ${data.container || 'N/A'} | Mode: ${data.mode || 'sandbox'}]`;
                    if (data.output) {
                        return `${header}\n\n${data.output}`;
                    }
                    const details = [];
                    if (data.server_version) details.push(`Version : ${data.server_version}`);
                    if (data.cache_hit_ratio !== undefined) details.push(`Cache Hit Ratio : ${data.cache_hit_ratio}%`);
                    if (data.settings && typeof data.settings === 'object') {
                        details.push('Paramètres clés :\n' + Object.entries(data.settings).map(([k, v]) => `  • ${k} = ${v}`).join('\n'));
                    }
                    if (data.activity && typeof data.activity === 'object') {
                        const a = data.activity;
                        const actSummary = `Activité backends : ${a.total_backends ?? 0} total (${a.active ?? 0} actif, ${a.idle ?? 0} idle, ${a.idle_in_transaction ?? 0} idle-in-tx, ${a.waiting_locks ?? 0} lock-wait)`;
                        const sessLines = Array.isArray(a.sessions)
                            ? a.sessions.map((s) => `  • PID ${s.pid} [${s.usename}@${s.datname}] ${s.state} : ${(s.query || '').slice(0, 90)}`).join('\n')
                            : '';
                        details.push(sessLines ? `${actSummary}\n${sessLines}` : actSummary);
                    }
                    if (Array.isArray(data.databases)) {
                        details.push('Bases de données :\n' + data.databases.map((d) => `  • ${d.datname} : ${d.size_pretty ?? ''} (hit ${d.cache_hit_ratio ?? 'N/A'}%)`).join('\n'));
                    }
                    if (Array.isArray(data.extensions)) {
                        details.push(`Extensions : ${data.extensions.join(', ')}`);
                    }
                    if (details.length === 0) details.push(JSON.stringify(data, null, 2));
                    return [header, data.message || '', ...details].filter(Boolean).join('\n\n');
                }
            );
        });
    }

    const btnCheckReadiness = document.getElementById('btn-check-readiness');
    if (btnCheckReadiness) {
        btnCheckReadiness.addEventListener('click', () => {
            const labId = btnCheckReadiness.getAttribute('data-lab-id') || '';
            runLabWorkbenchAction(
                btnCheckReadiness,
                `⏳ Vérification des prérequis système, base cible et extensions pour ${labId}...`,
                'api_lab_readiness',
                () => ({ lab_id: labId }),
                (data) => {
                    const status = data.ready ? '✅ PRÊT' : '⚠️ PRÉREQUIS INCOMPLETS';
                    const targetDb = data.recommended_database || data.database || getSelectedLabDb();
                    const header = `[READINESS LAB ${labId} | Statut: ${status} | Base cible: ${targetDb}]`;
                    const checks = Array.isArray(data.checks)
                        ? data.checks.map((c) => {
                            const line = `${c.ok ? '✔' : '✘'} ${c.label || c.name || 'Check'}${c.detail ? ' — ' + c.detail : ''}`;
                            return (!c.ok && c.remediation) ? `${line}\n    → Remédiation : ${c.remediation}` : line;
                        }).join('\n')
                        : '';
                    return [header, checks, data.message || '', data.output || ''].filter(Boolean).join('\n\n');
                }
            );
        });
    }

    const explainSqlBtn = document.getElementById('btn-explain-sql');
    if (explainSqlBtn && execSqlBtn) {
        explainSqlBtn.addEventListener('click', () => {
            const editor = document.getElementById('lab-sql-editor');
            if (!editor) return;
            const current = editor.value.trim();
            if (current && !/^EXPLAIN\b/i.test(current)) {
                editor.value = `EXPLAIN (ANALYZE, BUFFERS)\n${current}`;
            }
            execSqlBtn.click();
        });
    }

    const inspectSchemaBtn = document.getElementById('btn-inspect-schema');
    if (inspectSchemaBtn) {
        inspectSchemaBtn.addEventListener('click', async () => {
            const outBox = document.getElementById('lab-sql-output');
            const db = getSelectedLabDb();
            if (!outBox) return;
            inspectSchemaBtn.disabled = true;
            outBox.innerText = `⏳ Inspection du catalogue relationnel de la base ${db}...`;
            try {
                const res = await postApiJson('api_schema_inspect', { database: db });
                const data = await res.json();
                const tables = Array.isArray(data.tables) ? data.tables : [];
                let lines = [`[EXPLORATEUR DE SCHÉMA — Base : ${data.database || db} | Source : ${data.source || 'catalog'} | ${tables.length} table(s)]`, ''];
                tables.forEach((t) => {
                    lines.push(`▸ ${t.schema}.${t.table} (~${t.est_rows} lignes)`);
                    lines.push(`    Colonnes : ${t.columns}`);
                });
                outBox.innerText = lines.join('\n');
            } finally {
                inspectSchemaBtn.disabled = false;
            }
        });
    }

    const oracleBtn = document.getElementById('btn-run-oracle');
    if (oracleBtn) {
        oracleBtn.addEventListener('click', async () => {
            const labId = oracleBtn.getAttribute('data-lab-id');
            const course = oracleBtn.getAttribute('data-course') || currentCourse;
            const outBox = document.getElementById('lab-sql-output');
            const db = getSelectedLabDb();

            oracleBtn.disabled = true;
            const origText = oracleBtn.innerText;
            oracleBtn.innerText = '⏳ Vérification Oracle en cours...';

            const res = await postApiJson('api_run_oracle', {
                lab_id: labId,
                database: db
            }, course);
            const data = await res.json();
            oracleBtn.disabled = false;
            oracleBtn.innerText = origText;

            if (outBox) {
                outBox.innerText = `[ORACLE QUALIOPI — ${data.bloom} : ${data.title} | Base: ${data.database || db}]\n`
                    + `Statut : ${data.passed ? '✅ VALIDÉ (Compétence acquise !)' : '❌ ÉCHEC OU INSTANCE ARRÊTÉE'}\n\n`
                    + (data.output || '');
            }
            if (data.passed) {
                setTimeout(() => window.location.reload(), 1500);
            }
        });
    }

    const runSolutionBtn = document.getElementById('btn-run-lab-solution');
    if (runSolutionBtn) {
        runSolutionBtn.addEventListener('click', async () => {
            const labId = runSolutionBtn.getAttribute('data-lab-id');
            const course = runSolutionBtn.getAttribute('data-course') || currentCourse;
            const outBox = document.getElementById('lab-sql-output');
            const db = getSelectedLabDb();

            runSolutionBtn.disabled = true;
            const origText = runSolutionBtn.innerText;
            runSolutionBtn.innerText = '⏳ Exécution du corrigé officiel...';

            const res = await postApiJson('api_run_lab_solution', {
                lab_id: labId,
                database: db
            }, course);
            const data = await res.json();
            runSolutionBtn.disabled = false;
            runSolutionBtn.innerText = origText;

            if (outBox) {
                const stepsCount = data.steps_executed ?? data.steps_count ?? 0;
                outBox.innerText = `[TEST DU CORRIGÉ OFFICIEL — ${stepsCount} bloc(s) SQL exécuté(s) | Base: ${data.database || db}]\n`
                    + `Statut Oracle : ${data.passed ? '✅ CORRIGÉ & ORACLE VALIDÉS (VALIDATION_OK)' : '⚠️ VÉRIFICATION PARTIELLE'}\n\n`
                    + (data.output || '');
            }
            if (data.passed) {
                setTimeout(() => window.location.reload(), 1600);
            }
        });
    }

    // ------------------------------------------------------------------------
    // 12b. Bookmarks, Flashcards SRS Rating & Study-Time Heartbeat
    // ------------------------------------------------------------------------
    document.querySelectorAll('.btn-toggle-bookmark').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const itemType = btn.getAttribute('data-type') || 'module';
            const itemId = btn.getAttribute('data-id') || '';
            const title = btn.getAttribute('data-title') || itemId;
            btn.disabled = true;
            try {
                const res = await postApiJson('api_toggle_bookmark', {
                    item_type: itemType,
                    item_id: itemId,
                    title: title
                });
                const data = await res.json();
                if (data.success) {
                    btn.innerText = data.bookmarked ? '⭐ Favori' : '☆ Favori';
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.btn-fc-rate').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const cardId = btn.getAttribute('data-card-id') || '';
            const level = parseInt(btn.getAttribute('data-level') || '2', 10);
            const cardBox = btn.closest('.flashcard-box');
            const badge = cardBox ? cardBox.querySelector('.fc-status-badge') : null;
            const res = await postApiJson('api_flashcard_rate', {
                card_id: cardId,
                level: level
            });
            const data = await res.json();
            if (data.success && badge) {
                badge.textContent = level === 3 ? '🟢 Maîtrisé' : (level === 2 ? '🟡 En acquisition' : '🔴 À revoir');
            }
            if (cardBox) {
                cardBox.setAttribute('data-level', String(level));
            }
            const masteredCountEl = document.getElementById('fc-mastered-count');
            if (masteredCountEl) {
                masteredCountEl.textContent = String(document.querySelectorAll('.flashcard-box[data-level="3"]').length);
            }
        });
    });

    if (csrfToken) {
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                postApiJson('api_heartbeat', { seconds: 60 })
                .then((r) => r.json())
                .then((data) => {
                    if (data && data.classroom_state) {
                        const bText = (data.classroom_state.broadcast_message || '').trim();
                        const bCont = document.getElementById('classroom-broadcast-container');
                        const bMsg = document.getElementById('classroom-broadcast-text');
                        const bMeta = document.getElementById('classroom-broadcast-meta');
                        if (bCont && bMsg) {
                            if (bText !== '') {
                                bMsg.textContent = bText;
                                if (bMeta) {
                                    const by = data.classroom_state.updated_by || '';
                                    const at = (data.classroom_state.updated_at || '').substring(11, 16);
                                    bMeta.textContent = by ? `(${by}${at ? ' • ' + at : ''})` : '';
                                }
                                bCont.hidden = false;
                                bCont.style.display = '';
                            } else {
                                bCont.hidden = true;
                                bCont.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(() => {});
            }
        }, 60000);
    }

    // ------------------------------------------------------------------------
    // 13. DBA Chaos Engineering Triggers (Admin / Trainer)
    // ------------------------------------------------------------------------
    document.querySelectorAll('.btn-chaos-trigger').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const scenario = btn.getAttribute('data-scenario');
            const chaosAction = btn.getAttribute('data-action');
            const logBox = document.getElementById('chaos-feedback-box');

            btn.disabled = true;
            const res = await postApiJson('api_chaos', {
                scenario: scenario,
                chaos_action: chaosAction
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
    // 14. Manual Docker Status Refresh (explicit click only, zero navigation block)
    // ------------------------------------------------------------------------
    const refreshDockerBtn = document.getElementById('btn-refresh-docker');
    if (refreshDockerBtn && csrfToken) {
        refreshDockerBtn.addEventListener('click', () => {
            refreshDockerBtn.disabled = true;
            postApiJson('api_docker_status', {})
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

    // ------------------------------------------------------------------------
    // 15. Course Switcher, Delegated Print/Auto-Submit/Confirm & Video Speed
    // ------------------------------------------------------------------------
    const courseSwitcher = document.getElementById('course-switcher');
    if (courseSwitcher) {
        courseSwitcher.addEventListener('change', () => {
            window.location.href = `index.php?page=dashboard&course=${encodeURIComponent(courseSwitcher.value)}`;
        });
    }

    document.addEventListener('click', (e) => {
        const printBtn = e.target.closest('[data-action="print"]');
        if (printBtn) {
            e.preventDefault();
            window.print();
            return;
        }
        const confirmTrigger = e.target.closest('button[data-confirm], a[data-confirm]');
        if (confirmTrigger && !confirmTrigger.classList.contains('btn-docker-cmd')) {
            const msg = confirmTrigger.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    document.addEventListener('submit', (e) => {
        const msg = e.target && e.target.getAttribute ? e.target.getAttribute('data-confirm') : null;
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', (e) => {
        const sel = e.target.closest('[data-auto-submit="change"]');
        if (sel && sel.form) {
            sel.form.submit();
            return;
        }
        // Delegated bulk checkbox toggle for Admin user tables
        const master = e.target.closest('[data-bulk-select-all]');
        if (master) {
            const targetSelector = (master.getAttribute('data-bulk-select-all') || '').trim();
            const scope = master.closest('form') || master.closest('table') || document;
            let checkboxes = [];
            if (targetSelector !== '' && targetSelector !== '1' && targetSelector !== 'true') {
                try {
                    checkboxes = Array.from(scope.querySelectorAll(targetSelector));
                } catch (_) {
                    checkboxes = [];
                }
                if (checkboxes.length === 0) {
                    checkboxes = Array.from(scope.querySelectorAll(`input[type="checkbox"][name="${targetSelector}"]`));
                }
            }
            if (checkboxes.length === 0) {
                const table = master.closest('table') || scope;
                checkboxes = Array.from(table.querySelectorAll('tbody input[type="checkbox"]:not([disabled])'));
            }
            checkboxes.forEach((cb) => {
                if (cb !== master) {
                    cb.checked = Boolean(master.checked);
                }
            });
        }
    });

    document.querySelectorAll('.btn-video-speed').forEach((btn) => {
        btn.addEventListener('click', () => {
            const video = document.getElementById('lms-module-video');
            const speed = parseFloat(btn.getAttribute('data-speed') || '1');
            if (video && Number.isFinite(speed) && speed > 0) {
                video.playbackRate = speed;
            }
        });
    });

    // ------------------------------------------------------------------------
    // 16. Lab Dataset Seeder & Admin Docker Container Logs Viewer
    // ------------------------------------------------------------------------
    const seedLabBtn = document.getElementById('btn-seed-lab');
    if (seedLabBtn) {
        seedLabBtn.addEventListener('click', async () => {
            const outBox = document.getElementById('lab-sql-output');
            const dataset = seedLabBtn.getAttribute('data-dataset') || 'all';
            const forceReset = seedLabBtn.getAttribute('data-force') !== '0';
            seedLabBtn.disabled = true;
            const origText = seedLabBtn.innerText;
            seedLabBtn.innerText = '⏳ Amorçage en cours...';
            try {
                const res = await postApiJson('api_seed_lab', { dataset, force_reset: forceReset });
                const data = await res.json();
                const msg = (data.success ? '✅ ' : '⚠️ ') + (data.message || 'Amorçage terminé.')
                    + (data.output ? `\n\n${data.output}` : '');
                if (outBox) {
                    outBox.innerText = msg;
                } else {
                    window.alert(msg);
                }
            } catch (_) {
                if (outBox) outBox.innerText = '❌ Erreur réseau lors de l\'amorçage.';
            } finally {
                seedLabBtn.disabled = false;
                seedLabBtn.innerText = origText;
            }
        });
    }

    const dockerLogsPanel = document.getElementById('docker-logs-panel');
    const dockerLogsOutput = document.getElementById('docker-logs-output');
    const dockerLogsTitle = document.getElementById('docker-logs-title');
    const closeDockerLogsBtn = document.getElementById('btn-close-docker-logs');
    if (closeDockerLogsBtn && dockerLogsPanel) {
        closeDockerLogsBtn.addEventListener('click', () => {
            dockerLogsPanel.hidden = true;
        });
    }
    document.querySelectorAll('.btn-docker-logs, #btn-docker-logs').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const uid = parseInt(btn.getAttribute('data-user-id') || '0', 10);
            const cname = btn.getAttribute('data-container-name') || btn.getAttribute('data-container') || '';
            const labSqlOut = document.getElementById('lab-sql-output');
            const bodyPayload = uid > 0
                ? { target_user_id: uid, tail: 60 }
                : { tail: 60 };
            if (dockerLogsPanel) {
                dockerLogsPanel.classList.remove('is-hidden');
                dockerLogsPanel.hidden = false;
            }
            if (dockerLogsTitle) dockerLogsTitle.textContent = cname || `Conteneur #${uid}`;
            if (dockerLogsOutput) {
                dockerLogsOutput.textContent = '⏳ Chargement des logs PostgreSQL 18...';
            } else if (labSqlOut) {
                labSqlOut.textContent = '⏳ Chargement des logs PostgreSQL 18...';
            }
            btn.disabled = true;
            try {
                const res = await postApiJson('api_docker_logs', bodyPayload);
                const data = await res.json();
                const logText = data.logs || data.message || 'Aucun log disponible.';
                if (dockerLogsOutput) {
                    dockerLogsOutput.textContent = logText;
                } else if (labSqlOut) {
                    labSqlOut.textContent = logText;
                }
            } catch (_) {
                const errText = '❌ Erreur réseau lors de la lecture des logs.';
                if (dockerLogsOutput) {
                    dockerLogsOutput.textContent = errText;
                } else if (labSqlOut) {
                    labSqlOut.textContent = errText;
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    // ------------------------------------------------------------------------
    // 17. Admin Live Table Filters & Inline Enrollments Toggle
    // ------------------------------------------------------------------------
    const filterTableIds = new Set();
    document.querySelectorAll('[data-filter-table]').forEach((el) => {
        const id = el.getAttribute('data-filter-table');
        if (id) filterTableIds.add(id);
    });
    document.querySelectorAll('[data-filter-role-table]').forEach((el) => {
        const id = el.getAttribute('data-filter-role-table');
        if (id) filterTableIds.add(id);
    });

    filterTableIds.forEach((tableId) => {
        const tableEl = document.getElementById(tableId);
        if (!tableEl) return;
        const searchCtrl = document.querySelector(`[data-filter-table="${tableId}"]`);
        const roleCtrl = document.querySelector(`[data-filter-role-table="${tableId}"]`);
        const applyFilter = () => {
            const q = searchCtrl ? searchCtrl.value.trim().toLowerCase() : '';
            const r = roleCtrl ? roleCtrl.value.trim().toLowerCase() : '';
            tableEl.querySelectorAll('tbody tr').forEach((row) => {
                const hay = (row.getAttribute('data-search') || row.getAttribute('data-searchable') || row.textContent || '').toLowerCase();
                const rowRole = (row.getAttribute('data-role') || '').toLowerCase();
                const match = (q === '' || hay.includes(q)) && (r === '' || rowRole === r);
                row.hidden = !match;
                row.style.display = match ? '' : 'none';
            });
        };
        if (searchCtrl) searchCtrl.addEventListener('input', applyFilter);
        if (roleCtrl) roleCtrl.addEventListener('change', applyFilter);
    });

    document.querySelectorAll('.btn-toggle-enroll-box').forEach((btn) => {
        btn.addEventListener('click', () => {
            const uid = btn.getAttribute('data-user-id') || '';
            const box = document.querySelector(`[data-enroll-box="${uid}"]`);
            if (box) {
                box.hidden = !box.hidden;
                btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
            }
        });
    });

    // ------------------------------------------------------------------------
    // 18. Final Certification Exam Live Timer
    // ------------------------------------------------------------------------
    const examTimerDisplay = document.getElementById('exam-timer-display');
    const examDurationInput = document.getElementById('exam-duration-seconds');
    if (document.getElementById('final-exam-form') && (examTimerDisplay || examDurationInput)) {
        const startTs = Date.now();
        setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTs) / 1000);
            if (examDurationInput) examDurationInput.value = String(elapsed);
            if (examTimerDisplay) {
                const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
                const s = String(elapsed % 60).padStart(2, '0');
                examTimerDisplay.textContent = `${m}:${s}`;
            }
        }, 1000);
    }

    // ------------------------------------------------------------------------
    // 19. Study Notes Notebook Page & Glossary / Flashcards SRS Filters
    // ------------------------------------------------------------------------
    const saveNewNoteBtn = document.getElementById('btn-save-new-note');
    const newNoteSelect = document.getElementById('new-note-item-select');
    const newNoteArea = document.getElementById('new-note-textarea');
    const newNoteFeedback = document.getElementById('new-note-feedback');
    const postNoteUpdate = async (itemId, noteText) => {
        const res = await postApiJson('api_save_note', { item_id: itemId, note: noteText });
        return res.json();
    };
    if (saveNewNoteBtn && newNoteSelect && newNoteArea) {
        saveNewNoteBtn.addEventListener('click', async () => {
            try {
                const res = await postNoteUpdate(newNoteSelect.value, newNoteArea.value);
                if (res && res.success) {
                    if (newNoteFeedback) newNoteFeedback.hidden = false;
                    window.location.reload();
                }
            } catch (_) {}
        });
    }
    document.querySelectorAll('.btn-save-inline-note').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const card = btn.closest('.note-item-card');
            if (!card) return;
            const itemId = btn.getAttribute('data-item-id') || '';
            const area = card.querySelector('.note-inline-editor');
            const status = card.querySelector('.note-save-status');
            if (!area) return;
            try {
                const res = await postNoteUpdate(itemId, area.value);
                if (res && res.success && status) {
                    status.textContent = `✔ Enregistré à ${res.saved_at || ''}`;
                    status.classList.add('text-emerald');
                }
            } catch (_) {}
        });
    });
    const notesFilterInput = document.getElementById('notes-filter-input');
    if (notesFilterInput) {
        const noteCards = Array.from(document.querySelectorAll('.note-item-card'));
        notesFilterInput.addEventListener('input', () => {
            const q = notesFilterInput.value.trim().toLowerCase();
            noteCards.forEach((c) => {
                const hay = (c.getAttribute('data-searchable') || '').toLowerCase();
                c.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
            });
        });
    }

    const glossaryFilterInput = document.getElementById('glossary-filter-input');
    if (glossaryFilterInput) {
        const glossaryCards = Array.from(document.querySelectorAll('.glossary-item-card'));
        const applyGlossaryFilter = () => {
            const q = glossaryFilterInput.value.trim().toLowerCase();
            glossaryCards.forEach((c) => {
                const hay = (c.getAttribute('data-searchable') || '').toLowerCase();
                c.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
            });
        };
        glossaryFilterInput.addEventListener('input', applyGlossaryFilter);
        if (glossaryFilterInput.value) applyGlossaryFilter();
    }

    const fcGrid = document.getElementById('flashcards-grid');
    if (fcGrid) {
        const modSelect = document.getElementById('fc-filter-module');
        const lvlSelect = document.getElementById('fc-filter-level');
        const fcSearchInput = document.getElementById('fc-filter-search');
        const focusBtn = document.getElementById('btn-fc-focus-toggle');
        const focusControls = document.getElementById('fc-focus-controls');
        const focusPos = document.getElementById('fc-focus-position');
        const counterEl = document.getElementById('fc-visible-counter');
        const prevBtn = document.getElementById('btn-fc-prev');
        const nextBtn = document.getElementById('btn-fc-next');
        const flipBtn = document.getElementById('btn-fc-flip');
        const allCards = Array.from(document.querySelectorAll('.flashcard-box'));

        let isFocusMode = false;
        let focusIndex = 0;
        let filteredCards = allCards.slice();

        const renderFcCards = () => {
            if (counterEl) {
                counterEl.textContent = `${filteredCards.length} carte(s) affichée(s)`;
            }
            allCards.forEach((c) => { c.style.display = 'none'; });
            const emptyEl = document.getElementById('fc-empty-state');
            if (emptyEl) {
                emptyEl.style.display = filteredCards.length === 0 ? 'block' : 'none';
            }
            if (!isFocusMode) {
                filteredCards.forEach((c) => { c.style.display = ''; });
                if (focusControls) focusControls.style.display = 'none';
            } else {
                if (focusControls) focusControls.style.display = 'flex';
                if (filteredCards.length > 0) {
                    const activeCard = filteredCards[focusIndex] || filteredCards[0];
                    activeCard.style.display = '';
                    if (focusPos) {
                        focusPos.textContent = `Carte ${focusIndex + 1} / ${filteredCards.length}`;
                    }
                } else if (focusPos) {
                    focusPos.textContent = '0 / 0 carte';
                }
            }
        };

        const applyFlashcardFilters = () => {
            const mVal = modSelect ? modSelect.value : '';
            const lVal = lvlSelect ? lvlSelect.value : '';
            const qVal = fcSearchInput ? fcSearchInput.value.trim().toLowerCase() : '';
            filteredCards = allCards.filter((card) => {
                const cMod = card.getAttribute('data-module') || '';
                const cLvl = card.getAttribute('data-level') || '0';
                const cHay = (card.getAttribute('data-searchable') || '').toLowerCase();
                if (mVal !== '' && cMod !== mVal) return false;
                if (lVal !== '' && cLvl !== lVal) return false;
                if (qVal !== '' && !cHay.includes(qVal)) return false;
                return true;
            });
            if (focusIndex >= filteredCards.length) focusIndex = 0;
            renderFcCards();
        };

        const resetFcBtn = document.getElementById('btn-fc-reset');
        if (resetFcBtn) {
            resetFcBtn.addEventListener('click', () => {
                if (!window.confirm('Voulez-vous vraiment réinitialiser toutes vos auto-évaluations SRS pour ce cours ?')) {
                    return;
                }
                resetFcBtn.disabled = true;
                postApiJson('api_reset_flashcards', {})
                    .then((r) => r.json())
                    .then((data) => {
                        resetFcBtn.disabled = false;
                        if (data.success) window.location.reload();
                        else window.alert(data.message || 'Erreur lors de la réinitialisation.');
                    })
                    .catch(() => {
                        resetFcBtn.disabled = false;
                        window.location.reload();
                    });
            });
        }

        if (modSelect) modSelect.addEventListener('change', applyFlashcardFilters);
        if (lvlSelect) lvlSelect.addEventListener('change', applyFlashcardFilters);
        if (fcSearchInput) fcSearchInput.addEventListener('input', applyFlashcardFilters);
        if (focusBtn) {
            focusBtn.addEventListener('click', () => {
                isFocusMode = !isFocusMode;
                focusIndex = 0;
                focusBtn.classList.toggle('btn-primary', isFocusMode);
                focusBtn.classList.toggle('btn-outline-cyan', !isFocusMode);
                focusBtn.textContent = isFocusMode ? '📋 Retourner à la Grille Complète' : '🎯 Mode Focus (Carte par Carte)';
                renderFcCards();
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (filteredCards.length === 0) return;
                focusIndex = (focusIndex - 1 + filteredCards.length) % filteredCards.length;
                renderFcCards();
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (filteredCards.length === 0) return;
                focusIndex = (focusIndex + 1) % filteredCards.length;
                renderFcCards();
            });
        }
        if (flipBtn) {
            flipBtn.addEventListener('click', () => {
                if (filteredCards.length === 0) return;
                const activeCard = filteredCards[focusIndex];
                if (!activeCard) return;
                const det = activeCard.querySelector('details');
                if (det) det.open = !det.open;
            });
        }
        if (fcSearchInput && fcSearchInput.value) {
            applyFlashcardFilters();
        }
    }
});
