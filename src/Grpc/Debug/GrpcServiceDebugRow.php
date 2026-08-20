<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Debug;

readonly class GrpcServiceDebugRow
{
    /**
     * @param list<string> $accessAttributes
     */
    public function __construct(
        public string  $serviceName,
        public string  $interface,
        public string  $handlerClass,
        public string  $methodName,
        public string  $inputType,
        public string  $outputType,
        public array   $accessAttributes,
        public ?string $invalidReason,
    )
    {
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }
}
