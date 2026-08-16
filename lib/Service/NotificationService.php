<?php

declare(strict_types=1);

namespace OCA\Tickets\Service;

use OCA\Tickets\AppInfo\Application;
use OCA\Tickets\Db\Comment;
use OCA\Tickets\Db\Ticket;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Centralise la création des notifications Nextcloud liées aux tickets,
 * pour que le contrôleur reste focalisé sur la logique métier.
 */
class NotificationService {
    private INotificationManager $notificationManager;
    private IGroupManager $groupManager;
    private ConfigService $configService;
    private MailService $mailService;

    public function __construct(INotificationManager $notificationManager, IGroupManager $groupManager, ConfigService $configService, MailService $mailService) {
        $this->notificationManager = $notificationManager;
        $this->groupManager = $groupManager;
        $this->configService = $configService;
        $this->mailService = $mailService;
    }

    /**
     * Prévient chaque membre des groupes gestionnaires qu'un nouveau ticket a été
     * déposé. (Sauf si le membre du groupe gestionnaire est lui-même l'auteur du ticket ; chaque
     * destinataire n'est notifié qu'une fois même s'il appartient à plusieurs des
     * groupes configurés.)
     */
    public function notifyTicketCreated(Ticket $ticket): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setDateTime(new \DateTime())
            ->setObject('ticket', (string) $ticket->getId())
            ->setSubject('ticket_created', [
                'ticketId' => $ticket->getId(),
                'ticketNumber' => $ticket->getTicketNumber(),
                'title' => $ticket->getTitle(),
            ]);

        $notifiedUids = [];
        foreach ($this->configService->getBoardGroups() as $gid) {
            $group = $this->groupManager->get($gid);
            if ($group === null) {
                continue;
            }

            foreach ($group->getUsers() as $user) {
                $uid = $user->getUID();
                if ($uid === $ticket->getOwnerUid() || isset($notifiedUids[$uid])) {
                    continue;
                }
                $notifiedUids[$uid] = true;
                $notification->setUser($uid);
                $this->notificationManager->notify($notification);
            }
        }

        $this->mailService->sendTicketCreated($ticket);
    }

    /**
     * Prévient par email la boîte gestionnaire et l'initiateur qu'un
     * gestionnaire vient de prendre le ticket en charge (assignation). Pas de
     * cloche in-app pour cet évènement (les gestionnaires voient déjà le
     * tableau se mettre à jour). Ne fait rien si le ticket n'est en fait pas
     * assigné (garde-fou : ne devrait pas arriver, l'appelant ne déclenche
     * cet évènement que juste après avoir fixé un assigné).
     */
    public function notifyTicketAssigned(Ticket $ticket): void {
        if ($ticket->getAssignedUid() === null || $ticket->getAssignedUid() === '') {
            return;
        }
        $this->mailService->sendTicketAssigned($ticket);
    }

    /**
     * Prévient par email la boîte gestionnaire, l'assigné et l'initiateur que
     * le ticket vient d'être clôturé (sauf la personne qui l'a elle-même
     * clôturé). La cloche in-app couvre déjà l'initiateur pour ce cas via
     * notifyStatusChanged() (tout changement de statut) ; ceci ajoute
     * l'email, avec des destinataires plus larges, spécifique à la clôture.
     */
    public function notifyTicketClosed(Ticket $ticket, string $actorUid): void {
        $this->mailService->sendTicketClosed($ticket, $actorUid);
    }

    /**
     * Prévient l'utilisateur à l'origine du ticket que son statut vient de changer.
     * Aucune notification n'est envoyée si le statut n'a pas réellement changé, ou si
     * l'auteur de l'action est l'utilisateur lui-même.
     */
    public function notifyStatusChanged(Ticket $ticket, string $oldStatus, string $actorUid): void {
        if ($ticket->getStatus() === $oldStatus) {
            return;
        }
        if ($ticket->getOwnerUid() === $actorUid) {
            return;
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($ticket->getOwnerUid())
            ->setDateTime(new \DateTime())
            ->setObject('ticket', (string) $ticket->getId())
            ->setSubject('ticket_status_changed', [
                'ticketId' => $ticket->getId(),
                'ticketNumber' => $ticket->getTicketNumber(),
                'title' => $ticket->getTitle(),
                'newStatus' => $ticket->getStatus(),
            ]);

        $this->notificationManager->notify($notification);
    }

    /**
     * Prévient les personnes concernées par le ticket (demandeur, assigné) qu'un
     * nouveau commentaire vient d'être ajouté. L'auteur du commentaire n'est
     * jamais notifié de son propre message ; les autres destinataires ne le
     * sont qu'une fois même s'ils cumulent plusieurs rôles sur le ticket.
     */
    public function notifyCommentAdded(Ticket $ticket, Comment $comment): void {
        $notifiedUids = [$comment->getAuthorUid() => true];

        $recipients = [$ticket->getOwnerUid()];
        if ($ticket->getAssignedUid() !== null) {
            $recipients[] = $ticket->getAssignedUid();
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setDateTime(new \DateTime())
            ->setObject('ticket', (string) $ticket->getId())
            ->setSubject('ticket_comment_added', [
                'ticketId' => $ticket->getId(),
                'ticketNumber' => $ticket->getTicketNumber(),
                'title' => $ticket->getTitle(),
                'authorUid' => $comment->getAuthorUid(),
            ]);

        foreach ($recipients as $uid) {
            if ($uid === null || $uid === '' || isset($notifiedUids[$uid])) {
                continue;
            }
            $notifiedUids[$uid] = true;
            $notification->setUser($uid);
            $this->notificationManager->notify($notification);
        }

        $this->mailService->sendCommentAdded($ticket, $comment);
    }

    /**
     * Relance automatique (déclenchée par DueDateReminderJob) quand l'échéance d'un
     * ticket approche (J-1) ou vient d'être dépassée. Prévient l'assigné s'il y en a
     * un, sinon tous les membres des groupes gestionnaires (comme pour un nouveau
     * ticket, personne n'est encore explicitement en charge).
     */
    public function notifyTicketDueReminder(Ticket $ticket, bool $overdue): void {
        $subject = $overdue ? 'ticket_due_overdue' : 'ticket_due_soon';

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setDateTime(new \DateTime())
            ->setObject('ticket', (string) $ticket->getId())
            ->setSubject($subject, [
                'ticketId' => $ticket->getId(),
                'ticketNumber' => $ticket->getTicketNumber(),
                'title' => $ticket->getTitle(),
                'dueAt' => $ticket->getDueAt(),
            ]);

        $assignedUid = $ticket->getAssignedUid();
        if ($assignedUid !== null && $assignedUid !== '') {
            $notification->setUser($assignedUid);
            $this->notificationManager->notify($notification);
        } else {
            $notifiedUids = [];
            foreach ($this->configService->getBoardGroups() as $gid) {
                $group = $this->groupManager->get($gid);
                if ($group === null) {
                    continue;
                }
                foreach ($group->getUsers() as $user) {
                    $uid = $user->getUID();
                    if (isset($notifiedUids[$uid])) {
                        continue;
                    }
                    $notifiedUids[$uid] = true;
                    $notification->setUser($uid);
                    $this->notificationManager->notify($notification);
                }
            }
        }

        $this->mailService->sendTicketDueReminder($ticket, $overdue);
    }

    /**
     * Prévient les membres des groupes gestionnaires (nouveaux réglages) que la
     * configuration de l'app (groupes, catégories) vient d'être modifiée par un
     * administrateur. La personne qui a enregistré n'est pas notifiée d'elle-même ;
     * chaque destinataire n'est prévenu qu'une fois même s'il appartient à
     * plusieurs des groupes gestionnaires configurés.
     *
     * @param string[] $boardGroups Groupes gestionnaires tels qu'enregistrés (après sauvegarde)
     */
    public function notifyConfigSaved(array $boardGroups, string $actorUid): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setDateTime(new \DateTime())
            ->setObject('config', Application::APP_ID)
            ->setSubject('config_saved', []);

        $notifiedUids = [$actorUid => true];
        foreach ($boardGroups as $gid) {
            $group = $this->groupManager->get($gid);
            if ($group === null) {
                continue;
            }

            foreach ($group->getUsers() as $user) {
                $uid = $user->getUID();
                if (isset($notifiedUids[$uid])) {
                    continue;
                }
                $notifiedUids[$uid] = true;
                $notification->setUser($uid);
                $this->notificationManager->notify($notification);
            }
        }
    }
}
