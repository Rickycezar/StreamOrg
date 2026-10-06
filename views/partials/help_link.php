<?php
/**
 * The "How it works" button beside a page's title: opens that page's
 * guide in its own window (see app.js, [data-popup]).
 *
 * @var string $helpPage one of HelpController::PAGES
 */
?>
<a class="help-link" href="<?= e(url('/help/' . $helpPage)) ?>" target="streamorg-help" rel="noopener" data-popup data-turbo="false"
   title="<?= e(__('ui.help.link_hint')) ?>">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.6 9.2a2.5 2.5 0 0 1 4.8.8c0 1.7-2.4 2.2-2.4 3.5M12 16.8h.01"/></svg>
    <span><?= e(__('ui.help.link')) ?></span>
</a>
