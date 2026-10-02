<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Temporal;

use FluffyDiscord\RoadRunnerBundle\Temporal\Interceptor\NonRetryableErrorInterceptor;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\InvalidArgumentException;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\Header;

class NonRetryableErrorInterceptorTest extends BaseTestCase
{
    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function nonRetryableFailures(): iterable
    {
        yield 'TypeError' => [new \TypeError('Argument #1 must be of type int')];
        yield 'ValueError' => [new \ValueError('"x" is not a valid backing value')];
        yield 'SDK-wrapped TypeError' => [new InvalidArgumentException('Argument #1 must be of type int', previous: new \TypeError('Argument #1 must be of type int'))];
    }

    #[DataProvider('nonRetryableFailures')]
    public function testFailureBecomesNonRetryable(\Throwable $failure): void
    {
        $thrown = $this->handle(static fn (): never => throw $failure);

        self::assertInstanceOf(ApplicationFailure::class, $thrown);
        self::assertTrue($thrown->isNonRetryable());
        self::assertSame($failure::class, $thrown->getType());
        self::assertSame($failure->getMessage(), $thrown->getOriginalMessage());
        self::assertSame($failure, $thrown->getPrevious());
    }

    public function testExceptionStaysRetryable(): void
    {
        $failure = new \RuntimeException('SMTP down');

        self::assertSame($failure, $this->handle(static fn (): never => throw $failure));
    }

    public function testResultPassesThrough(): void
    {
        $result = (new NonRetryableErrorInterceptor())->handleActivityInbound(
            new ActivityInput(EncodedValues::empty(), Header::empty()),
            static fn (): string => 'done',
        );

        self::assertSame('done', $result);
    }

    private function handle(\Closure $activity): ?\Throwable
    {
        try {
            (new NonRetryableErrorInterceptor())->handleActivityInbound(new ActivityInput(EncodedValues::empty(), Header::empty()), $activity);
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
