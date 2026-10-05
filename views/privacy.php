<?php
/**
 * The privacy policy: what StreamOrg keeps about creators and about
 * viewers, why, for how long, and how to have it removed.
 *
 * @var string $email
 */
$sections = ['who', 'creators', 'viewers', 'giveaways', 'bot', 'twitch', 'cookies', 'retention', 'rights', 'changes'];
?>
<div class="pub-privacy">
    <header class="pub-hero">
        <span class="lp-eyebrow"><?= e(__('ui.privacy.updated')) ?></span>
        <h1><?= e(__('ui.privacy.title')) ?></h1>
        <p class="pub-lead"><?= e(__('ui.privacy.lead')) ?></p>
    </header>

    <?php foreach ($sections as $section): ?>
        <section class="pub-card">
            <h2><?= e(__('ui.privacy.' . $section . '_title')) ?></h2>
            <?php
            $lines  = explode("\n", __('ui.privacy.' . $section . '_text'));
            $inList = false;

            foreach ($lines as $line):
                $bullet = str_starts_with($line, '- ');

                if ($bullet && !$inList) { echo '<ul>'; $inList = true; }
                if (!$bullet && $inList) { echo '</ul>'; $inList = false; }
                ?>
                <?php if ($bullet): ?>
                    <li><?= e(sprintf(substr($line, 2), $email)) ?></li>
                <?php else: ?>
                    <p><?= e(sprintf($line, $email)) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($inList) { echo '</ul>'; } ?>
        </section>
    <?php endforeach; ?>
</div>
