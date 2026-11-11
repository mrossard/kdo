<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WishPriority;
use App\Repository\WishRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// @mago-expect lint:too-many-properties
#[ORM\Entity(repositoryClass: WishRepository::class)]
class Wish
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
    private ?string $price = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => WishPriority::NORMAL->value])]
    private int $priority = WishPriority::NORMAL->value;

    #[ORM\ManyToOne(inversedBy: 'wishes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reservedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reservedAt = null;

    /**
     * @var Collection<int, GiftIdea>
     */
    #[ORM\OneToMany(mappedBy: 'fromWish', targetEntity: GiftIdea::class)]
    private Collection $giftIdeasUsedFor;

    public function __construct()
    {
        $this->giftIdeasUsedFor = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getReservedBy(): ?User
    {
        return $this->reservedBy;
    }

    public function setReservedBy(?User $reservedBy): static
    {
        $this->reservedBy = $reservedBy;

        return $this;
    }

    public function getReservedAt(): ?\DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function setReservedAt(?\DateTimeImmutable $reservedAt): static
    {
        $this->reservedAt = $reservedAt;

        return $this;
    }

    public function getActiveProject(): ?GiftProject
    {
        foreach ($this->giftIdeasUsedFor as $idea) {
            if ($idea->getStatus() === GiftIdea::STATUS_DISCARDED) {
                continue;
            }
            $project = $idea->getGiftProject();
            if ($project !== null && !$project->isArchived()) {
                return $project;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, GiftIdea>
     */
    public function getGiftIdeasUsedFor(): Collection
    {
        return $this->giftIdeasUsedFor;
    }

    public function addGiftIdeasUsedFor(GiftIdea $giftIdeasUsedFor): static
    {
        if (!$this->giftIdeasUsedFor->contains($giftIdeasUsedFor)) {
            $this->giftIdeasUsedFor->add($giftIdeasUsedFor);
            $giftIdeasUsedFor->setFromWish($this);
        }

        return $this;
    }

    public function removeGiftIdeasUsedFor(GiftIdea $giftIdeasUsedFor): static
    {
        if ($this->giftIdeasUsedFor->removeElement($giftIdeasUsedFor)) {
            // set the owning side to null (unless already changed)
            if ($giftIdeasUsedFor->getFromWish() === $this) {
                $giftIdeasUsedFor->setFromWish(null);
            }
        }

        return $this;
    }
}
