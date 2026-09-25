# Componente `<govbr-satisfaction-requests>`

Consulta das solicitações de pesquisa de satisfação disparadas ao gov.br pelo BSC, na página "Satisfação gov.br" do painel. Restrito a quem administra a instalação.

- Os contadores do topo filtram por situação (clicar de novo limpa) e ignoram os filtros aplicados: são o retrato do conjunto.
- "Disparado" significa que o Mapa enviou, **não** que o cidadão recebeu o e-mail.
- Cada linha abre o conteúdo enviado e a resposta do BSC (`GET_payload`), com CPF, nome e e-mail mascarados.
- Linhas `recusado` têm a ação **Devolver à fila** (`POST_requeue`), a única escrita da tela.

Não recebe propriedades.

### Importando componente

```PHP
<?php $this->import('govbr-satisfaction-requests'); ?>
```

### Usando o componente

```HTML
<govbr-satisfaction-requests></govbr-satisfaction-requests>
```
