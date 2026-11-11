<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Repository\GiftProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// @mago-expect lint:cyclomatic-complexity
#[AsCommand(
    name: 'app:project:send-reminders',
    description: 'Envoie un rappel par e-mail pour les projets cadeaux dont la date cible approche.',
)]
final class SendProjectRemindersCommand extends Command
{
    public function __construct(
        private readonly GiftProjectRepository $giftProjectRepository,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Nombre de jours avant la date cible', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|int|null $rawDays */
        $rawDays = $input->getOption('days');
        $days = is_numeric($rawDays) ? max(0, (int) $rawDays) : 7;

        $targetDate = new \DateTimeImmutable('today')->modify("+{$days} days");
        $projects = $this->giftProjectRepository->findProjectsWithTargetDateOn($targetDate);

        if ($projects === []) {
            $io->success(sprintf('Aucun projet cadeau actif prévu pour le %s.', $targetDate->format('d/m/Y')));

            return Command::SUCCESS;
        }

        $emailsSent = 0;
        foreach ($projects as $project) {
            $emailsSent += $this->sendRemindersForProject($project, $days, $targetDate);
        }

        $io->success(sprintf(
            '%d projet(s) trouvé(s) pour le %s : %d e-mail(s) de rappel envoyé(s).',
            count($projects),
            $targetDate->format('d/m/Y'),
            $emailsSent,
        ));

        return Command::SUCCESS;
    }

    private function sendRemindersForProject(GiftProject $project, int $days, \DateTimeImmutable $targetDate): int
    {
        $proposedCount = 0;
        $selectedCount = 0;
        $purchasedCount = 0;

        foreach ($project->getGiftIdeas() as $idea) {
            if ($idea->getStatus() === GiftIdea::STATUS_PROPOSED) {
                $proposedCount++;
            }
            if ($idea->getStatus() === GiftIdea::STATUS_SELECTED) {
                $selectedCount++;
                if ($idea->getPurchasedBy() !== null) {
                    $purchasedCount++;
                }
            }
        }

        $projectUrl = $this->urlGenerator->generate(
            'app_project_show',
            ['id' => $project->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $members = $this->collectEligibleMembers($project);
        $sent = 0;

        foreach ($members as $member) {
            $emailAddress = $member->getEmail();
            if ($emailAddress === null || $emailAddress === '') {
                continue;
            }

            $email = new Email()
                ->to($emailAddress)
                ->subject(sprintf('Rappel (J-%d) : Projet cadeau « %s » sur KDO', $days, (string) $project->getName()))
                ->text(sprintf(
                    "Bonjour %s,\n\n"
                    . "Le projet cadeau « %s » pour %s arrive à échéance le %s (dans %d jour(s)).\n\n"
                    . "État d'avancement :\n"
                    . "- Idées proposées : %d\n"
                    . "- Idées validées : %d (dont %d achetée(s))\n\n"
                    . "Accéder au projet :\n%s",
                    $member->getDisplayName(),
                    (string) $project->getName(),
                    $project->getRecipientDisplayName(),
                    $targetDate->format('d/m/Y'),
                    $days,
                    $proposedCount,
                    $selectedCount,
                    $purchasedCount,
                    $projectUrl,
                ));

            $this->mailer->send($email);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return list<User>
     */
    private function collectEligibleMembers(GiftProject $project): array
    {
        $recipient = $project->getRecipient();
        /** @var array<int, User> $membersById */
        $membersById = [];

        $owner = $project->getOwner();
        $ownerId = $owner?->getId();
        if ($owner !== null && $owner !== $recipient && $ownerId !== null) {
            $membersById[$ownerId] = $owner;
        }

        foreach ($project->getParticipants() as $participant) {
            $participantId = $participant->getId();
            if ($participant === $recipient || $participantId === null) {
                continue;
            }
            $membersById[$participantId] = $participant;
        }

        return array_values($membersById);
    }
}
