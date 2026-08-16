<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tickets_comments', Comment::class);
    }

    /**
     * @return Comment[]
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
     * Date du dernier message (commentaire) pour chaque ticket demandé.
     * Sert de base au marqueur "Nouveau" : seul un nouveau message doit le déclencher,
     * pas un simple changement de statut/priorité/assignation.
     *
     * @param int[] $ticketIds
     * @return array<int, int> ticketId => timestamp du dernier commentaire
     */
    public function findLastCommentTimestamps(array $ticketIds): array {
        if (count($ticketIds) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('ticket_id')
            ->selectAlias($qb->createFunction('MAX(' . $qb->getColumnName('created_at') . ')'), 'last_comment_at')
            ->from($this->getTableName())
            ->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('ticket_id');

        $result = $qb->executeQuery();
        $timestamps = [];
        while ($row = $result->fetch()) {
            $timestamps[(int)$row['ticket_id']] = (int)$row['last_comment_at'];
        }
        $result->closeCursor();

        return $timestamps;
    }

    /**
     * Tous les commentaires écrits par un utilisateur donné, tous tickets
     * confondus : sert à l'export RGPD (TicketsMigrator).
     * @return Comment[]
     */
    public function findByAuthor(string $authorUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Suppression en masse des commentaires d'un ticket (appelée quand le ticket
     * lui-même est supprimé — voir TicketController::destroy et UserDeletedListener).
     */
    public function deleteByTicket(int $ticketId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Suppression en masse des commentaires écrits par un utilisateur, sur des
     * tickets qui ne sont pas eux-mêmes supprimés (UserDeletedListener) : le
     * ticket appartient à un tiers et reste, seule sa propre contribution part.
     */
    public function deleteByAuthor(string $authorUid): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid)));
        $qb->executeStatement();
    }
}
