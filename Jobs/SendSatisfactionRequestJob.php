<?php

namespace GovBrSatisfaction\Jobs;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Envia ao BSC as solicitações pendentes.
 *
 * Fora da requisição do usuário de propósito: ninguém deve esperar uma API
 * externa para ver a página carregar, nem ter a publicação falhando porque o
 * gov.br caiu.
 *
 * Sem retentativa — a API é assíncrona e sem leitura de retorno, então
 * "enviado" significa que o Mapa disparou, não que o cidadão recebeu.
 *
 * Aqui ficam lote, ordem e erro; cada solicitação é de Services\SatisfactionSender.
 *
 * @package GovBrSatisfaction
 */
class SendSatisfactionRequestJob extends JobType
{
    const SLUG = 'govbr-satisfaction-send';

    /**
     * Quantas solicitações cada execução processa. O volume esperado é baixo
     * — uma por usuário e serviço, para sempre —, mas uma carga inicial ou uma
     * fila represada por indisponibilidade do BSC não devem virar uma execução
     * de duração imprevisível.
     */
    const BATCH_SIZE = 50;

    /**
     * Espera antes de cada nova varredura, quando o transporte falha.
     *
     * Sem isto a varredura se reenfileira para "agora" e o cron a executa no
     * tique seguinte — dez segundos na configuração padrão. Uma queda do BSC,
     * que dura minutos, consumiria o limite de tentativas de cada solicitação em
     * meio minuto, marcando como recusado o que só precisava esperar.
     *
     * O índice é a quantidade de falhas seguidas; da última em diante o intervalo
     * se mantém. Três tentativas passam a cobrir onze minutos em vez de trinta
     * segundos, sem tentar mais vezes.
     */
    const BACKOFF = ['now', '+1 minutes', '+10 minutes'];

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG;
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        $plugin = $app->plugins['GovBrSatisfaction'] ?? null;

        if (!$plugin instanceof Plugin) {
            return true;
        }

        // Desligar o plugin precisa parar tudo, e não só os gatilhos. O tipo de
        // job continua registrado para que uma execução já enfileirada não
        // quebre por tipo desconhecido — ela apenas não faz nada.
        if (!$plugin->config['enabled']) {
            return true;
        }

        // Grava em nome de ninguém, e o core não desabilita sozinho — a linha
        // está comentada em App::executeJob(). O finally é obrigatório: o
        // contador de disableAccessControl() vazaria para os jobs seguintes do
        // mesmo worker.
        $app->disableAccessControl();

        // Marca que o transporte falhou e o lote precisa ser tentado de novo.
        $retry = false;

        // Falhas seguidas, para espaçar a próxima varredura. Vem do próprio job
        // porque a indisponibilidade é do transporte, e não de uma solicitação:
        // adiantar uma linha não adiantaria as outras.
        $falhas = (int) ($job->falhas ?? 0);

        try {
            $pending = $app->repo(SatisfactionRequest::class)->findBy(
                ['sendStatus' => SatisfactionRequest::STATUS_PENDING],
                ['createTimestamp' => 'ASC'],
                self::BATCH_SIZE
            );

            // Cada solicitação é protegida por si. Sem isto, uma que falhe aborta a
            // varredura inteira — e como o lote sai ordenado da mais antiga para a
            // mais nova, ela continuaria na cabeça da fila a cada execução,
            // bloqueando todas as seguintes para sempre.
            foreach ($pending as $request) {
                try {
                    // Uma solicitação que não chegou a ser despachada voltou para
                    // a fila, e o motivo é do transporte — credencial recusada,
                    // BSC fora do ar —, não dela. As seguintes falhariam igual, e
                    // insistir só multiplicaria a chamada ao endpoint de token.
                    if (!$plugin->sender()->send($request)) {
                        $retry = true;

                        break;
                    }
                } catch (\Throwable $e) {
                    $app->log->error(sprintf(
                        '[GovBrSatisfaction] falha ao processar a solicitação %d: %s',
                        $request->id,
                        $e->getMessage()
                    ));
                }
            }

            // Uma falha capturada no laço acima pode ter fechado a unidade de
            // trabalho. Sem esta guarda o flush estouraria, `_execute` lançaria,
            // e o job ficaria preso com status de erro — perdendo também o lote
            // seguinte. O mesmo cuidado de Security\Services\IpRegistry.
            if ($app->em->isOpen()) {
                $app->em->flush();
            } else {
                $app->log->error('[GovBrSatisfaction] unidade de trabalho fechada; o lote não foi gravado');
            }
        } finally {
            $app->enableAccessControl();
        }

        // Duas razões para marcar outra varredura: lote cheio, que provavelmente
        // deixou fila, e transporte falho, que devolveu linhas para pendente. Sem
        // a segunda, uma credencial recusada deixaria a fila parada até alguém
        // publicar outra coisa — a correção da credencial não bastaria.
        //
        // Reenfileirar aqui é seguro apesar do id constante: enqueueJob apaga
        // pelo `id` e insere linha com `pk` novo, e Job::execute() apaga pelo
        // `pk` antigo — a nova sobrevive.
        if ($retry) {
            $falhas++;
            $quando = self::BACKOFF[min($falhas, count(self::BACKOFF) - 1)];

            $app->log->warning(sprintf(
                '[GovBrSatisfaction] transporte indisponível; próxima varredura %s',
                $quando
            ));

            $app->enqueueOrReplaceJob(self::SLUG, ['falhas' => $falhas], $quando);
        } elseif (count($pending) === self::BATCH_SIZE) {
            // Fila represada escoa a BATCH_SIZE por tique, e sem falha não há
            // motivo para esperar: a contagem volta a zero.
            $app->enqueueOrReplaceJob(self::SLUG, ['falhas' => 0]);
        }

        return true;
    }
}
