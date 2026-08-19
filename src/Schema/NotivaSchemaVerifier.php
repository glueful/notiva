<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/notiva (schema policy spec B7): each create migration proves
 * every table it creates with its load-bearing columns. Unknown basenames are never adoptable.
 */
final class NotivaSchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/notiva';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreatePushDevicesTable.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreatePushDevicesTable.php' => $this->tablesWithColumns($db, [
                'push_devices' => ['user_uuid', 'device_token', 'subscription_json', 'invalidated_at'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
