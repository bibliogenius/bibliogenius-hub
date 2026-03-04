<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BorrowRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BorrowRequestRepository::class)]
#[ORM\Table(name: 'borrow_requests')]
#[ORM\Index(columns: ['lender_node_id', 'status'], name: 'idx_borrow_req_lender')]
#[ORM\Index(columns: ['requester_node_id', 'status'], name: 'idx_borrow_req_requester')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_borrow_req_expires')]
class BorrowRequest
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** Borrow requests expire after 30 days. */
    private const EXPIRY_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $requesterNodeId;

    #[ORM\Column(type: 'string', length: 128)]
    private string $lenderNodeId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $isbn;

    #[ORM\Column(type: 'string', length: 500)]
    private string $bookTitle;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $requesterNodeId, string $lenderNodeId, string $isbn, string $bookTitle = '')
    {
        $this->requesterNodeId = $requesterNodeId;
        $this->lenderNodeId = $lenderNodeId;
        $this->isbn = $isbn;
        $this->bookTitle = $bookTitle;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+' . self::EXPIRY_DAYS . ' days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequesterNodeId(): string
    {
        return $this->requesterNodeId;
    }

    public function getLenderNodeId(): string
    {
        return $this->lenderNodeId;
    }

    public function getIsbn(): string
    {
        return $this->isbn;
    }

    public function getBookTitle(): string
    {
        return $this->bookTitle;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function accept(): static
    {
        $this->status = self::STATUS_ACCEPTED;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reject(): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function cancel(): static
    {
        $this->status = self::STATUS_CANCELLED;
        $this->resolvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'requester_node_id' => $this->requesterNodeId,
            'lender_node_id'    => $this->lenderNodeId,
            'isbn'              => $this->isbn,
            'book_title'        => $this->bookTitle,
            'status'            => $this->status,
            'created_at'        => $this->createdAt->format(\DateTimeInterface::ATOM),
            'resolved_at'       => $this->resolvedAt?->format(\DateTimeInterface::ATOM),
            'expires_at'        => $this->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
