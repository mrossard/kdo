<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContactRequestStatus;
use App\Repository\ContactRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContactRequestRepository::class)]
#[ORM\Index(name: 'idx_contact_request_email_status', columns: ['recipient_email', 'status'])]
#[UniqueEntity(fields: ['invitationToken'], message: 'Ce jeton d\'invitation existe déjà. Veuillez réessayer.')]
class ContactRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sentContactRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $sender = null;

    // Recipient can be null if not yet registered
    #[ORM\ManyToOne(inversedBy: 'receivedContactRequests')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $recipient = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipientEmail = null; // To store email if recipient is not registered

    #[ORM\Column(type: 'uuid', unique: true, nullable: true)]
    private ?Uuid $invitationToken = null; // For unregistered users

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private ?string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public const string STATUS_PENDING = ContactRequestStatus::PENDING->value;
    public const string STATUS_ACCEPTED = ContactRequestStatus::ACCEPTED->value;
    public const string STATUS_DECLINED = ContactRequestStatus::DECLINED->value;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function setSender(?User $sender): static
    {
        $this->sender = $sender;

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

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): static
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getInvitationToken(): ?Uuid
    {
        return $this->invitationToken;
    }

    public function setInvitationToken(#[\SensitiveParameter] ?Uuid $invitationToken): static
    {
        $this->invitationToken = $invitationToken;

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

    public function getCreatedAt(): ?\DateTimeImmutable
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

    // Helper to get the display name of the recipient (email or user's display name)
    public function getRecipientDisplayName(): string
    {
        return $this->recipient ? $this->recipient->getDisplayName() : $this->recipientEmail ?? '';
    }
}
