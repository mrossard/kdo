<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GiftIdea;
use App\Entity\GiftProject;
use App\Entity\User;
use App\Enum\GiftIdeaStatus;
use App\Security\Voter\GiftProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// @mago-expect lint:cyclomatic-complexity
abstract class AbstractProjectController extends AbstractHtmxController
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

        return $this->redirectOrHtmx($request, 'app_project_show', ['id' => $project->getId(), 'tab' => $tab]);
    }

    protected function renderIdeasPartial(GiftProject $project, Request $request): Response
    {
        $sort = $request->query->getString('sort', $request->request->getString('sort', 'status'));

        return $this->render('gift_project/_ideas.html.twig', [
            'project' => $project,
            'gift_ideas' => $this->sortGiftIdeas($project, $sort),
            'current_sort' => $sort,
        ]);
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
        usort($sortedGiftIdeas, static fn(GiftIdea $a, GiftIdea $b): int => match ($sort) {
            'price_asc' => self::parseIdeaPrice($a) <=> self::parseIdeaPrice($b),
            'price_desc' => self::parseIdeaPrice($b) <=> self::parseIdeaPrice($a),
            'priority_desc' => $b->getPriority() <=> $a->getPriority(),
            default => self::compareIdeaByStatus($a, $b),
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
