<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware\Converter;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Util\AccessKeyHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Mapping\MappingServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\Logging\LogTypes;

abstract class SalesChannelConverter extends ShopwareConverter
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $paymentRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $shippingMethodRepo;
    /**
     * @var MappingServiceInterface
     */
    protected $mappingService;

    /**
     * @var LoggingServiceInterface
     */
    protected $loggingService;

    /**
     * @var string
     */
    protected $mainLocale;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var string
     */
    protected $connectionId;

    public function __construct(
        MappingServiceInterface $mappingService,
        LoggingServiceInterface $loggingService,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $shippingMethodRepo,
        EntityRepositoryInterface $countryRepo
    ) {
        $this->mappingService = $mappingService;
        $this->loggingService = $loggingService;
        $this->paymentRepository = $paymentRepository;
        $this->shippingMethodRepo = $shippingMethodRepo;
        $this->countryRepository = $countryRepo;
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    public function convert(array $data, Context $context, MigrationContextInterface $migrationContext): ConvertStruct
    {
        $this->context = $context;
        $this->mainLocale = $data['_locale'];
        $this->connectionId = $migrationContext->getConnection()->getId();

        $converted = [];
        $converted['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::SALES_CHANNEL,
            $data['id'],
            $context
        );

        $customerGroupUuid = $this->mappingService->getUuid(
            $this->connectionId,
            DefaultEntities::CUSTOMER_GROUP,
            $data['customer_group_id'],
            $context
        );

        if ($customerGroupUuid === null) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                LogTypes::ASSOCIATION_REQUIRED_MISSING,
                'Associated customer group not found',
                'Customer group for the sales channel can not be found.',
                [
                    'data' => $data,
                    'missingEntity' => 'customer_group',
                    'requiredFor' => 'sales_channel',
                    'missingImportEntity' => 'customer_group',
                ]
            );

            return new ConvertStruct(null, $data);
        }

        $converted['customerGroupId'] = $customerGroupUuid;

        $languageUuid = $this->mappingService->getLanguageUuid(
            $this->connectionId,
            $data['locale'],
            $context
        );

        if ($languageUuid === null) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                LogTypes::ASSOCIATION_REQUIRED_MISSING,
                'Associated language not found',
                'Language for the sales channel can not be found.',
                [
                    'data' => $data,
                    'missingEntity' => 'language',
                    'requiredFor' => 'sales_channel',
                    'missingImportEntity' => 'language',
                ]
            );

            return new ConvertStruct(null, $data);
        }

        $converted['languageId'] = $languageUuid;
        $converted['languages'] = $this->getSalesChannelLanguages($languageUuid, $data, $context);

        $currencyUuid = $this->mappingService->getCurrencyUuid(
            $this->connectionId,
            $data['currency'],
            $context
        );

        if ($currencyUuid === null) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                LogTypes::ASSOCIATION_REQUIRED_MISSING,
                'Associated currency not found',
                'Currency for the sales channel can not be found.',
                [
                    'data' => $data,
                    'missingEntity' => 'currency',
                    'requiredFor' => 'sales_channel',
                    'missingImportEntity' => 'currency',
                ]
            );

            return new ConvertStruct(null, $data);
        }

        $converted['currencyId'] = $currencyUuid;
        $converted['currencies'] = [
            [
                'id' => $currencyUuid,
            ],
        ];

        $categoryUuid = $this->mappingService->getUuid(
            $this->connectionId,
            DefaultEntities::CATEGORY,
            $data['category_id'],
            $context
        );

        if ($categoryUuid === null) {
            $this->loggingService->addWarning(
                $migrationContext->getRunUuid(),
                LogTypes::ASSOCIATION_REQUIRED_MISSING,
                'Associated category not found',
                'Navigation category root for the sales channel can not be found.',
                [
                    'data' => $data,
                    'missingEntity' => 'category',
                    'requiredFor' => 'sales_channel',
                    'missingImportEntity' => 'category',
                ]
            );

            return new ConvertStruct(null, $data);
        }
        $converted['navigationCategoryId'] = $categoryUuid;

        $countryUuid = $this->getFirstActiveCountryId();
        $converted['countryId'] = $countryUuid;
        $converted['countries'] = [
            [
                'id' => $countryUuid,
            ],
        ];

        $paymentMethodUuid = $this->getFirstActivePaymentMethodId();
        $converted['paymentMethodId'] = $paymentMethodUuid;
        $converted['paymentMethods'] = [
            [
                'id' => $paymentMethodUuid,
            ],
        ];

        $shippingMethodUuid = $this->getFirstActiveShippingMethodId();
        $converted['shippingMethodId'] = $shippingMethodUuid;
        $converted['shippingMethods'] = [
            [
                'id' => $shippingMethodUuid,
            ],
        ];

        $converted['typeId'] = Defaults::SALES_CHANNEL_TYPE_STOREFRONT;
        $this->getSalesChannelTranslation($converted, $data);
        $this->convertValue($converted, 'name', $data, 'name');
        $converted['accessKey'] = AccessKeyHelper::generateAccessKey('sales-channel');

        unset(
            $data['id'],
            $data['main_id'],
            $data['title'],
            $data['position'],
            $data['host'],
            $data['base_path'],
            $data['base_url'],
            $data['hosts'],
            $data['secure'],
            $data['template_id'],
            $data['document_template_id'],
            $data['category_id'],
            $data['locale_id'],
            $data['currency_id'],
            $data['customer_group_id'],
            $data['fallback_id'],
            $data['customer_scope'],
            $data['default'],
            $data['active'],
            $data['locale'],
            $data['currency'],
            $data['_locale'],
            $data['children']
        );

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    protected function getSalesChannelTranslation(array &$salesChannel, array $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->mainLocale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'name', $data, 'name');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            DefaultEntities::SALES_CHANNEL_TRANSLATION,
            $data['id'] . ':' . $this->mainLocale,
            $this->context
        );
        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->mainLocale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $salesChannel['translations'][$languageUuid] = $localeTranslation;
    }

    protected function getFirstActiveShippingMethodId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true));

        return $this->shippingMethodRepo->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    protected function getFirstActivePaymentMethodId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('position'));

        return $this->paymentRepository->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    protected function getFirstActiveCountryId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('position'));

        return $this->countryRepository->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    protected function getSalesChannelLanguages(string $languageUuid, array $data, Context $context): array
    {
        $languages[] = [
            'id' => $languageUuid,
        ];

        if (isset($data['children'])) {
            foreach ($data['children'] as $subShop) {
                $uuid = $this->mappingService->getLanguageUuid(
                    $this->connectionId,
                    $subShop['locale'],
                    $context
                );

                if ($uuid === null) {
                    continue;
                }

                $languages[] = [
                    'id' => $uuid,
                ];
            }
        }

        return $languages;
    }
}
