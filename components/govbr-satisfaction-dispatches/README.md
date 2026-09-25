# Componente `<govbr-satisfaction-dispatches>`

Histórico de envios de uma solicitação ao BSC, aberto dentro do card do painel "Satisfação gov.br".

- Cada envio mostra situação, uuid, data, origem e, quando houver, o usuário que o disparou.
- Cada tentativa mostra "Tentativa N/máximo", HTTP e duração; aberta, traz data, endpoint, resumo e as gavetas "Payload gerado" e "Resposta do servidor" (cabeçalhos e corpo), com botão de copiar.
- Os dados vêm de `GET_dispatches`, já mascarados. Sem envio, mostra a prévia de `GET_payload`.
- Com cofre configurado e usuário autorizado, a gaveta do payload tem **Revelar dados reais** e **Copiar dados reais** (`POST_reveal`). Sem janela aberta, pede o motivo (`POST_unlockReveal`); a janela dura o tempo de `revelacao.segundos`, vale até `revelacao.limite` revelações e, ao fechar, os dados reais saem da tela.
- A cada mudança de `leitura`, relê os envios já carregados sem fechar o que está aberto.

### Propriedades

- *Number **requestId*** - id da solicitação
- *Object **revelacao*** - `revelacao` do `GET_status`: `disponivel`, `autorizado`, `motivoMinimo`, `segundos`, `limite`
- *Number **leitura*** - contador do monitoramento em tempo real; cada mudança relê os envios

### Importando componente

```PHP
<?php $this->import('govbr-satisfaction-dispatches'); ?>
```

### Usando o componente

```HTML
<govbr-satisfaction-dispatches :request-id="registro.id" :revelacao="status.revelacao" :leitura="leitura"></govbr-satisfaction-dispatches>
```
