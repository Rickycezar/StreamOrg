<?php /* Opens the vault security explainer in its own window (see app.js, [data-popup]). */ ?>
<a class="security-link" href="<?= e(url('/vault/security')) ?>" target="streamorg-vault-security"
   rel="noopener" data-popup data-turbo="false">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4"/></svg>
    <?= e(__('ui.vault_security.link')) ?>
</a>
