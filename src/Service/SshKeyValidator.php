<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

final class SshKeyValidator
{
    /**
     * Validates standard OpenSSH public key formats (ssh-ed25519, ssh-rsa, ecdsa-sha2-nistp*).
     */
    private const string SSH_PUBKEY_REGEX = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521))\s+([A-Za-z0-9+\/]{32,}={0,3})(?:\s+([^\r\n]{1,160}))?$/';

    public static function isValid(?string $key): bool
    {
        if ($key === null) {
            return false;
        }
        $normalized = trim($key);
        if ($normalized === '' || strlen($normalized) > 4096) {
            return false;
        }
        return preg_match(self::SSH_PUBKEY_REGEX, $normalized) === 1;
    }
}
