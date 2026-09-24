<?php

use MapasCulturais\i;

return [
    // situações possíveis de uma solicitação
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

    // "Disparado" é o Mapa tendo enviado, e não o cidadão tendo recebido: a API
    // não devolve confirmação, e a tela não pode sugerir que devolve
    'disparadoExplicacao' => i::__('"Disparado" significa que o Mapa enviou a solicitação ao BSC. A confirmação de entrega do e-mail fica do lado do gov.br.'),

    'modoDesenvolvimento' => i::__('Modo de desenvolvimento ativo: as solicitações são registradas e o conteúdo é montado, mas nada sai desta instalação.'),
    'faltandoConfig' => i::__('Faltam variáveis de ambiente: %s. Enquanto isso, nada é enviado.'),

    'contagem' => i::__('%1 de %2 solicitações'),
    'erroAoCarregar' => i::__('Não foi possível ler as solicitações.'),

    // Lista vazia é a situação normal de uma instalação que acabou de ligar o
    // plugin, e não um erro. A dica diz o que faria aparecer alguma coisa.
    'vazioDica' => i::__('As solicitações aparecem aqui quando alguém conclui um dos seis serviços do Mapa da Cultura publicados no gov.br.'),
    'nenhumComFiltro' => i::__('Nenhuma solicitação corresponde ao filtro aplicado.'),
    'limparFiltros' => i::__('Limpar filtros'),

    'ver' => i::__('Ver conteúdo'),
    'respostaTitulo' => i::__('Resposta do BSC'),
    'copiar' => i::__('Copiar'),
    'copiado' => i::__('Copiado!'),
    'copiarErro' => i::__('Não foi possível copiar.'),
    'payloadTitulo' => i::__('Conteúdo enviado ao BSC'),

    // A cópia do envio só nasce no momento do envio. Antes disso — e são
    // segundos, entre publicar e o job varrer — o que se mostra é uma prévia
    // montada do cadastro atual, e a tela precisa dizer isso.
    'payloadPrevia' => i::__('Ainda não enviado. O conteúdo abaixo é uma prévia montada do cadastro atual e pode mudar até o envio.'),
    'payloadPreviaSelo' => i::__('prévia'),
    'payloadErro' => i::__('Não foi possível montar o conteúdo.'),
    'cadastroDaConta' => i::__('cadastro da conta'),
];
