<?php
/**
 * The chat bot's recent activity, newest first.
 *
 * @var list<array{at:string, level:string, message:string, username:?string}> $log
 * @var bool $showUser whether to name the streamer each line is about (admin)
 */
?>
<?php if ($log === []): ?>
    <p class="empty"><?= e(__('ui.message.bot_no_activity')) ?></p>
<?php else: ?>
    <ul class="bot-log">
        <?php foreach ($log as $line): ?>
            <li class="level-<?= e($line['level']) ?>">
                <time datetime="<?= e($line['at']) ?>"><?= e(fmt_datetime($line['at'])) ?></time>
                <?php if ($showUser && $line['username']): ?><span class="badge"><?= e($line['username']) ?></span><?php endif; ?>
                <span><?= e($line['message']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
