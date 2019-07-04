<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Profile\Shopware55\Media;

use Shopware\Core\Content\Media\Exception\DuplicatedMediaFileNameException;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationAssistant\Exception\NoFileSystemPermissionsException;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Media\AbstractMediaFileProcessor;
use SwagMigrationAssistant\Migration\Media\MediaProcessWorkloadStruct;
use SwagMigrationAssistant\Migration\Media\SwagMigrationMediaFileEntity;
use SwagMigrationAssistant\Migration\MigrationContextInterface;
use SwagMigrationAssistant\Profile\Shopware55\DataSelection\DataSet\MediaDataSet;
use SwagMigrationAssistant\Profile\Shopware55\Gateway\Local\Shopware55LocalGateway;
use SwagMigrationAssistant\Profile\Shopware55\Logging\Shopware55LogTypes;
use SwagMigrationAssistant\Profile\Shopware55\Media\Strategy\StrategyResolverInterface;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;

class LocalMediaProcessor extends AbstractMediaFileProcessor
{
    /**
     * @var FileSaver
     */
    private $fileSaver;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaFileRepo;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    /**
     * @var StrategyResolverInterface[]
     */
    private $resolver;

    public function __construct(
        EntityRepositoryInterface $migrationMediaFileRepo,
        FileSaver $fileSaver,
        LoggingServiceInterface $loggingService,
        iterable $resolver
    ) {
        $this->mediaFileRepo = $migrationMediaFileRepo;
        $this->fileSaver = $fileSaver;
        $this->loggingService = $loggingService;
        $this->resolver = $resolver;
    }

    public function getSupportedProfileName(): string
    {
        return Shopware55Profile::PROFILE_NAME;
    }

    public function getSupportedGatewayIdentifier(): string
    {
        return Shopware55LocalGateway::GATEWAY_NAME;
    }

    public function getSupportedEntity(): string
    {
        return MediaDataSet::getEntity();
    }

    /**
     * @param MediaProcessWorkloadStruct[] $workload
     *
     * @return MediaProcessWorkloadStruct[]
     */
    public function process(MigrationContextInterface $migrationContext, Context $context, array $workload, int $fileChunkByteSize): array
    {
        $mappedWorkload = [];
        $runId = $migrationContext->getRunUuid();

        foreach ($workload as $work) {
            $mappedWorkload[$work->getMediaId()] = $work;
        }

        if (!is_dir('_temp') && !mkdir('_temp') && !is_dir('_temp')) {
            $exception = new NoFileSystemPermissionsException();
            $this->loggingService->addError($runId, (string) $exception->getCode(), '', $exception->getMessage());
            $this->loggingService->saveLogging($context);

            return $workload;
        }

        /** @var SwagMigrationMediaFileEntity[] $media */
        $media = $this->getMediaFiles(array_keys($mappedWorkload), $migrationContext->getRunUuid(), $context);
        $mappedWorkload = $this->getMediaPathMapping($media, $mappedWorkload, $migrationContext);

        return $this->copyMediaFiles($media, $mappedWorkload, $migrationContext, $context);
    }

    private function getMediaFiles(array $mediaIds, string $runId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('mediaId', $mediaIds));
        $criteria->addFilter(new EqualsFilter('runId', $runId));
        $mediaSearchResult = $this->mediaFileRepo->search($criteria, $context);

        return $mediaSearchResult->getElements();
    }

    /**
     * @param MediaProcessWorkloadStruct[] $mappedWorkload
     *
     * @return MediaProcessWorkloadStruct[]
     */
    private function getMediaPathMapping(array $media, array $mappedWorkload, MigrationContextInterface $migrationContext): array
    {
        /** @var SwagMigrationMediaFileEntity[] $media */
        foreach ($media as $mediaFile) {
            $resolver = $this->getResolver($mediaFile, $migrationContext);

            if (!$resolver) {
                $mappedWorkload[$mediaFile->getMediaId()]->setAdditionalData(['path' => $mediaFile->getUri()]);

                continue;
            }
            $path = $resolver->resolve($mediaFile->getUri(), $migrationContext);
            $mappedWorkload[$mediaFile->getMediaId()]->setAdditionalData(['path' => $path]);
        }

        return $mappedWorkload;
    }

    private function getResolver(SwagMigrationMediaFileEntity $mediaFile, MigrationContextInterface $migrationContext): ?StrategyResolverInterface
    {
        foreach ($this->resolver as $resolver) {
            if ($resolver->supports($mediaFile->getUri(), $migrationContext)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * @param MediaProcessWorkloadStruct[] $mappedWorkload
     *
     * @return MediaProcessWorkloadStruct[]
     */
    private function copyMediaFiles(
        array $media,
        array $mappedWorkload,
        MigrationContextInterface $migrationContext,
        Context $context
    ): array {
        $processedMedia = [];

        /** @var SwagMigrationMediaFileEntity[] $media */
        foreach ($media as $mediaFile) {
            $sourcePath = $mappedWorkload[$mediaFile->getMediaId()]->getAdditionalData()['path'];

            if (!file_exists($sourcePath)) {
                $resolver = $this->getResolver($mediaFile, $migrationContext);

                if ($resolver === null) {
                    $mappedWorkload[$mediaFile->getMediaId()]->setState(MediaProcessWorkloadStruct::ERROR_STATE);
                    $this->loggingService->addError(
                        $mappedWorkload[$mediaFile->getMediaId()]->getRunId(),
                        Shopware55LogTypes::SOURCE_FILE_NOT_FOUND,
                        '',
                        'File not found in source system.',
                        [
                            'path' => $sourcePath,
                        ]
                    );
                    $processedMedia[] = $mediaFile->getMediaId();

                    continue;
                }
            }

            $fileExtension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $filePath = sprintf('_temp/%s.%s', $mediaFile->getId(), $fileExtension);

            if (copy($sourcePath, $filePath)) {
                $fileSize = filesize($filePath);
                $mappedWorkload[$mediaFile->getMediaId()]->setState(MediaProcessWorkloadStruct::FINISH_STATE);
                $this->persistFileToMedia($filePath, $mediaFile, $fileSize, $fileExtension, $context);
                unlink($filePath);
            } else {
                $mappedWorkload[$mediaFile->getMediaId()]->setState(MediaProcessWorkloadStruct::ERROR_STATE);
                $this->loggingService->addError(
                    $mappedWorkload[$mediaFile->getMediaId()]->getRunId(),
                    Shopware55LogTypes::CANNOT_COPY_MEDIA,
                    '',
                    'Cannot copy media.',
                    [
                        'path' => $sourcePath,
                    ]
                );
            }
            $processedMedia[] = $mediaFile->getMediaId();
        }

        $this->setProcessedFlag($migrationContext->getRunUuid(), $context, $processedMedia);
        $this->loggingService->saveLogging($context);

        return array_values($mappedWorkload);
    }

    private function persistFileToMedia(
        string $filePath,
        SwagMigrationMediaFileEntity $media,
        int $fileSize,
        string $fileExtension,
        Context $context
    ): void {
        $mimeType = mime_content_type($filePath);
        $mediaFile = new MediaFile($filePath, $mimeType, $fileExtension, $fileSize);

        try {
            $this->fileSaver->persistFileToMedia($mediaFile, $media->getFileName(), $media->getMediaId(), $context);
        } catch (DuplicatedMediaFileNameException $e) {
            $this->fileSaver->persistFileToMedia($mediaFile, $media->getFileName() . substr(Uuid::randomHex(), 0, 5), $media->getMediaId(), $context);
        }
    }

    private function setProcessedFlag(string $runId, Context $context, array $finishedUuids): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('mediaId', $finishedUuids));
        $criteria->addFilter(new EqualsFilter('runId', $runId));
        $mediaFiles = $this->mediaFileRepo->search($criteria, $context);

        $updateableMediaEntities = [];
        foreach ($mediaFiles->getElements() as $mediaFile) {
            /* @var SwagMigrationMediaFileEntity $mediaFile */
            $updateableMediaEntities[] = [
                'id' => $mediaFile->getId(),
                'processed' => true,
            ];
        }

        if (empty($updateableMediaEntities)) {
            return;
        }

        $this->mediaFileRepo->update($updateableMediaEntities, $context);
    }
}
