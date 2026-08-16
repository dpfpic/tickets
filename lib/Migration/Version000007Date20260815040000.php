<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Corrige les tickets créés avec les valeurs par défaut de statut/priorité
 * ('new' / 'normal') : Entity::setter() de Nextcloud ne marquait pas ces
 * champs comme modifiés quand la valeur posée par TicketController::create()
 * était déjà égale au défaut PHP de Ticket::$status / Ticket::$priority (voir
 * Ticket.php), donc QBMapper::insert() les omettait purement et simplement de
 * la requête. Sur les instances où la colonne SQL n'avait elle-même pas
 * (encore) de défaut au moment de l'exécution de Version000001, ces tickets
 * se sont retrouvés avec status/priority NULL ou vide en base, d'où les
 * badges sans couleur côté interface. Cette migration rattrape les lignes
 * concernées ; Ticket.php est corrigé en parallèle pour que le problème ne se
 * reproduise plus sur les nouveaux tickets.
 */
class Version000007Date20260815040000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $connection,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('tickets');

        // Défensif : s'assure que le défaut SQL est bien celui attendu, au cas où
        // l'instance aurait exécuté Version000001 avant qu'il n'y soit défini.
        if ($table->hasColumn('status')) {
            $table->getColumn('status')->setDefault('new');
        }
        if ($table->hasColumn('priority')) {
            $table->getColumn('priority')->setDefault('normal');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->connection->getQueryBuilder();
        $updatedStatus = $qb->update('tickets')
            ->set('status', $qb->createNamedParameter('new'))
            ->where($qb->expr()->orX(
                $qb->expr()->isNull('status'),
                $qb->expr()->eq('status', $qb->createNamedParameter(''))
            ))
            ->executeStatement();

        $qb = $this->connection->getQueryBuilder();
        $updatedPriority = $qb->update('tickets')
            ->set('priority', $qb->createNamedParameter('normal'))
            ->where($qb->expr()->orX(
                $qb->expr()->isNull('priority'),
                $qb->expr()->eq('priority', $qb->createNamedParameter(''))
            ))
            ->executeStatement();

        if ($updatedStatus > 0 || $updatedPriority > 0) {
            $output->info("Tickets rattrapés : {$updatedStatus} statut(s), {$updatedPriority} priorité(s) remis à leur valeur par défaut.");
        }
    }
}
