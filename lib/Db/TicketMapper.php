<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Ticket>
 */
class TicketMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tickets', Ticket::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Ticket {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Tous les tickets (vue groupe gestionnaire), paginés, filtrés et triés.
     * @param array{status?: string, priority?: string, category?: string, assignedUid?: string} $filters
     * @return Ticket[]
     */
    public function findAll(int $limit, int $offset, array $filters = [], string $sort = 'created_at', string $order = 'DESC'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        $this->applyFilters($qb, $filters);
        $this->applySort($qb, $sort, $order);
        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * @param array{status?: string, priority?: string, category?: string, assignedUid?: string} $filters
     */
    public function countAll(array $filters = []): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS total'))
            ->from($this->getTableName());
        $this->applyFilters($qb, $filters);
        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * @param array{status?: string, priority?: string, category?: string, assignedUid?: string, search?: string} $filters
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void {
        if (!empty($filters['status'])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }
        if (!empty($filters['priority'])) {
            $qb->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter($filters['priority'])));
        }
        if (!empty($filters['category'])) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($filters['category'])));
        }
        if (!empty($filters['assignedUid'])) {
            // Valeur spéciale "_unassigned" : tickets sans personne assignée.
            if ($filters['assignedUid'] === '_unassigned') {
                $qb->andWhere($qb->expr()->isNull('assigned_uid'));
            } else {
                $qb->andWhere($qb->expr()->eq('assigned_uid', $qb->createNamedParameter($filters['assignedUid'])));
            }
        }
        if (!empty($filters['search'])) {
            // Recherche libre sur titre/description/nom demandeur/lieu, insensible à la
            // casse. Utilisée aussi bien côté gestionnaire (tous les tickets) que côté
            // demandeur (ses propres tickets, filtre déjà appliqué en amont).
            $term = '%' . $this->db->escapeLikeParameter($filters['search']) . '%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('title', $qb->createNamedParameter($term)),
                $qb->expr()->iLike('description', $qb->createNamedParameter($term)),
                $qb->expr()->iLike('requester_name', $qb->createNamedParameter($term)),
                $qb->expr()->iLike('requester_location', $qb->createNamedParameter($term))
            ));
        }
    }

    /**
     * Nombre de tickets par statut, en tenant compte des filtres actifs autres que
     * le statut lui-même (priorité/catégorie/assigné) : alimente les compteurs
     * cliquables au-dessus du tableau, qui doivent rester cohérents avec les
     * autres filtres en cours sans être eux-mêmes limités au statut sélectionné.
     * @param array{priority?: string, category?: string, assignedUid?: string} $filters
     * @return array<string, int> statut => nombre
     */
    public function countsByStatus(array $filters = [], ?string $ownerUid = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status', $qb->createFunction('COUNT(*) AS total'))
            ->from($this->getTableName())
            ->groupBy('status');
        if ($ownerUid !== null) {
            $qb->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        }
        $this->applyFilters($qb, $filters);
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $result->closeCursor();
        return $counts;
    }

    /** Liste blanche des colonnes triables, pour ne jamais interpoler une entrée utilisateur telle quelle. */
    private function sortColumn(string $sort): string {
        $allowed = [
            'created_at', 'updated_at', 'priority', 'status', 'title',
            'id', 'category', 'requester_name', 'requester_location', 'assigned_uid', 'due_at',
        ];
        return in_array($sort, $allowed, true) ? $sort : 'created_at';
    }

    /**
     * Applique le tri demandé. Pour "priority" et "status", un tri alphabétique de la
     * colonne ne correspond pas à l'ordre logique métier (ex: "urgent" > "low"
     * alphabétiquement alors que c'est l'inverse en gravité) : on utilise donc un
     * CASE WHEN qui reflète l'ordre réel, plutôt qu'un simple ORDER BY colonne.
     * Pour "due_at", les tickets sans échéance (NULL) sont toujours relégués en fin
     * de liste, quel que soit le sens du tri, plutôt que de suivre le comportement
     * NULL FIRST/LAST par défaut (qui diffère selon le SGBD).
     */
    private function applySort(IQueryBuilder $qb, string $sort, string $order): void {
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $column = $this->sortColumn($sort);

        if ($column === 'priority') {
            $qb->addSelect($qb->createFunction(
                "(CASE priority WHEN 'urgent' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END) AS priority_rank"
            ));
            $qb->orderBy('priority_rank', $order);
        } elseif ($column === 'status') {
            $qb->addSelect($qb->createFunction(
                "(CASE status WHEN 'new' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 ELSE 5 END) AS status_rank"
            ));
            $qb->orderBy('status_rank', $order);
        } elseif ($column === 'due_at') {
            $qb->addSelect($qb->createFunction(
                '(CASE WHEN due_at IS NULL THEN 1 ELSE 0 END) AS due_at_null_rank'
            ));
            $qb->orderBy('due_at_null_rank', 'ASC');
            $qb->addOrderBy('due_at', $order);
        } else {
            $qb->orderBy($column, $order);
        }
        // Départage stable des égalités (même priorité/statut/échéance...) par date de création.
        if ($column !== 'created_at') {
            $qb->addOrderBy('created_at', 'DESC');
        }
    }

    /**
     * Tous les tickets correspondant aux filtres donnés, sans pagination : sert à
     * l'export "vue actuelle" du groupe gestionnaire (TicketController::exportTickets),
     * qui doit refléter exactement les filtres/tri actifs dans le tableau — contrairement
     * à findAllUnbounded(), qui ignore tout filtre et sert à l'export de sauvegarde
     * complet réservé à l'admin (SettingsController::exportTickets).
     * @param array{status?: string, priority?: string, category?: string, assignedUid?: string} $filters
     * @return Ticket[]
     */
    public function findAllFiltered(array $filters, string $sort = 'created_at', string $order = 'DESC'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        $this->applyFilters($qb, $filters);
        $this->applySort($qb, $sort, $order);

        return $this->findEntities($qb);
    }

    /**
     * Tous les tickets, sans pagination : réservé à l'export (SettingsController),
     * qui a besoin de l'intégralité des données, contrairement à la vue liste.
     * @return Ticket[]
     */
    public function findAllUnbounded(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Uniquement les tickets d'un utilisateur donné, paginés, filtrés et triés.
     * @param array{status?: string, priority?: string, category?: string} $filters
     * @return Ticket[]
     */
    public function findByOwner(string $ownerUid, int $limit, int $offset, array $filters = [], string $sort = 'created_at', string $order = 'DESC'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        $this->applyFilters($qb, $filters);
        $this->applySort($qb, $sort, $order);
        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Tickets avec échéance non close dont la relance automatique n'a pas
     * encore été envoyée pour l'état courant (voir DueDateReminderJob) :
     * échéance dépassée (quel que soit $threshold), ou échéance à portée de
     * $threshold et aucune relance envoyée pour l'instant. Le job appelant
     * décide ensuite, ticket par ticket, s'il s'agit d'une relance "proche"
     * ou "en retard" (selon due_at vs "maintenant") et si elle a déjà été
     * envoyée pour cet état précis.
     * @return Ticket[]
     */
    public function findDueForReminder(int $threshold): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('due_at'))
            ->andWhere($qb->expr()->lte('due_at', $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq('due_reminder_stage', $qb->createNamedParameter('overdue')))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(['resolved', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)));

        return $this->findEntities($qb);
    }

    /**
     * Tickets encore ouverts (ni résolus ni fermés) d'un utilisateur donné, sans
     * pagination : alimente la détection de doublons à la création. L'ensemble
     * reste petit par utilisateur, la comparaison de similarité se fait en PHP
     * (voir TicketController::findPotentialDuplicates) plutôt qu'en SQL.
     * @return Ticket[]
     */
    public function findOpenByOwner(string $ownerUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter(['resolved', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @param array{status?: string, priority?: string, category?: string} $filters
     */
    public function countByOwner(string $ownerUid, array $filters = []): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS total'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        $this->applyFilters($qb, $filters);
        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Tous les tickets d'un utilisateur donné, sans pagination ni filtre : sert à
     * l'export RGPD (portabilité) et à la suppression de compte (TicketsMigrator,
     * UserDeletedListener), qui ont besoin de l'intégralité, contrairement à la
     * vue liste paginée (findByOwner).
     * @return Ticket[]
     */
    public function findAllByOwner(string $ownerUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Tickets assignés à un utilisateur donné (quel qu'en soit le propriétaire) :
     * sert à la désassignation lors de la suppression de son compte
     * (UserDeletedListener) — ces tickets, propriété d'un tiers, ne sont eux-mêmes
     * pas supprimés.
     * @return Ticket[]
     */
    public function findByAssignee(string $assignedUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('assigned_uid', $qb->createNamedParameter($assignedUid)));

        return $this->findEntities($qb);
    }
}
