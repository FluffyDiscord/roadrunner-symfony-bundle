<?php

namespace FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\InvalidArgumentException;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;

final class NonRetryableErrorInterceptor implements \Temporal\Interceptor\ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        try {
            return $next($input);
        } catch (\Error|InvalidArgumentException $failure) {
            throw new ApplicationFailure($failure->getMessage(), $failure::class, true, previous: $failure);
        }
    }
}
