<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GiftProject;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ProjectMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendParticipantAddedEmail(GiftProject $project, User $addedBy, User $participant): void
    {
        $emailAddress = $participant->getEmail();
        if ($emailAddress === null || $emailAddress === '') {
            return;
        }

        $projectUrl = $this->urlGenerator->generate(
            'app_project_show',
            ['id' => $project->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $targetDate = $project->getTargetDate();
        $targetDateInfo = $targetDate !== null ? "\nDate de l'événement : " . $targetDate->format('d/m/Y') : '';

        $emailMessage = new Email()
            ->to($emailAddress)
            ->subject("Invitation au projet cadeau « {$project->getName()} » sur KDO")
            ->text(
                "Bonjour {$participant->getDisplayName()},\n\n"
                . "{$addedBy->getDisplayName()} vous a ajouté(e) au projet cadeau « {$project->getName()} » pour {$project->getRecipientDisplayName()}.{$targetDateInfo}\n\n"
                . "Accédez au projet pour proposer ou voter sur les idées cadeaux :\n{$projectUrl}",
            );

        $this->mailer->send($emailMessage);
    }

    public function sendContactRequestEmail(
        User $sender,
        string $recipientEmail,
        ?User $recipient,
        #[\SensitiveParameter]
        ?Uuid $invitationToken = null,
    ): void {
        $emailMessage = new Email()->to($recipientEmail)->subject('Nouvelle demande de contact sur KDO');

        if ($recipient !== null) {
            $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $emailMessage->text(
                "Bonjour,\n\n{$sender->getDisplayName()} souhaite vous ajouter à ses contacts sur KDO.\nConnectez-vous pour accepter la demande : {$loginUrl}",
            );
            $this->mailer->send($emailMessage);

            return;
        }

        if ($invitationToken !== null) {
            $registrationUrl = $this->urlGenerator->generate(
                'app_register',
                ['invitation_token' => $invitationToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $emailMessage->text(
                "Bonjour,\n\n{$sender->getDisplayName()} vous invite à rejoindre KDO pour partager vos listes de cadeaux.\n\nCliquez sur le lien suivant pour vous inscrire et accepter la demande :\n{$registrationUrl}",
            );
            $this->mailer->send($emailMessage);
        }
    }
}
