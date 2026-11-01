/**
 * Challenger 2a — Empirical UI, Styling & Views Verification Suite
 */
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
let passed = 0;
let failed = 0;
let total = 0;

function check(condition, message, extra = '') {
    total++;
    if (condition) {
        passed++;
        console.log(`  [PASS] ${message}`);
    } else {
        failed++;
        console.error(`  [FAIL] ${message} ${extra ? '--> ' + extra : ''}`);
    }
}

console.log('=================================================================');
console.log('CHALLENGER 2A — Empirical UI, Styling & Views Stress Harness');
console.log(`Node Runtime: ${process.version}`);
console.log('=================================================================\n');

// -----------------------------------------------------------------------------
// SUITE 1: CSS Parsing & Property Invariants (public/assets/style.css)
// -----------------------------------------------------------------------------
console.log('SUITE 1: CSS Rules & Elevation Invariants in public/assets/style.css');

const cssPath = path.join(repoRoot, 'public/assets/style.css');
const css = fs.readFileSync(cssPath, 'utf8');

// 1.1 Variable definitions
const rootShadowSm = /:root\s*{[^}]*--shadow-sm:\s*([^;]+);/s.exec(css);
check(rootShadowSm !== null, ':root defines --shadow-sm variable');
check(rootShadowSm && rootShadowSm[1].trim() === '0 1px 2px rgba(20, 20, 19, 0.04)', ':root --shadow-sm resolves to 0 1px 2px rgba(20, 20, 19, 0.04)');

const darkThemeShadowSm = /\[data-theme="dark"\]\s*{[^}]*--shadow-sm:\s*([^;]+);/s.exec(css);
check(darkThemeShadowSm !== null, '[data-theme="dark"] defines --shadow-sm variable');
check(darkThemeShadowSm && darkThemeShadowSm[1].trim() === 'none', '[data-theme="dark"] --shadow-sm resolves to none');

// 1.2 .btn box-shadow
const btnBlockMatch = /\.btn\s*\{([^}]+)\}/s.exec(css);
check(btnBlockMatch !== null, 'Found .btn declaration block in style.css');
if (btnBlockMatch) {
    const btnBody = btnBlockMatch[1];
    const boxShadowMatch = /box-shadow:\s*([^;]+);/.exec(btnBody);
    check(boxShadowMatch !== null, '.btn declares box-shadow property');
    check(boxShadowMatch && boxShadowMatch[1].trim() === 'var(--shadow-sm)', '.btn box-shadow is exactly var(--shadow-sm)');
    check(!btnBody.includes('var(--inset-highlight)'), '.btn does NOT contain var(--inset-highlight)');
}

// 1.3 .pve-banner box-shadow and border invariants
const pveBannerMatch = /\.pve-banner\s*\{([^}]+)\}/s.exec(css);
check(pveBannerMatch !== null, 'Found .pve-banner declaration block in style.css');
if (pveBannerMatch) {
    const pveBody = pveBannerMatch[1];
    const pveShadowMatch = /box-shadow:\s*([^;]+);/.exec(pveBody);
    check(pveShadowMatch !== null, '.pve-banner declares box-shadow property');
    check(pveShadowMatch && pveShadowMatch[1].trim() === 'var(--shadow-sm)', '.pve-banner box-shadow is exactly var(--shadow-sm)');
    check(!pveBody.includes('var(--inset-highlight)'), '.pve-banner does NOT contain var(--inset-highlight)');
}

// 1.4 Absence of .pve-banner.pve-live and .pve-banner.pve-simulated top borders
check(!css.includes('.pve-banner.pve-live'), '.pve-banner.pve-live selector is completely absent from style.css');
check(!css.includes('.pve-banner.pve-simulated'), '.pve-banner.pve-simulated selector is completely absent from style.css');
check(!/pve-banner[^{]*border-top\s*:\s*2px/i.test(css), 'No 2px border-top exists on any .pve-banner variant');

// 1.5 .footer-attribution typography class
const footerAttrMatch = /(?:^|\})\s*\.footer-attribution\s*\{([^}]+)\}/s.exec(css);
check(footerAttrMatch !== null, 'Found .footer-attribution class in style.css');
if (footerAttrMatch) {
    const footerAttrBody = footerAttrMatch[1];
    check(/font-size:\s*0\.75rem;/.test(footerAttrBody), '.footer-attribution has font-size: 0.75rem;');
    check(/letter-spacing:\s*0\.05em;/.test(footerAttrBody), '.footer-attribution has letter-spacing: 0.05em;');
    check(/text-transform:\s*uppercase;/.test(footerAttrBody), '.footer-attribution has text-transform: uppercase;');
}

// 1.6 Elevation alignment with .btn-outline and .card
const btnOutlineMatch = /\.btn-outline\s*\{([^}]+)\}/s.exec(css);
if (btnOutlineMatch) {
    check(/box-shadow:\s*var\(--shadow-sm\);/.test(btnOutlineMatch[1]), '.btn-outline shares box-shadow: var(--shadow-sm)');
}
const cardMatch = /(?:^|\})\s*\.card\s*\{([^}]+)\}/s.exec(css);
if (cardMatch) {
    check(/box-shadow:\s*var\(--shadow-sm\);/.test(cardMatch[1]), '.card shares box-shadow: var(--shadow-sm)');
}

// -----------------------------------------------------------------------------
// SUITE 2: View Rendering & Invariants in views/layout_bottom.php
// -----------------------------------------------------------------------------
console.log('\nSUITE 2: View Invariants in views/layout_bottom.php');

const layoutBottomPath = path.join(repoRoot, 'views/layout_bottom.php');
const layoutBottom = fs.readFileSync(layoutBottomPath, 'utf8');

// 2.1 Absence of $phpVer
check(!layoutBottom.includes('$phpVer'), 'layout_bottom.php does NOT contain $phpVer variable');
check(!layoutBottom.includes('PHP_MAJOR_VERSION'), 'layout_bottom.php does NOT reference PHP_MAJOR_VERSION');
check(!layoutBottom.includes('PHP_MINOR_VERSION'), 'layout_bottom.php does NOT reference PHP_MINOR_VERSION');

// 2.2 Semantic spans for footer
check(layoutBottom.includes('class="footer-institution"'), 'layout_bottom.php contains span.footer-institution');
check(layoutBottom.includes('class="footer-attribution"'), 'layout_bottom.php contains span.footer-attribution');

check(/<span class="footer-institution">\s*<\?=\s*e\(I18n::get\('footer_text',\s*\$currentLang\)\)\s*\?>\s*<\/span>/.test(layoutBottom),
    'layout_bottom.php renders footer-institution with I18n::get(\'footer_text\', $currentLang) and e() escaping');

check(/<span class="footer-attribution">\s*<\?=\s*e\(I18n::get\('footer_attribution',\s*\$currentLang\)\)\s*\?>\s*<\/span>/.test(layoutBottom),
    'layout_bottom.php renders footer-attribution with I18n::get(\'footer_attribution\', $currentLang) and e() escaping');

// 2.3 Absence of inline styling in footer
const footerHtml = /<footer\b[^>]*>([\s\S]*?)<\/footer>/.exec(layoutBottom);
check(footerHtml !== null, 'layout_bottom.php contains <footer>...</footer> block');
if (footerHtml) {
    check(!footerHtml[1].includes('style='), 'Footer block contains no inline style="..." attributes');
    check(footerHtml[1].includes('<div class="footer-inner">'), 'Footer contains div.footer-inner container');
}

// 2.4 Integrity of client-side scripts
check(layoutBottom.includes("document.getElementById('theme-toggle')"), 'layout_bottom.php has theme-toggle script');
check(layoutBottom.includes("document.getElementById('lang-toggle')"), 'layout_bottom.php has lang-toggle script');
check(layoutBottom.includes("function copyTextToClipboard("), 'layout_bottom.php has copyTextToClipboard helper');
check(layoutBottom.includes("vku_lang"), 'layout_bottom.php sets vku_lang cookie');

// -----------------------------------------------------------------------------
// SUITE 3: I18n Dictionary Parity & Unidirectional Mapping Auditing
// -----------------------------------------------------------------------------
console.log('\nSUITE 3: I18n Dictionary Integrity in src/Service/I18n.php');

const i18nPath = path.join(repoRoot, 'src/Service/I18n.php');
const i18nContent = fs.readFileSync(i18nPath, 'utf8');

// 3.1 Check English entries
check(i18nContent.includes("'footer_text' => 'VKU’s Allocator — Vietnam-Korea University of Information and Communication Technology'"),
    'EN footer_text is clean institutional title without PHP/MySQL versions');
check(i18nContent.includes("'footer_attribution' => 'A CREATION OF VORDENKER.WORK'"),
    'EN footer_attribution is present and exact');
check(i18nContent.includes("'status_online_free' => 'AVAILABLE'"),
    'EN status_online_free is AVAILABLE');
check(i18nContent.includes("'pve_live_badge' => 'LIVE TELEMETRY'"),
    'EN pve_live_badge is LIVE TELEMETRY');

// 3.2 Check Vietnamese entries
check(i18nContent.includes("'footer_text' => 'VKU’s Allocator — Trường Đại học Công nghệ Thông tin và Truyền thông Việt - Hàn'"),
    'VI footer_text is clean institutional title in Vietnamese');
check(i18nContent.includes("'status_online_free' => 'KHẢ DỤNG'"),
    'VI status_online_free is KHẢ DỤNG');
check(i18nContent.includes("'pve_live_badge' => 'DỮ LIỆU TRỰC TIẾP'"),
    'VI pve_live_badge is DỮ LIỆU TRỰC TIẾP');

// 3.3 Check translateStatus mapping
const translateStatusMatch = /public static function translateStatus\([^)]*\): string\s*\{([\s\S]*?)\}/.exec(i18nContent);
check(translateStatusMatch !== null, 'Found translateStatus method in I18n.php');
if (translateStatusMatch) {
    const fnBody = translateStatusMatch[1];
    check(fnBody.includes("'AVAILABLE' => 'status_online_free'"), 'translateStatus maps canonical AVAILABLE to status_online_free');
    check(fnBody.includes("'ONLINE // FREE' => 'status_online_free'"), 'translateStatus preserves legacy ONLINE // FREE mapping');
    check(!fnBody.includes('KHẢ DỤNG'), 'translateStatus has NO Vietnamese string KHẢ DỤNG in $map (strictly unidirectional)');
    check(!fnBody.includes('SẴN SÀNG PHỤC VỤ'), 'translateStatus has NO Vietnamese string SẴN SÀNG PHỤC VỤ in $map');
}

// -----------------------------------------------------------------------------
// SUITE 4: Adversarial Edge Cases & Stress Scenarios
// -----------------------------------------------------------------------------
console.log('\nSUITE 4: Adversarial Edge Cases & Optical Stress Scenarios');

// 4.1 Check for accidental box-shadow regressions anywhere in style.css
const insetHighlightMatches = [];
let match;
const insetRegex = /([^{}]+)\{[^{}]*var\(--inset-highlight\)[^{}]*\}/g;
while ((match = insetRegex.exec(css)) !== null) {
    insetHighlightMatches.push(match[1].trim());
}
console.log(`  [INFO] Elements still using --inset-highlight: ${insetHighlightMatches.join(', ')}`);
check(!insetHighlightMatches.some(sel => sel.includes('.btn') && !sel.includes('.btn-')), '.btn does not appear in inset-highlight selectors');
check(!insetHighlightMatches.some(sel => sel.includes('.pve-banner')), '.pve-banner does not appear in inset-highlight selectors');

// 4.2 Check contrast ratio invariant
// Primary button in light mode: bg #141413, text #FAF8F5
function getLuminance(hex) {
    const rgb = [
        parseInt(hex.slice(1, 3), 16) / 255,
        parseInt(hex.slice(3, 5), 16) / 255,
        parseInt(hex.slice(5, 7), 16) / 255
    ].map(val => val <= 0.03928 ? val / 12.92 : Math.pow((val + 0.055) / 1.055, 2.4));
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}
function contrastRatio(hex1, hex2) {
    const l1 = getLuminance(hex1);
    const l2 = getLuminance(hex2);
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
}
const lightBtnContrast = contrastRatio('#FAF8F5', '#141413');
check(lightBtnContrast >= 7.0, `Button text contrast (${lightBtnContrast.toFixed(2)}:1) meets WCAG AAA (>= 7.0:1)`);

// 4.3 Check that footer responsive layout exists
const mediaQueryCheck = /@media\s*\([^)]*max-width:[^)]*\)\s*\{[\s\S]*?\.footer-inner[\s\S]*?\}/.test(css) ||
                        /\.footer-inner\s*\{[^}]*flex-wrap:\s*wrap;/s.test(css);
check(mediaQueryCheck, 'footer-inner has flex-wrap: wrap for responsive mobile wrapping');

console.log('\n=================================================================');
console.log(`CHALLENGER 2A SUMMARY: ${passed} Passed, ${failed} Failed out of ${total}`);
console.log('=================================================================');

if (failed > 0) {
    process.exit(1);
}
