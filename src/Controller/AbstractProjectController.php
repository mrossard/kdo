<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Enum\GiftIdeaStatus;
use App\Security\Voter\GiftProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// @mago-expect lint:cyclomatic-complexity
abstract class AbstractProjectController extends AbstractController
{
    /**
     * If the project is delivered, prevent any modification and redirect to show page with a warning.
     */
    protected function denyWhenDelivered(GiftProject $project, Request $request, string $tab = 'ideas'): ?Response
    {
        if ($project->getDeliveredAt() === null) {
            return null;
        }

        $this->addFlash('warning', "Le projet a été remis et n'est plus modifiable.");

        if ($request->headers->get('HX-Request')) {
            return new Response(null, 204, [
                'HX-Redirect' => $this->generateUrl('app_project_show', ['id' => $project->getId(), 'tab' => $tab]),
            ]);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId(), 'tab' => $tab]);
    }

    protected function denyUnlessCanViewProject(
        GiftProject $project,
        User $user,
        string $recipientMessage = 'Surprise ! Vous ne pouvez pas voir ce projet.',
    ): void {
        if ($project->getRecipient() === $user) {
            throw $this->createAccessDeniedException($recipientMessage);
        }
        if (!$this->isGranted(GiftProjectVoter::VIEW, $project)) {
            throw $this->createAccessDeniedException();
        }
    }

    protected function requireIdeaProject(GiftIdea $giftIdea): GiftProject
    {
        $project = $giftIdea->getGiftProject();
        if ($project === null) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    /**
     * @return list<GiftIdea>
     */
    protected function sortGiftIdeas(GiftProject $project, string $sort): array
    {
        $sortedGiftIdeas = array_values($project->getGiftIdeas()->toArray());
        usort($sortedGiftIdeas, static function (GiftIdea $a, GiftIdea $b) use ($sort): int {
            if ($sort === 'price_asc') {
                return self::parseIdeaPrice($a) <=> self::parseIdeaPrice($b);
            }
            if ($sort === 'price_desc') {
                return self::parseIdeaPrice($b) <=> self::parseIdeaPrice($a);
            }
            if ($sort === 'priority_desc') {
                return $b->getPriority() <=> $a->getPriority();
            }

            return self::compareIdeaByStatus($a, $b);
        });

        return $sortedGiftIdeas;
    }

    private static function parseIdeaPrice(GiftIdea $idea): float
    {
        $rawPrice = $idea->getPrice();

        return $rawPrice !== null && is_numeric($rawPrice) ? (float) $rawPrice : 0.0;
    }

    private static function compareIdeaByStatus(GiftIdea $a, GiftIdea $b): int
    {
        $statusA = GiftIdeaStatus::tryFrom($a->getStatus() ?? '')?->getSortWeight() ?? 99;
        $statusB = GiftIdeaStatus::tryFrom($b->getStatus() ?? '')?->getSortWeight() ?? 99;

        if ($statusA === $statusB) {
            return ($b->getId() ?? 0) <=> ($a->getId() ?? 0);
        }

        return $statusA <=> $statusB;
    }
}
