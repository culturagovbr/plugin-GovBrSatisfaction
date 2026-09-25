# GovBrSatisfaction

Integração da API de Avaliação de Satisfação com Serviços Públicos Digitais (gov.br, via BSC) aos seis serviços do Ministério da Cultura publicados no Portal de Serviços.

Ao concluir um dos serviços, o Mapa grava uma solicitação e um job avisa o BSC, que envia ao cidadão um e-mail com o link do questionário. Não há tela de avaliação nem consentimento aqui, só o painel de consulta.

## Instalação

Ative o plugin em `config/plugins.php` (a lista base precisa ser repetida por inteiro: a configuração é montada com `array_merge`):

```php
'plugins' => ['MultipleLocalAuth', 'AdminLoginAsUser', 'RecreatePCacheOnLogin', 'SpamDetector', 'Security', 'GovBrSatisfaction']
```

Para desligar sem remover, `enabled => false` na configuração do plugin.

## Serviços e gatilhos

| Serviço | Entidade | Gatilho |
| --- | --- | --- |
| Cadastrar-se | User | Confirmação do e-mail |
| Cadastrar coletivo | Agent | Publicação (agente individual não conta) |
| Cadastrar oportunidade | Opportunity | Publicação |
| Cadastrar evento cultural | Event | Publicação |
| Cadastrar espaço cultural | Space | Publicação |
| Cadastrar projeto cultural | Project | Publicação |

Publicação inclui "criar e publicar" numa etapa. Cada usuário avalia cada serviço uma única vez (índice único `(user_id, servico)`). Publicação em subsite diferente do configurado é descartada.

## Configuração

Por variáveis de ambiente. **Faltando qualquer uma, nada é registrado**; o painel aponta quais faltam.

| Variável | Papel |
| --- | --- |
| `AVALIACAO_DEV_MODE` | `TRUE` ou `FALSE`. **Ausente equivale a `TRUE`**: nenhuma requisição sai da máquina |
| `AVALIACAO_BSC_URL` | Endereço do BSC **com o prefixo da API**, ex. `https://bsc.../avaliacoes` |
| `AVALIACAO_ORGAO` | ID do MinC no Portal de Serviços |
| `AVALIACAO_SUBSITE_ID` | ID do subsite do Mapa da Cultura **neste ambiente** (4 em homologação e produção) |
| `AVALIACAO_SERVICO_CADASTRO` | ID do serviço "Cadastrar-se" |
| `AVALIACAO_SERVICO_COLETIVO` | ID do serviço "Cadastrar coletivo" |
| `AVALIACAO_SERVICO_OPORTUNIDADE` | ID do serviço "Cadastrar oportunidade" |
| `AVALIACAO_SERVICO_EVENTO` | ID do serviço "Cadastrar evento cultural" |
| `AVALIACAO_SERVICO_ESPACO` | ID do serviço "Cadastrar espaço cultural" |
| `AVALIACAO_SERVICO_PROJETO` | ID do serviço "Cadastrar projeto cultural" |

As credenciais do gateway são as `RCV_BSC_*` já existentes (mesma autenticação da consulta de CNPJ).

## Envio e retentativa

Cada solicitação tem o próprio job, enfileirado no gatilho para agora; em operação normal o envio acontece segundos depois da publicação. A linha fica `pendente` durante o POST e só vira `enviado` com a resposta; se o processo morrer no meio, a retentativa recebe "Avaliação já enviada" do BSC, que deduplica por CPF e serviço.

Cada chamada ao BSC tem 10 s para conectar e 30 s no total. Quando o envio não conclui, o próprio job se reagenda:

- **Transporte** (token, rede, 3xx, 502/503/504): `+1 min`, `+10 min`, depois `+30 min` enquanto durar. A linha não é penalizada.
- **500 da aplicação**, ou exceção no cliente: conta uma tentativa e reagenda em `+1 min`. Após 3, a linha vira `recusado`.

"Avaliação já enviada" (500) é envio. Em 2xx, `emailEnviado: false` também é envio (o e-mail conclui do lado deles) e fica registrado no detalhe.

## Painel

Página **Satisfação gov.br**, sob Administração, restrita a `saasSuperAdmin` e existente só no subsite atendido (fora dele, página e endpoints respondem 404).

| Situação | Significado |
| --- | --- |
| `pendente` | Aguardando o job (segundos), ou o reagendamento se o BSC estiver fora |
| `enviado` | O Mapa disparou. **Não** significa que o cidadão recebeu o e-mail |
| `recusado` | O BSC recusou em definitivo, ou o teto de tentativas esgotou |
| `sem-cpf` | Usuário sem CPF no cadastro; nada foi enviado |

Cada linha abre o conteúdo enviado (mascarado) e a resposta do BSC. Ações, com confirmação e registro no log:

- **Devolver à fila** (`recusado`, `sem-cpf`): zera as tentativas e volta a `pendente`; o CPF é relido do cadastro.
- **Tentar agora** (`pendente` que já falhou): antecipa o job, sem zerar as tentativas.
- **Devolver todas à fila** (filtro em `recusado`): as recusadas do filtro atual, até 500 por clique, com os jobs escalonados de 10 s para não virar rajada contra o BSC.

## Dados pessoais

A tabela não guarda CPF, nome nem e-mail por extenso: a cópia do envio em `send_payload` é gravada já mascarada (`776.***.***-68`, `m***@example.com`, `Maria ***`), e o log mascara o que ecoar do BSC. O vínculo com a pessoa é o `user_id`.

## Testes

A suíte roda sobre a do repositório principal, com o `tests/docker-compose.yml` deste plugin por cima (ele monta o plugin em `src/plugins/GovBrSatisfaction`, ativa-o e define as variáveis; os comentários do arquivo explicam cada linha). Nenhum teste toca `bsc.cultura.gov.br`.

Com `cwd = tests/` do repositório principal:

```bash
docker compose -f docker-compose.yml -f ../src/plugins/GovBrSatisfaction/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/GovBrSatisfaction                          # suíte
  run --rm mapas pu /var/www/tests/GovBrSatisfaction/TriggerTest.php          # um arquivo
  run --rm mapas pu /var/www/tests/GovBrSatisfaction/TriggerTest.php --filter testPublicarEspacoRegistra
```

- Estenda `Tests\GovBrSatisfaction\TestCase`: cria os subsites, restaura a configuração do plugin, limpa a tabela e traz os helpers (`publicar()`, `processarEnvios()`, `clienteQueDevolve()`…).
- A resposta do BSC é testada por `HttpClient::interpret()`; o envio, por um `Client` injetado via `configurar(['client' => ...])`.
- O transporte (curl, token, timeouts) é testado em `HttpTransportTest` contra um servidor simulado no loopback (`tests/src/Fake/bsc-server.php`).
