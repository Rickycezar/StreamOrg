<?php
declare(strict_types=1);

/**
 * A plain-language explanation of how the key vault protects codes,
 * opened in its own window from the vault and the security settings.
 * Public: it holds nothing secret, and reassures people before they sign up.
 */
final class VaultInfoController
{
    public static function security(): void
    {
        $user = Auth::user();

        echo View::partial('vault_security', [
            'theme' => $user['theme'] ?? 'light',
            'mode'  => $user !== null ? Vault::mode((int) $user['id']) : null,
        ]);
    }
}
