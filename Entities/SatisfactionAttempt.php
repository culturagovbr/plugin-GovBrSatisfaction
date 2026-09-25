<?php

namespace GovBrSatisfaction\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tentativa de um envio ao BSC, com dados pessoais mascarados.
 *
 * @property int $id
 * @property SatisfactionDispatch $dispatch
 * @property int $number
 * @property int $maxAttempts
 * @property string $outcome Valor de \GovBrSatisfaction\Bsc\Outcome ou self::OUTCOME_SIMULATED
 * @property string|null $method
 * @property string|null $endpoint
 * @property int|null $httpStatus
 * @property string|null $payload
 * @property string|null $payloadSealed
 * @property string|null $response
 * @property bool $responseTruncated
 * @property array|null $responseHeaders
 * @property string|null $detail
 * @property \DateTime $sentAt
 * @property int|null $durationMs
 * @ORM\Table(name="govbr_satisfaction_attempt")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 * @package GovBrSatisfaction
 */
class SatisfactionAttempt extends \MapasCulturais\Entity
{
    /** Envio simulado pela fixture, sem requisição HTTP. */
    const OUTCOME_SIMULATED = 'simulado';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="govbr_satisfaction_attempt_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var SatisfactionDispatch
     *
     * @ORM\ManyToOne(targetEntity="GovBrSatisfaction\Entities\SatisfactionDispatch", fetch="LAZY")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="dispatch_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $dispatch;

    /**
     * @var int
     *
     * @ORM\Column(name="number", type="smallint", nullable=false)
     */
    protected $number;

    /**
     * @var int
     *
     * @ORM\Column(name="max_attempts", type="smallint", nullable=false)
     */
    protected $maxAttempts;

    /**
     * @var string
     *
     * @ORM\Column(name="outcome", type="string", length=16, nullable=false)
     */
    protected $outcome;

    /**
     * @var string|null
     *
     * @ORM\Column(name="method", type="string", length=8, nullable=true)
     */
    protected $method;

    /**
     * @var string|null
     *
     * @ORM\Column(name="endpoint", type="text", nullable=true)
     */
    protected $endpoint;

    /**
     * @var int|null
     *
     * @ORM\Column(name="http_status", type="smallint", nullable=true)
     */
    protected $httpStatus;

    /**
     * Corpo enviado, mascarado.
     *
     * @var string|null
     * @ORM\Column(name="payload", type="text", nullable=true)
     */
    protected $payload;

    /**
     * Corpo enviado, cifrado pelo PayloadVault.
     *
     * @var string|null
     * @ORM\Column(name="payload_sealed", type="text", nullable=true)
     */
    protected $payloadSealed;

    /**
     * Corpo da resposta, mascarado.
     *
     * @var string|null
     * @ORM\Column(name="response", type="text", nullable=true)
     */
    protected $response;

    /**
     * @var bool
     *
     * @ORM\Column(name="response_truncated", type="boolean", nullable=false)
     */
    protected $responseTruncated = false;

    /**
     * Linhas de cabeçalho da resposta.
     *
     * @var array|null
     * @ORM\Column(name="response_headers", type="json", nullable=true)
     */
    protected $responseHeaders;

    /**
     * Resumo legível, mascarado.
     *
     * @var string|null
     * @ORM\Column(name="detail", type="string", length=500, nullable=true)
     */
    protected $detail;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="sent_at", type="datetime", nullable=false)
     */
    protected $sentAt;

    /**
     * @var int|null
     *
     * @ORM\Column(name="duration_ms", type="integer", nullable=true)
     */
    protected $durationMs;

    public static function usesPermissionCache(): bool
    {
        return false;
    }

    protected function canUserView($user): bool
    {
        return $user->is(\GovBrSatisfaction\Plugin::ADMIN_ROLE);
    }
}
