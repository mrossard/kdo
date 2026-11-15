<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GiftIdeaStatus;
use App\Enum\WishPriority;
use App\Repository\GiftIdeaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// @mago-expect lint:too-many-properties,too-many-methods,cyclomatic-complexity
#[ORM\Entity(repositoryClass: GiftIdeaRepository::class)]
class GiftIdea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $price = null; // Stored as string to avoid float precision issues

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => WishPriority::NORMAL->value])]
    private int $priority = WishPriority::NORMAL->value;

    #[ORM\ManyToOne(inversedBy: 'giftIdeas')]
    #[ORM\JoinColumn(nullable: false)]
    private ?GiftProject $giftProject = null;

    #[ORM\ManyToOne(inversedBy: 'proposedGiftIdeas')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $proposer = null;

    #[ORM\Column(length: 20, options: ['default' => 'PROPOSED'])]
    private ?string $status = self::STATUS_PROPOSED;

    #[ORM\ManyToOne(inversedBy: 'giftIdeasUsedFor')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Wish $fromWish = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $purchasedBy = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $purchasePrice = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCrowdfunded = false;

    /**
     * @var Collection<int, GiftIdeaContribution>
     */
    #[ORM\OneToMany(
        mappedBy: 'giftIdea',
        targetEntity: GiftIdeaContribution::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $contributions;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(mappedBy: 'giftIdea', targetEntity: Vote::class, orphanRemoval: true)]
    private Collection $votes;

    public const string STATUS_PROPOSED = GiftIdeaStatus::PROPOSED->value;
    public const string STATUS_SELECTED = GiftIdeaStatus::SELECTED->value;
    public const string STATUS_DISCARDED = GiftIdeaStatus::DISCARDED->value;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
        $this->contributions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(?\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getFromWish(): ?Wish
    {
        return $this->fromWish;
    }

    public function setFromWish(?Wish $fromWish): static
    {
        $this->fromWish = $fromWish;

        return $this;
    }

    public function getPurchasedBy(): ?User
    {
        return $this->purchasedBy;
    }

    public function setPurchasedBy(?User $purchasedBy): static
    {
        $this->purchasedBy = $purchasedBy;

        return $this;
    }

    public function getPurchasePrice(): ?string
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?string $purchasePrice): static
    {
        $this->purchasePrice = $purchasePrice;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = (WishPriority::tryFrom($priority) ?? WishPriority::NORMAL)->value;

        return $this;
    }

    public function getPriorityEnum(): WishPriority
    {
        return WishPriority::tryFrom($this->priority) ?? WishPriority::NORMAL;
    }

    public function getGiftProject(): ?GiftProject
    {
        return $this->giftProject;
    }

    public function setGiftProject(?GiftProject $giftProject): static
    {
        $this->giftProject = $giftProject;

        return $this;
    }

    public function getProposer(): ?User
    {
        return $this->proposer;
    }

    public function setProposer(?User $proposer): static
    {
        $this->proposer = $proposer;

        return $this;
    }

    /**
     * @return Collection<int, Vote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setGiftIdea($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            if ($vote->getGiftIdea() === $this) {
                $vote->setGiftIdea(null);
            }
        }

        return $this;
    }

    public function getVoteCount(): int
    {
        return $this->votes->count();
    }

    public function hasVoteFromUser(User $user): bool
    {
        foreach ($this->votes as $vote) {
            if ($vote->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    public function isCrowdfunded(): bool
    {
        return $this->isCrowdfunded;
    }

    public function setIsCrowdfunded(bool $isCrowdfunded): static
    {
        $this->isCrowdfunded = $isCrowdfunded;

        return $this;
    }

    /**
     * @return Collection<int, GiftIdeaContribution>
     */
    public function getContributions(): Collection
    {
        return $this->contributions;
    }

    public function addContribution(GiftIdeaContribution $contribution): static
    {
        if (!$this->contributions->contains($contribution)) {
            $this->contributions->add($contribution);
            $contribution->setGiftIdea($this);
        }

        return $this;
    }

    public function removeContribution(GiftIdeaContribution $contribution): static
    {
        if ($this->contributions->removeElement($contribution)) {
            if ($contribution->getGiftIdea() === $this) {
                $contribution->setGiftIdea(null);
            }
        }

        return $this;
    }

    public function getTotalContributed(): float
    {
        $total = 0.0;
        foreach ($this->contributions as $contribution) {
            $total += $contribution->getAmountAsFloat();
        }

        return $total;
    }

    public function getRemainingAmount(): float
    {
        $rawPrice = $this->price;
        $targetPrice = $rawPrice !== null && is_numeric($rawPrice) ? (float) $rawPrice : 0.0;

        return max(0.0, $targetPrice - $this->getTotalContributed());
    }

    public function getProgressPercentage(): float
    {
        $rawPrice = $this->price;
        $targetPrice = $rawPrice !== null && is_numeric($rawPrice) ? (float) $rawPrice : 0.0;
        if ($targetPrice <= 0.0) {
            return 0.0;
        }

        return min(100.0, round(($this->getTotalContributed() / $targetPrice) * 100, 1));
    }

    public function getContributionForUser(User $user): ?GiftIdeaContribution
    {
        foreach ($this->contributions as $contribution) {
            if ($contribution->getUser() === $user) {
                return $contribution;
            }
        }

        return null;
    }
}
