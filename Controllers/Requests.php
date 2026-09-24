<?php

namespace GovBrSatisfaction\Controllers;

use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\App;

/**
 * Leitura das solicitações de avaliação, para a tela do painel.
 *
 * Restrito a quem administra a instalação: é a única fonte sobre o que foi
 * disparado ao gov.br, já que de lá não vem retorno.
 *
 * Só leitura — as regras vivem no código e no `.env`, e um endpoint de escrita
 * seria um jeito de quebrar por acidente o que foi acordado com a área.
 *
 * @package GovBrSatisfaction
 */
class Requests extends \MapasCulturais\Controller
{
    const POR_PAGINA = 25;

    /**
     * Página de solicitações, com os filtros aplicados e os totais do conjunto.
     *
     * DQL com campos escalares em vez de entidades hidratadas: trazê-las
     * inteiras carregaria usuário e agente por associação preguiçosa, uma
     * consulta por linha.
     *
     * @return void
     */
    public function GET_index()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $pagina = max(1, (int) ($this->data['pagina'] ?? 1));

        $qb = $app->em->createQueryBuilder()
            ->from(SatisfactionRequest::class, 'r')
            ->join('r.user', 'u')
            ->leftJoin('u.profile', 'a');

        foreach (['situacao' => 'sendStatus', 'servico' => 'servico'] as $campo => $propriedade) {
            $valor = $this->data[$campo] ?? '';

            if (is_string($valor) && $valor !== '') {
                $qb->andWhere("r.{$propriedade} = :{$campo}")->setParameter($campo, $valor);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        // Só os campos que a tela mostra: hidratar a entidade inteira traria o
        // usuário e o agente por associação preguiçosa, uma consulta por linha.
        $registros = (clone $qb)
            ->select('r.id, r.servico, r.sendStatus, r.objectType, r.objectId,
                      r.createTimestamp, r.sendTimestamp, r.sendHttpStatus, r.sendDetail,
                      r.sendResponse, r.sendAttempts, u.id AS userId, u.email, a.name AS agente')
            ->orderBy('r.createTimestamp', 'DESC')
            // Desempate obrigatório: várias solicitações nascem no mesmo segundo
            // — uma carga inicial, um lote de publicações —, e ordem indefinida
            // entre elas faz a paginação por deslocamento repetir uma linha numa
            // página e perder outra na seguinte.
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($pagina - 1) * self::POR_PAGINA)
            ->setMaxResults(self::POR_PAGINA)
            ->getQuery()
            ->getResult();

        // Os totais ignoram o filtro de propósito: eles são o retrato do
        // conjunto, e precisam continuar válidos enquanto se navega dentro de
        // uma situação específica.
        $totais = [];

        $contagens = $app->em->createQueryBuilder()
            ->select('r.sendStatus AS situacao, COUNT(r.id) AS n')
            ->from(SatisfactionRequest::class, 'r')
            ->groupBy('r.sendStatus')
            ->getQuery()
            ->getResult();

        foreach ($contagens as $linha) {
            $totais[$linha['situacao']] = (int) $linha['n'];
        }

        $this->json([
            'registros' => array_map([$this, 'formatar'], $registros),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / self::POR_PAGINA),
            'totais' => $totais,
        ]);
    }

    /**
     * O conteúdo enviado ao BSC, para conferência.
     *
     * É reconstrução, não cópia: CPF, nome e e-mail vêm do cadastro atual, e
     * não da tabela — se a pessoa mudou o cadastro depois do envio, aparece o
     * valor de hoje. A tela diz isso.
     *
     * Saem mascarados: aqui se confere formato e roteamento, e o dado completo
     * está no cadastro do usuário.
     *
     * @return void
     */
    public function GET_payload()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $request = $app->repo(SatisfactionRequest::class)->find((int) ($this->data['id'] ?? 0));

        if (!$request) {
            // Erro de domínio em endpoint JSON volta como JSON com chave `error`,
            // como em Security\Controllers\Monitor. `halt()` escreveria texto puro
            // sem Content-Type, e o script.js faz `response.json()` antes de checar
            // o `ok`: a mensagem específica viraria uma falha genérica na tela.
            $this->json(['error' => \MapasCulturais\i::__('Solicitação não encontrada.')], 404);

            return;
        }

        $plugin = $app->plugins['GovBrSatisfaction'] ?? null;

        if (!$plugin) {
            $this->json(['error' => \MapasCulturais\i::__('Plugin indisponível.')], 503);

            return;
        }
        // O que foi enviado, e não o que seria enviado agora. Esta distinção é a
        // razão de a coluna existir: reconstruir leria o cadastro de hoje, e uma
        // pessoa que corrigiu o CPF depois do envio apareceria com o valor novo,
        // afirmando que foi esse que saiu.
        $enviado = $request->sendPayload ? json_decode($request->sendPayload, true) : null;

        if (is_array($enviado)) {
            $this->json([
                'payload' => $this->mascarar($enviado),
                'reconstruido' => false,
                'motivo' => null,
            ]);

            return;
        }

        // Sem cópia guardada — linha anterior à coluna, ou que nunca chegou a
        // ser enviada. Aí a reconstrução é o que há, e a tela avisa.
        $cpf = Payload::cpf($request->user, $plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $this->json([
                'payload' => null,
                'reconstruido' => true,
                'motivo' => \MapasCulturais\i::__('Sem CPF no cadastro: não há conteúdo a enviar.'),
            ]);

            return;
        }

        $this->json([
            'payload' => $this->mascarar(Payload::build($request, $cpf)),
            'reconstruido' => true,
            'motivo' => null,
        ]);
    }

    /**
     * Esconde o dado pessoal do payload antes de ele chegar à tela.
     *
     * `cpfConsulta` e `usuario` repetem o CPF: sem mascarar os três, a tela
     * mostraria mascarado num campo e por extenso nos outros.
     *
     * @param array $payload
     * @return array
     */
    protected function mascarar(array $payload): array
    {
        foreach (['cpfCidadao', 'cpfConsulta', 'usuario'] as $chave) {
            if (isset($payload[$chave])) {
                $payload[$chave] = $this->mascararCpf((string) $payload[$chave]);
            }
        }

        if (isset($payload['email'])) {
            $payload['email'] = $this->mascararEmail((string) $payload['email']);
        }

        if (isset($payload['nomeCidadao'])) {
            $payload['nomeCidadao'] = $this->mascararNome((string) $payload['nomeCidadao']);
        }

        return $payload;
    }

    /**
     * @param string $cpf
     * @return string
     */
    protected function mascararCpf(string $cpf): string
    {
        if (strlen($cpf) !== 11) {
            return '***';
        }

        return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2);
    }

    /**
     * @param string $email
     * @return string
     */
    protected function mascararEmail(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($usuario, 0, 1) . '***' . ($dominio ? '@' . $dominio : '');
    }

    /**
     * @param string $nome
     * @return string
     */
    protected function mascararNome(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome));

        return $partes[0] . (count($partes) > 1 ? ' ***' : '');
    }

    /**
     * Configuração vigente, para a tela avisar quando algo impede o envio.
     *
     * @return void
     */
    public function GET_status()
    {
        $this->requireInstallationAdmin();

        $plugin = App::i()->plugins['GovBrSatisfaction'] ?? null;

        if (!$plugin) {
            $this->json(['error' => \MapasCulturais\i::__('Plugin indisponível.')], 503);

            return;
        }

        $servicos = [];

        foreach ($plugin->config['servicos'] as $chave => $id) {
            if ($id !== '') {
                $servicos[] = ['id' => (string) $id, 'chave' => $chave];
            }
        }

        $this->json([
            'devMode' => $plugin->isDevMode(),
            'faltando' => $plugin->missingConfig(),
            'servicos' => $servicos,
        ]);
    }

    /**
     * @param array $registro
     * @return array
     */
    protected function formatar(array $registro): array
    {
        // Linhas gravadas antes guardam o nome completo da classe; as novas já
        // guardam só o tipo.
        $tipo = $registro['objectType'];
        $tipo = $tipo && str_contains($tipo, '\\') ? substr(strrchr($tipo, '\\'), 1) : $tipo;

        return [
            'id' => (int) $registro['id'],
            'servico' => (string) $registro['servico'],
            'situacao' => $registro['sendStatus'],
            'pessoa' => $registro['agente'] ?: $registro['email'],
            'userId' => (int) $registro['userId'],
            'origem' => $tipo ? $tipo . ' #' . (int) $registro['objectId'] : null,
            // instante, e não o texto do banco: quem formata é a tela, no
            // fuso e no idioma da instalação
            'registrada' => $registro['createTimestamp']->getTimestamp(),
            'disparada' => $registro['sendTimestamp']?->getTimestamp(),

            // O que o BSC respondeu, para a linha explicar a si mesma
            'httpStatus' => $registro['sendHttpStatus'] === null ? null : (int) $registro['sendHttpStatus'],
            'detalhe' => $registro['sendDetail'],
            'resposta' => $registro['sendResponse'],
            'tentativas' => (int) $registro['sendAttempts'],
        ];
    }

    /**
     * Interrompe quem não administra a instalação.
     *
     * @return void
     */
    protected function requireInstallationAdmin(): void
    {
        $app = App::i();

        $this->requireAuthentication();

        if (!$app->user->is('saasSuperAdmin')) {
            $app->halt(403, \MapasCulturais\i::__('Acesso restrito.'));
        }
    }
}
