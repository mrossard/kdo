<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActivityType;
use App\Repository\ActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Activity
{
    public const string TYPE_IDEA_ADDED = ActivityType::IDEA_ADDED->value;
    public const string TYPE_IDEA_SELECTED = ActivityType::IDEA_SELECTED->value;
    public const string TYPE_IDEA_DISCARDED = ActivityType::IDEA_DISCARDED->value;
    public const string TYPE_IDEA_PURCHASED = ActivityType::IDEA_PURCHASED->value;
    public const string TYPE_COMMENT_ADDED = ActivityType::COMMENT_ADDED->value;
    public const string TYPE_PARTICIPANT_ADDED = ActivityType::PARTICIPANT_ADDED->value;
    public const string TYPE_PARTICIPANT_REMOVED = ActivityType::PARTICIPANT_REMOVED->value;
    public const string TYPE_PROJECT_LOCKED = ActivityType::PROJECT_LOCKED->value;
    public const string TYPE_PROJECT_UNLOCKED = ActivityType::PROJECT_UNLOCKED->value;
    public const string TYPE_PROJECT_DELIVERED = ActivityType::PROJECT_DELIVERED->value;
    public const string TYPE_REIMBURSEMENT_CREATED = ActivityType::REIMBURSEMENT_CREATED->value;
    public const string TYPE_CROWDFUND_CONTRIBUTED = ActivityType::CROWDFUND_CONTRIBUTED->value;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false)]
    private ?GiftProject $project = null;

    #[ORM\ManyToOne(inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?GiftProject
    {
        return $this->project;
    }

    public function setProject(?GiftProject $project): static
    {
        $this->project = $project;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

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

    public function getMessage(): string
    {
        $userName = $this->getUser()?->getDisplayName() ?? 'Quelqu\'un';
        /** @var array<string, string|int|float|null> $meta */
        $meta = $this->getMeta() ?? [];
        $type = ActivityType::tryFrom($this->getType() ?? '');

        return $type !== null ? $type->formatMessage($userName, $meta) : 'Action inconnue';
    }

    public function getIcon(): string
    {
        $type = ActivityType::tryFrom($this->getType() ?? '');

        return $type !== null ? $type->getIcon() : 'material-symbols:info';
    }
}
