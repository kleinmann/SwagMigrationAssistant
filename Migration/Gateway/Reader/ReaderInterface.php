<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Gateway\Reader;

use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Migration\TotalStruct;

interface ReaderInterface
{
    public function supports(MigrationContextInterface $migrationContext): bool;

    public function supportsTotal(MigrationContextInterface $migrationContext): bool;

    /**
     * Reads data from source via the given gateway based on implementation
     */
    public function read(MigrationContextInterface $migrationContext, array $params = []): array;

    public function readTotal(MigrationContextInterface $migrationContext): ?TotalStruct;
}
