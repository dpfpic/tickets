<?php

declare(strict_types=1);

namespace OCA\Tickets\Notification;

use OCA\Tickets\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    private IL10NFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    private const STATUS_LABELS = [
        'new' => 'New',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public function __construct(IL10NFactory $l10nFactory, IURLGenerator $urlGenerator) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l10nFactory->get(Application::APP_ID)->t('Tickets');
    }

    /**
     * @throws \InvalidArgumentException When this notifier doesn't know this notification type
     */
    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Unknown app');
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();

        $notification->setIcon($this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
        ));

        switch ($notification->getSubject()) {
            case 'ticket_created':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $params['ticketId']
                );
                $notification->setParsedSubject(
                    $l->t('New ticket: %s (%s)', [$params['title'], $params['ticketNumber']])
                );
                return $notification;

            case 'ticket_status_changed':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $params['ticketId']
                );
                $notification->setParsedSubject(
                    $l->t('%s (%s) is now "%s"', [
                        $params['title'],
                        $params['ticketNumber'],
                        $this->statusLabel($l, $params['newStatus']),
                    ])
                );
                return $notification;

            case 'ticket_comment_added':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $params['ticketId']
                );
                $notification->setParsedSubject(
                    $l->t('New comment on %s (%s)', [$params['title'], $params['ticketNumber']])
                );
                return $notification;

            case 'ticket_due_soon':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $params['ticketId']
                );
                $notification->setParsedSubject(
                    $l->t('%s (%s) is due soon (%s)', [
                        $params['title'],
                        $params['ticketNumber'],
                        $this->dueLabel($l, $params['dueAt']),
                    ])
                );
                return $notification;

            case 'ticket_due_overdue':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('tickets.page.index') . '?ticket=' . $params['ticketId']
                );
                $notification->setParsedSubject(
                    $l->t('%s (%s) is overdue (was due %s)', [
                        $params['title'],
                        $params['ticketNumber'],
                        $this->dueLabel($l, $params['dueAt']),
                    ])
                );
                return $notification;

            case 'config_saved':
                $notification->setLink(
                    $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID])
                );
                $notification->setParsedSubject(
                    $l->t('Tickets settings have been updated')
                );
                return $notification;

            default:
                throw new \InvalidArgumentException('Unknown subject');
        }
    }

    private function statusLabel(\OCP\IL10N $l, string $status): string {
        if (!isset(self::STATUS_LABELS[$status])) {
            return $status;
        }
        return $l->t(self::STATUS_LABELS[$status]);
    }

    private function dueLabel(\OCP\IL10N $l, $dueAt): string {
        if ($dueAt === null || $dueAt === '') {
            return '';
        }
        return $l->l('date', (int) $dueAt);
    }
}
