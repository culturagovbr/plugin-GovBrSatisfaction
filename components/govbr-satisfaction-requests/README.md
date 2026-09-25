# Componente `<govbr-satisfaction-requests>`

Consulta das solicitações de pesquisa de satisfação disparadas ao gov.br pelo BSC, na página "Satisfação gov.br" do painel. Restrito a quem administra a instalação.

- Os contadores do topo filtram por situação (clicar de novo limpa) e ignoram os filtros aplicados: são o retrato do conjunto.
- "Disparado" significa que o Mapa enviou, **não** que o cidadão recebeu o e-mail.
- A coluna "Pessoa" mostra o nome do agente ou, sem ele, o e-mail mascarado.
- Cada linha abre o conteúdo enviado e a resposta do BSC (`GET_payload`), com CPF, nome, e-mail e IP mascarados.
- Linhas `recusado` e `sem-cpf` têm **Devolver à fila** e pendentes que aguardam retentativa têm **Tentar agora**, as duas em `POST_requeue`.
- Com o filtro `recusado`, a barra do lote oferece **Devolver todas à fila** (`POST_requeueAll`).

Não recebe propriedades.

### Importando componente

```PHP
<?php $this->import('govbr-satisfaction-requests'); ?>
```

### Usando o componente

```HTML
<govbr-satisfaction-requests></govbr-satisfaction-requests>
```
