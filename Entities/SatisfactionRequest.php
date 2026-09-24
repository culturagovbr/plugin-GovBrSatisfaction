<?php

namespace GovBrSatisfaction\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Solicitação de avaliação de satisfação enviada ao gov.br pelo BSC.
 *
 * Um registro por usuário e serviço, para sempre. A unicidade é do índice
 * (user_id, servico), não da entidade que originou o disparo: publicar o
 * segundo evento não gera solicitação nova. `objectType` e `objectId` ficam
 * como auditoria, fora da chave.
 *
 * CPF, nome e e-mail não são copiados — são lidos do usuário na hora do envio
 * e da exibição, o que mantém a rastreabilidade sem duplicar dado pessoal.
 *
 * @property int $id
 * @property \MapasCulturais\Entities\User $user
 * @property string $servico ID do serviço no Portal de Serviços do gov.br
 * @property string|null $objectType Classe da entidade que originou o disparo
 * @property int|null $objectId
 * @property string $sendStatus self::STATUS_*
 * @property string $etapa
 * @property \DateTime $dataEtapa
 * @property string $situacaoEtapa
 * @property string $canalPrestacao
 * @property string $canalAvaliacao
 * @property string|null $orgao
 * @property string|null $ipOrigem
 * @property string|null $ipUsuario
 * @property \DateTime $createTimestamp
 * @property \DateTime|null $sendTimestamp
 * @property int $sendAttempts
 * @property int|null $sendHttpStatus
 * @property string|null $sendDetail
 * @property string|null $sendResponse
 * @property string|null $sendPayload
 *
 * @ORM\Table(name="govbr_satisfaction_request")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 *
 * @package GovBrSatisfaction
 */
class SatisfactionRequest extends \MapasCulturais\Entity
{
    /** Registrada, ainda não processada pelo job. */
    const STATUS_PENDING = 'pendente';

    /** O Mapa disparou a requisição. Não significa que o cidadão recebeu o e-mail. */
    const STATUS_SENT = 'enviado';

    /** Usuário sem CPF no cadastro: nada é enviado, mas o caso fica visível no painel. */
    const STATUS_NO_CPF = 'sem-cpf';

    /**
     * O BSC recusou em definitivo — credencial, permissão, serviço inexistente
     * ou payload inválido. Ninguém foi convidado, e repetir não mudaria isso;
     * é situação para alguém olhar, não para a fila insistir.
     */
    const STATUS_REJECTED = 'recusado';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="govbr_satisfaction_request_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var \MapasCulturais\Entities\User
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\User", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $user;

    /**
     * @var string
     *
     * @ORM\Column(name="servico", type="string", length=32, nullable=false)
     */
    protected $servico;

    /**
     * Portal em que o serviço foi prestado, revalidado no envio.
     *
     * Id cru lido por getSubsite(), como MapasCulturais\Traits\EntityOriginSubsite:
     * o getter mágico não enxerga um ManyToOne declarado aqui e devolveria nulo
     * com a coluna preenchida, fazendo a revalidação recusar todo envio.
     *
     * @var int|null
     *
     * @ORM\Column(name="subsite_id", type="integer", nullable=true)
     */
    protected $_subsiteId;

    /**
     * @var string|null
     *
     * @ORM\Column(name="object_type", type="string", length=255, nullable=true)
     */
    protected $objectType;

    /**
     * @var int|null
     *
     * @ORM\Column(name="object_id", type="integer", nullable=true)
     */
    protected $objectId;

    /**
     * `sendStatus` e não `status`: Entity::setStatus() é tipado como int e
     * colidiria. Mesmo motivo do `result` em AldirBlanc\Entities\CultBrRequestLog.
     *
     * @var string
     *
     * @ORM\Column(name="send_status", type="string", length=32, nullable=false)
     */
    protected $sendStatus = self::STATUS_PENDING;

    /**
     * @var string
     *
     * @ORM\Column(name="etapa", type="string", length=64, nullable=false)
     */
    protected $etapa;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="data_etapa", type="datetime", nullable=false)
     */
    protected $dataEtapa;

    /**
     * @var string
     *
     * @ORM\Column(name="situacao_etapa", type="string", length=8, nullable=false)
     */
    protected $situacaoEtapa;

    /**
     * @var string
     *
     * @ORM\Column(name="canal_prestacao", type="string", length=8, nullable=false)
     */
    protected $canalPrestacao;

    /**
     * @var string
     *
     * @ORM\Column(name="canal_avaliacao", type="string", length=8, nullable=false)
     */
    protected $canalAvaliacao;

    /**
     * @var string|null
     *
     * @ORM\Column(name="orgao", type="string", length=32, nullable=true)
     */
    protected $orgao;

    /**
     * Capturados no gatilho, não no envio: o job monta o payload em linha de
     * comando, sem requisição, e leria o loopback do servidor.
     *
     * @var string|null
     *
     * @ORM\Column(name="ip_origem", type="string", length=45, nullable=true)
     */
    protected $ipOrigem;

    /**
     * @var string|null
     *
     * @ORM\Column(name="ip_usuario", type="string", length=45, nullable=true)
     */
    protected $ipUsuario;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="send_timestamp", type="datetime", nullable=true)
     */
    protected $sendTimestamp;

    /**
     * Quantas vezes o envio foi tentado sem desfecho.
     *
     * Existe para que nenhuma linha fique retentando indefinidamente. O BSC
     * devolve 500 tanto para indisponibilidade quanto para regra de negócio, e
     * distinguir depende de casar o texto da mensagem — se a redação mudar, o
     * caso vira laço. Este contador é o limite que não depende disso.
     *
     * @var int
     *
     * @ORM\Column(name="send_attempts", type="smallint", nullable=false)
     */
    protected $sendAttempts = 0;

    /**
     * Código HTTP da última resposta, ou nulo quando a requisição não saiu.
     *
     * @var int|null
     *
     * @ORM\Column(name="send_http_status", type="smallint", nullable=true)
     */
    protected $sendHttpStatus;

    /**
     * Como o BSC descreveu o resultado, ou o erro de rede.
     *
     * Guardado para que a recusa seja diagnosticável no painel. Em homologação,
     * "Avaliação já enviada", "no healthy upstream" e "Parâmetro(s) de entrada
     * inválido(s)" chegam todos como erro 5xx ou 4xx genérico, e só a mensagem
     * distingue um problema nosso de um problema deles.
     *
     * Truncado em 500: a resposta de erro traz a pilha inteira do Java, e o que
     * interessa está nas primeiras linhas.
     *
     * @var string|null
     *
     * @ORM\Column(name="send_detail", type="string", length=500, nullable=true)
     */
    protected $sendDetail;

    /**
     * O corpo da resposta do BSC, sem a pilha de exceção.
     *
     * Guardado porque a recusa traz campos que o swagger não documenta —
     * `subErrors` com o motivo real, `codigoErro`, `protocolo` — e sem eles a
     * situação no painel não explica nada.
     *
     * Sai apenas o ruído da plataforma deles: `stackTrace`, `suppressed`,
     * `cause` e afins, que somam dezenas de milhares de caracteres por recusa
     * sem dizer nada sobre o caso. Ver HttpClient::limpar().
     *
     * @var string|null
     *
     * @ORM\Column(name="send_response", type="text", nullable=true)
     */
    protected $sendResponse;

    /**
     * O corpo que foi enviado, exatamente como saiu.
     *
     * Auditoria precisa do que aconteceu, não do que aconteceria hoje. Sem esta
     * cópia, a tela reconstruiria o payload a partir do cadastro atual da
     * pessoa: quem corrigiu o CPF ou trocou o e-mail depois do envio apareceria
     * com os valores de agora, afirmando que foram esses que saíram.
     *
     * Implica guardar CPF, nome e e-mail aqui, o que este plugin evitava de
     * propósito. É o preço de poder responder \"o que foi enviado\" — e o painel
     * continua exibindo tudo mascarado.
     *
     * @var string|null
     *
     * @ORM\Column(name="send_payload", type="text", nullable=true)
     */
    protected $sendPayload;

    public function setSubsite(?\MapasCulturais\Entities\Subsite $subsite): void
    {
        $this->_subsiteId = $subsite ? $subsite->id : null;
    }

    public function getSubsite(): ?\MapasCulturais\Entities\Subsite
    {
        if (!$this->_subsiteId) {
            return null;
        }

        return \MapasCulturais\App::i()->repo('Subsite')->find($this->_subsiteId);
    }

    public function __construct()
    {
        $this->createTimestamp = new \DateTime();

        parent::__construct();
    }

    /**
     * Registros de avaliação não usam o cache de permissões do core: não são
     * entidades de conteúdo, e só o painel restrito os lê.
     */
    public static function usesPermissionCache(): bool
    {
        return false;
    }

    /**
     * Leitura restrita a quem administra a instalação inteira, como o painel.
     */
    protected function canUserView($user): bool
    {
        return !$user->is('guest') && $user->is('saasSuperAdmin');
    }
}
