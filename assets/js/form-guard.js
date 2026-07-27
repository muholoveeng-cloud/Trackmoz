/** Evita duplo clique em formulários e mostra estado de carregamento */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-guard-submit]').forEach((form) => {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            const loading = btn.getAttribute('data-loading-text');
            if (loading) {
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = loading;
            }
            btn.classList.add('disabled');
        });
    });
});
