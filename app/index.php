<?php
require_once __DIR__ . '/auth.php';

// Protect this page; unauthenticated users go to login.
require_authentication();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EEMS Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Exo:wght@300;400;600;700&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="layout-shell">
        <aside class="sidebar p-3">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
        </aside>
        <div class="d-flex flex-column">
            <?php include __DIR__ . '/partials/header.php'; ?>
            <main class="content-area" id="contentArea">
                <?php include __DIR__ . '/partials/dashboard.php'; ?>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const storageKey = 'eems-theme';
            const root = document.documentElement;
            const toggleBtn = document.getElementById('themeToggle');
            const contentArea = document.getElementById('contentArea');
            const navLinks = Array.from(document.querySelectorAll('[data-view]'));

            function getParamsFromElement(element) {
                const rawParams = element?.dataset?.params || '';

                try {
                    return new URLSearchParams(rawParams);
                } catch (error) {
                    return new URLSearchParams();
                }
            }

            function applyTheme(theme) {
                root.setAttribute('data-bs-theme', theme);
            }

            function toggleTheme() {
                const current = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                localStorage.setItem(storageKey, current);
                applyTheme(current);
            }

            function setActiveLink(view) {
                navLinks.forEach((link) => {
                    const isMatch = link.getAttribute('data-view') === view;
                    link.classList.toggle('active', isMatch);
                });
            }

            async function loadView(view, params = new URLSearchParams()) {
                setActiveLink(view);

                // Carry through any query parameters so deep links can open with filters already applied.
                const mergedParams = new URLSearchParams(params);
                mergedParams.set('view', view);

                const queryString = mergedParams.toString();
                const fetchUrl = queryString ? `content.php?${queryString}` : 'content.php';

                try {
                    const response = await fetch(fetchUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });

                    if (!response.ok) {
                        throw new Error('Unable to load view');
                    }

                    const html = await response.text();

                    // Replace content while re-executing any inline scripts contained in the partial.
                    contentArea.innerHTML = html;
                    const scripts = contentArea.querySelectorAll('script');
                    scripts.forEach((oldScript) => {
                        const newScript = document.createElement('script');

                        // Preserve existing script attributes such as type or nonce.
                        Array.from(oldScript.attributes).forEach((attr) => {
                            newScript.setAttribute(attr.name, attr.value);
                        });

                        // Copy inline content to ensure logic executes after insertion via innerHTML.
                        newScript.textContent = oldScript.textContent;

                        oldScript.replaceWith(newScript);
                    });

                    // Keep the URL in sync so bookmarking a view reloads it with the same filters.
                    const newUrl = queryString ? `?${queryString}` : window.location.pathname;
                    window.history.replaceState(null, '', newUrl);
                } catch (err) {
                    contentArea.innerHTML = '<div class="alert alert-danger" role="alert">There was a problem loading this section. Please try again.</div>';
                }
            }

            function handleNavClick(event) {
                const view = event.currentTarget.getAttribute('data-view');
                if (view) {
                    event.preventDefault();
                    const params = getParamsFromElement(event.currentTarget);
                    loadView(view, params);
                }
            }

            const savedTheme = localStorage.getItem(storageKey);
            if (savedTheme === 'dark' || savedTheme === 'light') {
                applyTheme(savedTheme);
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleTheme);
            }

            navLinks.forEach((link) => {
                link.addEventListener('click', handleNavClick);
            });

            // Support buttons inside content area that also carry data-view.
            contentArea.addEventListener('click', (event) => {
                const target = event.target;
                if (target instanceof HTMLElement && target.dataset.view) {
                    event.preventDefault();
                    const params = getParamsFromElement(target);
                    loadView(target.dataset.view, params);
                }
            });

            // Load initial view from the URL so deep links can open directly to filtered pages.
            const initialParams = new URLSearchParams(window.location.search);
            const initialView = initialParams.get('view') || 'dashboard';

            loadView(initialView, initialParams);
        })();
    </script>
</body>
</html>
