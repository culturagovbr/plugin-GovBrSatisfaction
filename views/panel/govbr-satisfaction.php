<?php

use MapasCulturais\i;

$this->import('
    mc-icon
    govbr-satisfaction-requests
');
?>

<div class="panel-page">
    <header class="panel-page__header">
        <div class="panel-page__header-title">
            <div class="title">
                <div class="title__icon primary__background">
                    <mc-icon name="govbr-satisfaction"></mc-icon>
                </div>
                <h1 class="title__title"><?= i::_e('Satisfação gov.br') ?></h1>
            </div>
        </div>
        <p class="panel-page__header-subtitle">
            <?= i::_e('Pesquisas de satisfação disparadas ao gov.br pelo BSC ao fim de cada serviço concluído.') ?>
        </p>
    </header>

    <govbr-satisfaction-requests></govbr-satisfaction-requests>
</div>
