<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ManagedPersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ManagedPersonRepository::class)]
class ManagedPerson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Veuillez saisir un nom.')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'managedPersons')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $manager = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'coManagedPersons')]
    #[ORM\JoinTable(name: 'managed_person_co_manager')]
    private Collection $coManagers;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Wish>
     */
    #[ORM\OneToMany(targetEntity: Wish::class, mappedBy: 'managedPerson', orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $wishes;

    /**
     * @var Collection<int, GiftProject>
     */
    #[ORM\OneToMany(targetEntity: GiftProject::class, mappedBy: 'managedRecipient')]
    private Collection $targetedGiftProjects;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->coManagers = new ArrayCollection();
        $this->wishes = new ArrayCollection();
        $this->targetedGiftProjects = new ArrayCollection();
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

    public function getDisplayName(): string
    {
        return $this->name ?? '';
    }

    public function getManager(): ?User
    {
        return $this->manager;
    }

    public function setManager(?User $manager): static
    {
        $this->manager = $manager;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getCoManagers(): Collection
    {
        return $this->coManagers;
    }

    public function addCoManager(User $coManager): static
    {
        if ($coManager !== $this->manager && !$this->coManagers->contains($coManager)) {
            $this->coManagers->add($coManager);
        }

        return $this;
    }

    public function removeCoManager(User $coManager): static
    {
        $this->coManagers->removeElement($coManager);

        return $this;
    }

    public function isManagedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->manager === $user || $this->coManagers->contains($user);
    }

    /**
     * @return list<User>
     */
    public function getAllManagers(): array
    {
        $managers = [];
        if ($this->manager !== null) {
            $managers[] = $this->manager;
        }
        foreach ($this->coManagers as $coManager) {
            if (in_array($coManager, $managers, true)) {
                continue;
            }
            $managers[] = $coManager;
        }

        return $managers;
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

    /**
     * @return Collection<int, Wish>
     */
    public function getWishes(): Collection
    {
        return $this->wishes;
    }

    public function addWish(Wish $wish): static
    {
        if (!$this->wishes->contains($wish)) {
            $this->wishes->add($wish);
            $wish->setManagedPerson($this);
        }

        return $this;
    }

    public function removeWish(Wish $wish): static
    {
        if ($this->wishes->removeElement($wish)) {
            if ($wish->getManagedPerson() === $this) {
                $wish->setManagedPerson(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GiftProject>
     */
    public function getTargetedGiftProjects(): Collection
    {
        return $this->targetedGiftProjects;
    }
}
