<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;

/**
 * Envio de uma solicitação ao BSC
 *
 * Contraparte do SatisfactionRegistry: ele decide o que vira linha, esta
 * decide o que da linha vira requisição ao gov.br — e o que acontece depois.
 *
 * Uma solicitação por vez: lote, ordem e erro são do job, e separá-los permite
 * exercitar o envio sem passar pela fila.
 *
 * @package GovBrSatisfaction
 */
class SatisfactionSender
{
    /**
     * Quantas tentativas antes de desistir.
     *
     * O BSC devolve 500 para indisponibilidade e para regra de negócio, e o que
     * separa os dois é o texto da mensagem — frágil por natureza. Sem um teto,
     * uma recusa permanente que não soubéssemos reconhecer retentaria a cada
     * tique do cron, para sempre.
     *
     * Três porque o caso conhecido converge em duas: quando a auditoria do BSC
     * falha depois de gravar a avaliação, a primeira tentativa devolve 500 e a
     * segunda "já enviada". A terceira é a margem.
     *
     * A contrapartida é assumida: uma indisponibilidade longa esgota o limite e
     * as linhas viram `recusado`, exigindo que alguém as devolva à fila. É o
     * preço de não deixar a varredura girando sobre um caso que não se resolve.
     */
    const MAX_ATTEMPTS = 3;

    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Resolve uma solicitação pendente: descarta, marca sem CPF, ou envia.
     *
     * @return bool Falso quando a linha voltou para a fila sem ter saído
     */
    public function send(SatisfactionRequest $request): bool
    {
        $app = App::i();

        // Segunda barreira, redundante com a do gatilho: é aqui que dado de
        // cidadão sai da plataforma, e linha vinda por outro caminho — carga
        // manual, migração, gatilho futuro com defeito — não pode virar envio
        // só por estar na tabela. Descartada, não marcada: não é caso a
        // acompanhar no painel.
        $reason = $this->plugin->subsiteRejectionReason($request->subsite);

        if ($reason) {
            $app->log->warning(sprintf(
                '[GovBrSatisfaction] solicitação %d descartada: %s',
                $request->id,
                $reason
            ));

            $request->delete();

            return true;
        }

        $cpf = Payload::cpf($request->user, $this->plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            // Sem modal no fluxo, não existe momento para pedir que a pessoa
            // complete o cadastro. O registro fica visível no painel para que o
            // tamanho real do caso seja medido.
            $request->sendStatus = SatisfactionRequest::STATUS_NO_CPF;
            $request->save();

            return true;
        }

        $payload = Payload::build($request, $cpf);

        // Gravado antes da chamada, e não depois: é o registro do que saiu, e
        // vale mesmo que o envio falhe — uma recusa por payload inválido só é
        // diagnosticável com o payload que a causou.
        $request->sendPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Marca antes de chamar, e grava na hora: se o processo morrer no meio do
        // POST não há como saber se chegou, e é preferível deixar de convidar a
        // convidar duas vezes.
        $request->sendStatus = SatisfactionRequest::STATUS_SENT;
        $request->sendTimestamp = new \DateTime();
        $request->save(true);

        // Só que nem toda falha é ambígua, e o desfecho decide o que a linha
        // passa a dizer. Manter "enviado" depois de uma recusa afirmaria que o
        // cidadão foi convidado quando ninguém foi.
        $resultado = $this->plugin->client()->send($payload);

        // O que a API respondeu fica na linha, e não só no log: é o que permite
        // a quem opera distinguir no painel um payload recusado de um BSC fora
        // do ar, sem precisar de acesso ao servidor.
        $request->sendHttpStatus = $resultado->status;
        $request->sendResponse = $resultado->body;
        $request->sendDetail = $resultado->detail === null
            ? null
            : mb_substr($resultado->detail, 0, 500);

        if ($resultado->outcome === Result::SENT) {
            $request->save(true);

            return true;
        }

        $request->sendAttempts = (int) $request->sendAttempts + 1;
        $request->sendTimestamp = null;

        // Transitório vira recusa quando as tentativas se esgotam: continuar é
        // admitir que o caso se resolve sozinho, e passado esse ponto ele não
        // se resolveu. Melhor aparecer no painel do que consumir a fila.
        $desistir = $request->sendAttempts >= self::MAX_ATTEMPTS;

        if ($desistir && $resultado->outcome === Result::RETRY) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] solicitação %d recusada após %d tentativas sem sucesso',
                $request->id,
                $request->sendAttempts
            ));
        }

        // Recusa definitiva fica visível no painel e sai da fila; transitória
        // volta a pendente para a varredura seguinte.
        $request->sendStatus = $resultado->outcome === Result::REJECTED || $desistir
            ? SatisfactionRequest::STATUS_REJECTED
            : SatisfactionRequest::STATUS_PENDING;

        $request->save(true);

        // Só o transitório que ainda tem tentativa pede outra varredura:
        // repetir uma recusa encheria o log sem chance de mudar de resultado.
        return $request->sendStatus === SatisfactionRequest::STATUS_REJECTED;
    }
}
