<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware\Premapping;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Migration\Premapping\AbstractPremappingReader;
use SwagMigrationAssistant\Migration\Premapping\PremappingChoiceStruct;
use SwagMigrationAssistant\Migration\Premapping\PremappingEntityStruct;
use SwagMigrationAssistant\Migration\Premapping\PremappingStruct;
use SwagMigrationAssistant\Profile\Shopware\DataSelection\ProductDataSelection;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;

class ProductManufacturerReader extends AbstractPremappingReader
{
    private const MAPPING_NAME = 'manufacturer';

    /**
     * @var EntityRepositoryInterface
     */
    private $manufacturerRepo;

    /**
     * @var string
     */
    private $connectionPremappingValue = '';

    public function __construct(EntityRepositoryInterface $manufacturerRepo)
    {
        $this->manufacturerRepo = $manufacturerRepo;
    }

    public static function getMappingName(): string
    {
        return self::MAPPING_NAME;
    }

    /**
     * @param string[] $entityGroupNames
     */
    public function supports(MigrationContextInterface $migrationContext, array $entityGroupNames): bool
    {
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface
            && in_array(ProductDataSelection::IDENTIFIER, $entityGroupNames, true);
    }

    public function getPremapping(Context $context, MigrationContextInterface $migrationContext): PremappingStruct
    {
        $this->fillConnectionPremappingValue($migrationContext);
        $mapping = $this->getMapping();
        $choices = $this->getChoices($context);

        return new PremappingStruct(self::getMappingName(), $mapping, $choices);
    }

    protected function fillConnectionPremappingValue(MigrationContextInterface $migrationContext): void
    {
        if ($migrationContext->getConnection()->getPremapping() === null) {
            return;
        }

        foreach ($migrationContext->getConnection()->getPremapping() as $premapping) {
            if ($premapping['entity'] === self::MAPPING_NAME) {
                foreach ($premapping['mapping'] as $mapping) {
                    $this->connectionPremappingValue = $mapping['destinationUuid'];
                }
            }
        }
    }

    /**
     * @return PremappingEntityStruct[]
     */
    private function getMapping(): array
    {
        $entityData[] = new PremappingEntityStruct('default_manufacturer', 'Standard manufacturer', $this->connectionPremappingValue);

        return $entityData;
    }

    /**
     * @return PremappingChoiceStruct[]
     */
    private function getChoices(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name'));
        $manufacturers = $this->manufacturerRepo->search($criteria, $context);

        $choices = [];
        /** @var ProductManufacturerEntity $manufacturer */
        foreach ($manufacturers as $manufacturer) {
            $choices[] = new PremappingChoiceStruct($manufacturer->getId(), $manufacturer->getName());
        }

        return $choices;
    }
}
