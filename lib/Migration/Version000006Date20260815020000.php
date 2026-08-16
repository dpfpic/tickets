<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Journal d'activité horodaté d'un ticket : création, changements de statut,
 * priorité, assignation, échéance, ajout/suppression de pièces jointes. Vient
 * compléter les commentaires (tickets_comments) pour reconstituer une
 * chronologie complète des événements d'un ticket, dans l'ordre.
 */
class Version000006Date20260815020000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('tickets_activity')) {
            $table = $schema->createTable('tickets_activity');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('actor_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            // Type d'événement : 'created' | 'status_changed' | 'priority_changed' |
            // 'assigned_changed' | 'due_changed' | 'attachment_added' | 'attachment_deleted'.
            $table->addColumn('type', 'string', [
                'notnull' => true,
                'length' => 32,
            ]);
            // Valeurs déjà mises en forme côté serveur (libellé affichable, pas de clé
            // brute à retraduire), pour rester simple à afficher côté client. Vide/null
            // selon le type (ex. 'created' n'a ni ancienne ni nouvelle valeur).
            $table->addColumn('old_value', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('new_value', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'tickets_activity_ticket_idx');
        }

        return $schema;
    }
}
