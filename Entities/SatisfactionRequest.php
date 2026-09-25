<?php

namespace GovBrSatisfaction\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Solicitação de avaliação enviada ao BSC. Uma por usuário e serviço.
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
 * @ORM\Table(name="govbr_satisfaction_request")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 * @package GovBrSatisfaction
 */
class SatisfactionRequest extends \MapasCulturais\Entity
{
    /** Registrada, ainda não processada pelo job. */
    const STATUS_PENDING = 'pendente';

    /** O Mapa disparou. Não significa que o cidadão recebeu o e-mail. */
    const STATUS_SENT = 'enviado';

    /** Usuário sem CPF no cadastro: nada é enviado, mas fica visível no painel. */
    const STATUS_NO_CPF = 'sem-cpf';

    /** Recusa definitiva do BSC, ou teto de tentativas esgotado. */
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
     * Id do subsite; ver getSubsite().
     *
     * @var int|null
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
     * Situação do envio (STATUS_*).
     *
     * @var string
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
     * IPs capturados no gatilho.
     *
     * @var string|null
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
     * Tentativas com 500 da aplicação.
     *
     * @var int
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
     * Resumo da última resposta.
     *
     * @var string|null
     *
     * @ORM\Column(name="send_detail", type="string", length=500, nullable=true)
     */
    protected $sendDetail;

    /**
     * Corpo da última resposta, sem a pilha de exceção.
     *
     * @var string|null
     * @ORM\Column(name="send_response", type="text", nullable=true)
     */
    protected $sendResponse;

    /**
     * Corpo enviado, com dados pessoais mascarados.
     *
     * @var string|null
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

    public static function usesPermissionCache(): bool
    {
        return false;
    }

    protected function canUserView($user): bool
    {
        return $user->is(\GovBrSatisfaction\Plugin::ADMIN_ROLE);
    }
}
