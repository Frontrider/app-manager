<script>
    const appActionForms = document.querySelectorAll('form[data-pending-label]');
    appActionForms.forEach((form) => {
        const configureButton = form.querySelector('[data-configure-app]');
        const modal = form.querySelector('.modal');
        const closeModal = () => {
            if (modal === null) {
                return;
            }
            modal.classList.remove('is-active');
            modal.setAttribute('aria-hidden', 'true');
            if (document.querySelector('.modal.is-active') === null) {
                document.documentElement.classList.remove('is-clipped');
            }
            configureButton.focus();
        };
        if (configureButton !== null) {
            configureButton.addEventListener('click', () => {
                modal.classList.add('is-active');
                modal.setAttribute('aria-hidden', 'false');
                document.documentElement.classList.add('is-clipped');
                modal.querySelector('input, button').focus();
            });
            modal.querySelectorAll('[data-cancel-configuration]').forEach((close) => {
                close.addEventListener('click', closeModal);
            });
            modal.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeModal();
                } else if (event.key === 'Tab') {
                    const controls = [...modal.querySelectorAll('input, button')].filter((control) => !control.disabled);
                    const first = controls[0];
                    const last = controls[controls.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });
        }
        const button = configureButton ?? form.querySelector('button[type="submit"]');
        const originalLabel = button.textContent;
        const originallyDisabled = button.disabled;

        form.addEventListener('submit', (event) => {
            if (form.dataset.pending === 'true') {
                event.preventDefault();
                return;
            }
            if (event.defaultPrevented) {
                return;
            }

            form.dataset.pending = 'true';
            button.textContent = form.dataset.pendingLabel;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            if (modal !== null) {
                closeModal();
                form.querySelectorAll('button').forEach((action) => { action.disabled = true; });
            }
        });

        window.addEventListener('pageshow', () => {
            delete form.dataset.pending;
            button.textContent = originalLabel;
            button.disabled = originallyDisabled;
            button.removeAttribute('aria-busy');
            if (modal !== null) {
                if (modal.classList.contains('is-active')) {
                    closeModal();
                }
                modal.querySelectorAll('button').forEach((action) => { action.disabled = false; });
            }
        });
    });

    const themeToggle = document.getElementById('theme-toggle');
    const themeMessage = document.getElementById('theme-message');

    function updateThemeToggle() {
        themeToggle.checked = document.documentElement.dataset.theme === 'dark';
    }

    updateThemeToggle();
    themeToggle.addEventListener('change', () => {
        const theme = themeToggle.checked ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        updateThemeToggle();
        themeMessage.textContent = '';

        try {
            localStorage.setItem('appstore-theme', theme);
        } catch (error) {
            console.warn('Could not save the theme.', error);
            themeMessage.textContent = 'Your browser could not save this theme preference.';
        }
    });
</script>
</body>
</html>
