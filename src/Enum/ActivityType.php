<?php

declare(strict_types=1);

namespace App\Enum;

enum ActivityType: string
{
    case IDEA_ADDED = 'idea_added';
    case IDEA_SELECTED = 'idea_selected';
    case IDEA_DISCARDED = 'idea_discarded';
    case IDEA_PURCHASED = 'idea_purchased';
    case COMMENT_ADDED = 'comment_added';
    case PARTICIPANT_ADDED = 'participant_added';
    case PARTICIPANT_REMOVED = 'participant_removed';
    case PROJECT_LOCKED = 'project_locked';
    case PROJECT_UNLOCKED = 'project_unlocked';
    case PROJECT_DELIVERED = 'project_delivered';
    case REIMBURSEMENT_CREATED = 'reimbursement_created';

    /**
     * @param array<string, string|int|float|null> $meta
     */
    public function formatMessage(string $userName, array $meta = []): string
    {
        $ideaName = (string) ($meta['idea_name'] ?? '');
        $price = (string) ($meta['price'] ?? '');
        $amount = (string) ($meta['amount'] ?? '');

        return match ($this) {
            self::IDEA_ADDED => "{$userName} a proposé l'idée « {$ideaName} »",
            self::IDEA_SELECTED => "{$userName} a sélectionné l'idée « {$ideaName} »",
            self::IDEA_DISCARDED => "{$userName} a écarté l'idée « {$ideaName} »",
            self::IDEA_PURCHASED => "{$userName} a acheté « {$ideaName} » pour {$price} €",
            self::COMMENT_ADDED => "{$userName} a commenté « {$ideaName} »",
            self::PARTICIPANT_ADDED => "{$userName} a rejoint le projet",
            self::PARTICIPANT_REMOVED => "{$userName} a quitté le projet",
            self::PROJECT_LOCKED => "{$userName} a verrouillé le projet",
            self::PROJECT_UNLOCKED => "{$userName} a déverrouillé le projet",
            self::PROJECT_DELIVERED => "{$userName} a marqué le projet comme remis",
            self::REIMBURSEMENT_CREATED => "{$userName} a enregistré un remboursement de {$amount} €",
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::IDEA_ADDED => 'material-symbols:lightbulb',
            self::IDEA_SELECTED => 'material-symbols:check-circle',
            self::IDEA_DISCARDED => 'material-symbols:cancel',
            self::IDEA_PURCHASED => 'material-symbols:shopping-cart',
            self::COMMENT_ADDED => 'material-symbols:comment',
            self::PARTICIPANT_ADDED => 'material-symbols:person-add',
            self::PARTICIPANT_REMOVED => 'material-symbols:person-remove',
            self::PROJECT_LOCKED => 'material-symbols:lock',
            self::PROJECT_UNLOCKED => 'material-symbols:lock-open',
            self::PROJECT_DELIVERED => 'material-symbols:redeem',
            self::REIMBURSEMENT_CREATED => 'material-symbols:euro',
        };
    }
}
