<?php

declare(strict_types=1);

namespace OCA\Tickets\Service;

use OCA\Tickets\AppInfo\Application;
use OCA\Tickets\Db\Comment;
use OCA\Tickets\Db\Ticket;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Envoie les notifications par email liées aux tickets, en complément de la
 * cloche in-app gérée par NotificationService : création, prise en charge,
 * nouveau commentaire, clôture.
 *
 * Deux types de destinataires :
 * - un utilisateur Nextcloud (déterminé par son uid), dont on lit l'adresse
 *   email via IUserManager — silencieusement ignoré s'il n'en a pas ;
 * - la « boîte gestionnaire » configurée par l'admin (ConfigService), une
 *   adresse email brute qui peut ne correspondre à aucun compte Nextcloud.
 *
 * Un échec d'envoi (serveur SMTP non configuré, adresse invalide...) est
 * journalisé mais ne doit jamais faire échouer l'action métier en cours
 * (création du ticket, ajout d'un commentaire...).
 */
class MailService {
    private IMailer $mailer;
    private IUserManager $userManager;
    private IURLGenerator $urlGenerator;
    private IL10NFactory $l10nFactory;
    private ConfigService $configService;
    private LoggerInterface $logger;

    private const STATUS_LABELS = [
        'new' => 'New',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public function __construct(
        IMailer $mailer,
        IUserManager $userManager,
        IURLGenerator $urlGenerator,
        IL10NFactory $l10nFactory,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        $this->mailer = $mailer;
        $this->userManager = $userManager;
        $this->urlGenerator = $urlGenerator;
        $this->l10nFactory = $l10nFactory;
        $this->configService = $configService;
        $this->logger = $logger;
    }

    /** Ticket créé : boîte gestionnaire + initiateur. */
    public function sendTicketCreated(Ticket $ticket): void {
        $this->sendToUidsAndManager(
            [$ticket->getOwnerUid()],
            $ticket,
            'ticket_created',
            fn ($l) => $l->t('New ticket: %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()]),
            fn ($l) => $l->t('A new ticket has just been submitted.')
        );
    }

    /**
     * Un gestionnaire prend le ticket en charge (assignation) : email
     * uniquement à la boîte gestionnaire, pour informer l'équipe de qui a
     * pris le ticket (l'initiateur n'est pas notifié à ce stade — il verra
     * l'assigné dès le premier échange).
     */
    public function sendTicketAssigned(Ticket $ticket): void {
        $this->sendToUidsAndManager(
            [],
            $ticket,
            'ticket_assigned',
            fn ($l) => $l->t('Ticket taken in charge: %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()]),
            function ($l) use ($ticket) {
                $assignedDisplayName = $this->displayName($ticket->getAssignedUid());
                return $l->t('%s is now handling this ticket.', [$assignedDisplayName]);
            }
        );
    }

    /**
     * Nouveau commentaire : assigné + initiateur, jamais l'auteur du
     * commentaire lui-même. Ne touche pas à la boîte gestionnaire — une fois
     * un ticket pris en charge, on évite de continuer à solliciter toute la
     * boîte partagée sur chaque échange.
     */
    public function sendCommentAdded(Ticket $ticket, Comment $comment): void {
        $recipients = [$ticket->getOwnerUid()];
        if ($ticket->getAssignedUid() !== null) {
            $recipients[] = $ticket->getAssignedUid();
        }
        $recipients = array_unique(array_filter(
            $recipients,
            static fn (string $uid) => $uid !== $comment->getAuthorUid()
        ));

        $this->sendToUids(
            $recipients,
            $ticket,
            'ticket_comment_added',
            fn ($l) => $l->t('New comment on %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()]),
            function ($l) use ($comment) {
                return $l->t('%s added a comment: "%s"', [$this->displayName($comment->getAuthorUid()), $this->excerpt($comment->getMessage())]);
            }
        );
    }

    /**
     * Clôture du ticket : boîte gestionnaire + assigné + initiateur, sauf la
     * personne qui vient elle-même de clôturer le ticket (elle le sait déjà).
     */
    public function sendTicketClosed(Ticket $ticket, string $actorUid): void {
        $recipients = array_unique(array_filter(
            [$ticket->getOwnerUid(), $ticket->getAssignedUid()],
            static fn (?string $uid) => $uid !== null && $uid !== '' && $uid !== $actorUid
        ));

        $this->sendToUidsAndManager(
            $recipients,
            $ticket,
            'ticket_closed',
            fn ($l) => $l->t('Ticket closed: %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()]),
            fn ($l) => $l->t('This ticket has been closed.')
        );
    }

    /**
     * Relance automatique d'échéance : à l'assigné s'il y en a un, et toujours à la
     * boîte gestionnaire (une échéance proche ou dépassée concerne toute l'équipe,
     * pas seulement la personne en charge).
     */
    public function sendTicketDueReminder(Ticket $ticket, bool $overdue): void {
        $assignedUid = $ticket->getAssignedUid();
        $uids = ($assignedUid !== null && $assignedUid !== '') ? [$assignedUid] : [];

        $this->sendToUidsAndManager(
            $uids,
            $ticket,
            $overdue ? 'ticket_due_overdue' : 'ticket_due_soon',
            fn ($l) => $overdue
                ? $l->t('Overdue: %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()])
                : $l->t('Due soon: %s (%s)', [$ticket->getTitle(), $ticket->getTicketNumber()]),
            function ($l) use ($ticket, $overdue) {
                $dueLabel = $ticket->getDueAt() !== null ? $l->l('date', $ticket->getDueAt()) : '';
                return $overdue
                    ? $l->t('This ticket was due on %s and has not been resolved yet.', [$dueLabel])
                    : $l->t('This ticket is due on %s.', [$dueLabel]);
            }
        );
    }

    /**
     * @param string[] $uids
     * @param callable(\OCP\IL10N): string $subjectFn
     * @param callable(\OCP\IL10N): string $introFn
     */
    private function sendToUidsAndManager(array $uids, Ticket $ticket, string $templateId, callable $subjectFn, callable $introFn): void {
        $this->sendToUids($uids, $ticket, $templateId, $subjectFn, $introFn);

        $managerEmail = $this->configService->getManagerEmail();
        if ($managerEmail === '') {
            return;
        }
        $this->sendOne($managerEmail, null, $ticket, $templateId, $subjectFn, $introFn);
    }

    /**
     * @param string[] $uids
     * @param callable(\OCP\IL10N): string $subjectFn
     * @param callable(\OCP\IL10N): string $introFn
     */
    private function sendToUids(array $uids, Ticket $ticket, string $templateId, callable $subjectFn, callable $introFn): void {
        $sentTo = [];
        foreach ($uids as $uid) {
            if ($uid === null || $uid === '' || isset($sentTo[$uid])) {
                continue;
            }
            $sentTo[$uid] = true;

            $user = $this->userManager->get($uid);
            $email = $user !== null ? $user->getEMailAddress() : null;
            if ($email === null || $email === '') {
                $this->logger->info('Tickets: email de notification non envoyé, aucune adresse configurée sur le compte', [
                    'uid' => $uid,
                    'templateId' => $templateId,
                    'app' => Application::APP_ID,
                ]);
                continue;
            }
            $this->sendOne($email, $user, $ticket, $templateId, $subjectFn, $introFn);
        }
    }

    /**
     * @param callable(\OCP\IL10N): string $subjectFn
     * @param callable(\OCP\IL10N): string $introFn
     */
    private function sendOne(string $email, ?IUser $user, Ticket $ticket, string $templateId, callable $subjectFn, callable $introFn): void {
        if (!$this->mailer->validateMailAddress($email)) {
            $this->logger->info('Tickets: email de notification non envoyé, adresse jugée invalide', [
                'templateId' => $templateId,
                'app' => Application::APP_ID,
            ]);
            return;
        }

        // La boîte gestionnaire n'a pas forcément de compte Nextcloud : on
        // retombe sur la langue par défaut de l'instance dans ce cas.
        $lang = $user !== null ? $this->l10nFactory->getUserLanguage($user) : $this->l10nFactory->findLanguage();
        $l = $this->l10nFactory->get(Application::APP_ID, $lang);

        $subject = $subjectFn($l);
        $intro = $introFn($l);
        $link = $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $ticket->getId();

        try {
            $template = $this->mailer->createEMailTemplate('tickets.' . $templateId, [
                'ticketId' => $ticket->getId(),
            ]);
            // useTemplate() plus bas écrase le sujet du message par celui du
            // template (IEMailTemplate::renderSubject()) : le sujet DOIT être
            // fixé ici, pas via IMessage::setSubject(), sinon les emails
            // partent sans objet.
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading($subject, $subject);
            $template->addBodyText($intro);
            $template->addBodyText($l->t('Category: %s — Status: %s', [$ticket->getCategory(), $this->statusLabel($l, $ticket->getStatus())]));
            $template->addBodyButton($l->t('Open the ticket'), $link);
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->useTemplate($template);

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            // Un email qui échoue ne doit jamais bloquer l'action métier
            // (création de ticket, commentaire...) qui l'a déclenché.
            $this->logger->warning('Tickets: échec d\'envoi d\'email de notification', [
                'exception' => $e,
                'app' => Application::APP_ID,
            ]);
        }
    }

    private function displayName(?string $uid): string {
        if ($uid === null || $uid === '') {
            return $uid ?? '';
        }
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : $uid;
    }

    private function excerpt(string $message, int $maxLength = 200): string {
        $message = trim($message);
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }
        return mb_substr($message, 0, $maxLength) . '…';
    }

    private function statusLabel(\OCP\IL10N $l, string $status): string {
        if (!isset(self::STATUS_LABELS[$status])) {
            return $status;
        }
        return $l->t(self::STATUS_LABELS[$status]);
    }
}
