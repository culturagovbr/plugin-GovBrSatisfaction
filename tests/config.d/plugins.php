<?php

// Ativa o plugin GovBrSatisfaction na suíte de testes do repositório principal,
// montado como zz-govbr-satisfaction.d via tests/docker-compose.yml deste plugin.
//
// A lista base é repetida por inteiro porque a configuração é montada com
// array_merge, que substitui a chave em vez de fundir os arrays — declarar
// apenas 'GovBrSatisfaction' aqui desativaria todos os outros plugins.
return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'GovBrSatisfaction',
    ]
];
