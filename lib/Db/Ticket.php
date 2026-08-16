<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method string|null getStatus()
 * @method void setStatus(string $status)
 * @method string|null getPriority()
 * @method void setPriority(string $priority)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string|null getAssignedUid()
 * @method void setAssignedUid(?string $assignedUid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getRequesterName()
 * @method void setRequesterName(?string $requesterName)
 * @method string|null getRequesterLocation()
 * @method void setRequesterLocation(?string $requesterLocation)
 * @method int|null getDueAt()
 * @method void setDueAt(?int $dueAt)
 * @method string getDueReminderStage()
 * @method void setDueReminderStage(string $dueReminderStage)
 */
class Ticket extends Entity implements JsonSerializable {
    protected $title = '';
    protected $description = '';
    protected $category = 'other';
    // IMPORTANT : ne pas remettre 'new'/'normal' comme valeur par défaut ici.
    // Entity::setter() de Nextcloud n'ajoute un champ à getUpdatedFields() que si
    // la nouvelle valeur diffère de la valeur actuelle de la propriété. Si ce
    // défaut valait déjà 'new', l'appel TicketController::create() -> setStatus('new')
    // serait un no-op silencieux : la colonne 'status' disparaîtrait purement et
    // simplement de l'INSERT généré par QBMapper (idem pour 'priority'), en comptant
    // sur le défaut SQL de la colonne pour rattraper le coup — ce qui a justement fini
    // par ne pas être le cas en prod (voir Version000007). En gardant null ici, le
    // premier setStatus('new')/setPriority('normal') de create() diffère toujours de
    // la valeur courante et est donc bien marqué comme modifié. jsonSerialize()
    // ci-dessous retombe de toute façon sur 'new'/'normal' si jamais getStatus()/
    // getPriority() renvoient null (ticket très ancien non rattrapé, appel API direct...).
    protected $status = null;
    protected $priority = null;
    protected $ownerUid = '';
    protected $assignedUid = null;
    protected $createdAt = 0;
    protected $updatedAt = 0;
    // Nom et localisation du demandeur, saisis (et modifiables) dans le formulaire de
    // création — pré-remplis côté client avec le nom complet et l'adresse du profil
    // Nextcloud de l'utilisateur connecté (voir TicketController::context()), mais pas
    // recalculés depuis le profil ensuite : ce sont des champs libres propres au ticket.
    protected $requesterName = '';
    protected $requesterLocation = '';
    // Échéance ("à traiter avant le"), en timestamp unix (fin de journée locale),
    // ou null si aucune échéance n'a été fixée. Réservé aux gestionnaires
    // (voir TicketController::update()).
    protected $dueAt = null;
    // État des relances automatiques déjà envoyées pour l'échéance courante
    // (voir DueDateReminderJob) : 'none' | 'due_soon' | 'overdue'. Remis à
    // 'none' dès que dueAt change, pour ne jamais spammer sur une échéance
    // déjà dépassée puis décalée.
    protected $dueReminderStage = 'none';

    public const STATUSES = ['new', 'in_progress', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'urgent'];

    /**
     * @deprecated Depuis la 0.4.0, la liste des catégories est configurable par
     * l'admin (voir ConfigService::getCategories()) ; cette constante n'est
     * conservée que comme repli historique/documentation, elle n'est plus lue
     * pour la validation.
     */
    public const CATEGORIES = ['plumbing', 'elevator', 'common_areas', 'nuisance', 'other'];

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ticketNumber' => $this->getTicketNumber(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'category' => $this->getCategory(),
            // Si des lignes historiques ont un statut/priorité NULL en base (import,
            // ancienne donnée...), on retombe sur une valeur valide plutôt que de
            // renvoyer null au frontend : sinon le badge correspondant ne matche
            // aucune classe CSS de couleur et s'affiche vide (texte blanc invisible).
            'status' => $this->getStatus() ?: 'new',
            'priority' => $this->getPriority() ?: 'normal',
            'ownerUid' => $this->getOwnerUid(),
            'assignedUid' => $this->getAssignedUid(),
            'requesterName' => $this->getRequesterName(),
            'requesterLocation' => $this->getRequesterLocation(),
            'dueAt' => $this->getDueAt() !== null ? (int) $this->getDueAt() : null,
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * Numéro de ticket lisible, dérivé de l'id (garanti unique, pas de
     * compteur séparé à maintenir) et de l'année de création.
     * Ex: TCK-2026-00007
     */
    public function getTicketNumber(): string {
        $year = $this->getCreatedAt() > 0 ? (int) date('Y', $this->getCreatedAt()) : (int) date('Y');
        return sprintf('TCK-%d-%05d', $year, $this->getId());
    }
}
