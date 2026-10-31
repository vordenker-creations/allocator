<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

final class I18n
{
    public const string LANG_EN = 'en';
    public const string LANG_VI = 'vi';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_LANGUAGES = [self::LANG_EN, self::LANG_VI];

    /**
     * @var array<string, array<string, string>>
     */
    private static array $messages = [
        self::LANG_EN => [
            'nav_node_matrix' => 'Node Matrix',
            'nav_staging_tray' => 'Staging Tray',
            'nav_active_console' => 'Active Console',
            'nav_cluster_admin' => 'Cluster Admin',
            'nav_sign_in' => 'Sign In',
            'nav_sign_out' => 'Sign Out',
            'nav_theme' => 'Theme',
            'lang_toggle_label' => 'VI',
            'lang_toggle_target' => 'vi',
            'lang_toggle_title' => 'Chuyển sang Tiếng Việt',
            'brand_sub' => 'HPC & Hardware Lease Scheduler',
        ],
        self::LANG_VI => [
            'nav_node_matrix' => 'Ma trận Node',
            'nav_staging_tray' => 'Khay chờ',
            'nav_active_console' => 'Bảng điều khiển',
            'nav_cluster_admin' => 'Quản trị cụm',
            'nav_sign_in' => 'Đăng nhập',
            'nav_sign_out' => 'Đăng xuất',
            'nav_theme' => 'Giao diện',
            'lang_toggle_label' => 'EN',
            'lang_toggle_target' => 'en',
            'lang_toggle_title' => 'Switch to English',
            'brand_sub' => 'Hệ thống điều phối thuê phần cứng & HPC',
        ],
    ];

    public static function normalize(?string $lang): string
    {
        if ($lang === null) {
            return self::LANG_EN;
        }
        $normalized = strtolower(trim($lang));
        return in_array($normalized, self::SUPPORTED_LANGUAGES, true) ? $normalized : self::LANG_EN;
    }

    public static function resolve(?string $getLang, ?string $cookieLang, ?string $sessionLang): string
    {
        if ($getLang !== null) {
            $norm = strtolower(trim($getLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        if ($cookieLang !== null) {
            $norm = strtolower(trim($cookieLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        if ($sessionLang !== null) {
            $norm = strtolower(trim($sessionLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        return self::LANG_EN;
    }

    public static function get(string $key, ?string $lang = null): string
    {
        $lang = self::normalize($lang);
        return self::$messages[$lang][$key] ?? self::$messages[self::LANG_EN][$key] ?? $key;
    }

    /**
     * @return array<string, string>
     */
    public static function all(?string $lang = null): array
    {
        $lang = self::normalize($lang);
        return self::$messages[$lang] ?? self::$messages[self::LANG_EN];
    }

    public static function sanitizeReturnTo(string $returnTo, ?string $currentHost = null, ?string $referer = null): string
    {
        $raw = trim($returnTo);
        if ($raw === '' && $referer !== null && $referer !== '') {
            $refHost = parse_url($referer, PHP_URL_HOST);
            $currHostOnly = $currentHost !== null ? explode(':', $currentHost)[0] : '';
            $refHostOnly = $refHost ? explode(':', $refHost)[0] : '';
            if ($refHost === null || $refHostOnly === '' || ($currHostOnly !== '' && strtolower($refHostOnly) === strtolower($currHostOnly))) {
                $refPath = parse_url($referer, PHP_URL_PATH) ?: '/';
                $refQuery = parse_url($referer, PHP_URL_QUERY);
                $refFrag = parse_url($referer, PHP_URL_FRAGMENT);
                $raw = $refPath . ($refQuery !== null ? '?' . $refQuery : '') . ($refFrag !== null ? '#' . $refFrag : '');
            }
        }

        if ($raw === '') {
            return '/';
        }

        $raw = str_replace(["\r", "\n", "\0"], '', $raw);
        $isSafe = str_starts_with($raw, '/') && !str_starts_with($raw, '//') && !str_starts_with($raw, '/\\');
        if (!$isSafe) {
            return '/';
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if (in_array($path, ['/lang', '/set-lang', '/locale'], true)) {
            return '/';
        }

        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
            unset($queryParams['lang']);
            $query = http_build_query($queryParams);
            $cleanUrl = $path . ($query !== '' ? '?' . $query : '');
        } else {
            $cleanUrl = $path;
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }
}
