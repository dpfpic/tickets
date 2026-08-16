<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ajoute au ticket le nom du demandeur et sa localisation, saisis dans le
 * formulaire de nouvelle requête et pré-remplis par défaut avec le nom
 * complet et l'adresse du profil Nextcloud de l'utilisateur connecté (voir
 * TicketController::context()).
 */
class Version000003Date20260813010000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('tickets');

        if (!$table->hasColumn('requester_name')) {
            $table->addColumn('requester_name', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
        }

        if (!$table->hasColumn('requester_location')) {
            $table->addColumn('requester_location', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
        }

        return $schema;
    }
}
