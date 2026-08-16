<?php

declare(strict_types=1);

namespace OCA\Tickets\AppInfo;

use OCA\Tickets\BackgroundJob\DueDateReminderJob;
use OCA\Tickets\Listener\UserDeletedListener;
use OCA\Tickets\Notification\Notifier;
use OCA\Tickets\UserMigration\TicketsMigrator;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'tickets';

    // Valeur par défaut du groupe gestionnaire, utilisée tant qu'aucun
    // groupe n'a été choisi dans Réglages > Tickets (voir ConfigService).
    public const BOARD_GROUP = 'board';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Les mappers/controllers sont résolus automatiquement via l'auto-wiring
        $context->registerNotifierService(Notifier::class);
        // Relance automatique sur échéance de ticket (cron horaire, voir la classe).
        $context->registerBackgroundJob(DueDateReminderJob::class);
        // Volet RGPD : export (portabilité) et purge (suppression de compte),
        // voir TicketsMigrator et UserDeletedListener.
        $context->registerUserMigrator(TicketsMigrator::class);
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
