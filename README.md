# GovBrSatisfaction

Integração da API de Avaliação de Satisfação com Serviços Públicos Digitais (gov.br, via BSC) aos seis serviços do Ministério da Cultura publicados no Portal de Serviços.

Ao concluir um dos serviços, o Mapa grava uma solicitação e um job avisa o BSC, que envia ao cidadão um e-mail com o link do questionário. A avaliação acontece em site do gov.br: não há tela de avaliação nem pedido de consentimento aqui.

> Faltam o ID do órgão no Portal de Serviços e os valores de homologação, pendentes com a equipe do BSC — até lá, só o modo de desenvolvimento é operável.

## Instalação

Ative o plugin em `config/plugins.php`:

```php
'plugins' => ['MultipleLocalAuth', 'AdminLoginAsUser', 'RecreatePCacheOnLogin', 'SpamDetector', 'Security', 'GovBrSatisfaction']
```

A lista base precisa ser repetida por inteiro: a configuração é montada com `array_merge`, que substitui a chave em vez de fundir os arrays.

Depois, rode as atualizações de banco — criam a tabela `govbr_satisfaction_request` — e compile os assets:

```bash
pnpm run build
```

Sem os assets o painel aparece sem estilo.

Para desligar sem remover, declare `enabled` na configuração do plugin, como fazem `Security` e `Metabase`.

## Serviços e gatilhos

| Serviço | Entidade | Gatilho |
| --- | --- | --- |
| Cadastrar-se | User | Criação da conta |
| Cadastrar coletivo | Agent | Publicação |
| Cadastrar oportunidade | Opportunity | Publicação |
| Cadastrar evento cultural | Event | Publicação |
| Cadastrar espaço cultural | Space | Publicação |
| Cadastrar projeto cultural | Project | Publicação |

Cada usuário avalia cada serviço uma única vez, garantido pelo índice único `(user_id, servico)`.

Publicação em subsite diferente do configurado é descartada, não registrada.

## Configuração

Por variáveis de ambiente. O código não carrega nenhum valor como padrão, e **faltando qualquer uma, nada é registrado** — o painel aponta quais faltam.

| Variável | Papel |
| --- | --- |
| `AVALIACAO_DEV_MODE` | `TRUE` ou `FALSE`. **Ausente equivale a `TRUE`** |
| `AVALIACAO_BSC_URL` | Endereço do BSC no ambiente |
| `AVALIACAO_ORGAO` | ID do MinC no Portal de Serviços |
| `AVALIACAO_SUBSITE_ID` | ID do subsite do Mapa da Cultura **neste ambiente** (4 em homologação e produção) |
| `AVALIACAO_SERVICO_CADASTRO` | ID do serviço "Cadastrar-se" |
| `AVALIACAO_SERVICO_COLETIVO` | ID do serviço "Cadastrar coletivo" |
| `AVALIACAO_SERVICO_OPORTUNIDADE` | ID do serviço "Cadastrar oportunidade" |
| `AVALIACAO_SERVICO_EVENTO` | ID do serviço "Cadastrar evento cultural" |
| `AVALIACAO_SERVICO_ESPACO` | ID do serviço "Cadastrar espaço cultural" |
| `AVALIACAO_SERVICO_PROJETO` | ID do serviço "Cadastrar projeto cultural" |

As credenciais do gateway são as `RCV_BSC_*` já existentes: a autenticação é a mesma da consulta de CNPJ do CulturaViva.

### Modo de desenvolvimento

Com `AVALIACAO_DEV_MODE=TRUE` a solicitação é registrada e o conteúdo é montado, mas **nenhuma requisição HTTP sai da máquina** — o payload carrega CPF, nome e e-mail de cidadão real.

É o padrão: sem a variável definida, nada é enviado. A exigência de configuração completa também só vale no modo real.

## Painel

Página **Satisfação gov.br**, sob Administração, restrita a `saasSuperAdmin` e visível apenas no subsite atendido. Somente leitura.

Cada linha abre o conteúdo enviado, campo a campo. CPF, nome e e-mail aparecem mascarados e vêm do cadastro atual da pessoa — não são guardados na tabela, então se o cadastro mudou depois do envio, mostram o valor de hoje.

| Situação | Significado |
| --- | --- |
| `pendente` | Registrada, ainda não processada pelo job |
| `enviado` | O Mapa disparou. **Não** significa que o cidadão recebeu o e-mail |
| `sem-cpf` | Usuário sem CPF no cadastro; nada foi enviado |

`pendente` dura segundos em operação normal — o intervalo até a próxima varredura. Vê-lo crescer significa fila de jobs parada.

## Testes

A suíte roda sobre a do repositório principal, com um arquivo de composição extra que ativa o plugin e define as variáveis de ambiente.

**Os comandos abaixo assumem `cwd = tests/` do repositório principal** — os caminhos relativos do override dependem disso.

```bash
cd tests

# suíte completa do plugin
docker compose -f docker-compose.yml -f ../src/plugins/GovBrSatisfaction/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/GovBrSatisfaction

# um arquivo
docker compose -f docker-compose.yml -f ../src/plugins/GovBrSatisfaction/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/GovBrSatisfaction/TriggerTest.php

# um método
docker compose -f docker-compose.yml -f ../src/plugins/GovBrSatisfaction/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/GovBrSatisfaction/TriggerTest.php --filter "testPublicarEspacoRegistra"
```

Sem o `-f` do plugin, a suíte do repositório principal roda normalmente, sem este plugin.

### O que o arquivo de composição faz

| | Por quê |
|---|---|
| Monta a pasta do plugin | O bind resolve o caminho no host, então funciona mesmo quando o plugin vive fora do repositório do core. Um link simbólico sozinho não bastaria: dentro do container ele apontaria para um caminho inexistente |
| Define as variáveis `AVALIACAO_*` | Sem elas o plugin não registra nada, e nenhum teste teria o que exercitar |
| Força `AVALIACAO_DEV_MODE=TRUE` | É o padrão do plugin, mas explícito aqui para que nenhuma execução da suíte consiga falar com o BSC |
| Monta `config.d` como `zz-govbr-satisfaction.d` | O prefixo faz o diretório ordenar depois de `config.d` no glob da configuração, ativando o plugin sem editar arquivos do core |
| Monta `tests/src` em `/var/www/tests/GovBrSatisfaction` | O autoload `Tests\` resolve as classes daqui a partir dali |

### Escrevendo testes

Estenda `Tests\GovBrSatisfaction\TestCase`, não a base do core. Ela cria os dois subsites que as regras de portal exigem, restaura a configuração do plugin e limpa a tabela — sem isso um teste enxerga o que o anterior deixou.

Três armadilhas que custaram caro:

- **A configuração do plugin sobrevive entre testes** e os subsites não: o plugin é singleton da aplicação, os subsites são desfeitos pelo rollback. Um teste que esvazia uma variável deixaria os seguintes rodando com ela vazia.
- **Publicar exige sessão.** Sem alguém logado, o core entra no caminho de pedido de troca de titularidade e falha antes de o gatilho ser alcançado. Use `publicar()`, que loga como o dono.
- **`AgentDirector::createAgent()` ignora o tipo pedido** — todo agente sai como coletivo. Use `criarAgente()`, que atribui o tipo direto.

## Armadilhas conhecidas

- **O plugin precisa estar montado no container.** O compose de desenvolvimento monta cada plugin individualmente; sem a linha, a pasta não existe para a aplicação.
- **Mudança na entidade não tem efeito até limpar o cache de metadados do Doctrine** (`/tmp/symfony-cache` dentro do container). Em deploy resolve sozinho, porque o container é recriado.
- **`sendStatus`, e não `status`.** `MapasCulturais\Entity::setStatus()` é tipado como `int`; uma propriedade `status` de texto colide com ele.
- **Os IPs são gravados no gatilho**, não no envio. O job roda em linha de comando, onde `REMOTE_ADDR` é o loopback definido pelo `execute-job.sh`.
