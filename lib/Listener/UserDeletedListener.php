<?php

declare(strict_types=1);

namespace OCA\Tickets\Listener;

use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Volet RGPD (droit à l'effacement) : quand un compte utilisateur est supprimé
 * de l'instance, purge entièrement ce qu'il a produit dans Tickets.
 *
 * Choix retenu (discuté avec l'association, cf. échange sur le point 8 du
 * suivi) : suppression complète plutôt qu'anonymisation. Portée :
 * - tickets dont il est le PROPRIÉTAIRE : supprimés entièrement (pièces
 *   jointes + dossier, commentaires, activité, marqueurs de lecture, puis le
 *   ticket lui-même) — il n'existe qu'un seul propriétaire par ticket, ce
 *   n'est donc jamais la donnée d'un tiers ;
 * - tickets appartenant à un TIERS où il est simplement ASSIGNÉ : le ticket
 *   n'est pas supprimé (ce n'est pas sa donnée), seule l'assignation est
 *   retirée ;
 * - commentaires et pièces jointes qu'il a déposés sur le ticket d'un tiers :
 *   supprimés individuellement, sans toucher au reste du ticket.
 *
 * Volontairement laissé de côté : les entrées d'activité (tickets_activity)
 * où il apparaît comme auteur d'une action sur le ticket d'un tiers (ex. « a
 * changé le statut ») ne sont pas supprimées — elles constituent la trace de
 * suivi du ticket lui-même, pas une donnée personnelle qu'il aurait rédigée ;
 * seul actor_uid y référence encore le compte supprimé.
 */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private TicketMapper $ticketMapper,
        private CommentMapper $commentMapper,
        private ActivityMapper $activityMapper,
        private TicketReadMapper $ticketReadMapper,
        private AttachmentMapper $attachmentMapper,
        private AttachmentService $attachmentService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }

        $uid = $event->getUser()->getUID();

        try {
            $this->deleteOwnedTickets($uid);
            $this->unassignFromForeignTickets($uid);
            $this->deleteForeignContributions($uid);
            $this->ticketReadMapper->deleteByUid($uid);
        } catch (\Throwable $e) {
            // On ne bloque jamais la suppression du compte lui-même pour une
            // erreur côté Tickets : on journalise et on laisse la main à
            // Nextcloud, quitte à devoir nettoyer manuellement ensuite.
            $this->logger->error('Tickets: failed to purge data for deleted user ' . $uid . ': ' . $e->getMessage(), [
                'app' => 'tickets',
                'exception' => $e,
            ]);
        }
    }

    /** Tickets dont l'utilisateur est propriétaire : supprimés entièrement. */
    private function deleteOwnedTickets(string $uid): void {
        foreach ($this->ticketMapper->findAllByOwner($uid) as $ticket) {
            $this->deleteTicketEntirely($ticket);
        }
    }

    private function deleteTicketEntirely(Ticket $ticket): void {
        $this->attachmentService->deleteAllForTicket($ticket);
        $this->commentMapper->deleteByTicket($ticket->getId());
        $this->activityMapper->deleteByTicket($ticket->getId());
        $this->ticketReadMapper->deleteByTicket($ticket->getId());
        $this->ticketMapper->delete($ticket);
    }

    /** Tickets d'un tiers où l'utilisateur était simplement assigné : désassignation seule. */
    private function unassignFromForeignTickets(string $uid): void {
        foreach ($this->ticketMapper->findByAssignee($uid) as $ticket) {
            $ticket->setAssignedUid(null);
            $this->ticketMapper->update($ticket);
        }
    }

    /** Commentaires et pièces jointes déposés sur le ticket d'un tiers : supprimés individuellement. */
    private function deleteForeignContributions(string $uid): void {
        $this->commentMapper->deleteByAuthor($uid);

        foreach ($this->attachmentMapper->findByUploader($uid) as $attachment) {
            try {
                $ticket = $this->ticketMapper->find($attachment->getTicketId());
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Le ticket parent a déjà disparu (cas normalement déjà couvert par
                // deleteOwnedTickets, mais on reste défensif) : il ne reste alors que
                // la ligne de métadonnées à nettoyer.
                $this->attachmentMapper->delete($attachment);
                continue;
            }
            $this->attachmentService->deleteAttachment($attachment, $ticket);
        }
    }
}
