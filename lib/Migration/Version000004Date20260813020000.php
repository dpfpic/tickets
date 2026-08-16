<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Pièces jointes d'un ticket. Le fichier lui-même n'est pas stocké dans une
 * colonne de cette table : il vit dans les Fichiers du compte de stockage
 * configuré par l'admin (voir ConfigService::getStorageAccountUid()), sous
 * Tickets/<numéro-ticket>/<nom-fichier>. Cette table ne fait que référencer
 * ce fichier (métadonnées + lien vers le ticket), un peu comme
 * tickets_comments référence un message.
 */
class Version000004Date20260813020000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('tickets_attachments')) {
            $table = $schema->createTable('tickets_attachments');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('file_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('mimetype', 'string', [
                'notnull' => false,
                'length' => 128,
            ]);
            $table->addColumn('size', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('uploaded_by', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'tickets_attach_ticket_idx');
        }

        return $schema;
    }
}
