# Componente `<govbr-satisfaction-dispatches>`

Histórico de envios de uma solicitação ao BSC, no estilo GitHub Actions. Aberto sob a linha do painel "Satisfação gov.br".

- Cada envio mostra situação, uuid, data, origem e, quando houver, o usuário que o disparou.
- Cada tentativa mostra "Tentativa N/3", HTTP e duração; aberta, traz data, endpoint, resumo e as gavetas "Payload gerado" e "Resposta do servidor", com botão de copiar.
- Os dados vêm de `GET_dispatches`, já mascarados. Sem envio, mostra a prévia de `GET_payload`.
- Com cofre configurado e usuário autorizado, a gaveta do payload tem **Revelar dados reais** e **Copiar dados reais** (`POST_reveal`). Sem janela aberta, pede o motivo (`POST_unlockReveal`); a janela dura o tempo de `revelacao.segundos` e, ao fechar, os dados reais saem da tela.

### Propriedades

- *Number **requestId*** - id da solicitação
- *Object **revelacao*** - `revelacao` do `GET_status`: `disponivel`, `autorizado`, `motivoMinimo`, `segundos`

### Importando componente

```PHP
<?php $this->import('govbr-satisfaction-dispatches'); ?>
```

### Usando o componente

```HTML
<govbr-satisfaction-dispatches :request-id="registro.id"></govbr-satisfaction-dispatches>
```
