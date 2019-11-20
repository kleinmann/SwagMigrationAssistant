<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware\DataSelection\DataSet;

use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\ShopwareProfileInterface;

class NewsletterRecipientDataSet extends ShopwareDataSet
{
    public static function getEntity(): string
    {
        return DefaultEntities::NEWSLETTER_RECIPIENT;
    }

    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile() instanceof ShopwareProfileInterface;
    }

    public function getApiRoute(): string
    {
        return 'SwagMigrationNewsletterRecipients';
    }

    public function getExtraQueryParameters(): array
    {
        return [];
    }
}
