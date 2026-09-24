# Componente `<govbr-satisfaction-requests>`

Consulta das solicitações de pesquisa de satisfação disparadas ao gov.br pelo BSC,
na página "Satisfação gov.br" do painel.

Só leitura. As regras da integração estão fechadas com a área e vivem no código
e no `.env` — uma tela para alterá-las seria um jeito de quebrar por acidente o
que foi acordado.

Os contadores do topo são também o filtro de situação: clicar em um deles filtra
a lista, clicar de novo limpa. Eles ignoram os filtros aplicados de propósito,
porque são o retrato do conjunto e precisam continuar válidos enquanto se navega
dentro de uma situação.

"Disparado" significa que o Mapa enviou a solicitação, e **não** que o cidadão
recebeu o e-mail: a API não devolve confirmação, e o fluxo segue do lado do
gov.br. A tela diz isso explicitamente para que a coluna não seja lida como
entrega.

Não recebe propriedades — busca tudo em `govbr-satisfaction-requests`, restrito a
quem administra a instalação.

### Importando componente

```PHP
<?php $this->import('govbr-satisfaction-requests'); ?>
```

### Usando o componente

```HTML
<govbr-satisfaction-requests></govbr-satisfaction-requests>
```
