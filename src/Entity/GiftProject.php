<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GiftProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// @mago-expect lint:too-many-properties,too-many-methods,cyclomatic-complexity
#[ORM\Entity(repositoryClass: GiftProjectRepository::class)]
class GiftProject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du projet est obligatoire.')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isLocked = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $targetDate = null;

    #[ORM\ManyToOne(inversedBy: 'ownedGiftProjects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(inversedBy: 'targetedGiftProjects')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $recipient = null;

    #[ORM\ManyToOne(inversedBy: 'targetedGiftProjects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ManagedPerson $managedRecipient = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $recipientName = null;

    /**
     * @var array<int|string, float|int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $participantShares = [];

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'participatingGiftProjects')]
    private Collection $participants;

    /**
     * @var Collection<int, GiftIdea>
     */
    #[ORM\OneToMany(mappedBy: 'giftProject', targetEntity: GiftIdea::class, orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $giftIdeas;

    /**
     * @var Collection<int, Reimbursement>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Reimbursement::class, orphanRemoval: true)]
    private Collection $reimbursements;

    /**
     * @var Collection<int, Activity>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Activity::class, orphanRemoval: true)]
    private Collection $activities;

    /**
     * @var Collection<int, Comment>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Comment::class, orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $comments;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->giftIdeas = new ArrayCollection();
        $this->reimbursements = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->comments = new ArrayCollection();
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function setLocked(bool $isLocked): static
    {
        $this->isLocked = $isLocked;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setArchived(bool $isArchived): static
    {
        $this->isArchived = $isArchived;

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getTargetDate(): ?\DateTimeImmutable
    {
        return $this->targetDate;
    }

    public function setTargetDate(?\DateTimeImmutable $targetDate): static
    {
        $this->targetDate = $targetDate;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function setRecipient(?User $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getManagedRecipient(): ?ManagedPerson
    {
        return $this->managedRecipient;
    }

    public function setManagedRecipient(?ManagedPerson $managedRecipient): static
    {
        $this->managedRecipient = $managedRecipient;

        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $this->recipientName = $recipientName;

        return $this;
    }

    public function getRecipientDisplayName(): string
    {
        if ($this->recipient !== null) {
            return $this->recipient->getDisplayName();
        }

        if ($this->managedRecipient !== null) {
            return $this->managedRecipient->getDisplayName();
        }

        return $this->recipientName ?? 'Anonyme';
    }

    public function hasWishlistRecipient(): bool
    {
        return $this->recipient !== null || $this->managedRecipient !== null;
    }

    /**
     * @return Collection<int, Wish>
     */
    public function getRecipientWishes(): Collection
    {
        if ($this->recipient !== null) {
            return $this->recipient->getWishes();
        }

        if ($this->managedRecipient !== null) {
            return $this->managedRecipient->getWishes();
        }

        return new ArrayCollection();
    }

    /**
     * @return Collection<int, User>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(User $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }

        return $this;
    }

    public function removeParticipant(User $participant): static
    {
        $this->participants->removeElement($participant);

        return $this;
    }

    /**
     * @return Collection<int, GiftIdea>
     */
    public function getGiftIdeas(): Collection
    {
        return $this->giftIdeas;
    }

    public function addGiftIdea(GiftIdea $giftIdea): static
    {
        if (!$this->giftIdeas->contains($giftIdea)) {
            $this->giftIdeas->add($giftIdea);
            $giftIdea->setGiftProject($this);
        }

        return $this;
    }

    public function removeGiftIdea(GiftIdea $giftIdea): static
    {
        if ($this->giftIdeas->removeElement($giftIdea)) {
            // set the owning side to null (unless already changed)
            if ($giftIdea->getGiftProject() === $this) {
                $giftIdea->setGiftProject(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reimbursement>
     */
    public function getReimbursements(): Collection
    {
        return $this->reimbursements;
    }

    public function addReimbursement(Reimbursement $reimbursement): static
    {
        if (!$this->reimbursements->contains($reimbursement)) {
            $this->reimbursements->add($reimbursement);
            $reimbursement->setProject($this);
        }

        return $this;
    }

    public function removeReimbursement(Reimbursement $reimbursement): static
    {
        if ($this->reimbursements->removeElement($reimbursement)) {
            // set the owning side to null (unless already changed)
            if ($reimbursement->getProject() === $this) {
                $reimbursement->setProject(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(Activity $activity): static
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
            $activity->setProject($this);
        }

        return $this;
    }

    public function removeActivity(Activity $activity): static
    {
        if ($this->activities->removeElement($activity)) {
            if ($activity->getProject() === $this) {
                $activity->setProject(null);
            }
        }

        return $this;
    }

    /**
     * @return array<int|string, float|int>
     */
    public function getParticipantShares(): array
    {
        return $this->participantShares;
    }

    /**
     * @param array<int|string, float|int> $participantShares
     */
    public function setParticipantShares(array $participantShares): static
    {
        $this->participantShares = $participantShares;

        return $this;
    }

    public function getParticipantShare(User $user): float
    {
        $userId = $user->getId();
        if ($userId === null) {
            return 1.0;
        }

        $share = $this->participantShares[$userId] ?? $this->participantShares[(string) $userId] ?? 1.0;

        return max(0.0, (float) $share);
    }

    public function setParticipantShare(User $user, float $share): static
    {
        $userId = $user->getId();
        if ($userId !== null) {
            $this->participantShares[(string) $userId] = max(0.0, $share);
        }

        return $this;
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(Comment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setProject($this);
        }

        return $this;
    }

    public function removeComment(Comment $comment): static
    {
        if ($this->comments->removeElement($comment)) {
            if ($comment->getProject() === $this) {
                $comment->setProject(null);
            }
        }

        return $this;
    }
}
