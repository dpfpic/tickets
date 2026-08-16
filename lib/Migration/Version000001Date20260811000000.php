<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260811000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('tickets')) {
            $table = $schema->createTable('tickets');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('title', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('category', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => 'other',
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'new',
            ]);
            $table->addColumn('priority', 'string', [
                'notnull' => true,
                'length' => 32,
                'default' => 'normal',
            ]);
            $table->addColumn('owner_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('assigned_uid', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner_uid'], 'tickets_owner_idx');
            $table->addIndex(['status'], 'tickets_status_idx');
        }

        if (!$schema->hasTable('tickets_comments')) {
            $table = $schema->createTable('tickets_comments');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ticket_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('author_uid', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('message', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['ticket_id'], 'tickets_comments_ticket_idx');
        }

        return $schema;
    }
}
