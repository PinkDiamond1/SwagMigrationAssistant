<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Converter;

use SwagMigrationAssistant\Exception\ConverterNotFoundException;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class ConverterRegistry implements ConverterRegistryInterface
{
    /**
     * @var ConverterInterface[]
     */
    private $converters;

    public function __construct(iterable $converters)
    {
        $this->converters = $converters;
    }

    /**
     * @throws ConverterNotFoundException
     */
    public function getConverter(MigrationContextInterface $migrationContext): ConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($migrationContext)) {
                return $converter;
            }
        }

        throw new ConverterNotFoundException($migrationContext->getConnection()->getProfileName());
    }
}
