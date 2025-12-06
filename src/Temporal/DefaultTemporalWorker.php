<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal;

use Psr\Log\LoggerInterface;
use Temporal\Exception\ExceptionInterceptorInterface;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;

class DefaultTemporalWorker implements TemporalWorkerInterface
{
    public function __construct(
        private readonly ?array                        $workerOptions = null,
        private readonly ?LoggerInterface              $logger = null,
        private readonly ExceptionInterceptorInterface $exceptionInterceptor,
        private readonly SimplePipelineProvider        $simplePipelineProvider,
    )
    {
    }

    public function create(WorkerFactoryInterface $workerFactory): WorkerInterface
    {
        $options = WorkerOptions::new();
        foreach ($this->workerOptions ?? [] as $key => $value) {
            $options->{$key} = $value;
        }

        \Symfony\Component\VarDumper\VarDumper::dump($this->simplePipelineProvider);
        return $workerFactory->newWorker(
            options: $options,
            exceptionInterceptor: $this->exceptionInterceptor,
            interceptorProvider: $this->simplePipelineProvider,
            logger: $this->logger,
        );
    }
}