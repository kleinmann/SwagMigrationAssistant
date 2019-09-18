<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware\Converter;

use Shopware\Core\Framework\Context;
use SwagMigrationAssistant\Migration\Converter\ConvertStruct;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Mapping\MappingServiceInterface;
use SwagMigrationAssistant\Migration\Media\MediaFileServiceInterface;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware\DataSelection\DataSet\MediaDataSet;

abstract class MediaConverter extends ShopwareConverter
{
    /**
     * @var MappingServiceInterface
     */
    protected $mappingService;

    /**
     * @var MediaFileServiceInterface
     */
    protected $mediaFileService;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var string
     */
    protected $locale;

    /**
     * @var string
     */
    protected $connectionId;

    public function __construct(
        MappingServiceInterface $mappingService,
        MediaFileServiceInterface $mediaFileService
    ) {
        $this->mappingService = $mappingService;
        $this->mediaFileService = $mediaFileService;
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    public function convert(
        array $data,
        Context $context,
        MigrationContextInterface $migrationContext
    ): ConvertStruct {
        $checksum = $this->generateChecksum($data);
        $this->context = $context;
        $this->locale = $data['_locale'];
        unset($data['_locale']);
        $this->connectionId = $migrationContext->getConnection()->getId();

        $converted = [];
        $this->mapping = $this->mappingService->getOrCreateMapping(
            $this->connectionId,
            DefaultEntities::MEDIA,
            $data['id'],
            $context,
            $checksum
        );
        $this->mapping['checksum'] = $checksum;
        $converted['id'] = $this->mapping['entityUuid'];

        if (!isset($data['name'])) {
            $data['name'] = $converted['id'];
        }

        $this->mediaFileService->saveMediaFile(
            [
                'runId' => $migrationContext->getRunUuid(),
                'entity' => MediaDataSet::getEntity(),
                'uri' => $data['uri'] ?? $data['path'],
                'fileName' => $data['name'],
                'fileSize' => (int) $data['file_size'],
                'mediaId' => $converted['id'],
            ]
        );
        unset($data['uri'], $data['file_size']);

        $this->getMediaTranslation($converted, $data);
        $this->convertValue($converted, 'title', $data, 'name');
        $this->convertValue($converted, 'alt', $data, 'description');

        $albumMapping = $this->mappingService->getMapping(
            $this->connectionId,
            DefaultEntities::MEDIA_FOLDER,
            $data['albumID'],
            $this->context
        );

        if ($albumMapping !== null) {
            $converted['mediaFolderId'] = $albumMapping['entityUuid'];
            $this->mappingIds[] = $albumMapping['id'];
        }

        unset(
            $data['id'],
            $data['albumID'],

            // Legacy data which don't need a mapping or there is no equivalent field
            $data['path'],
            $data['type'],
            $data['extension'],
            $data['file_size'],
            $data['width'],
            $data['height'],
            $data['userID'],
            $data['created']
        );

        if (empty($data)) {
            $data = null;
        }

        $this->mapping['additionalData']['relatedMappings'] = $this->mappingIds;
        $this->mappingIds = [];
        $this->mappingService->updateMapping(
            $this->connectionId,
            DefaultEntities::MEDIA,
            $this->mapping['oldIdentifier'],
            $this->mapping,
            $context
        );

        return new ConvertStruct($converted, $data, $this->mapping['id']);
    }

    protected function getMediaTranslation(array &$media, array $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'title', $data, 'name');
        $this->convertValue($localeTranslation, 'alt', $data, 'description');

        $mapping = $this->mappingService->getOrCreateMapping(
            $this->connectionId,
            DefaultEntities::MEDIA_TRANSLATION,
            $data['id'] . ':' . $this->locale,
            $this->context
        );
        $localeTranslation['id'] = $mapping['entityUuid'];
        $this->mappingIds[] = $mapping['id'];

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $media['translations'][$languageUuid] = $localeTranslation;
    }
}
