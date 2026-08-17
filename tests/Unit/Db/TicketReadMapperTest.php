<?php

declare(strict_types=1);

namespace OCA\Tickets\Tests\Db;

use OCA\Tickets\Db\TicketReadMapper;
use OCP\IDBConnection;

/**
 * @covers \OCA\Tickets\Db\TicketReadMapper
 *
 * Tourne contre une vraie connexion DB (celle du serveur de test Nextcloud,
 * généralement SQLite) plutôt que contre un mock : c'est justement la
 * logique select-puis-insert/update, censée être portable entre SGBD, que ce
 * test doit couvrir. La table tickets_reads n'a pas de contrainte de clé
 * étrangère (voir la migration), donc des ticket_id fictifs suffisent — pas
 * besoin de créer de vrais tickets.
 *
 * Un préfixe uid dédié et un tearDown() de nettoyage évitent de polluer
 * d'autres données de la table lors de l'exécution de la suite.
 *
 * @group DB
 */
class TicketReadMapperTest extends \Test\TestCase {
    private const TEST_UID = 'phpunit-ticketreadmapper-test';
    private const TICKET_A = 900000001;
    private const TICKET_B = 900000002;

    private IDBConnection $db;
    private TicketReadMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db = \OC::$server->get(IDBConnection::class);
        $this->mapper = new TicketReadMapper($this->db);
        $this->cleanUp();
    }

    protected function tearDown(): void {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('tickets_reads')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter(self::TEST_UID)))
            ->executeStatement();
    }

    public function testFindReadTimestampsReturnsEmptyArrayForNoIds(): void {
        $this->assertSame([], $this->mapper->findReadTimestamps([], self::TEST_UID));
    }

    public function testFindReadTimestampsOmitsNeverReadTickets(): void {
        $this->mapper->markRead(self::TICKET_A, self::TEST_UID, 1000);

        $result = $this->mapper->findReadTimestamps([self::TICKET_A, self::TICKET_B], self::TEST_UID);

        // TICKET_B n'a jamais été lu : absent du résultat, pas une entrée à 0.
        $this->assertSame([self::TICKET_A => 1000], $result);
    }

    public function testMarkReadInsertsOnFirstCall(): void {
        $this->mapper->markRead(self::TICKET_A, self::TEST_UID, 1234);

        $result = $this->mapper->findReadTimestamps([self::TICKET_A], self::TEST_UID);

        $this->assertSame([self::TICKET_A => 1234], $result);
    }

    public function testMarkReadUpdatesInPlaceOnSecondCall(): void {
        $this->mapper->markRead(self::TICKET_A, self::TEST_UID, 1000);
        $this->mapper->markRead(self::TICKET_A, self::TEST_UID, 2000);

        $result = $this->mapper->findReadTimestamps([self::TICKET_A], self::TEST_UID);

        $this->assertSame([self::TICKET_A => 2000], $result);

        // Toujours une seule ligne pour ce couple (ticket, uid) : c'est bien
        // un update et non un doublon d'insert (la contrainte unique de la
        // migration l'interdirait de toute façon, mais autant vérifier le
        // comportement du mapper directement).
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from('tickets_reads')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter(self::TEST_UID)))
            ->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter(self::TICKET_A, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $count = (int) $qb->executeQuery()->fetchOne();
        $this->assertSame(1, $count);
    }

    public function testMarkReadKeepsSeparateRowsPerTicket(): void {
        $this->mapper->markRead(self::TICKET_A, self::TEST_UID, 1000);
        $this->mapper->markRead(self::TICKET_B, self::TEST_UID, 5000);

        $result = $this->mapper->findReadTimestamps([self::TICKET_A, self::TICKET_B], self::TEST_UID);

        $this->assertSame([
            self::TICKET_A => 1000,
            self::TICKET_B => 5000,
        ], $result);
    }
}
