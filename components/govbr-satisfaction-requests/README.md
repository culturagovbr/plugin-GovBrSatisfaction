# Componente `<govbr-satisfaction-requests>`

Consulta das solicitações de pesquisa de satisfação disparadas ao gov.br pelo BSC, na página "Satisfação gov.br" do painel. Restrito a quem administra a instalação.

- Uma solicitação por card. A busca aceita nome do agente, id do usuário ou uuid de um envio (`busca` em `GET_index`).
- As pílulas filtram por situação; os números delas ignoram os filtros aplicados: são o retrato do conjunto.
- "Disparado" significa que o Mapa enviou, **não** que o cidadão recebeu o e-mail.
- O card mostra o nome do agente ou, sem ele, o e-mail mascarado.
- O botão de histórico abre, dentro do card, os envios e as tentativas da solicitação (`<govbr-satisfaction-dispatches>`).
- Cards `recusado` e `sem-cpf` têm **Devolver à fila** e pendentes que aguardam retentativa têm **Tentar agora**, as duas em `POST_requeue`.
- Cards `recusado` e `sem-cpf` podem ser selecionados; a seleção vale entre páginas e filtros e sai em **Devolver selecionadas** (`POST_requeueSelected`), até 500 por vez.
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
