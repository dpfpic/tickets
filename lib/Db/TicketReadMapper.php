<?php

declare(strict_types=1);

namespace OCA\Tickets\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TicketRead>
 */
class TicketReadMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tickets_reads', TicketRead::class);
    }

    /**
     * Date de dernière lecture, par ticket, pour un utilisateur donné. Les
     * tickets absents du résultat n'ont jamais été consultés par cet
     * utilisateur (à traiter comme "jamais lu", pas comme une erreur).
     *
     * @param int[] $ticketIds
     * @return array<int, int> ticketId => timestamp unix de dernière lecture
     */
    public function findReadTimestamps(array $ticketIds, string $uid): array {
        if (count($ticketIds) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('ticket_id', 'read_at')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int) $row['ticket_id']] = (int) $row['read_at'];
        }
        $result->closeCursor();

        return $map;
    }

    /**
     * Marque le ticket comme lu maintenant par cet utilisateur (upsert manuel
     * select puis insert/update, pour rester portable entre MySQL, PostgreSQL
     * et SQLite sans dépendre d'une syntaxe "ON CONFLICT" spécifique).
     */
    public function markRead(int $ticketId, string $uid, int $readAt): void {
        $select = $this->db->getQueryBuilder();
        $select->select('id')
            ->from($this->getTableName())
            ->where($select->expr()->eq('ticket_id', $select->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
            ->andWhere($select->expr()->eq('uid', $select->createNamedParameter($uid)));
        $result = $select->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            $insert = $this->db->getQueryBuilder();
            $insert->insert($this->getTableName())
                ->values([
                    'ticket_id' => $insert->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
                    'uid' => $insert->createNamedParameter($uid),
                    'read_at' => $insert->createNamedParameter($readAt, IQueryBuilder::PARAM_INT),
                ]);
            $insert->executeStatement();
            return;
        }

        $update = $this->db->getQueryBuilder();
        $update->update($this->getTableName())
            ->set('read_at', $update->createNamedParameter($readAt, IQueryBuilder::PARAM_INT))
            ->where($update->expr()->eq('id', $update->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)));
        $update->executeStatement();
    }

    /**
     * Tous les marqueurs de lecture d'un utilisateur donné, tous tickets confondus :
     * sert à l'export RGPD (TicketsMigrator).
     * @return TicketRead[]
     */
    public function findByUid(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        return $this->findEntities($qb);
    }

    /**
     * Suppression en masse des marqueurs de lecture d'un ticket (appelée quand le
     * ticket lui-même est supprimé — voir TicketController::destroy et UserDeletedListener).
     */
    public function deleteByTicket(int $ticketId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Suppression en masse des marqueurs de lecture d'un utilisateur, tous tickets
     * confondus (UserDeletedListener) : ce sont ses propres marqueurs, sans impact
     * sur les tickets d'un tiers.
     */
    public function deleteByUid(string $uid): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
        $qb->executeStatement();
    }

    /**
     * Suppression de tous les marqueurs de lecture, tous tickets/utilisateurs confondus
     * (appelée par SettingsController::reset() lors d'une remise à zéro complète de la base).
     */
    public function deleteAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        $qb->executeStatement();
    }
}
