<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Contracts\EventDispatcher\Event;

class JobsRunEvent extends Event
{
    public function __construct(
        private readonly ReceivedTaskInterface $task,
    )
    {
    }

    public function getTask(): ReceivedTaskInterface
    {
        return $this->task;
    }

    /**
     * @return non-empty-string
     */
    public function getQueue(): string
    {
        return $this->task->getQueue();
    }

    /**
     * @return non-empty-string
     */
    public function getPipeline(): string
    {
        return $this->task->getPipeline();
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return $this->task->getName();
    }

    /**
     * @return non-empty-string
     */
    public function getId(): string
    {
        return $this->task->getId();
    }

    public function getPayload(): string
    {
        return $this->task->getPayload();
    }

    /**
     * @return array<non-empty-string, array<string>>
     */
    public function getHeaders(): array
    {
        return $this->task->getHeaders();
    }
}
