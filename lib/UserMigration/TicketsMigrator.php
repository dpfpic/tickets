<?php

declare(strict_types=1);

namespace OCA\Tickets\UserMigration;

use OCA\Tickets\AppInfo\Application;
use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Volet RGPD (droit à la portabilité, art. 20) : exporte, via l'app
 * user_migration (Réglages > Personnel > Vie privée > Télécharger mes données),
 * tout ce que l'utilisateur a lui-même produit dans Tickets : ses tickets
 * (en tant que demandeur), ses commentaires, ses pièces jointes (avec leur
 * contenu), son activité et ses marqueurs de lecture — quel que soit le
 * ticket concerné, y compris ceux d'un tiers pour les commentaires/pièces
 * jointes/activité.
 *
 * L'import n'est volontairement pas supporté : réimporter des tickets sur une
 * autre instance recréerait des lignes déconnectées de leurs id d'origine
 * (assignation, autres commentateurs...), sans grand intérêt pour un usage de
 * migration de compte. seul l'export est donc implémenté.
 */
class TicketsMigrator implements IMigrator, ISizeEstimationMigrator {
    use TMigratorBasicVersionHandling;

    private const PATH_ROOT = Application::APP_ID . '/';
    private const PATH_DATA_FILE = self::PATH_ROOT . 'tickets.json';
    private const PATH_FILES_ROOT = self::PATH_ROOT . 'files/';

    public function __construct(
        private TicketMapper $ticketMapper,
        private CommentMapper $commentMapper,
        private ActivityMapper $activityMapper,
        private TicketReadMapper $ticketReadMapper,
        private AttachmentMapper $attachmentMapper,
        private AttachmentService $attachmentService,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'tickets';
    }

    public function getDisplayName(): string {
        return $this->l10n->t('Tickets');
    }

    public function getDescription(): string {
        return $this->l10n->t('Your tickets, comments, attachments and read markers');
    }

    /**
     * Estimation rapide et volontairement grossière (pas de somme réelle des
     * tailles de fichiers, coûteuse) : nombre de lignes concernées * 1 KiB,
     * plus 20 KiB par pièce jointe pour approximer le poids des fichiers.
     */
    public function getEstimatedExportSize(IUser $user): int|float {
        $uid = $user->getUID();
        $rows = count($this->ticketMapper->findAllByOwner($uid))
            + count($this->commentMapper->findByAuthor($uid))
            + count($this->activityMapper->findByActor($uid))
            + count($this->ticketReadMapper->findByUid($uid));
        $attachments = count($this->attachmentMapper->findByUploader($uid));

        return $rows * 1 + $attachments * 20;
    }

    public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
        $uid = $user->getUID();

        try {
            $tickets = $this->ticketMapper->findAllByOwner($uid);
            $output->writeln('Exporting ' . count($tickets) . ' ticket(s) owned by ' . $uid . '…');

            $attachments = $this->attachmentMapper->findByUploader($uid);
            $output->writeln('Exporting ' . count($attachments) . ' attachment(s) uploaded by ' . $uid . '…');

            $data = [
                'tickets' => array_map(static fn (Ticket $t) => $t->jsonSerialize(), $tickets),
                'comments' => array_map(static fn ($c) => $c->jsonSerialize(), $this->commentMapper->findByAuthor($uid)),
                'attachments' => array_map(static fn ($a) => $a->jsonSerialize(), $attachments),
                'activity' => array_map(static fn ($a) => $a->jsonSerialize(), $this->activityMapper->findByActor($uid)),
                'readMarkers' => array_map(static fn ($r) => [
                    'ticketId' => $r->getTicketId(),
                    'readAt' => $r->getReadAt(),
                ], $this->ticketReadMapper->findByUid($uid)),
            ];

            $exportDestination->addFileContents(
                self::PATH_DATA_FILE,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            $this->exportAttachmentFiles($attachments, $exportDestination, $output);
        } catch (Throwable $e) {
            throw new UserMigrationException('Could not export Tickets information: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Copie le contenu réel des pièces jointes déposées par l'utilisateur, en
     * plus de leurs métadonnées déjà incluses dans tickets.json. Un fichier
     * disparu du disque (compte de stockage réorganisé entre-temps...) est
     * signalé dans la sortie mais n'interrompt pas le reste de l'export.
     * @param \OCA\Tickets\Db\Attachment[] $attachments
     */
    private function exportAttachmentFiles(array $attachments, IExportDestination $exportDestination, OutputInterface $output): void {
        // Regroupement par ticket pour ne charger chaque Ticket qu'une fois,
        // même si l'utilisateur a déposé plusieurs pièces jointes dessus.
        $ticketsById = [];

        foreach ($attachments as $attachment) {
            $ticketId = $attachment->getTicketId();
            if (!isset($ticketsById[$ticketId])) {
                try {
                    $ticketsById[$ticketId] = $this->ticketMapper->find($ticketId);
                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    $ticketsById[$ticketId] = null;
                }
            }
            $ticket = $ticketsById[$ticketId];
            if ($ticket === null) {
                continue;
            }

            try {
                $file = $this->attachmentService->getFile($attachment, $ticket);
                $exportPath = self::PATH_FILES_ROOT . $attachment->getId() . '-' . $attachment->getFileName();
                // Préfixé par l'id de pièce jointe pour éviter toute collision entre
                // deux tickets ayant chacun un fichier de même nom.
                $exportDestination->addFileContents($exportPath, $this->readFileContents($file));
            } catch (Throwable $e) {
                // Fichier manquant, verrouillé, ou compte de stockage temporairement
                // inaccessible : on ne bloque pas le reste de l'export pour autant,
                // les métadonnées de cette pièce jointe restent dans tickets.json.
                $output->writeln('  Skipping unreadable file for attachment #' . $attachment->getId() . ' (' . $attachment->getFileName() . '): ' . $e->getMessage());
            }
        }
    }

    private function readFileContents(File $file): string {
        $content = $file->getContent();
        return $content === false ? '' : $content;
    }

    public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
        $output->writeln('Import is not supported for Tickets, skipping.');
    }
}
