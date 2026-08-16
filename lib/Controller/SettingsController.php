<?php

declare(strict_types=1);

namespace OCA\Tickets\Controller;

use OCA\Tickets\AppInfo\Application;
use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\NotificationService;
use OCA\Tickets\Service\XlsxWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Réservé aux administrateurs (pas de @NoAdminRequired) : permet de choisir,
 * parmi les groupes Nextcloud existants, lesquels ont le droit de déposer des
 * demandes et lesquels les gèrent (groupe gestionnaire), ainsi que la liste des
 * catégories de tickets.
 */
class SettingsController extends Controller {
    /** Libellés français utilisés pour l'export Excel (statuts/priorités ne sont pas configurables). */
    private const STATUS_LABELS_FR = [
        'new' => 'Nouveau',
        'in_progress' => 'En cours',
        'resolved' => 'Résolu',
        'closed' => 'Fermé',
    ];
    private const PRIORITY_LABELS_FR = [
        'low' => 'Basse',
        'normal' => 'Normale',
        'urgent' => 'Urgente',
    ];

    /** Mot que l'admin doit saisir pour confirmer la réinitialisation de la base. */
    private const RESET_CONFIRMATION_WORD = 'RESET';

    private IGroupManager $groupManager;
    private ConfigService $configService;
    private NotificationService $notificationService;
    private IUserSession $userSession;
    private TicketMapper $ticketMapper;
    private CommentMapper $commentMapper;
    private XlsxWriter $xlsxWriter;
    private IDBConnection $db;
    private IMailer $mailer;
    private IUserManager $userManager;
    private AttachmentService $attachmentService;
    private AttachmentMapper $attachmentMapper;
    private ActivityMapper $activityMapper;
    private TicketReadMapper $ticketReadMapper;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        IGroupManager $groupManager,
        ConfigService $configService,
        NotificationService $notificationService,
        IUserSession $userSession,
        TicketMapper $ticketMapper,
        CommentMapper $commentMapper,
        XlsxWriter $xlsxWriter,
        IDBConnection $db,
        IMailer $mailer,
        IUserManager $userManager,
        AttachmentService $attachmentService,
        AttachmentMapper $attachmentMapper,
        ActivityMapper $activityMapper,
        TicketReadMapper $ticketReadMapper,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->groupManager = $groupManager;
        $this->configService = $configService;
        $this->notificationService = $notificationService;
        $this->userSession = $userSession;
        $this->ticketMapper = $ticketMapper;
        $this->commentMapper = $commentMapper;
        $this->xlsxWriter = $xlsxWriter;
        $this->db = $db;
        $this->mailer = $mailer;
        $this->userManager = $userManager;
        $this->attachmentService = $attachmentService;
        $this->attachmentMapper = $attachmentMapper;
        $this->activityMapper = $activityMapper;
        $this->ticketReadMapper = $ticketReadMapper;
        $this->logger = $logger;
    }

    /**
     * Liste tous les groupes Nextcloud existants, pour peupler les sélecteurs d'admin.
     */
    public function groups(): DataResponse {
        $groups = array_map(
            static fn ($group) => ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()],
            $this->groupManager->search('')
        );

        return new DataResponse(array_values($groups));
    }

    public function getConfig(): DataResponse {
        return new DataResponse([
            'requesterGroups' => $this->configService->getRequesterGroups(),
            'boardGroups' => $this->configService->getBoardGroups(),
            'categories' => $this->configService->getCategories(),
            'managerEmail' => $this->configService->getManagerEmail(),
            'storageAccountUid' => $this->configService->getStorageAccountUid(),
            'openInNewTab' => $this->configService->getOpenInNewTab(),
            'locationLabelFr' => $this->configService->getLocationLabelFr(),
            'locationLabelEn' => $this->configService->getLocationLabelEn(),
            'dueDateEnabled' => $this->configService->getDueDateEnabled(),
            'allowedExtensions' => $this->configService->getAllowedExtensions(),
            'maxAttachmentSizeMb' => $this->configService->getMaxAttachmentSizeMb(),
            // Purement informatif : la limite réellement appliquée est le plus petit des
            // deux (réglage de l'appli et limite serveur PHP), pour aider l'admin à éviter
            // de configurer une valeur inutilement supérieure à ce que PHP accepte.
            'serverUploadLimitMb' => $this->serverUploadLimitMb(),
        ]);
    }

    /**
     * @param string[] $requesterGroups
     * @param string[] $boardGroups
     * @param array<int, array{value?: string, label_fr?: string, label_en?: string}> $categories
     */
    public function saveConfig(array $requesterGroups = [], array $boardGroups = [], array $categories = [], string $managerEmail = '', string $storageAccountUid = '', bool $openInNewTab = true, string $locationLabelFr = '', string $locationLabelEn = '', bool $dueDateEnabled = true, array $allowedExtensions = [], int $maxAttachmentSizeMb = 20): DataResponse {
        // Capturés avant toute modification, pour ne notifier les gestionnaires que si
        // la config a réellement changé (voir plus bas) : sans ça, chaque coche/décoche
        // dans l'autosave de l'admin (Admin.vue::autosaveGroups) déclenche une
        // notification à tout le groupe gestionnaire, même quand rien de nouveau n'a
        // été enregistré (ex. renvoi de la même valeur, ou requêtes d'autosave qui se
        // chevauchent).
        $oldBoardGroups = $this->configService->getBoardGroups();
        $oldRequesterGroups = $this->configService->getRequesterGroups();
        $oldCategories = $this->configService->getCategories();
        $oldManagerEmail = $this->configService->getManagerEmail();
        $oldOpenInNewTab = $this->configService->getOpenInNewTab();
        $oldLocationLabelFr = $this->configService->getLocationLabelFr();
        $oldLocationLabelEn = $this->configService->getLocationLabelEn();
        $oldDueDateEnabled = $this->configService->getDueDateEnabled();
        $oldAllowedExtensions = $this->configService->getAllowedExtensions();
        $oldMaxAttachmentSizeMb = $this->configService->getMaxAttachmentSizeMb();

        $boardGroups = array_values(array_unique(array_filter($boardGroups, static fn ($g) => $g !== '')));
        if (count($boardGroups) === 0) {
            return new DataResponse(['message' => 'At least one board group is required'], Http::STATUS_BAD_REQUEST);
        }
        foreach ($boardGroups as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                return new DataResponse(['message' => 'Invalid board group'], Http::STATUS_BAD_REQUEST);
            }
        }

        $requesterGroups = array_values(array_unique(array_filter($requesterGroups, static fn ($g) => $g !== '')));
        foreach ($requesterGroups as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                return new DataResponse(['message' => 'Invalid requester group'], Http::STATUS_BAD_REQUEST);
            }
        }

        $normalizedCategories = $this->normalizeCategories($categories);
        if ($normalizedCategories === null) {
            return new DataResponse(['message' => 'At least one category with a non-empty French or English label is required'], Http::STATUS_BAD_REQUEST);
        }

        $managerEmail = trim($managerEmail);
        if ($managerEmail !== '' && !$this->mailer->validateMailAddress($managerEmail)) {
            return new DataResponse(['message' => 'Invalid manager email address'], Http::STATUS_BAD_REQUEST);
        }

        $storageAccountUid = trim($storageAccountUid);
        if ($storageAccountUid !== '' && !$this->userManager->userExists($storageAccountUid)) {
            return new DataResponse(['message' => 'Invalid attachment storage account'], Http::STATUS_BAD_REQUEST);
        }

        $normalizedExtensions = $this->normalizeExtensions($allowedExtensions);
        if ($normalizedExtensions === null) {
            return new DataResponse(['message' => 'At least one valid file extension is required (letters/digits only)'], Http::STATUS_BAD_REQUEST);
        }

        if ($maxAttachmentSizeMb < 1) {
            return new DataResponse(['message' => 'Maximum attachment size must be at least 1 MB'], Http::STATUS_BAD_REQUEST);
        }

        $oldStorageAccountUid = $this->configService->getStorageAccountUid();

        $this->configService->setBoardGroups($boardGroups);
        $this->configService->setRequesterGroups($requesterGroups);
        $this->configService->setCategories($normalizedCategories);
        $this->configService->setManagerEmail($managerEmail);
        $this->configService->setStorageAccountUid($storageAccountUid);
        $this->configService->setOpenInNewTab($openInNewTab);
        $this->configService->setLocationLabelFr($locationLabelFr);
        $this->configService->setLocationLabelEn($locationLabelEn);
        $this->configService->setDueDateEnabled($dueDateEnabled);
        $this->configService->setAllowedExtensions($normalizedExtensions);
        $this->configService->setMaxAttachmentSizeMb($maxAttachmentSizeMb);

        // Le compte de stockage des pièces jointes a changé : on migre le dossier
        // Tickets/ existant vers le nouveau compte plutôt que de laisser les dossiers
        // déjà créés orphelins sur l'ancien compte. Un échec ici ne remet pas en cause
        // le reste des réglages, déjà enregistrés ; l'admin est prévenu via le message
        // renvoyé, pour pouvoir migrer manuellement si besoin.
        $attachmentMigrationWarning = null;
        if ($oldStorageAccountUid !== $storageAccountUid) {
            try {
                $this->attachmentService->migrateStorageAccount($oldStorageAccountUid, $storageAccountUid);
            } catch (\Throwable $e) {
                $this->logger->error('Tickets: attachment storage account migration failed', [
                    'app' => Application::APP_ID,
                    'exception' => $e,
                    'oldUid' => $oldStorageAccountUid,
                    'newUid' => $storageAccountUid,
                ]);
                $attachmentMigrationWarning = 'Attachments could not be fully migrated to the new storage account. Please check the server logs and move them manually if needed.';
            }
        }

        $configChanged = $this->normalizeGroupListForComparison($oldBoardGroups) !== $this->normalizeGroupListForComparison($boardGroups)
            || $this->normalizeGroupListForComparison($oldRequesterGroups) !== $this->normalizeGroupListForComparison($requesterGroups)
            || $oldCategories !== $normalizedCategories
            || $oldManagerEmail !== $managerEmail
            || $oldStorageAccountUid !== $storageAccountUid
            || $oldOpenInNewTab !== $openInNewTab
            || $oldLocationLabelFr !== $locationLabelFr
            || $oldLocationLabelEn !== $locationLabelEn
            || $oldDueDateEnabled !== $dueDateEnabled
            || $oldAllowedExtensions !== $normalizedExtensions
            || $oldMaxAttachmentSizeMb !== $maxAttachmentSizeMb;

        $actor = $this->userSession->getUser();
        if ($configChanged && $actor !== null) {
            $this->notificationService->notifyConfigSaved($boardGroups, $actor->getUID());
        }

        return new DataResponse([
            'requesterGroups' => $this->configService->getRequesterGroups(),
            'boardGroups' => $this->configService->getBoardGroups(),
            'categories' => $this->configService->getCategories(),
            'managerEmail' => $this->configService->getManagerEmail(),
            'storageAccountUid' => $this->configService->getStorageAccountUid(),
            'openInNewTab' => $this->configService->getOpenInNewTab(),
            'locationLabelFr' => $this->configService->getLocationLabelFr(),
            'locationLabelEn' => $this->configService->getLocationLabelEn(),
            'dueDateEnabled' => $this->configService->getDueDateEnabled(),
            'allowedExtensions' => $this->configService->getAllowedExtensions(),
            'maxAttachmentSizeMb' => $this->configService->getMaxAttachmentSizeMb(),
            'attachmentMigrationWarning' => $attachmentMigrationWarning,
        ]);
    }

    /**
     * Télécharge la liste des catégories actuelles au format JSON, pour être
     * réimportée plus tard (ici ou sur une autre instance).
     *
     * @NoCSRFRequired
     * Accédée via un simple lien <a href>, pas un fetch() : le navigateur ne
     * peut pas y joindre le header "requesttoken" lors d'une navigation
     * classique, donc la vérification CSRF standard échouerait à tort. Sans
     * risque ici puisque la méthode est un GET en lecture seule.
     */
    public function exportCategories(): DataDownloadResponse {
        $json = json_encode(
            $this->configService->getCategories(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return new DataDownloadResponse(
            $json !== false ? $json : '[]',
            'tickets-categories-' . date('Y-m-d') . '.json',
            'application/json'
        );
    }

    /**
     * Remplace la liste des catégories par le contenu d'un fichier JSON
     * (même format que celui produit par exportCategories).
     */
    public function importCategories(): DataResponse {
        $file = $this->request->getUploadedFile('file');
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $content = file_get_contents($file['tmp_name']);
        $decoded = $content !== false ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            return new DataResponse(['message' => 'Invalid JSON file'], Http::STATUS_BAD_REQUEST);
        }

        $normalized = $this->normalizeCategories($decoded);
        if ($normalized === null) {
            return new DataResponse(['message' => 'No valid category found in file'], Http::STATUS_BAD_REQUEST);
        }

        $this->configService->setCategories($normalized);

        return new DataResponse(['categories' => $this->configService->getCategories()]);
    }

    /**
     * Exporte tous les tickets (avec leurs commentaires) dans un classeur
     * Excel (.xlsx), pour archivage/sauvegarde en dehors de Nextcloud.
     *
     * @NoCSRFRequired
     * Même raison que exportCategories() : lien <a href>, pas de header
     * "requesttoken" possible sur une navigation classique.
     */
    public function exportTickets(): DataDownloadResponse {
        $categoryLabels = [];
        foreach ($this->configService->getCategories() as $category) {
            $categoryLabels[$category['value']] = $category['label_fr'];
        }

        $headers = [
            'N° ticket', 'Titre', 'Description', 'Catégorie', 'Statut', 'Priorité',
            'Demandeur', 'Assigné à', 'À traiter avant le', 'Créé le', 'Mis à jour le', 'Commentaires',
        ];

        $rows = [];
        foreach ($this->ticketMapper->findAllUnbounded() as $ticket) {
            $comments = $this->commentMapper->findByTicket($ticket->getId());
            $commentsText = implode(' | ', array_map(
                static fn ($c) => $c->getAuthorUid() . ' (' . date('d/m/Y H:i', $c->getCreatedAt()) . ') : ' . $c->getMessage(),
                $comments
            ));

            $rows[] = [
                $ticket->getTicketNumber(),
                $ticket->getTitle(),
                $ticket->getDescription(),
                $categoryLabels[$ticket->getCategory()] ?? $ticket->getCategory(),
                self::STATUS_LABELS_FR[$ticket->getStatus()] ?? $ticket->getStatus(),
                self::PRIORITY_LABELS_FR[$ticket->getPriority()] ?? $ticket->getPriority(),
                $ticket->getOwnerUid(),
                $ticket->getAssignedUid() ?? '',
                $ticket->getDueAt() !== null ? date('d/m/Y', $ticket->getDueAt()) : '',
                date('d/m/Y H:i', $ticket->getCreatedAt()),
                date('d/m/Y H:i', $ticket->getUpdatedAt()),
                $commentsText,
            ];
        }

        $xlsx = $this->xlsxWriter->build($headers, $rows);

        return new DataDownloadResponse(
            $xlsx,
            'tickets-export-' . date('Y-m-d') . '.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Supprime tous les tickets et toutes leurs données associées (remise à zéro
     * complète de la base de l'app) : commentaires, activité, marqueurs de lecture,
     * pièces jointes (métadonnées ET fichiers sur le compte de stockage). Sans quoi
     * ces lignes/fichiers restaient orphelins après un reset, comme pour la
     * suppression d'un ticket unique (voir TicketController::destroy). Irréversible :
     * n'agit que si $confirm vaut exactement le mot de confirmation attendu, saisi
     * explicitement par l'admin.
     */
    public function reset(string $confirm = ''): DataResponse {
        if ($confirm !== self::RESET_CONFIRMATION_WORD) {
            return new DataResponse(['message' => 'Confirmation required'], Http::STATUS_BAD_REQUEST);
        }

        $this->attachmentService->deleteAllTicketFolders();
        $this->attachmentMapper->deleteAll();
        $this->activityMapper->deleteAll();
        $this->ticketReadMapper->deleteAll();

        $deleteComments = $this->db->getQueryBuilder();
        $deleteComments->delete('tickets_comments');
        $deleteComments->executeStatement();

        $deleteTickets = $this->db->getQueryBuilder();
        $deleteTickets->delete('tickets');
        $deleteTickets->executeStatement();

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    /**
     * Valide la liste de catégories envoyée par l'admin et déduit une valeur
     * (slug) pour toute entrée qui n'en fournit pas encore une. Renvoie null
     * si la liste ne contient aucune catégorie valide.
     *
     * Chaque catégorie doit avoir au moins un libellé (FR ou EN) non vide ;
     * si un seul des deux est renseigné, l'autre reprend la même valeur pour
     * garantir qu'un libellé est toujours disponible côté affichage.
     *
     * @param array<int, array{value?: string, label_fr?: string, label_en?: string}> $categories
     * @return array<int, array{value: string, label_fr: string, label_en: string}>|null
     */
    private function normalizeCategories(array $categories): ?array {
        $result = [];
        $usedValues = [];

        foreach ($categories as $category) {
            $labelFr = trim((string) ($category['label_fr'] ?? ''));
            $labelEn = trim((string) ($category['label_en'] ?? ''));
            if ($labelFr === '' && $labelEn === '') {
                continue;
            }
            if ($labelFr === '') {
                $labelFr = $labelEn;
            }
            if ($labelEn === '') {
                $labelEn = $labelFr;
            }

            $value = isset($category['value']) ? trim((string) $category['value']) : '';
            if ($value === '') {
                $value = $this->slugify($labelFr);
            }

            // Garantit l'unicité des valeurs : ce sont elles qui sont stockées sur chaque ticket
            $base = $value;
            $suffix = 2;
            while (in_array($value, $usedValues, true)) {
                $value = $base . '_' . $suffix;
                $suffix++;
            }
            $usedValues[] = $value;

            $result[] = ['value' => $value, 'label_fr' => $labelFr, 'label_en' => $labelEn];
        }

        return count($result) > 0 ? $result : null;
    }

    /**
     * Convertit une notation PHP ini ("8M", "2G", "512K"...) en Mo entiers.
     * Renvoie le plus petit des deux limites (upload_max_filesize / post_max_size),
     * puisque c'est elle qui s'applique réellement à un envoi de fichier.
     */
    private function serverUploadLimitMb(): int {
        $toMb = static function (string $iniValue): int {
            $iniValue = trim($iniValue);
            if ($iniValue === '' || $iniValue === '0') {
                return PHP_INT_MAX; // pas de limite
            }
            $unit = strtolower(substr($iniValue, -1));
            $number = (float) $iniValue;
            switch ($unit) {
                case 'g':
                    return (int) ($number * 1024);
                case 'k':
                    return (int) ($number / 1024);
                case 'm':
                    return (int) $number;
                default:
                    return (int) ($number / (1024 * 1024)); // valeur brute en octets
            }
        };

        $uploadMax = $toMb((string) ini_get('upload_max_filesize'));
        $postMax = $toMb((string) ini_get('post_max_size'));

        return min($uploadMax, $postMax);
    }

    /**
     * Valide et normalise la liste d'extensions envoyée par l'admin : minuscules,
     * point de tête retiré, uniquement lettres/chiffres, dédoublonnée. Renvoie null
     * si aucune extension valide n'a été fournie (l'appli exige au moins une
     * extension autorisée, sans quoi plus aucune pièce jointe ne serait acceptable).
     *
     * @param string[] $extensions
     * @return string[]|null
     */
    private function normalizeExtensions(array $extensions): ?array {
        $result = [];
        foreach ($extensions as $ext) {
            $ext = strtolower(trim((string) $ext, ". \t\n\r\0\x0B"));
            if ($ext === '' || !preg_match('/^[a-z0-9]+$/', $ext)) {
                continue;
            }
            if (!in_array($ext, $result, true)) {
                $result[] = $ext;
            }
        }
        return count($result) > 0 ? $result : null;
    }

    /**
     * Normalise une liste de groupes pour une comparaison avant/après insensible
     * à l'ordre (deux cases cochées dans un ordre différent = même config).
     *
     * @param string[] $groups
     * @return string[]
     */
    private function normalizeGroupListForComparison(array $groups): array {
        sort($groups);
        return $groups;
    }

    private function slugify(string $label): string {
        $slug = strtolower($label);
        $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'category';
    }
}
