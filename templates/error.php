<?php

use HostingerSpace\Web\Fmt;

/**
 * @var string $title
 * @var string $message
 */

?>
<section class="card">
    <div class="empty-state">
        <h2><?= Fmt::e($title) ?></h2>
        <p><?= Fmt::e($message) ?></p>
        <p><a href="<?= Fmt::url('/') ?>">← Retour au tableau de bord</a></p>
    </div>
</section>
