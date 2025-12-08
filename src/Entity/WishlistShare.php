<?php

namespace App\Entity;

use App\Repository\WishlistShareRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WishlistShareRepository::class)]
class WishlistShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sentShares')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(inversedBy: 'receivedShares')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $recipient = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $sharedAt = null;

    public function __construct()
    {
        $this->sharedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSharedAt(): ?\DateTimeImmutable
    {
        return $this->sharedAt;
    }

    public function setSharedAt(\DateTimeImmutable $sharedAt): static
    {
        $this->sharedAt = $sharedAt;

        return $this;
    }
}