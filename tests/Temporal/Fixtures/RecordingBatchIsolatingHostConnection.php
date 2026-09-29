<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal\Fixtures;

use FluffyDiscord\RoadRunnerBundle\Temporal\Transport\BatchIsolatingHostConnection;

class RecordingBatchIsolatingHostConnection extends BatchIsolatingHostConnection
{
    public int $handedBatchCount = 0;

    protected function handBatchToFreshWorker(): void
    {
        ++$this->handedBatchCount;
    }
}
