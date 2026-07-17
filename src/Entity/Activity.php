<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Activity
{
    public const TYPE_IDEA_ADDED = 'idea_added';
    public const TYPE_IDEA_SELECTED = 'idea_selected';
    public const TYPE_IDEA_DISCARDED = 'idea_discarded';
    public const TYPE_IDEA_PURCHASED = 'idea_purchased';
    public const TYPE_COMMENT_ADDED = 'comment_added';
    public const TYPE_PARTICIPANT_ADDED = 'participant_added';
    public const TYPE_PARTICIPANT_REMOVED = 'participant_removed';
    public const TYPE_PROJECT_LOCKED = 'project_locked';
    public const TYPE_PROJECT_UNLOCKED = 'project_unlocked';
    public const TYPE_PROJECT_DELIVERED = 'project_delivered';
    public const TYPE_REIMBURSEMENT_CREATED = 'reimbursement_created';

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
        $meta = $this->getMeta() ?? [];

        return match ($this->getType()) {
            self::TYPE_IDEA_ADDED => "{$userName} a proposé l'idée « {$meta['idea_name']} »",
            self::TYPE_IDEA_SELECTED => "{$userName} a sélectionné l'idée « {$meta['idea_name']} »",
            self::TYPE_IDEA_DISCARDED => "{$userName} a écarté l'idée « {$meta['idea_name']} »",
            self::TYPE_IDEA_PURCHASED => "{$userName} a acheté « {$meta['idea_name']} » pour {$meta['price']} €",
            self::TYPE_COMMENT_ADDED => "{$userName} a commenté « {$meta['idea_name']} »",
            self::TYPE_PARTICIPANT_ADDED => "{$userName} a rejoint le projet",
            self::TYPE_PARTICIPANT_REMOVED => "{$userName} a quitté le projet",
            self::TYPE_PROJECT_LOCKED => "{$userName} a verrouillé le projet",
            self::TYPE_PROJECT_UNLOCKED => "{$userName} a déverrouillé le projet",
            self::TYPE_PROJECT_DELIVERED => "{$userName} a marqué le projet comme remis",
            self::TYPE_REIMBURSEMENT_CREATED => "{$userName} a enregistré un remboursement de {$meta['amount']} €",
            default => 'Action inconnue',
        };
    }

    public function getIcon(): string
    {
        return match ($this->getType()) {
            self::TYPE_IDEA_ADDED => 'material-symbols:lightbulb',
            self::TYPE_IDEA_SELECTED => 'material-symbols:check-circle',
            self::TYPE_IDEA_DISCARDED => 'material-symbols:cancel',
            self::TYPE_IDEA_PURCHASED => 'material-symbols:shopping-cart',
            self::TYPE_COMMENT_ADDED => 'material-symbols:comment',
            self::TYPE_PARTICIPANT_ADDED => 'material-symbols:person-add',
            self::TYPE_PARTICIPANT_REMOVED => 'material-symbols:person-remove',
            self::TYPE_PROJECT_LOCKED => 'material-symbols:lock',
            self::TYPE_PROJECT_UNLOCKED => 'material-symbols:lock-open',
            self::TYPE_PROJECT_DELIVERED => 'material-symbols:redeem',
            self::TYPE_REIMBURSEMENT_CREATED => 'material-symbols:euro',
            default => 'material-symbols:info',
        };
    }
}
