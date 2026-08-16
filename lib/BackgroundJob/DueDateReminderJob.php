<?php

declare(strict_types=1);

namespace OCA\Tickets\BackgroundJob;

use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Tourne toutes les heures (cron Nextcloud) : relance automatiquement
 * (notification in-app + email) sur les tickets dont l'échéance ("à traiter
 * avant le") approche à moins de 24h ou vient d'être dépassée.
 *
 * Chaque ticket ne reçoit au plus une relance "à échéance proche" et une
 * relance "en retard" par échéance fixée — l'état déjà envoyé est mémorisé
 * dans Ticket::dueReminderStage pour ne jamais spammer deux fois pour la
 * même échéance ; ce champ est remis à zéro dès que l'échéance est modifiée
 * (voir TicketController::update()).
 */
class DueDateReminderJob extends TimedJob {
    /** Fenêtre d'anticipation de la relance "à échéance proche". */
    private const DUE_SOON_WINDOW = 86400; // 24h

    private TicketMapper $ticketMapper;
    private NotificationService $notificationService;

    public function __construct(ITimeFactory $time, TicketMapper $ticketMapper, NotificationService $notificationService) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->ticketMapper = $ticketMapper;
        $this->notificationService = $notificationService;
    }

    protected function run($argument): void {
        $now = $this->time->getTime();
        $tickets = $this->ticketMapper->findDueForReminder($now + self::DUE_SOON_WINDOW);

        foreach ($tickets as $ticket) {
            $this->processTicket($ticket, $now);
        }
    }

    private function processTicket(Ticket $ticket, int $now): void {
        $dueAt = $ticket->getDueAt();
        if ($dueAt === null) {
            return;
        }

        $overdue = $dueAt <= $now;
        $stage = $ticket->getDueReminderStage();

        if ($overdue) {
            if ($stage === 'overdue') {
                return;
            }
            $ticket->setDueReminderStage('overdue');
        } else {
            // Déjà relancé pour l'échéance proche : rien à refaire tant qu'elle
            // n'est pas dépassée (auquel cas la branche ci-dessus prend le relais).
            if ($stage !== 'none') {
                return;
            }
            $ticket->setDueReminderStage('due_soon');
        }

        $this->ticketMapper->update($ticket);
        $this->notificationService->notifyTicketDueReminder($ticket, $overdue);
    }
}
