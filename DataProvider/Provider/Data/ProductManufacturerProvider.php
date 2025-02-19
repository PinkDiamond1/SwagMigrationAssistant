<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\DataProvider\Provider\Data;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;

class ProductManufacturerProvider extends AbstractProvider
{
    /**
     * @var EntityRepositoryInterface
     */
    private $manufacturerRepo;

    public function __construct(EntityRepositoryInterface $manufacturerRepo)
    {
        $this->manufacturerRepo = $manufacturerRepo;
    }

    public function getIdentifier(): string
    {
        return DefaultEntities::PRODUCT_MANUFACTURER;
    }

    public function getProvidedData(int $limit, int $offset, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('media.translations');
        $criteria->addAssociation('media.tags');
        $criteria->addSorting(new FieldSorting('id'));
        $result = $this->manufacturerRepo->search($criteria, $context);

        return $this->cleanupSearchResult(
            $result,
            [
                'mimeType',
                'fileExtension',
                'mediaTypeRaw',
                'metaData',
                'mediaType',
                'mediaId',
                'thumbnails',
                'thumbnailsRo',
                'hasFile',
                'userId', // maybe put back in, if we migrate users
            ]
        );
    }

    public function getProvidedTotal(Context $context): int
    {
        return $this->readTotalFromRepo($this->manufacturerRepo, $context);
    }
}
