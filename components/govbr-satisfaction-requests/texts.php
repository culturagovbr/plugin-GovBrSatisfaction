<?php

use MapasCulturais\i;

return [
    // situações de uma solicitação
    'pendente' => i::__('Pendente'),
    'enviado' => i::__('Disparado'),
    'recusado' => i::__('Recusado'),
    'sem-cpf' => i::__('Sem CPF'),

    // nomes dos serviços no Portal de Serviços do gov.br
    'cadastro' => i::__('Cadastrar-se'),
    'coletivo' => i::__('Cadastrar coletivo'),
    'oportunidade' => i::__('Cadastrar oportunidade'),
    'evento' => i::__('Cadastrar evento cultural'),
    'espaco' => i::__('Cadastrar espaço cultural'),
    'projeto' => i::__('Cadastrar projeto cultural'),

    'todos' => i::__('Todos'),

    'disparadoExplicacao' => i::__('"Disparado" significa que o Mapa enviou a solicitação ao BSC. A confirmação de entrega do e-mail fica do lado do gov.br.'),

    'modoDesenvolvimento' => i::__('Modo de desenvolvimento ativo: as solicitações são registradas e o conteúdo é montado, mas nada sai desta instalação.'),
    'faltandoConfig' => i::__('Faltam variáveis de ambiente: %s. Enquanto isso, nada é enviado.'),

    'contagem' => i::__('%s de %s solicitações'),
    'erroAoCarregar' => i::__('Não foi possível ler as solicitações.'),

    'vazioDica' => i::__('As solicitações aparecem aqui quando alguém conclui um dos seis serviços do Mapa da Cultura publicados no gov.br.'),
    'nenhumComFiltro' => i::__('Nenhuma solicitação corresponde ao filtro aplicado.'),
    'limparFiltros' => i::__('Limpar filtros'),

    'ver' => i::__('Ver conteúdo'),

    'devolver' => i::__('Devolver à fila'),
    'devolverTitulo' => i::__('Devolver à fila?'),
    'devolverConfirmacao' => i::__('A solicitação volta a pendente e o Mapa tenta enviar de novo na próxima varredura. Se o BSC aceitar, o cidadão recebe o e-mail do gov.br.'),
    'devolverConfirmacaoSemCpf' => i::__('O CPF é lido de novo do cadastro. Se a pessoa já o preencheu, o envio acontece na próxima varredura; senão, a solicitação volta a "Sem CPF".'),
    'devolvido' => i::__('Solicitação devolvida à fila. O próximo envio acontece na próxima varredura.'),
    'devolverErro' => i::__('Não foi possível devolver a solicitação à fila.'),
    'tentativas' => i::__('%s resposta(s) 500 do BSC antes da recusa'),

    'respostaTitulo' => i::__('Resposta do BSC'),
    'ultimaRespostaTitulo' => i::__('Última resposta do BSC'),
    'copiar' => i::__('Copiar'),
    'copiado' => i::__('Copiado!'),
    'copiarErro' => i::__('Não foi possível copiar.'),
    'payloadTitulo' => i::__('Conteúdo enviado ao BSC'),

    'payloadPrevia' => i::__('Ainda não enviado. O conteúdo abaixo é uma prévia montada do cadastro atual e pode mudar até o envio.'),
    'payloadPreviaSelo' => i::__('prévia'),
    'payloadErro' => i::__('Não foi possível montar o conteúdo.'),
    'cadastroDaConta' => i::__('cadastro da conta'),
];
