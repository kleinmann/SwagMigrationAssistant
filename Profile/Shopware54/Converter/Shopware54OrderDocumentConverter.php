<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware54\Converter;

use Shopware\Core\Framework\Context;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Converter\OrderDocumentConverter;
use SwagMigrationAssistant\Profile\Shopware\DataSelection\DataSet\OrderDocumentDataSet;
use SwagMigrationAssistant\Profile\Shopware54\Shopware54Profile;

class Shopware54OrderDocumentConverter extends OrderDocumentConverter
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Shopware54Profile::PROFILE_NAME
            && $migrationContext->getDataSet()::getEntity() === OrderDocumentDataSet::getEntity();
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        $data['id'] = $data['ID'];

        return parent::convert($data, $context, $migrationContext);
    }

    protected function getDocumentType(array $data): ?array
    {
        switch ($data['id']) {
            case '1':
                $key = 'invoice';
                break;
            case '2':
                $key = 'delivery_note';
                break;
            case '3':
                $key = 'credit_note';
                break;
            case '4':
                $key = 'storno';
                break;
            default:
                return null;
        }

        $data['key'] = $key;

        return parent::getDocumentType($data);
    }
}
