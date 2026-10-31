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
