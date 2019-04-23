<?php declare(strict_types=1);

namespace SwagMigrationNext\Profile\Shopware55\Converter;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\NumberRange\Aggregate\NumberRangeSalesChannel\NumberRangeSalesChannelDefinition;
use Shopware\Core\System\NumberRange\Aggregate\NumberRangeTranslation\NumberRangeTranslationDefinition;
use Shopware\Core\System\NumberRange\NumberRangeDefinition;
use Shopware\Core\System\NumberRange\NumberRangeEntity;
use SwagMigrationNext\Migration\Converter\ConvertStruct;
use SwagMigrationNext\Migration\Logging\LoggingServiceInterface;
use SwagMigrationNext\Migration\Mapping\MappingServiceInterface;
use SwagMigrationNext\Migration\MigrationContextInterface;
use SwagMigrationNext\Profile\Shopware55\Logging\Shopware55LogTypes;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;

class NumberRangeConverter extends Shopware55Converter
{
    /**
     * @var array
     */
    private const TYPE_MAPPING = [
        'user' => 'customer',
        'invoice' => 'order',
        'articleordernumber' => 'product',
        'doc_0' => 'document_inovice',
        'doc_1' => 'document_delivery_note',
        'doc_2' => 'document_credit_note',
    ];
    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    /**
     * @var EntityRepositoryInterface
     */
    private $numberRangeTypeRepo;

    /**
     * @var EntityCollection
     */
    private $numberRangeTypes;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    public function __construct(
        MappingServiceInterface $mappingService,
        EntityRepositoryInterface $numberRangeTypeRepo,
        LoggingServiceInterface $loggingService
    ) {
        $this->mappingService = $mappingService;
        $this->numberRangeTypeRepo = $numberRangeTypeRepo;
        $this->loggingService = $loggingService;
    }

    public function getSupportedEntityName(): string
    {
        return NumberRangeDefinition::getEntityName();
    }

    public function getSupportedProfileName(): string
    {
        return Shopware55Profile::PROFILE_NAME;
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        if (empty($this->numberRangeTypes)) {
            $this->numberRangeTypes = $this->numberRangeTypeRepo->search(new Criteria(), $context)->getEntities();
        }

        if (!array_key_exists($data['name'], self::TYPE_MAPPING)) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Unsupported number range type',
                sprintf('NumberRange-Entity could not converted because of unsupported type: %s.', $data['name']),
                [
                    'id' => $data['id'],
                    'entity' => 'NumberRange',
                ],
                1
            );

            return new ConvertStruct(null, $data);
        }

        $converted['id'] = $this->getUuid($data, $migrationContext, $context);
        $converted['typeId'] = $this->getProductNumberRangeTypeUuid($data['name']);

        if (empty($converted['typeId'])) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data',
                sprintf('NumberRange-Entity could not converted cause of empty necessary field(s): %s.', implode(', ', ['typeId'])),
                [
                    'id' => $data['id'],
                    'entity' => 'NumberRange',
                    'fields' => ['typeId'],
                ],
                1
            );

            return new ConvertStruct(null, $data);
        }

        $converted['global'] = $this->getGlobal($data['name']);

        // only write name and description when not overriding global number range
        if ($converted['global'] === false) {
            $this->setNumberRangeTranslation($converted, $data, $migrationContext, $context);
            $this->convertValue($converted, 'name', $data, 'name', self::TYPE_STRING);
            $this->convertValue($converted, 'description', $data, 'desc', self::TYPE_STRING);

            $this->setNumberRangeSalesChannels($converted, $migrationContext, $context);
        }

        $converted['pattern'] = $data['prefix'] . '{n}';
        $converted['start'] = (int) $data['number'];
        // increment start value by 1 because of different handling in platform
        ++$converted['start'];

        unset(
            $data['id'],
            $data['prefix'],
            $data['number'],
            $data['_locale'],
            $data['name'],
            $data['desc']
        );

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    private function getUuid(array $data, MigrationContextInterface $migrationContext, Context $context): string
    {
        $id = $this->mappingService->getUuid(
            $migrationContext->getConnection()->getId(),
            NumberRangeDefinition::getEntityName(),
            $data['id'],
            $context
        );

        if ($id !== null) {
            return $id;
        }

        // use global number range uuid for products if available
        if ($data['name'] === 'articleordernumber') {
            $id = $this->mappingService->getNumberRangeUuid('product', $data['id'], $migrationContext, $context);
        }

        if ($id === null) {
            $id = $this->mappingService->createNewUuid(
                $migrationContext->getConnection()->getId(),
                NumberRangeDefinition::getEntityName(),
                $data['id'],
                $context
            );
        }

        return $id;
    }

    private function getProductNumberRangeTypeUuid(string $type): ?string
    {
        $collection = $this->numberRangeTypes->filterByProperty('technicalName', self::TYPE_MAPPING[$type]);

        if (empty($collection->first())) {
            return null;
        }
        /** @var NumberRangeEntity $numberRange */
        $numberRange = $collection->first();

        return $numberRange->getId();
    }

    private function getGlobal(string $name): bool
    {
        return $name === 'articleordernumber' ?? false;
    }

    private function setNumberRangeTranslation(
        array &$converted,
        array $data,
        MigrationContextInterface $migrationContext,
        Context $context
    ): void {
        $languageData = $this->mappingService->getDefaultLanguageUuid($context);
        if ($languageData['createData']['localeCode'] === $data['_locale']) {
            return;
        }

        $connectionId = $migrationContext->getConnection()->getId();

        $localeTranslation = [];
        $localeTranslation['number_range_id'] = $converted['id'];
        $localeTranslation['name'] = (string) $data['desc'];

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $connectionId,
            NumberRangeTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $data['_locale'],
            $context
        );

        $languageData = $this->mappingService->getLanguageUuid($connectionId, $data['_locale'], $context);
        if (isset($languageData['createData']) && !empty($languageData['createData'])) {
            $localeTranslation['language']['id'] = $languageData['uuid'];
            $localeTranslation['language']['localeId'] = $languageData['createData']['localeId'];
            $localeTranslation['language']['name'] = $languageData['createData']['localeCode'];
        } else {
            $localeTranslation['languageId'] = $languageData['uuid'];
        }

        $converted['translations'][$languageData['uuid']] = $localeTranslation;
    }

    private function setNumberRangeSalesChannels(array &$converted, MigrationContextInterface $migrationContext, Context $context): void
    {
        $connectionId = $migrationContext->getConnection()->getId();
        $saleschannelIds = $this->mappingService->getMigratedSalesChannelUuids($connectionId, $context);

        $numberRangeSaleschannels = [];

        foreach ($saleschannelIds as $saleschannelId) {
            $numberRangeSaleschannel = [];
            $numberRangeSaleschannel['id'] = $this->mappingService->createNewUuid(
                $connectionId,
                NumberRangeSalesChannelDefinition::getEntityName(),
                $converted['id'] . ':' . $saleschannelId,
                $context
            );
            $numberRangeSaleschannel['numberRangeId'] = $converted['id'];
            $numberRangeSaleschannel['salesChannelId'] = $saleschannelId;
            $numberRangeSaleschannel['numberRangeTypeId'] = $converted['typeId'];
            $numberRangeSaleschannels[] = $numberRangeSaleschannel;
        }

        $converted['numberRangeSalesChannels'] = $numberRangeSaleschannels;
    }
}
