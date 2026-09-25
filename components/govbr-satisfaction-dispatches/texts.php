<?php

use MapasCulturais\i;

return [
    // situações de envio e de tentativa
    'situacao-pendente' => i::__('Pendente'),
    'situacao-enviado' => i::__('Disparado'),
    'situacao-recusado' => i::__('Recusado'),
    'situacao-sem-cpf' => i::__('Sem CPF'),
    'situacao-substituido' => i::__('Substituído'),
    'situacao-retentar' => i::__('Falhou'),
    'situacao-simulado' => i::__('Simulado'),

    // o que abriu o envio
    'origem-registro' => i::__('envio automático'),
    'origem-devolucao' => i::__('devolvido à fila'),
    'origem-tentar-agora' => i::__('tentar agora'),
    'origem-lote' => i::__('devolução em lote'),

    'porUsuario' => i::__('por usuário #%s'),
    'tentativa' => i::__('Tentativa %s/%s'),
    'duracao' => i::__('%s ms'),
    'enviadoEm' => i::__('Enviado em'),
    'endpoint' => i::__('Endpoint'),
    'resumo' => i::__('Resumo'),
    'payload' => i::__('Payload gerado'),
    'resposta' => i::__('Resposta do servidor'),
    'cabecalhos' => i::__('Cabeçalhos da resposta'),
    'respostaCortada' => i::__('[resposta cortada em 64 KB]'),

    'semEnvio' => i::__('Nenhum envio registrado para esta solicitação.'),
    'previa' => i::__('Prévia do conteúdo'),
    'previaNota' => i::__('Montada do cadastro atual; pode mudar até o envio.'),
    'aguardandoTentativa' => i::__('Aguardando a primeira tentativa.'),
    'semTentativa' => i::__('Encerrado sem tentativa de envio.'),
    'semPayload' => i::__('Nenhum conteúdo foi montado nesta tentativa.'),
    'semResposta' => i::__('Sem resposta do servidor.'),

    // revelação do payload real
    'revelar' => i::__('Revelar dados reais'),
    'copiarReais' => i::__('Copiar dados reais'),
    'ocultar' => i::__('Ocultar'),
    'dadosReais' => i::__('Dados reais visíveis por mais %s.'),
    'dadosReaisRotulo' => i::__('Payload com os dados reais'),
    'janelaAberta' => i::__('Revelação liberada por mais %s, com %s revelação(ões) restante(s). Cada uma fica registrada.'),
    'motivoTitulo' => i::__('Por que revelar os dados reais?'),
    'motivoExplicacao' => i::__('O motivo fica registrado com seu usuário, a data e cada revelação ou cópia feita nos próximos %s minutos.'),
    'motivo' => i::__('Motivo'),
    'motivoDica' => i::__('Ex.: cidadão contestou o envio no chamado 123'),
    'motivoContagem' => i::__('%s de %s caracteres mínimos'),
    'liberar' => i::__('Liberar por %s minutos'),
    'cancelar' => i::__('Cancelar'),
    'revelarErro' => i::__('Não foi possível revelar os dados reais.'),

    'carregarMais' => i::__('Carregar mais'),
    'erroAoCarregar' => i::__('Não foi possível ler o histórico de envios.'),
    'copiar' => i::__('Copiar'),
    'copiado' => i::__('Copiado para a área de transferência.'),
    'copiarErro' => i::__('Não foi possível copiar.'),
];
