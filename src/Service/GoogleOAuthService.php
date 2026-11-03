<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

use Throwable;

final class GoogleOAuthService
{
    private const string AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    private string $clientId;
    private string $clientSecret;
    private string $explicitRedirectUri;
    private string $allowedDomain;
    /** @var callable|null */
    private $httpExecutor;

    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = null,
        ?string $allowedDomain = null,
        ?callable $httpExecutor = null,
    ) {
        $this->clientId = trim($clientId ?? (string) ($_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: ''));
        $this->clientSecret = trim($clientSecret ?? (string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: ''));
        $this->explicitRedirectUri = trim($redirectUri ?? (string) ($_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: ''));
        $this->allowedDomain = strtolower(trim($allowedDomain ?? (string) ($_ENV['GOOGLE_ALLOWED_DOMAIN'] ?? getenv('GOOGLE_ALLOWED_DOMAIN') ?: '')));
        $this->httpExecutor = $httpExecutor;
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getAllowedDomain(): string
    {
        return $this->allowedDomain;
    }

    /**
     * Dynamically resolves the OAuth 2.0 callback URI, honoring reverse-proxy TLS headers (Cloudflare / Nginx).
     *
     * @param array<string, mixed>|null $server
     */
    public function resolveRedirectUri(?array $server = null): string
    {
        if ($this->explicitRedirectUri !== '') {
            return $this->explicitRedirectUri;
        }

        $srv = $server ?? $_SERVER;
        $scheme = 'http';

        if (!empty($srv['HTTPS']) && strtolower((string) $srv['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (strtolower((string) ($srv['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $scheme = 'https';
        } elseif (!empty($srv['HTTP_CF_VISITOR']) && str_contains((string) $srv['HTTP_CF_VISITOR'], '"https"')) {
            $scheme = 'https';
        }

        $host = trim((string) ($srv['HTTP_X_FORWARDED_HOST'] ?? $srv['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $publicHost = trim((string) ($_ENV['ALLOCATOR_PUBLIC_HOST'] ?? getenv('ALLOCATOR_PUBLIC_HOST') ?: 'localhost:8090'));
            $host = $publicHost !== '' ? $publicHost : 'localhost:8090';
        }

        // Prevent host header injection characters
        $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host) ?: 'localhost:8090';

        // If running on public production domain, default to https
        if (!str_starts_with($host, 'localhost') && !str_starts_with($host, '127.0.0.1') && !str_starts_with($host, '192.168.')) {
            $scheme = 'https';
        }

        return sprintf('%s://%s/auth/google/callback', $scheme, $host);
    }

    /**
     * Builds the Google OAuth 2.0 authorization URL with CSRF state parameter.
     */
    public function createAuthorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $resolvedRedirect = $redirectUri ?? $this->resolveRedirectUri();

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $resolvedRedirect,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ];

        if ($this->allowedDomain !== '') {
            $params['hd'] = $this->allowedDomain;
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges an authorization code for tokens and extracts the verified Google user profile.
     *
     * @return array{
     *   ok: bool,
     *   email?: string,
     *   name?: string,
     *   sub?: string,
     *   hd?: string|null,
     *   error?: string
     * }
     */
    public function authenticateCode(string $code, ?string $redirectUri = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'error' => 'Google OAuth credentials are not configured on this server.',
            ];
        }

        $cleanCode = trim($code);
        if ($cleanCode === '') {
            return [
                'ok' => false,
                'error' => 'Authorization code is missing from Google callback.',
            ];
        }

        $resolvedRedirect = $redirectUri ?? $this->resolveRedirectUri();

        $tokenPayload = $this->executeRequest(
            url: self::TOKEN_ENDPOINT,
            method: 'POST',
            headers: [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            postFields: [
                'code' => $cleanCode,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $resolvedRedirect,
                'grant_type' => 'authorization_code',
            ],
        );

        if (!is_array($tokenPayload)) {
            return [
                'ok' => false,
                'error' => 'Could not reach Google OAuth token endpoint. Check container network connectivity.',
            ];
        }

        if (isset($tokenPayload['error'])) {
            $desc = (string) ($tokenPayload['error_description'] ?? $tokenPayload['error']);
            return [
                'ok' => false,
                'error' => 'Google OAuth token exchange failed: ' . $desc,
            ];
        }

        $claims = null;
        if (isset($tokenPayload['id_token']) && is_string($tokenPayload['id_token'])) {
            $claims = self::decodeJwtPayload($tokenPayload['id_token']);
        }

        // Fallback to OpenID UserInfo endpoint if id_token claims are absent or incomplete
        if ((!is_array($claims) || empty($claims['email'])) && isset($tokenPayload['access_token']) && is_string($tokenPayload['access_token'])) {
            $claims = $this->executeRequest(
                url: self::USERINFO_ENDPOINT,
                method: 'GET',
                headers: [
                    'Authorization: Bearer ' . $tokenPayload['access_token'],
                    'Accept: application/json',
                ],
            );
        }

        if (!is_array($claims) || empty($claims['email'])) {
            return [
                'ok' => false,
                'error' => 'Google OAuth did not return a valid email address.',
            ];
        }

        $emailVerified = $claims['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true' && $emailVerified !== 1) {
            return [
                'ok' => false,
                'error' => 'Google account email address is not verified.',
            ];
        }

        $email = strtolower(trim((string) $claims['email']));
        $emailDomain = str_contains($email, '@') ? explode('@', $email)[1] : '';

        // Check if email is an authorized administrator override
        $adminCsv = (string) ($_ENV['GOOGLE_ADMIN_EMAILS'] ?? getenv('GOOGLE_ADMIN_EMAILS') ?: '');
        $adminList = array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', $adminCsv)));
        $isAdmin = in_array($email, $adminList, true);

        if (!$isAdmin && $this->allowedDomain !== '' && strcasecmp($emailDomain, $this->allowedDomain) !== 0) {
            return [
                'ok' => false,
                'error' => sprintf('Access restricted to @%s Google Workspace accounts (signed in as %s).', $this->allowedDomain, $email),
            ];
        }

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        $sub = (string) ($claims['sub'] ?? '');
        $hd = isset($claims['hd']) && is_string($claims['hd']) ? $claims['hd'] : null;

        return [
            'ok' => true,
            'email' => $email,
            'name' => $name,
            'sub' => $sub,
            'hd' => $hd,
        ];
    }

    /**
     * Decodes the payload segment of a JWT received directly over TLS from Google's token endpoint.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadB64 = strtr($parts[1], '-_', '+/');
        $padLen = (4 - (strlen($payloadB64) % 4)) % 4;
        if ($padLen > 0) {
            $payloadB64 .= str_repeat('=', $padLen);
        }

        $decoded = base64_decode($payloadB64, true);
        if (!is_string($decoded)) {
            return null;
        }

        $data = json_decode($decoded, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param list<string> $headers
     * @param array<string, string> $postFields
     * @return array<string, mixed>|null
     */
    private function executeRequest(string $url, string $method, array $headers, array $postFields = []): ?array
    {
        if ($this->httpExecutor !== null) {
            try {
                $executor = $this->httpExecutor;
                $res = $executor($url, $method, $headers, $postFields);
                if (is_array($res)) {
                    return $res;
                }
                if (is_string($res)) {
                    $decoded = json_decode($res, true);
                    return is_array($decoded) ? $decoded : null;
                }
            } catch (Throwable) {
                return null;
            }
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => 6000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields, '', '&', PHP_QUERY_RFC3986);
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
