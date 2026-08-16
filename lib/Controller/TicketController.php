<?php

declare(strict_types=1);

namespace OCA\Tickets\Controller;

use OCA\Tickets\AppInfo\Application;
use OCA\Tickets\Db\Activity;
use OCA\Tickets\Db\ActivityMapper;
use OCA\Tickets\Db\Attachment;
use OCA\Tickets\Db\AttachmentMapper;
use OCA\Tickets\Db\Comment;
use OCA\Tickets\Db\CommentMapper;
use OCA\Tickets\Db\Ticket;
use OCA\Tickets\Db\TicketMapper;
use OCA\Tickets\Db\TicketReadMapper;
use OCA\Tickets\Service\AttachmentService;
use OCA\Tickets\Service\ConfigService;
use OCA\Tickets\Service\NotificationService;
use OCA\Tickets\Service\XlsxWriter;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class TicketController extends Controller {
    /** Libellés français utilisés pour l'export Excel (mêmes valeurs que SettingsController::exportTickets). */
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
    /**
     * Seuil de similarité (0-100, voir similar_text()) au-delà duquel le titre
     * d'une nouvelle requête est considéré comme un doublon potentiel d'un
     * ticket encore ouvert du même demandeur. Choisi empiriquement assez haut
     * pour éviter les faux positifs entre titres courts qui se ressemblent par
     * coïncidence ("Fuite évier" / "Fuite douche").
     */
    private const DUPLICATE_TITLE_THRESHOLD = 60;

    private TicketMapper $ticketMapper;
    private CommentMapper $commentMapper;
    private TicketReadMapper $ticketReadMapper;
    private AttachmentMapper $attachmentMapper;
    private ActivityMapper $activityMapper;
    private AttachmentService $attachmentService;
    private IGroupManager $groupManager;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IAccountManager $accountManager;
    private NotificationService $notificationService;
    private ConfigService $configService;
    private XlsxWriter $xlsxWriter;

    public function __construct(
        IRequest $request,
        TicketMapper $ticketMapper,
        CommentMapper $commentMapper,
        TicketReadMapper $ticketReadMapper,
        AttachmentMapper $attachmentMapper,
        ActivityMapper $activityMapper,
        AttachmentService $attachmentService,
        IGroupManager $groupManager,
        IUserSession $userSession,
        IUserManager $userManager,
        IAccountManager $accountManager,
        NotificationService $notificationService,
        ConfigService $configService,
        XlsxWriter $xlsxWriter
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->ticketMapper = $ticketMapper;
        $this->commentMapper = $commentMapper;
        $this->ticketReadMapper = $ticketReadMapper;
        $this->attachmentMapper = $attachmentMapper;
        $this->activityMapper = $activityMapper;
        $this->attachmentService = $attachmentService;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->accountManager = $accountManager;
        $this->notificationService = $notificationService;
        $this->configService = $configService;
        $this->xlsxWriter = $xlsxWriter;
    }

    /**
     * Nom "Prénom Nom" (nom affiché Nextcloud) correspondant à un uid assigné,
     * ou l'uid lui-même en repli si le compte n'existe plus.
     */
    private function assignedDisplayName(?string $uid): ?string {
        if ($uid === null || $uid === '') {
            return null;
        }
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : $uid;
    }

    private function currentUid(): string {
        return $this->userSession->getUser()->getUID();
    }

    /** Marque le ticket comme lu maintenant par l'utilisateur courant. */
    private function markRead(int $ticketId): void {
        $this->ticketReadMapper->markRead($ticketId, $this->currentUid(), time());
    }

    /**
     * Enregistre une entrée dans le journal d'activité horodaté du ticket.
     * $oldValue/$newValue sont déjà des libellés affichables (pas des clés brutes
     * à retraduire côté client) pour rester simples à consommer par le front-end.
     */
    private function logActivity(int $ticketId, string $type, ?string $oldValue, ?string $newValue): void {
        $activity = new Activity();
        $activity->setTicketId($ticketId);
        $activity->setActorUid($this->currentUid());
        $activity->setType($type);
        $activity->setOldValue($oldValue);
        $activity->setNewValue($newValue);
        $activity->setCreatedAt(time());
        $this->activityMapper->insert($activity);
    }

    /** Échéance mise en forme pour le journal d'activité, ou null si aucune échéance. */
    private function formatDueAt(?int $dueAt): ?string {
        return $dueAt !== null ? date('d/m/Y', $dueAt) : null;
    }

    /**
     * Doublons potentiels : tickets encore ouverts du même demandeur dont le titre
     * est proche (similar_text sur une forme normalisée : minuscules, espaces
     * multiples réduits, espaces de bord retirés) du titre soumis. Limité aux 5
     * meilleurs candidats, triés par similarité décroissante.
     * @return array<int, array{id: int, ticketNumber: string, title: string, status: string, createdAt: int}>
     */
    private function findPotentialDuplicates(string $title, string $ownerUid): array {
        $normalizedNew = $this->normalizeTitle($title);
        if ($normalizedNew === '') {
            return [];
        }

        $matches = [];
        foreach ($this->ticketMapper->findOpenByOwner($ownerUid) as $existing) {
            $normalizedExisting = $this->normalizeTitle($existing->getTitle());
            if ($normalizedExisting === '') {
                continue;
            }
            similar_text($normalizedNew, $normalizedExisting, $percent);
            if ($percent >= self::DUPLICATE_TITLE_THRESHOLD) {
                $matches[] = [
                    'percent' => $percent,
                    'ticket' => [
                        'id' => $existing->getId(),
                        'ticketNumber' => $existing->getTicketNumber(),
                        'title' => $existing->getTitle(),
                        'status' => $existing->getStatus(),
                        'createdAt' => $existing->getCreatedAt(),
                    ],
                ];
            }
        }

        usort($matches, static fn (array $a, array $b) => $b['percent'] <=> $a['percent']);

        return array_map(static fn (array $m) => $m['ticket'], array_slice($matches, 0, 5));
    }

    private function normalizeTitle(string $title): string {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($title)));
    }

    /** Est-ce que l'utilisateur courant fait partie d'un des groupes gestionnaires ? */
    private function isBoardMember(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        foreach ($this->configService->getBoardGroups() as $gid) {
            if ($this->groupManager->isInGroup($user->getUID(), $gid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Est-ce que l'utilisateur courant a le droit de déposer/consulter des demandes ?
     * Le groupe gestionnaire y a toujours accès. Sinon, dépend des groupes "demandeurs"
     * configurés (aucun groupe = tous les utilisateurs connectés, comportement historique).
     */
    private function canRequest(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        if ($this->isBoardMember()) {
            return true;
        }
        $requesterGroups = $this->configService->getRequesterGroups();
        if (count($requesterGroups) === 0) {
            return true;
        }
        foreach ($requesterGroups as $gid) {
            if ($this->groupManager->isInGroup($user->getUID(), $gid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valeur par défaut du champ "Localisation" du formulaire : l'adresse renseignée
     * par l'utilisateur connecté dans son profil Nextcloud (Réglages personnels →
     * Informations personnelles), ou une chaîne vide si non renseignée.
     */
    private function defaultRequesterLocation(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }
        try {
            $account = $this->accountManager->getAccount($user);
            return $account->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @NoAdminRequired
     */
    public function context(): DataResponse {
        $user = $this->userSession->getUser();
        $isBoardMember = $this->isBoardMember();
        return new DataResponse([
            'uid' => $this->currentUid(),
            'isBoardMember' => $isBoardMember,
            'canRequest' => $this->canRequest(),
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            'categories' => $this->configService->getCategories(),
            'openInNewTab' => $this->configService->getOpenInNewTab(),
            'locationLabelFr' => $this->configService->getLocationLabelFr(),
            'locationLabelEn' => $this->configService->getLocationLabelEn(),
            'dueDateEnabled' => $this->configService->getDueDateEnabled(),
            'allowedExtensions' => $this->configService->getAllowedExtensions(),
            'maxAttachmentSizeMb' => $this->configService->getMaxAttachmentSizeMb(),
            // Valeurs par défaut pour pré-remplir "Nom" et "Localisation" dans le
            // formulaire de nouvelle requête (modifiables par l'utilisateur avant envoi).
            'defaultRequesterName' => $user !== null ? $user->getDisplayName() : '',
            'defaultRequesterLocation' => $this->defaultRequesterLocation(),
            // Utile uniquement pour peupler le sélecteur de réassignation manuelle,
            // donc pas la peine de le calculer pour un simple demandeur.
            'boardMembers' => $isBoardMember ? $this->boardMembers() : [],
        ]);
    }

    /**
     * Membres (uid + nom affiché) de tous les groupes gestionnaires configurés,
     * dédupliqués et triés par nom — alimente le sélecteur de réassignation.
     * @return array<int, array{uid: string, displayName: string}>
     */
    private function boardMembers(): array {
        $members = [];
        foreach ($this->configService->getBoardGroups() as $gid) {
            $group = $this->groupManager->get($gid);
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                $members[$user->getUID()] = $user->getDisplayName();
            }
        }
        asort($members, SORT_FLAG_CASE | SORT_STRING);
        $result = [];
        foreach ($members as $uid => $displayName) {
            $result[] = ['uid' => $uid, 'displayName' => $displayName];
        }
        return $result;
    }

    /**
     * @NoAdminRequired
     * Liste : le groupe gestionnaire voit tout, un utilisateur ne voit que ses tickets
     */
    public function index(int $limit = 12, int $offset = 0, ?string $status = null, ?string $priority = null, ?string $category = null, ?string $assignedUid = null, ?string $search = null, string $sort = 'created_at', string $order = 'DESC'): DataResponse {
        if (!$this->canRequest()) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        // Bornes défensives : évite un scan complet de la table si le client
        // envoie une valeur farfelue (ou un offset négatif).
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $filters = array_filter([
            'status' => in_array($status, Ticket::STATUSES, true) ? $status : null,
            'priority' => in_array($priority, Ticket::PRIORITIES, true) ? $priority : null,
            'category' => $category !== null && $category !== '' ? $category : null,
            'search' => $search !== null && trim($search) !== '' ? trim($search) : null,
        ]);

        // Compteurs par statut (badges cliquables au-dessus du tableau) : calculés à
        // partir des mêmes filtres que la liste, MAIS sans celui de statut lui-même,
        // pour que chaque compteur reste juste même quand un statut est déjà sélectionné.
        $countFilters = $filters;
        unset($countFilters['status']);

        if ($this->isBoardMember()) {
            // Le filtre par assigné n'a de sens que côté gestionnaire (un demandeur
            // ne voit de toute façon que ses propres tickets).
            if ($assignedUid !== null && $assignedUid !== '') {
                $filters['assignedUid'] = $assignedUid;
                $countFilters['assignedUid'] = $assignedUid;
            }
            $tickets = $this->ticketMapper->findAll($limit, $offset, $filters, $sort, $order);
            $total = $this->ticketMapper->countAll($filters);
            $rawStatusCounts = $this->ticketMapper->countsByStatus($countFilters);
        } else {
            $tickets = $this->ticketMapper->findByOwner($this->currentUid(), $limit, $offset, $filters, $sort, $order);
            $total = $this->ticketMapper->countByOwner($this->currentUid(), $filters);
            $rawStatusCounts = $this->ticketMapper->countsByStatus($countFilters, $this->currentUid());
        }

        $statusCounts = ['all' => 0];
        foreach (Ticket::STATUSES as $s) {
            $statusCounts[$s] = $rawStatusCounts[$s] ?? 0;
            $statusCounts['all'] += $statusCounts[$s];
        }

        $ticketIds = array_map(fn (Ticket $t) => $t->getId(), $tickets);
        $readTimestamps = $this->ticketReadMapper->findReadTimestamps($ticketIds, $this->currentUid());
        $lastCommentTimestamps = $this->commentMapper->findLastCommentTimestamps($ticketIds);
        $attachmentCounts = $this->attachmentMapper->countByTickets($ticketIds);

        $result = array_map(function (Ticket $t) use ($readTimestamps, $lastCommentTimestamps, $attachmentCounts) {
            $data = $t->jsonSerialize();
            // "Nouveau" doit refléter le dernier message envoyé (création du ticket ou
            // commentaire), pas n'importe quelle mise à jour : un changement de statut/priorité/
            // assignation seul ne doit pas faire réapparaître le marqueur.
            $lastMessageAt = max($t->getCreatedAt(), $lastCommentTimestamps[$t->getId()] ?? 0);
            $data['hasUnread'] = $lastMessageAt > ($readTimestamps[$t->getId()] ?? 0);
            $data['attachmentCount'] = $attachmentCounts[$t->getId()] ?? 0;
            $data['assignedDisplayName'] = $this->assignedDisplayName($t->getAssignedUid());
            return $data;
        }, $tickets);

        return new DataResponse(['items' => $result, 'total' => $total, 'statusCounts' => $statusCounts]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * Export Excel de la vue actuelle du tableau gestionnaire (mêmes filtres/tri que
     * index(), mais sans pagination) : contrairement à SettingsController::exportTickets
     * (réservé à l'admin, toujours intégral, avec commentaires), celui-ci sert de
     * rapport ponctuel pour un sous-ensemble filtré. Pas de header requesttoken possible
     * sur un lien <a href> classique, d'où @NoCSRFRequired (lecture seule).
     */
    public function exportTickets(?string $status = null, ?string $priority = null, ?string $category = null, ?string $assignedUid = null, ?string $search = null, string $sort = 'created_at', string $order = 'DESC'): DataDownloadResponse|DataResponse {
        if (!$this->isBoardMember()) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $filters = array_filter([
            'status' => in_array($status, Ticket::STATUSES, true) ? $status : null,
            'priority' => in_array($priority, Ticket::PRIORITIES, true) ? $priority : null,
            'category' => $category !== null && $category !== '' ? $category : null,
            'search' => $search !== null && trim($search) !== '' ? trim($search) : null,
        ]);
        if ($assignedUid !== null && $assignedUid !== '') {
            $filters['assignedUid'] = $assignedUid;
        }

        $categoryLabels = [];
        foreach ($this->configService->getCategories() as $c) {
            $categoryLabels[$c['value']] = $c['label_fr'];
        }

        $headers = [
            'N° ticket', 'Titre', 'Description', 'Catégorie', 'Statut', 'Priorité',
            'Demandeur', 'Localisation', 'Assigné à', 'À traiter avant le', 'Créé le', 'Mis à jour le',
        ];

        $rows = [];
        foreach ($this->ticketMapper->findAllFiltered($filters, $sort, $order) as $ticket) {
            $rows[] = [
                $ticket->getTicketNumber(),
                $ticket->getTitle(),
                $ticket->getDescription(),
                $categoryLabels[$ticket->getCategory()] ?? $ticket->getCategory(),
                self::STATUS_LABELS_FR[$ticket->getStatus()] ?? $ticket->getStatus(),
                self::PRIORITY_LABELS_FR[$ticket->getPriority()] ?? $ticket->getPriority(),
                $ticket->getRequesterName(),
                $ticket->getRequesterLocation(),
                $this->assignedDisplayName($ticket->getAssignedUid()) ?? '',
                $ticket->getDueAt() !== null ? date('d/m/Y', $ticket->getDueAt()) : '',
                date('d/m/Y H:i', $ticket->getCreatedAt()),
                date('d/m/Y H:i', $ticket->getUpdatedAt()),
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
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->canAccess($ticket)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $this->markRead($id);

        $data = $ticket->jsonSerialize();
        $data['assignedDisplayName'] = $this->assignedDisplayName($ticket->getAssignedUid());
        $data['attachments'] = array_map(
            static fn (Attachment $a) => $a->jsonSerialize(),
            $this->attachmentMapper->findByTicket($id)
        );
        $data['comments'] = array_map(
            fn (Comment $c) => $c->jsonSerialize(),
            $this->commentMapper->findByTicket($id)
        );
        $data['activity'] = array_map(
            fn (Activity $a) => $a->jsonSerialize(),
            $this->activityMapper->findByTicket($id)
        );

        return new DataResponse($data);
    }

    /**
     * @NoAdminRequired
     * Création d'un ticket - accessible à tout utilisateur connecté
     */
    public function create(string $title, string $description = '', string $category = 'other', string $priority = 'normal', string $requesterName = '', string $requesterLocation = '', bool $force = false): DataResponse {
        if (!$this->canRequest()) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        if (trim($title) === '') {
            return new DataResponse(['message' => 'Title is required'], Http::STATUS_BAD_REQUEST);
        }

        // Détection de doublons : on prévient plutôt qu'on ne bloque — le demandeur
        // peut avoir une bonne raison de rouvrir une demande similaire (nouvel
        // épisode du même problème, etc.), d'où le $force pour confirmer et passer
        // outre l'avertissement plutôt qu'un refus pur et simple.
        if (!$force) {
            $duplicates = $this->findPotentialDuplicates($title, $this->currentUid());
            if (count($duplicates) > 0) {
                return new DataResponse([
                    'message' => 'Possible duplicate tickets found',
                    'duplicates' => $duplicates,
                ], Http::STATUS_CONFLICT);
            }
        }

        $categoryValues = $this->configService->getCategoryValues();

        $ticket = new Ticket();
        $ticket->setTitle($title);
        $ticket->setDescription($description);
        $ticket->setCategory(in_array($category, $categoryValues, true) ? $category : ($categoryValues[0] ?? 'other'));
        $ticket->setPriority(in_array($priority, Ticket::PRIORITIES, true) ? $priority : 'normal');
        $ticket->setStatus('new');
        $ticket->setOwnerUid($this->currentUid());
        // Repli côté serveur si le champ est laissé vide (ex. appel API direct) : on
        // retombe sur le nom affiché du compte connecté plutôt que de stocker une
        // chaîne vide sans intérêt.
        $user = $this->userSession->getUser();
        $ticket->setRequesterName(trim($requesterName) !== '' ? $requesterName : ($user !== null ? $user->getDisplayName() : ''));
        $ticket->setRequesterLocation($requesterLocation);
        $ticket->setCreatedAt(time());
        $ticket->setUpdatedAt(time());

        $ticket = $this->ticketMapper->insert($ticket);
        $this->markRead($ticket->getId());
        $this->logActivity($ticket->getId(), 'created', null, null);
        $this->notificationService->notifyTicketCreated($ticket);

        return new DataResponse($ticket, Http::STATUS_CREATED);
    }

    /**
     * @NoAdminRequired
     * Update: the requester can edit title/description while the ticket is still "new",
     * the board can change status/priority/assignment/due date at any time.
     * $dueAt is a "YYYY-MM-DD" date string (board only), or '' to clear the due date;
     * null means "field not provided, leave unchanged".
     * $expectedAssignedUid is the assignment the client believes is currently in place
     * (empty string for "unassigned", null for "not checked" — callers that don't care
     * about concurrent reassignment, e.g. scripts/API, can simply omit it). When it is
     * provided and no longer matches the ticket's actual current assignment, the update
     * is rejected with 409 instead of silently overwriting someone else's reassignment.
     */
    public function update(int $id, ?string $title = null, ?string $description = null, ?string $status = null, ?string $priority = null, ?string $assignedUid = null, ?string $expectedAssignedUid = null, ?string $requesterName = null, ?string $requesterLocation = null, ?string $dueAt = null): DataResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->canAccess($ticket)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $isBoard = $this->isBoardMember();
        $oldStatus = $ticket->getStatus();
        $oldPriority = $ticket->getPriority();
        $oldAssignedUid = $ticket->getAssignedUid();
        $oldDueAt = $ticket->getDueAt();
        $wasAssigned = $ticket->getAssignedUid() !== null;

        if ($isBoard) {
            if ($status !== null && in_array($status, Ticket::STATUSES, true)) {
                $ticket->setStatus($status);
            }
            if ($priority !== null && in_array($priority, Ticket::PRIORITIES, true)) {
                $ticket->setPriority($priority);
            }
            if ($assignedUid !== null) {
                // Vérification d'assignation existante : si l'appelant a indiqué ce qu'il
                // pensait être l'assignation actuelle et qu'elle ne correspond plus à la
                // réalité (quelqu'un d'autre a réassigné le ticket entre-temps), on refuse
                // plutôt que d'écraser silencieusement ce changement.
                if ($expectedAssignedUid !== null) {
                    $currentAssignedUid = $ticket->getAssignedUid();
                    $expected = $expectedAssignedUid === '' ? null : $expectedAssignedUid;
                    if ($currentAssignedUid !== $expected) {
                        return new DataResponse([
                            'message' => 'Ticket assignment has changed',
                            'assignedUid' => $currentAssignedUid,
                            'assignedDisplayName' => $this->assignedDisplayName($currentAssignedUid),
                        ], Http::STATUS_CONFLICT);
                    }
                }
                $ticket->setAssignedUid($assignedUid === '' ? null : $assignedUid);
            }
            // Correction du nom/localisation du demandeur par un gestionnaire (ex. faute
            // de frappe, information manquante) : possible quel que soit le statut du
            // ticket, contrairement au titre/description réservés au demandeur d'origine
            // et seulement tant que le ticket est encore "new" (voir plus bas).
            if ($requesterName !== null) {
                $ticket->setRequesterName($requesterName);
            }
            if ($requesterLocation !== null) {
                $ticket->setRequesterLocation($requesterLocation);
            }
            // Échéance : '' efface la date, une chaîne "YYYY-MM-DD" la fixe (à la fin
            // de journée locale, cohérent avec "à traiter avant le"). Un changement
            // d'échéance remet à zéro l'état des relances déjà envoyées, pour qu'une
            // échéance décalée puisse à nouveau déclencher une relance "proche"/"en
            // retard" au bon moment.
            if ($dueAt !== null) {
                $newDueAt = null;
                if ($dueAt !== '') {
                    $ts = strtotime($dueAt . ' 23:59:59');
                    if ($ts !== false) {
                        $newDueAt = $ts;
                    }
                }
                if ($newDueAt !== $ticket->getDueAt()) {
                    $ticket->setDueAt($newDueAt);
                    $ticket->setDueReminderStage('none');
                }
            }
        }

        // Le demandeur d'origine peut corriger titre/description tant que non pris en charge
        if (!$isBoard && $ticket->getOwnerUid() === $this->currentUid() && $ticket->getStatus() === 'new') {
            if ($title !== null) {
                $ticket->setTitle($title);
            }
            if ($description !== null) {
                $ticket->setDescription($description);
            }
        }

        $ticket->setUpdatedAt(time());
        $ticket = $this->ticketMapper->update($ticket);
        $this->markRead($id);

        if ($ticket->getStatus() !== $oldStatus) {
            $this->logActivity($id, 'status_changed', $oldStatus, $ticket->getStatus());
        }
        if ($ticket->getPriority() !== $oldPriority) {
            $this->logActivity($id, 'priority_changed', $oldPriority, $ticket->getPriority());
        }
        if ($ticket->getAssignedUid() !== $oldAssignedUid) {
            $this->logActivity(
                $id,
                'assigned_changed',
                $this->assignedDisplayName($oldAssignedUid),
                $this->assignedDisplayName($ticket->getAssignedUid())
            );
        }
        if ($ticket->getDueAt() !== $oldDueAt) {
            $this->logActivity($id, 'due_changed', $this->formatDueAt($oldDueAt), $this->formatDueAt($ticket->getDueAt()));
        }

        // Prise en charge : le ticket vient de passer de "non assigné" à "assigné"
        if (!$wasAssigned && $ticket->getAssignedUid() !== null) {
            $this->notificationService->notifyTicketAssigned($ticket);
        }

        if ($ticket->getStatus() !== $oldStatus) {
            $this->notificationService->notifyStatusChanged($ticket, $oldStatus, $this->currentUid());
            if ($ticket->getStatus() === 'closed') {
                $this->notificationService->notifyTicketClosed($ticket, $this->currentUid());
            }
            // Range le dossier de pièces jointes dans Tickets/Résolus ou Tickets/Fermés
            // (ou le fait revenir dans Tickets/ si le ticket est réouvert), plutôt que
            // d'attendre la prochaine ouverture du dossier ou le prochain dépôt de fichier.
            $this->attachmentService->relocateFolderForStatus($ticket);
        }

        return new DataResponse($ticket);
    }

    /**
     * @NoAdminRequired
     * Suppression : réservée au groupe gestionnaire
     */
    public function destroy(int $id): DataResponse {
        if (!$this->isBoardMember()) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        // Le dossier de pièces jointes du ticket (et ses métadonnées) n'a plus lieu
        // d'être une fois le ticket lui-même supprimé.
        $this->attachmentService->deleteAllForTicket($ticket);
        // Idem pour les commentaires, l'activité et les marqueurs de lecture : sans
        // ça, ces lignes restaient orphelines en base (aucune contrainte de clé
        // étrangère ne les supprime automatiquement), repéré à l'occasion du volet RGPD.
        $this->commentMapper->deleteByTicket($id);
        $this->activityMapper->deleteByTicket($id);
        $this->ticketReadMapper->deleteByTicket($id);

        $this->ticketMapper->delete($ticket);
        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    /**
     * @NoAdminRequired
     * Envoi d'un commentaire, éventuellement accompagné d'un changement de statut/priorité
     * (validé par le même bouton "Envoyer" côté interface). Le statut/priorité est appliqué
     * avant la création de la notification de commentaire, pour que celle-ci reflète l'état
     * réellement enregistré (sinon la notification affiche encore l'ancien statut alors que
     * le message annonce déjà le nouveau).
     */
    public function addComment(int $id, string $message, ?string $status = null, ?string $priority = null): DataResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->canAccess($ticket) || trim($message) === '') {
            return new DataResponse(['message' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }

        // Un ticket résolu ou fermé est considéré comme clos : plus d'échanges possibles.
        // (Le statut vérifié ici est celui AVANT cette requête : rien n'empêche donc de
        // clore le ticket et d'y laisser un dernier commentaire dans le même envoi.)
        if (in_array($ticket->getStatus(), ['resolved', 'closed'], true)) {
            return new DataResponse(['message' => 'Ticket is resolved, comments are locked'], Http::STATUS_BAD_REQUEST);
        }

        $isBoard = $this->isBoardMember();
        $oldStatus = $ticket->getStatus();
        $oldPriority = $ticket->getPriority();

        if ($isBoard && $status !== null && in_array($status, Ticket::STATUSES, true)) {
            $ticket->setStatus($status);
        } elseif ($isBoard && $ticket->getStatus() === 'new') {
            // Un gestionnaire qui répond à un ticket "Nouveau" sans choisir de statut
            // le fait implicitement passer "En cours" : la réponse vaut prise en charge.
            $ticket->setStatus('in_progress');
        }
        if ($isBoard && $priority !== null && in_array($priority, Ticket::PRIORITIES, true)) {
            $ticket->setPriority($priority);
        }

        $comment = new Comment();
        $comment->setTicketId($id);
        $comment->setAuthorUid($this->currentUid());
        $comment->setMessage($message);
        $comment->setCreatedAt(time());
        $comment = $this->commentMapper->insert($comment);

        $ticket->setUpdatedAt(time());
        // Quand un gestionnaire répond à un ticket non encore assigné, on le lui
        // assigne automatiquement : c'est lui qui prend le ticket en charge.
        $justAssigned = false;
        if ($isBoard && $ticket->getAssignedUid() === null) {
            $ticket->setAssignedUid($this->currentUid());
            $justAssigned = true;
        }
        $ticket = $this->ticketMapper->update($ticket);
        $this->markRead($id);

        if ($ticket->getStatus() !== $oldStatus) {
            $this->logActivity($id, 'status_changed', $oldStatus, $ticket->getStatus());
        }
        if ($ticket->getPriority() !== $oldPriority) {
            $this->logActivity($id, 'priority_changed', $oldPriority, $ticket->getPriority());
        }
        if ($justAssigned) {
            $this->logActivity($id, 'assigned_changed', null, $this->assignedDisplayName($ticket->getAssignedUid()));
        }

        if ($justAssigned) {
            $this->notificationService->notifyTicketAssigned($ticket);
        }
        if ($ticket->getStatus() !== $oldStatus) {
            $this->notificationService->notifyStatusChanged($ticket, $oldStatus, $this->currentUid());
            if ($ticket->getStatus() === 'closed') {
                $this->notificationService->notifyTicketClosed($ticket, $this->currentUid());
            }
            $this->attachmentService->relocateFolderForStatus($ticket);
        }
        $this->notificationService->notifyCommentAdded($ticket, $comment);

        return new DataResponse($comment, Http::STATUS_CREATED);
    }

    /**
     * @NoAdminRequired
     * Dépôt d'une pièce jointe : mêmes règles d'accès que pour un commentaire
     * (accès au ticket + ticket encore ouvert). Stockée dans les Fichiers du
     * compte de stockage configuré par l'admin, pas ceux du demandeur — voir
     * AttachmentService.
     */
    public function addAttachment(int $id): DataResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        if (!$this->canAccess($ticket)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        if (in_array($ticket->getStatus(), ['resolved', 'closed'], true)) {
            return new DataResponse(['message' => 'Ticket is resolved, attachments are locked'], Http::STATUS_BAD_REQUEST);
        }

        if (!$this->attachmentService->isConfigured()) {
            return new DataResponse(['message' => 'Attachment storage is not configured'], Http::STATUS_BAD_REQUEST);
        }

        $file = $this->request->getUploadedFile('file');

        // Fichier déjà rejeté par PHP avant d'atteindre ce code (dépassement de
        // upload_max_filesize / post_max_size côté serveur) : sans ce contrôle,
        // l'absence de tmp_name retomberait sur le message générique "No file
        // uploaded" ci-dessous, trompeur pour un admin qui découvre l'appli.
        if (isset($file['error']) && in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return new DataResponse([
                'message' => 'File too large for this server\'s upload limit. Maximum size: ' . $this->configService->getMaxAttachmentSizeMb() . ' MB',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $attachment = $this->attachmentService->addAttachment(
                $ticket,
                $this->currentUid(),
                $file['tmp_name'],
                (string) ($file['name'] ?? 'file'),
                isset($file['type']) ? (string) $file['type'] : null
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'message' => 'File type not allowed. Allowed extensions: ' . implode(', ', $this->configService->getAllowedExtensions()),
            ], Http::STATUS_BAD_REQUEST);
        } catch (\LengthException $e) {
            return new DataResponse([
                'message' => 'File too large. Maximum size: ' . $this->configService->getMaxAttachmentSizeMb() . ' MB',
            ], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => 'Could not store attachment'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $ticket->setUpdatedAt(time());
        $this->ticketMapper->update($ticket);
        $this->markRead($id);
        $this->logActivity($id, 'attachment_added', null, $attachment->getFileName());

        return new DataResponse($attachment, Http::STATUS_CREATED);
    }

    /**
     * @NoAdminRequired
     * URL du dossier de pièces jointes du ticket dans l'app Fichiers (bouton
     * « Ouvrir le dossier » du tableau, côté gestionnaire uniquement — pas
     * les demandeurs, qui gèrent leurs pièces jointes depuis le ticket).
     */
    public function attachmentsFolder(int $id): DataResponse {
        if (!$this->isBoardMember()) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        try {
            $ticket = $this->ticketMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $url = $this->attachmentService->getFolderUrl($ticket);
        } catch (NotFoundException $e) {
            return new DataResponse(['message' => 'No attachments for this ticket'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => 'Attachment storage is not configured'], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['url' => $url]);
    }

    /**
     * Mimetypes pouvant être servis "inline" (aperçu maison : image, PDF, texte
     * brut — les seuls types que le front-end sait effectivement prévisualiser,
     * voir isPreviewable() côté Vue). Le mimetype stocké provient à l'origine
     * du navigateur du déposant au moment de l'upload (voir
     * AttachmentService::addAttachment) et peut donc avoir été falsifié : un
     * fichier à l'extension autorisée pourrait être enregistré avec un
     * Content-Type mensonger. Sans cette liste blanche, le renvoyer tel quel
     * en "inline" permettrait au navigateur de l'interpréter (ex. comme du
     * HTML) plutôt que de le télécharger, donc une exécution de script dans le
     * contexte de l'instance Nextcloud (XSS stocké). En téléchargement classique
     * (Content-Disposition: attachment) ce risque n'existe pas : le navigateur
     * enregistre le fichier sans jamais l'exécuter, quel que soit le Content-Type
     * annoncé.
     */
    private const INLINE_SAFE_MIMETYPES = ['image/png', 'image/jpeg', 'application/pdf', 'text/plain'];

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * Téléchargement via l'API du contrôleur (pas de lien de partage Nextcloud
     * natif) : c'est canAccess() ici, et pas un partage, qui décide qui peut
     * récupérer le fichier. Pas de header requesttoken possible sur un lien
     * <a href> classique, d'où @NoCSRFRequired (lecture seule).
     * Le paramètre $inline permet à l'aperçu maison du front-end (<img> pour
     * une image, fetch() texte brut pour un .txt) de charger le fichier pour
     * affichage sans déclencher un téléchargement (Content-Disposition:
     * inline au lieu de attachment). Voir INLINE_SAFE_MIMETYPES ci-dessus :
     * $inline est ignoré (on repasse en "attachment") si le mimetype stocké
     * n'est pas dans la liste blanche, par sécurité.
     */
    public function downloadAttachment(int $id, int $attachmentId, bool $inline = false): DataResponse|StreamResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
            $attachment = $this->attachmentMapper->find($attachmentId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Attachment not found'], Http::STATUS_NOT_FOUND);
        }

        if ($attachment->getTicketId() !== $ticket->getId() || !$this->canAccess($ticket)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        try {
            $node = $this->attachmentService->getFile($attachment, $ticket);
            $stream = $node->fopen('r');
        } catch (NotFoundException|\RuntimeException $e) {
            return new DataResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        $mimetype = $attachment->getMimetype() ?? 'application/octet-stream';
        if ($inline && !in_array($mimetype, self::INLINE_SAFE_MIMETYPES, true)) {
            $inline = false;
        }

        $disposition = $inline ? 'inline' : 'attachment';
        $response = new StreamResponse($stream);
        $response->addHeader('Content-Disposition', $this->contentDispositionHeader($disposition, $attachment->getFileName()));
        $response->addHeader('Content-Type', $mimetype);
        // Défense en profondeur : empêche le navigateur de deviner un type différent
        // de celui annoncé (sans effet sur le risque ci-dessus, qui porte sur le type
        // annoncé lui-même, mais bonne pratique standard sur ce genre de réponse).
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /**
     * @NoAdminRequired
     * Suppression réservée au groupe gestionnaire, ou à l'auteur du dépôt tant
     * que le ticket est encore ouvert (même logique que pour l'édition du
     * ticket par son demandeur).
     */
    public function deleteAttachment(int $id, int $attachmentId): DataResponse {
        try {
            $ticket = $this->ticketMapper->find($id);
            $attachment = $this->attachmentMapper->find($attachmentId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Attachment not found'], Http::STATUS_NOT_FOUND);
        }

        if ($attachment->getTicketId() !== $ticket->getId() || !$this->canAccess($ticket)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $isBoard = $this->isBoardMember();
        $isOpen = !in_array($ticket->getStatus(), ['resolved', 'closed'], true);
        if (!$isBoard && !($attachment->getUploadedBy() === $this->currentUid() && $isOpen)) {
            return new DataResponse(['message' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }

        $this->attachmentService->deleteAttachment($attachment, $ticket);
        $this->logActivity($id, 'attachment_deleted', $attachment->getFileName(), null);

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    /**
     * Construit un header Content-Disposition conforme à la RFC 6266/5987 pour un
     * nom de fichier pouvant contenir des caractères non-ASCII (accents, etc.,
     * fréquents vu que les pièces jointes viennent de noms de fichiers choisis par
     * l'utilisateur). Un filename="..." purement ASCII (translittéré, guillemets
     * retirés) sert de repli pour les clients qui ignorent filename*, accompagné
     * d'un filename*=UTF-8''... pourcent-encodé pour les autres : sans ce second
     * paramètre, un nom de fichier accentué était soit tronqué soit mal interprété
     * par certains clients HTTP/navigateurs.
     */
    private function contentDispositionHeader(string $disposition, string $fileName): string {
        $asciiFallback = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $fileName);
        if ($asciiFallback === false || $asciiFallback === '') {
            $asciiFallback = 'file';
        }
        $asciiFallback = str_replace(['"', '\\'], '', $asciiFallback);

        return $disposition
            . '; filename="' . $asciiFallback . '"'
            . '; filename*=UTF-8\'\'' . rawurlencode($fileName);
    }

    /** Un utilisateur ne peut voir/commenter que ses propres tickets ; le groupe gestionnaire voit tout */
    private function canAccess(Ticket $ticket): bool {
        return $this->isBoardMember() || $ticket->getOwnerUid() === $this->currentUid();
    }
}
