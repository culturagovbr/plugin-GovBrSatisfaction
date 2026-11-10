<?php

namespace GovBrSatisfaction\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Envio de uma solicitação ao BSC, com as tentativas agrupadas.
 *
 * @property int $id
 * @property SatisfactionRequest $request
 * @property string $uuid
 * @property string $origin self::ORIGIN_*
 * @property string $state self::STATE_*
 * @property \MapasCulturais\Entities\User|null $user Quem disparou; nulo no registro automático
 * @property \DateTime $createTimestamp
 * @property \DateTime|null $finishTimestamp
 * @ORM\Table(name="govbr_satisfaction_dispatch")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 * @package GovBrSatisfaction
 */
class SatisfactionDispatch extends \MapasCulturais\Entity
{
    /** Registro da solicitação no gatilho. */
    const ORIGIN_REGISTRATION = 'registro';

    /** "Devolver à fila". */
    const ORIGIN_REQUEUE = 'devolucao';

    /** "Tentar agora". */
    const ORIGIN_RETRY_NOW = 'tentar-agora';

    /** "Devolver todas" ou "Devolver selecionadas". */
    const ORIGIN_BULK = 'lote';

    /** Em curso. */
    const STATE_PENDING = SatisfactionRequest::STATUS_PENDING;

    const STATE_SENT = SatisfactionRequest::STATUS_SENT;

    const STATE_NO_CPF = SatisfactionRequest::STATUS_NO_CPF;

    const STATE_REJECTED = SatisfactionRequest::STATUS_REJECTED;

    /** Encerrado por um envio mais novo da mesma solicitação. */
    const STATE_REPLACED = 'substituido';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="govbr_satisfaction_dispatch_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var SatisfactionRequest
     *
     * @ORM\ManyToOne(targetEntity="GovBrSatisfaction\Entities\SatisfactionRequest", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="request_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $request;

    /**
     * @var string
     *
     * @ORM\Column(name="uuid", type="string", length=36, nullable=false)
     */
    protected $uuid;

    /**
     * @var string
     *
     * @ORM\Column(name="origin", type="string", length=16, nullable=false)
     */
    protected $origin;

    /**
     * @var string
     *
     * @ORM\Column(name="state", type="string", length=16, nullable=false)
     */
    protected $state = self::STATE_PENDING;

    /**
     * @var \MapasCulturais\Entities\User|null
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\User", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     * })
     */
    protected $user;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="finish_timestamp", type="datetime", nullable=true)
     */
    protected $finishTimestamp;

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
