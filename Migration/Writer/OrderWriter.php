<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Migration\Writer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\Struct\Serializer\StructNormalizer;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;

class OrderWriter extends AbstractWriter
{
    /**
     * @var StructNormalizer
     */
    private $structNormalizer;

    public function __construct(
        EntityWriterInterface $entityWriter,
        EntityDefinition $definition,
        StructNormalizer $structNormalizer
    ) {
        parent::__construct($entityWriter, $definition);
        $this->structNormalizer = $structNormalizer;
    }

    public function supports(): string
    {
        return DefaultEntities::ORDER;
    }

    public function writeData(array $data, Context $context): array
    {
        foreach ($data as &$item) {
            foreach ($item['transactions'] as &$transaction) {
                $transaction['amount'] = $this->structNormalizer->denormalize($transaction['amount']);
            }
            unset($transaction);
        }
        unset($item);

        return parent::writeData($data, $context);
    }
}
