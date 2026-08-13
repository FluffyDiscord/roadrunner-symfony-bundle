<?php

namespace FluffyDiscord\RoadRunnerBundle\Warmup;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Hydrates all Doctrine metadata and, for ORM managers, builds every entity
 * persister — both otherwise constructed lazily on each worker's first queries.
 * See docs/specs/worker-warmup.md §3.
 */
readonly class DoctrineWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?ManagerRegistry $managerRegistry = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->managerRegistry === null) {
            return;
        }

        foreach ($this->managerRegistry->getManagers() as $manager) {
            $allMetadata = $manager->getMetadataFactory()->getAllMetadata();

            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }

            $unitOfWork = $manager->getUnitOfWork();

            foreach ($allMetadata as $metadata) {
                if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                    continue;
                }

                $unitOfWork->getEntityPersister($metadata->getName());
            }
        }
    }
}
