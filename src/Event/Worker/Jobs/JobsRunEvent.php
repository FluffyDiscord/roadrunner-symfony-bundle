<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Worker\Jobs;

use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per consumed RoadRunner Jobs task. Listen to this event to process a queue task.
 *
 * The worker decides ack/nack based on whether a listener threw:
 *  - listener returns normally  => the worker calls {@see ReceivedTaskInterface::ack()}
 *  - listener throws \Throwable => the worker calls nack(..., redelivery: true) (the job is requeued)
 *
 * A listener that wants full control may call {@see getTask()}->ack()/nack()/requeue() itself; the
 * worker then skips its own ack (it checks {@see ReceivedTaskInterface::isCompleted()}).
 */
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
     * Broker queue name.
     *
     * @return non-empty-string
     */
    public function getQueue(): string
    {
        return $this->task->getQueue();
    }

    /**
     * RoadRunner pipeline name.
     *
     * @return non-empty-string
     */
    public function getPipeline(): string
    {
        return $this->task->getPipeline();
    }

    /**
     * Job name.
     *
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
