<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

$currentLang = $currentLang ?? I18n::resolve(
    isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : null,
    isset($_COOKIE['vku_lang']) && is_string($_COOKIE['vku_lang']) ? $_COOKIE['vku_lang'] : (isset($_COOKIE['lang']) && is_string($_COOKIE['lang']) ? $_COOKIE['lang'] : null),
    isset($_SESSION['lang']) && is_string($_SESSION['lang']) ? $_SESSION['lang'] : null
);
$phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$copiedText = I18n::get('copied_btn', $currentLang);
?>
</main>

<footer class="footer">
    <?= e(I18n::format('footer_text', $currentLang, [$phpVer])) ?>
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
            buttonEl.textContent = <?= json_encode($copiedText) ?>;
            setTimeout(function () {
                buttonEl.textContent = orig;
            }, 1400);
        });
    }
</script>
</body>
</html>
