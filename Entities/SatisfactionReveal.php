<?php

namespace GovBrSatisfaction\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de auditoria da revelação do payload real. Só inclusão.
 *
 * @property int $id
 * @property int|null $requestId
 * @property int|null $attemptId
 * @property int $userId
 * @property string $action self::ACTION_*
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $userAgent
 * @property \DateTime $createTimestamp
 * @ORM\Table(name="govbr_satisfaction_reveal")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 * @package GovBrSatisfaction
 */
class SatisfactionReveal extends \MapasCulturais\Entity
{
    /** Janela aberta, com o motivo. */
    const ACTION_UNLOCK = 'liberar';

    const ACTION_REVEAL = 'revelar';

    const ACTION_COPY = 'copiar';

    /** Pedido recusado: fora da lista ou sem janela. */
    const ACTION_DENIED = 'negado';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="govbr_satisfaction_reveal_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var int|null
     *
     * @ORM\Column(name="request_id", type="integer", nullable=true)
     */
    protected $requestId;

    /**
     * @var int|null
     *
     * @ORM\Column(name="attempt_id", type="integer", nullable=true)
     */
    protected $attemptId;

    /**
     * @var int
     *
     * @ORM\Column(name="user_id", type="integer", nullable=false)
     */
    protected $userId;

    /**
     * @var string
     *
     * @ORM\Column(name="action", type="string", length=16, nullable=false)
     */
    protected $action;

    /**
     * @var string|null
     *
     * @ORM\Column(name="reason", type="text", nullable=true)
     */
    protected $reason;

    /**
     * @var string|null
     *
     * @ORM\Column(name="ip", type="string", length=45, nullable=true)
     */
    protected $ip;

    /**
     * @var string|null
     *
     * @ORM\Column(name="user_agent", type="string", length=255, nullable=true)
     */
    protected $userAgent;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

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
