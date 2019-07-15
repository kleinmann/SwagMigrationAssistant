<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Logging\Log;

use SwagMigrationAssistant\Migration\Logging\LogType;

class EmptyNecessaryFieldRunLog extends BaseRunLogEntry
{
    /**
     * @var string
     */
    private $emptyField;

    public function __construct(string $runId, string $entity, string $sourceId, string $emptyField)
    {
        parent::__construct($runId, $entity, $sourceId);
        $this->emptyField = $emptyField;
    }

    public function getCode(): string
    {
        return sprintf('SWAG_MIGRATION_EMPTY_NECESSARY_FIELD_%s', strtoupper($this->getEntity()));
    }

    public function getLevel(): string
    {
        return self::LOG_LEVEL_WARNING;
    }

    public function getTitle(): string
    {
        return sprintf('The %s entity has an empty necessary field', $this->getEntity());
    }

    public function getDescriptionArguments(): array
    {
        return [
            'entity' => $this->getEntity(),
            'sourceId' => $this->getSourceId(),
            'emptyField' => $this->emptyField,
        ];
    }

    public function getDescription(): string
    {
        $args = $this->getDescriptionArguments();

        return sprintf(
            'The %s entity with the source id %s has not the necessary data for the field %s',
                $args['entity'],
                $args['sourceId'],
                $args['emptyField']
            );
    }

    public function getTitleSnippet(): string
    {
        return sprintf('%s.%s.title', $this->getSnippetRoot(), LogType::EMPTY_NECESSARY_DATA_FIELDS);
    }

    public function getDescriptionSnippet(): string
    {
        return sprintf('%s.%s.description', $this->getSnippetRoot(), LogType::EMPTY_NECESSARY_DATA_FIELDS);
    }
}
