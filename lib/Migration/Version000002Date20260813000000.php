<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Une ligne par (ticket, utilisateur) : mémorise quand cet utilisateur a
 * consulté ce ticket pour la dernière fois. Sert à afficher un repère visuel
 * (pastille) sur les tickets ayant une activité plus récente que la dernière
 * consultation.
 */
class Version000002Date20260813000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('tickets_reads')) {
            $table = $schema->createTable('tickets_reads');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('read_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['ticket_id', 'uid'], 'tickets_reads_ticket_uid_idx');
        }

        return $schema;
    }
}
