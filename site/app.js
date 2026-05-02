/**
 * Nour docs site — single-page client.
 *
 * Loads markdown files from /docs at runtime, renders with marked.js,
 * highlights code with Prism. Hash-routed so it works on any static
 * host (GitHub Pages, S3, plain Apache) without server config.
 */

(() => {
    'use strict';

    const PAGES = {
        '/':                          'README.md',
        '/01-getting-started':        '01-getting-started.md',
        '/02-configuration':          '02-configuration.md',
        '/03-routing':                '03-routing.md',
        '/04-middleware':             '04-middleware.md',
        '/05-events':                 '05-events.md',
        '/06-validation':             '06-validation.md',
        '/07-databases':              '07-databases.md',
        '/08-websocket':              '08-websocket.md',
        '/09-webhooks-and-timers':    '09-webhooks-and-timers.md',
        '/10-plugins':                '10-plugins.md',
        '/11-cli':                    '11-cli.md',
        '/12-deployment':             '12-deployment.md',
    };

    const TITLES = {
        '/':                          null,                  // use base title alone
        '/01-getting-started':        'Quick start',
        '/02-configuration':          'Configuration',
        '/03-routing':                'Routing',
        '/04-middleware':             'Middleware',
        '/05-events':                 'Events',
        '/06-validation':             'Validation',
        '/07-databases':              'Databases',
        '/08-websocket':              'WebSocket',
        '/09-webhooks-and-timers':    'Webhooks & Timers',
        '/10-plugins':                'Plugins',
        '/11-cli':                    'CLI',
        '/12-deployment':             'Deployment',
    };

    // Pages that have an Arabic translation under docs/ar/. Anything
    // not in this set falls back to the English version with a banner.
    // Add filenames here as translations land.
    const AR_PAGES = new Set([
        'README.md',
    ]);

    const I18N = {
        en: {
            onThisPage: 'On this page',
            fallbackBanner: 'This page is not yet translated to Arabic — showing English.',
            langButton: 'AR',
        },
        ar: {
            onThisPage: 'في هذه الصفحة',
            fallbackBanner: 'هذه الصفحة لم تترجم للعربية بعد — تظهر بالإنجليزية.',
            langButton: 'EN',
        },
    };

    const $content   = document.getElementById('content');
    const $toc       = document.getElementById('pageToc');
    const $sidebar   = document.getElementById('sidebar');
    const $overlay   = document.getElementById('sidebarOverlay');
    const $menu      = document.getElementById('menuToggle');
    const $themeBtn  = document.getElementById('themeToggle');
    const $iconLight = document.getElementById('themeIconLight');
    const $iconDark  = document.getElementById('themeIconDark');
    const $langBtn   = document.getElementById('langToggle');
    const $langLabel = document.getElementById('langLabel');

    let currentLang = 'en';   // set by initLang() below; mutated by toggle

    // ── Marked configuration ──────────────────────────────────────
    marked.setOptions({ gfm: true, breaks: false });

    let currentPagePath = '/';

    function slugify(text) {
        return text
            .toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-') || 'section';
    }

    /**
     * DOM post-processing — added after marked.parse() returns. Cleaner
     * than overriding the marked renderer (whose API has changed across
     * major versions) and version-independent.
     */
    function postProcess(root) {
        // 1. Headings: slugify text → set id, append anchor link.
        const used = new Set();
        root.querySelectorAll('h2, h3, h4').forEach(h => {
            const text = h.textContent.trim();
            let slug = slugify(text);
            let i = 2;
            while (used.has(slug)) slug = slugify(text) + '-' + (i++);
            used.add(slug);
            h.id = slug;
            const a = document.createElement('a');
            a.href = `#${currentPagePath}#${slug}`;
            a.className = 'anchor';
            a.setAttribute('aria-label', 'Permalink');
            a.textContent = '#';
            h.appendChild(a);
        });

        // 2. Links: rewrite .md hrefs to hash routes; mark external links.
        root.querySelectorAll('a[href]').forEach(a => {
            const href = a.getAttribute('href');
            if (!href) return;

            // External
            if (/^https?:\/\//.test(href)) {
                a.target = '_blank';
                a.rel = 'noopener';
                return;
            }
            // In-page anchor (#section) — keep on current page.
            if (href.startsWith('#') && !href.startsWith('#/')) {
                a.setAttribute('href', `#${currentPagePath}${href}`);
                return;
            }
            // .md file (with optional anchor) → hash route.
            const m = href.match(/^([^#]+)\.md(#.*)?$/);
            if (m) {
                const slug = m[1] === 'README' ? '/' : '/' + m[1];
                a.setAttribute('href', `#${slug}${m[2] || ''}`);
            }
        });
    }

    // ── Page loader ───────────────────────────────────────────────
    async function loadPage(path) {
        const route = PAGES[path] ? path : '/';
        const file  = PAGES[route];
        currentPagePath = route;

        $content.innerHTML = '<div class="loading">Loading…</div>';
        $toc.innerHTML = '';

        // Resolve which file to load. If the user picked Arabic and the
        // page has an Arabic version, fetch from docs/ar/; otherwise
        // fetch the English source and show a fallback banner.
        const arHasTranslation = currentLang === 'ar' && AR_PAGES.has(file);
        const url = arHasTranslation ? `docs/ar/${file}` : `docs/${file}`;
        const showFallbackBanner = currentLang === 'ar' && !arHasTranslation;

        let md;
        try {
            const res = await fetch(url, { cache: 'no-cache' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            md = await res.text();
        } catch (e) {
            $content.innerHTML = `
                <h1>Page not found</h1>
                <p>Couldn't load <code>${url}</code>: ${e.message}.</p>
                <p><a href="#/">Back to introduction</a></p>
            `;
            return;
        }

        try {
            const banner = showFallbackBanner
                ? `<div class="lang-fallback">${I18N.ar.fallbackBanner}</div>`
                : '';
            $content.innerHTML = banner + marked.parse(md);
        } catch (e) {
            $content.innerHTML = `<h1>Render error</h1><p>${e.message}</p>`;
            return;
        }
        document.title = TITLES[route]
            ? `${TITLES[route]} — Nour Framework`
            : 'Nour Framework — Documentation';

        postProcess($content);
        if (window.Prism) Prism.highlightAllUnder($content);

        buildToc();
        updateActiveNav(route);

        // Scroll to anchor or top
        const anchor = location.hash.split('#')[2];
        if (anchor) {
            const el = document.getElementById(anchor);
            if (el) {
                setTimeout(() => el.scrollIntoView({ block: 'start', behavior: 'instant' }), 30);
            }
        } else {
            window.scrollTo(0, 0);
        }
    }

    function buildToc() {
        const headings = $content.querySelectorAll('h2, h3');
        if (headings.length < 2) {
            $toc.innerHTML = '';
            return;
        }
        const tocTitle = I18N[currentLang].onThisPage;
        let html = `<div class="page-toc-title">${tocTitle}</div><ul>`;
        headings.forEach(h => {
            const cls = h.tagName === 'H3' ? 'toc-h3' : '';
            // Strip the trailing anchor "#" we appended in postProcess.
            const text = (h.cloneNode(true).childNodes[0]?.textContent ?? h.textContent).replace(/#$/, '').trim();
            html += `<li class="${cls}"><a href="#${currentPagePath}#${h.id}">${text}</a></li>`;
        });
        html += '</ul>';
        $toc.innerHTML = html;
    }

    function updateActiveNav(path) {
        document.querySelectorAll('.nav-list a').forEach(a => {
            a.classList.toggle('active', a.dataset.page === path);
        });
    }

    // ── Hash routing ──────────────────────────────────────────────
    function parseHash() {
        // Hash format: #/page or #/page#anchor
        const h = location.hash.slice(1) || '/';
        const path = '/' + h.replace(/^\//, '').split('#')[0];
        return path === '/' ? '/' : path;
    }

    function handleRoute() {
        loadPage(parseHash());
        closeSidebar();
    }

    window.addEventListener('hashchange', handleRoute);
    // NOTE: the initial handleRoute() call lives at the bottom of the
    // IIFE so initTheme() and initLang() can populate the global state
    // (currentLang in particular) BEFORE the first loadPage runs. Calling
    // it here would load the page with default 'en' regardless of the
    // user's stored preference.

    // ── In-page TOC active-section tracking ───────────────────────
    let observerInstance = null;
    function observeHeadings() {
        if (observerInstance) observerInstance.disconnect();
        const headings = $content.querySelectorAll('h2, h3');
        if (!headings.length) return;

        observerInstance = new IntersectionObserver((entries) => {
            // Pick the topmost intersecting heading
            const visible = entries.filter(e => e.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            if (!visible.length) return;
            const id = visible[0].target.id;
            $toc.querySelectorAll('a').forEach(a => {
                a.classList.toggle('active', a.getAttribute('href').endsWith('#' + id));
            });
        }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });

        headings.forEach(h => observerInstance.observe(h));
    }
    // Re-observe after each page load
    new MutationObserver(observeHeadings).observe($content, { childList: true });

    // ── Theme toggle ──────────────────────────────────────────────
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        $iconLight.style.display = theme === 'dark' ? 'none' : '';
        $iconDark.style.display  = theme === 'dark' ? '' : 'none';
        try { localStorage.setItem('nour-theme', theme); } catch (_) {}
    }

    function initTheme() {
        let saved = null;
        try { saved = localStorage.getItem('nour-theme'); } catch (_) {}
        if (saved === 'light' || saved === 'dark') {
            applyTheme(saved);
            return;
        }
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }

    $themeBtn.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });
    initTheme();

    // ── Language toggle ───────────────────────────────────────────
    function applyLang(lang) {
        currentLang = lang === 'ar' ? 'ar' : 'en';
        const html = document.documentElement;
        html.setAttribute('lang', currentLang);
        html.setAttribute('dir', currentLang === 'ar' ? 'rtl' : 'ltr');
        // Button label shows the OTHER language (the one a click would
        // switch to) — matches conventional bilingual toggles.
        $langLabel.textContent = I18N[currentLang].langButton;
        try { localStorage.setItem('nour-lang', currentLang); } catch (_) {}
    }

    function initLang() {
        let saved = null;
        try { saved = localStorage.getItem('nour-lang'); } catch (_) {}
        if (saved === 'ar' || saved === 'en') {
            applyLang(saved);
            return;
        }
        const browser = (navigator.language || 'en').toLowerCase();
        applyLang(browser.startsWith('ar') ? 'ar' : 'en');
    }

    $langBtn.addEventListener('click', () => {
        applyLang(currentLang === 'ar' ? 'en' : 'ar');
        // Re-render the current page with the new language.
        loadPage(parseHash());
    });
    initLang();

    // ── Mobile sidebar toggle ─────────────────────────────────────
    function openSidebar() {
        $sidebar.classList.add('open');
        $overlay.classList.add('show');
    }
    function closeSidebar() {
        $sidebar.classList.remove('open');
        $overlay.classList.remove('show');
    }
    $menu.addEventListener('click', () => {
        $sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    $overlay.addEventListener('click', closeSidebar);

    // Initial route — runs LAST so currentLang and theme state are
    // populated by initLang() / initTheme() above. Otherwise the first
    // loadPage call would fetch the English version regardless of the
    // user's stored language preference.
    handleRoute();
})();
