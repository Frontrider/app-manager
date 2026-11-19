<div id="modal-container"></div>
<div id="modal-loading" class="modal-loading htmx-indicator" role="status" aria-live="polite">
    <span class="modal-loading__spinner" aria-hidden="true"></span>
    <span class="is-sr-only">Loading…</span>
</div>
<script>
    document.body.addEventListener('htmx:afterSwap', () => {
        const modal = document.querySelector('#modal-container .modal.is-active');
        if (modal !== null) {
            document.documentElement.classList.add('is-clipped');
            const focusTarget = modal.querySelector('[data-modal-primary]')
                ?? modal.querySelector('input, select, textarea, button');
            focusTarget?.focus();
        }
    });

    window.closeHtmxModal = () => {
        document.querySelector('#modal-container .modal')?.remove();
        document.documentElement.classList.remove('is-clipped');
    };

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape'
            && document.querySelector('#modal-container .modal.is-active') !== null) {
            window.closeHtmxModal();
        }
    });

    document.querySelectorAll('[data-navbar-burger]').forEach((burger) => {
        const menu = document.getElementById(burger.getAttribute('aria-controls'));
        if (menu === null) {
            return;
        }
        burger.addEventListener('click', () => {
            const expanded = burger.getAttribute('aria-expanded') === 'true';
            burger.setAttribute('aria-expanded', String(!expanded));
            burger.classList.toggle('is-active', !expanded);
            menu.classList.toggle('is-active', !expanded);
        });
    });

    if (document.querySelector('.app-catalog') !== null) {
        const scrollKey = 'localhost-catalog-scroll';
        let scrollFrame = null;

        const saveCatalogScroll = () => {
            try {
                sessionStorage.setItem(scrollKey, String(window.scrollY));
            } catch (error) {
                console.warn('Could not save the catalog scroll position.', error);
            }
        };

        try {
            const savedScroll = Number.parseInt(sessionStorage.getItem(scrollKey) ?? '', 10);
            if (Number.isFinite(savedScroll)) {
                history.scrollRestoration = 'manual';
                requestAnimationFrame(() => window.scrollTo(0, savedScroll));
            }
        } catch (error) {
            console.warn('Could not restore the catalog scroll position.', error);
        }

        window.addEventListener('scroll', () => {
            if (scrollFrame !== null) {
                return;
            }
            scrollFrame = requestAnimationFrame(() => {
                scrollFrame = null;
                saveCatalogScroll();
            });
        }, { passive: true });
        window.addEventListener('pagehide', saveCatalogScroll);
    }
</script>
<script src="/script/htmx.min.js"></script>
<script src="/script/idiomorph-ext.min.js"></script>


</body>
</html>
