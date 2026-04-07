<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookPriceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookPriceRepository::class)]
#[ORM\Table(name: 'book_prices')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_book_prices_updated_at')]
class BookPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 13)]
    private string $isbn;

    #[ORM\Column(type: 'integer')]
    private int $priceCents;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $offersCount;

    #[ORM\Column(type: 'string', length: 32)]
    private string $source;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $isbn,
        int $priceCents,
        string $currency = 'EUR',
        ?int $offersCount = null,
        string $source = 'nudger',
    ) {
        $this->isbn = $isbn;
        $this->priceCents = $priceCents;
        $this->currency = $currency;
        $this->offersCount = $offersCount;
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getIsbn(): string
    {
        return $this->isbn;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOffersCount(): ?int
    {
        return $this->offersCount;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(int $priceCents, string $currency = 'EUR', ?int $offersCount = null, string $source = 'nudger'): static
    {
        $this->priceCents = $priceCents;
        $this->currency = $currency;
        $this->offersCount = $offersCount;
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function toArray(): array
    {
        return [
            'isbn' => $this->isbn,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'offers_count' => $this->offersCount,
            'source' => $this->source,
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::RFC3339),
        ];
    }
}
