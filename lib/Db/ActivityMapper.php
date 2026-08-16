<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Activity>
 */
class ActivityMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tickets_activity', Activity::class);
    }

    /**
     * @return Activity[]
     */
    public function findByTicket(int $ticketId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Toutes les entrées d'activité dont un utilisateur donné est l'auteur de
     * l'action, tous tickets confondus : sert à l'export RGPD (TicketsMigrator).
     * @return Activity[]
     */
    public function findByActor(string $actorUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Suppression en masse de l'activité d'un ticket (appelée quand le ticket
     * lui-même est supprimé — voir TicketController::destroy et UserDeletedListener).
     */
    public function deleteByTicket(int $ticketId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Suppression de toute l'activité, tous tickets confondus (appelée par
     * SettingsController::reset() lors d'une remise à zéro complète de la base).
     */
    public function deleteAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        $qb->executeStatement();
    }
}
