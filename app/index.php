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

            async function loadView(view) {
                setActiveLink(view);

                try {
                    const response = await fetch(`content.php?view=${encodeURIComponent(view)}`, {
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
                } catch (err) {
                    contentArea.innerHTML = '<div class="alert alert-danger" role="alert">There was a problem loading this section. Please try again.</div>';
                }
            }

            function handleNavClick(event) {
                const view = event.currentTarget.getAttribute('data-view');
                if (view) {
                    event.preventDefault();
                    loadView(view);
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
                    loadView(target.dataset.view);
                }
            });

            // Load default view on first paint to sync active state.
            setActiveLink('dashboard');
        })();
    </script>
</body>
</html>
