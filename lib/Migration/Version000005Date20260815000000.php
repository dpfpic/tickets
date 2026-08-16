<?php

declare(strict_types=1);

namespace OCA\Tickets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ajoute l'échéance ("à traiter avant le") et le suivi des relances
 * automatiques (DueDateReminderJob) sur les tickets.
 */
class Version000005Date20260815000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('tickets');

        if (!$table->hasColumn('due_at')) {
            $table->addColumn('due_at', 'bigint', [
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('due_reminder_stage')) {
            $table->addColumn('due_reminder_stage', 'string', [
                'notnull' => true,
                'length' => 16,
                'default' => 'none',
            ]);
        }

        return $schema;
    }
}
