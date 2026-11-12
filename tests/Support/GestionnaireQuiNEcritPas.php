<?php

declare(strict_types=1);

namespace Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use RuntimeException;

final class GestionnaireQuiNEcritPas extends AbstractProcessingHandler
{
    #[\Override]
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('Le disque est plein.');
    }
}
