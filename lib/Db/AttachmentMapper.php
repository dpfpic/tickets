<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tickets_attachments', Attachment::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Attachment {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return Attachment[]
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
     * Nombre de pièces jointes par ticket, pour un ensemble de tickets (utilisé
     * par la liste : évite une requête par ligne).
     *
     * @param int[] $ticketIds
     * @return array<int, int> ticket_id => nombre de pièces jointes
     */
    public function countByTickets(array $ticketIds): array {
        if (count($ticketIds) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('ticket_id')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'attachment_count')
            ->from($this->getTableName())
            ->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('ticket_id');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['ticket_id']] = (int)$row['attachment_count'];
        }
        $result->closeCursor();

        return $counts;
    }

    /**
     * Toutes les pièces jointes déposées par un utilisateur donné, tous tickets
     * confondus : sert à l'export et à la suppression RGPD (TicketsMigrator,
     * UserDeletedListener).
     * @return Attachment[]
     */
    public function findByUploader(string $uploadedBy): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uploaded_by', $qb->createNamedParameter($uploadedBy)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Suppression en masse des pièces jointes d'un ticket (appelée quand le
     * ticket lui-même est supprimé). Passe par une requête DELETE directe
     * plutôt que par un findByTicket()+delete() entité par entité : plus
     * léger, et évite de charger des entités qu'on va de toute façon jeter.
     */
    public function deleteByTicket(int $ticketId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Suppression de toutes les pièces jointes, tous tickets confondus (appelée par
     * SettingsController::reset() lors d'une remise à zéro complète de la base).
     */
    public function deleteAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        $qb->executeStatement();
    }
}
