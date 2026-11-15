<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GiftIdeaContributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GiftIdeaContributionRepository::class)]
#[ORM\Table(name: 'gift_idea_contribution')]
#[ORM\UniqueConstraint(name: 'uniq_idea_user_contribution', columns: ['gift_idea_id', 'user_id'])]
class GiftIdeaContribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'contributions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GiftIdea $giftIdea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGiftIdea(): ?GiftIdea
    {
        return $this->giftIdea;
    }

    public function setGiftIdea(?GiftIdea $giftIdea): static
    {
        $this->giftIdea = $giftIdea;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getAmountAsFloat(): float
    {
        return $this->amount !== null && is_numeric($this->amount) ? (float) $this->amount : 0.0;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
