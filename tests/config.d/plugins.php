<?php

// Montado como zz-govbr-satisfaction.d pelo tests/docker-compose.yml. A lista
// base é repetida por inteiro: a configuração é montada com array_merge.
return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'GovBrSatisfaction',
    ]
];
