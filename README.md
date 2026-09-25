# GovBrSatisfaction

Integra os serviços do Ministério da Cultura publicados no Portal de Serviços à API de Avaliação de Satisfação do gov.br (BSC). Ao concluir um serviço, o cidadão recebe do gov.br um e-mail com o questionário.

## Instalação

Inclua `GovBrSatisfaction` na lista de plugins de `config/plugins.php`.

Para desligar sem remover, use `enabled => false` na configuração do plugin.

## Serviços

| Serviço | Entidade | Gatilho |
| --- | --- | --- |
| Cadastrar-se | User | Confirmação do e-mail |
| Cadastrar coletivo | Agent | Publicação de agente coletivo |
| Cadastrar oportunidade | Opportunity | Publicação |
| Cadastrar evento cultural | Event | Publicação |
| Cadastrar espaço cultural | Space | Publicação |
| Cadastrar projeto cultural | Project | Publicação |

Cada usuário recebe uma pesquisa por serviço, no subsite configurado.

## Configuração

Obrigatórias:

| Variável | Descrição |
| --- | --- |
| `AVALIACAO_DEV_MODE` | `TRUE` ou `FALSE`; ausente equivale a `TRUE` (nada é enviado) |
| `AVALIACAO_BSC_URL` | Endereço do BSC com o prefixo da API |
| `AVALIACAO_ORGAO` | ID do órgão no Portal de Serviços |
| `AVALIACAO_SUBSITE_ID` | ID do subsite atendido |
| `AVALIACAO_SERVICO_CADASTRO` | ID do serviço "Cadastrar-se" |
| `AVALIACAO_SERVICO_COLETIVO` | ID do serviço "Cadastrar coletivo" |
| `AVALIACAO_SERVICO_OPORTUNIDADE` | ID do serviço "Cadastrar oportunidade" |
| `AVALIACAO_SERVICO_EVENTO` | ID do serviço "Cadastrar evento cultural" |
| `AVALIACAO_SERVICO_ESPACO` | ID do serviço "Cadastrar espaço cultural" |
| `AVALIACAO_SERVICO_PROJETO` | ID do serviço "Cadastrar projeto cultural" |
| `RCV_BSC_AUTH_TOKEN`, `RCV_BSC_CLIENT_ID`, `RCV_BSC_CLIENT_SECRET` | Credenciais do gateway do BSC |

Opcionais:

| Variável | Descrição |
| --- | --- |
| `AVALIACAO_CHAVES_PAYLOAD` | Chaves do conteúdo cifrado, `versão:base64` separadas por vírgula; a maior versão cifra |
| `AVALIACAO_REVELAR_USUARIOS` | IDs dos usuários que podem revelar o conteúdo real, separados por vírgula |
| `AVALIACAO_RETENCAO_DIAS` | Dias até apagar o conteúdo cifrado e a resposta do BSC; padrão `180`, `0` desliga |

Gerar uma chave:

```bash
php -r 'echo "1:".base64_encode(random_bytes(32)), PHP_EOL;'
```

## Funcionamento

- Cada solicitação é enviada por um job próprio.
- Cada envio registra suas tentativas, com HTTP, duração, endpoint, payload e resposta.
- Após 3 falhas a solicitação fica `recusado`; um 4xx recusa na hora.
- Um job diário apaga o conteúdo cifrado e a resposta das tentativas mais antigas que o prazo de retenção.

| Situação | Descrição |
| --- | --- |
| `pendente` | Aguardando envio |
| `enviado` | Enviado ao BSC |
| `recusado` | Recusado pelo BSC ou tentativas esgotadas |
| `sem-cpf` | Usuário sem CPF no cadastro |

## Painel

Página **Satisfação gov.br**, em Administração, para `saasSuperAdmin`.

- Busca por nome, id do usuário ou uuid do envio, com filtros por situação e serviço.
- Histórico de envios e tentativas de cada solicitação.
- Ações: **Devolver à fila**, **Tentar agora**, **Devolver selecionadas** e **Devolver todas à fila**.

## Dados pessoais

- CPF, nome, e-mail e IP são gravados e exibidos mascarados.
- Com `AVALIACAO_CHAVES_PAYLOAD`, o payload real de cada tentativa é guardado cifrado.
- Os usuários de `AVALIACAO_REVELAR_USUARIOS` podem revelar ou copiar o payload real após informar um motivo, por tempo limitado.
- Cada revelação é registrada.

## Testes

A partir de `tests/` do repositório principal:

```bash
docker compose -f docker-compose.yml -f ../src/plugins/GovBrSatisfaction/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/GovBrSatisfaction
```
