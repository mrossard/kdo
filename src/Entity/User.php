<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// @mago-expect lint:too-many-properties,too-many-methods,cyclomatic-complexity,kan-defect
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastname = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $uiPreferences = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var ?string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    /**
     * @var Collection<int, Wish>
     */
    #[ORM\OneToMany(targetEntity: Wish::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $wishes;

    /**
     * @var Collection<int, WishlistShare>
     */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: WishlistShare::class, orphanRemoval: true)]
    private Collection $sentShares;

    /**
     * @var Collection<int, WishlistShare>
     */
    #[ORM\OneToMany(mappedBy: 'recipient', targetEntity: WishlistShare::class, orphanRemoval: true)]
    private Collection $receivedShares;

    /**
     * @var Collection<int, GiftProject>
     */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: GiftProject::class, orphanRemoval: true)]
    private Collection $ownedGiftProjects;

    /**
     * @var Collection<int, GiftProject>
     */
    #[ORM\OneToMany(mappedBy: 'recipient', targetEntity: GiftProject::class, orphanRemoval: true)]
    private Collection $targetedGiftProjects;

    /**
     * @var Collection<int, GiftProject>
     */
    #[ORM\ManyToMany(targetEntity: GiftProject::class, mappedBy: 'participants')]
    private Collection $participatingGiftProjects;

    /**
     * @var Collection<int, GiftIdea>
     */
    #[ORM\OneToMany(mappedBy: 'proposer', targetEntity: GiftIdea::class, orphanRemoval: true)]
    private Collection $proposedGiftIdeas;

    /**
     * @var Collection<int, Comment>
     */
    #[ORM\OneToMany(mappedBy: 'author', targetEntity: Comment::class, orphanRemoval: true)]
    private Collection $comments;

    /**
     * @var Collection<int, ProjectReadStatus>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ProjectReadStatus::class, orphanRemoval: true)]
    private Collection $projectReadStatuses;

    /**
     * @var Collection<int, ContactRequest>
     */
    #[ORM\OneToMany(mappedBy: 'sender', targetEntity: ContactRequest::class, orphanRemoval: true)]
    private Collection $sentContactRequests;

    /**
     * @var Collection<int, ContactRequest>
     */
    #[ORM\OneToMany(mappedBy: 'recipient', targetEntity: ContactRequest::class, orphanRemoval: true)]
    private Collection $receivedContactRequests;

    /**
     * @var Collection<int, Activity>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Activity::class, orphanRemoval: true)]
    private Collection $activities;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Vote::class, orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->wishes = new ArrayCollection();
        $this->sentShares = new ArrayCollection();
        $this->receivedShares = new ArrayCollection();
        $this->ownedGiftProjects = new ArrayCollection();
        $this->targetedGiftProjects = new ArrayCollection();
        $this->participatingGiftProjects = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->projectReadStatuses = new ArrayCollection();
        $this->proposedGiftIdeas = new ArrayCollection();
        $this->sentContactRequests = new ArrayCollection();
        $this->receivedContactRequests = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getDisplayName(): string
    {
        if ($this->firstname && $this->lastname) {
            return $this->firstname . ' ' . $this->lastname;
        }
        if ($this->firstname) {
            return $this->firstname;
        }
        return $this->email ?? '';
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        $identifier = (string) $this->email;

        return $identifier !== '' ? $identifier : 'anonymous';
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(#[\SensitiveParameter] string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
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
            $wish->setUser($this);
        }

        return $this;
    }

    public function removeWish(Wish $wish): static
    {
        if ($this->wishes->removeElement($wish)) {
            // set the owning side to null (unless already changed)
            if ($wish->getUser() === $this) {
                $wish->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WishlistShare>
     */
    public function getSentShares(): Collection
    {
        return $this->sentShares;
    }

    public function addSentShare(WishlistShare $sentShare): static
    {
        if (!$this->sentShares->contains($sentShare)) {
            $this->sentShares->add($sentShare);
            $sentShare->setOwner($this);
        }

        return $this;
    }

    public function removeSentShare(WishlistShare $sentShare): static
    {
        if ($this->sentShares->removeElement($sentShare)) {
            // set the owning side to null (unless already changed)
            if ($sentShare->getOwner() === $this) {
                $sentShare->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WishlistShare>
     */
    public function getReceivedShares(): Collection
    {
        return $this->receivedShares;
    }

    public function addReceivedShare(WishlistShare $receivedShare): static
    {
        if (!$this->receivedShares->contains($receivedShare)) {
            $this->receivedShares->add($receivedShare);
            $receivedShare->setRecipient($this);
        }

        return $this;
    }

    public function removeReceivedShare(WishlistShare $receivedShare): static
    {
        if ($this->receivedShares->removeElement($receivedShare)) {
            // set the owning side to null (unless already changed)
            if ($receivedShare->getRecipient() === $this) {
                $receivedShare->setRecipient(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, GiftProject>
     */
    public function getOwnedGiftProjects(): Collection
    {
        return $this->ownedGiftProjects;
    }

    /**
     * @return Collection<int, GiftProject>
     */
    public function getTargetedGiftProjects(): Collection
    {
        return $this->targetedGiftProjects;
    }

    /**
     * @return Collection<int, GiftProject>
     */
    public function getParticipatingGiftProjects(): Collection
    {
        return $this->participatingGiftProjects;
    }

    /**
     * @return Collection<int, GiftIdea>
     */
    public function getProposedGiftIdeas(): Collection
    {
        return $this->proposedGiftIdeas;
    }

    public function addProposedGiftIdea(GiftIdea $proposedGiftIdea): static
    {
        if (!$this->proposedGiftIdeas->contains($proposedGiftIdea)) {
            $this->proposedGiftIdeas->add($proposedGiftIdea);
            $proposedGiftIdea->setProposer($this);
        }

        return $this;
    }

    public function removeProposedGiftIdea(GiftIdea $proposedGiftIdea): static
    {
        if ($this->proposedGiftIdeas->removeElement($proposedGiftIdea)) {
            // set the owning side to null (unless already changed)
            if ($proposedGiftIdea->getProposer() === $this) {
                $proposedGiftIdea->setProposer(null);
            }
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
            $comment->setAuthor($this);
        }

        return $this;
    }

    public function removeComment(Comment $comment): static
    {
        if ($this->comments->removeElement($comment)) {
            // set the owning side to null (unless already changed)
            if ($comment->getAuthor() === $this) {
                $comment->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProjectReadStatus>
     */
    public function getProjectReadStatuses(): Collection
    {
        return $this->projectReadStatuses;
    }

    public function addProjectReadStatus(ProjectReadStatus $projectReadStatus): static
    {
        if (!$this->projectReadStatuses->contains($projectReadStatus)) {
            $this->projectReadStatuses->add($projectReadStatus);
            $projectReadStatus->setUser($this);
        }

        return $this;
    }

    public function removeProjectReadStatus(ProjectReadStatus $projectReadStatus): static
    {
        if ($this->projectReadStatuses->removeElement($projectReadStatus)) {
            // set the owning side to null (unless already changed)
            if ($projectReadStatus->getUser() === $this) {
                $projectReadStatus->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUiPreferences(): ?array
    {
        return $this->uiPreferences;
    }

    /**
     * @param array<string, mixed>|null $uiPreferences
     */
    public function setUiPreferences(?array $uiPreferences): static
    {
        $this->uiPreferences = $uiPreferences;

        return $this;
    }

    /**
     * @return Collection<int, ContactRequest>
     */
    public function getSentContactRequests(): Collection
    {
        return $this->sentContactRequests;
    }

    public function addSentContactRequest(ContactRequest $sentContactRequest): static
    {
        if (!$this->sentContactRequests->contains($sentContactRequest)) {
            $this->sentContactRequests->add($sentContactRequest);
            $sentContactRequest->setSender($this);
        }

        return $this;
    }

    public function removeSentContactRequest(ContactRequest $sentContactRequest): static
    {
        if ($this->sentContactRequests->removeElement($sentContactRequest)) {
            // set the owning side to null (unless already changed)
            if ($sentContactRequest->getSender() === $this) {
                $sentContactRequest->setSender(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContactRequest>
     */
    public function getReceivedContactRequests(): Collection
    {
        return $this->receivedContactRequests;
    }

    public function addReceivedContactRequest(ContactRequest $receivedContactRequest): static
    {
        if (!$this->receivedContactRequests->contains($receivedContactRequest)) {
            $this->receivedContactRequests->add($receivedContactRequest);
            $receivedContactRequest->setRecipient($this);
        }

        return $this;
    }

    public function removeReceivedContactRequest(ContactRequest $receivedContactRequest): static
    {
        if ($this->receivedContactRequests->removeElement($receivedContactRequest)) {
            // set the owning side to null (unless already changed)
            if ($receivedContactRequest->getRecipient() === $this) {
                $receivedContactRequest->setRecipient(null);
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
            $activity->setUser($this);
        }

        return $this;
    }

    public function removeActivity(Activity $activity): static
    {
        if ($this->activities->removeElement($activity)) {
            if ($activity->getUser() === $this) {
                $activity->setUser(null);
            }
        }

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
            $vote->setUser($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            if ($vote->getUser() === $this) {
                $vote->setUser(null);
            }
        }

        return $this;
    }
}
