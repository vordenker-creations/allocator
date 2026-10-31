</main>

<footer class="footer">
    VKU’s Allocator &mdash; Vietnam-Korea University of Information and Communication Technology &bull; PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?> + MySQL 8.4 (3NF ACID Scheduler)
</footer>

<script>
    (function () {
        const btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                const html = document.documentElement;
                const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', next);
                localStorage.setItem('vku_allocator_theme', next);
            });
        }
    })();

    (function () {
        const langBtn = document.getElementById('lang-toggle');
        if (langBtn) {
            langBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const target = langBtn.getAttribute('data-lang') || (document.documentElement.getAttribute('lang') === 'vi' ? 'en' : 'vi');
                document.cookie = 'vku_lang=' + target + '; path=/; max-age=31536000; SameSite=Lax';
                document.cookie = 'lang=' + target + '; path=/; max-age=31536000; SameSite=Lax';
                let returnPath = window.location.pathname;
                try {
                    const searchParams = new URLSearchParams(window.location.search);
                    searchParams.delete('lang');
                    const q = searchParams.toString();
                    if (q) returnPath += '?' + q;
                } catch (_) {
                    returnPath += window.location.search;
                }
                if (window.location.hash) {
                    returnPath += window.location.hash;
                }
                if (returnPath.startsWith('/lang') || returnPath.startsWith('/set-lang') || returnPath.startsWith('/locale')) {
                    returnPath = '/';
                }
                window.location.href = '/lang?lang=' + target + '&return_to=' + encodeURIComponent(returnPath);
            });
        }
    })();

    function copyTextToClipboard(text, buttonEl) {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text).then(function () {
            const orig = buttonEl.textContent;
            buttonEl.textContent = 'Copied!';
            setTimeout(function () {
                buttonEl.textContent = orig;
            }, 1400);
        });
    }
</script>
</body>
</html>
