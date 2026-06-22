<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\JobEnvelope;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\PlainMessage;

/**
 * Wire-contract tests for the envelope header format. See docs/specs/jobs-message-bus.md §N-2 TC-04.
 */
class JobEnvelopeTest extends BaseTestCase
{
    public function testToHeadersProducesListPerKey(): void
    {
        $envelope = new JobEnvelope(PlainMessage::class, 'native', 'payload-bytes');

        self::assertSame([
            'x-job-class' => [PlainMessage::class],
            'x-job-serializer' => ['native'],
        ], $envelope->toHeaders());
    }

    public function testFromTaskRoundTrip(): void
    {
        $original = new JobEnvelope(PlainMessage::class, 'symfony', 'the-payload');

        $restored = JobEnvelope::fromTask('the-payload', $original->toHeaders());

        self::assertInstanceOf(JobEnvelope::class, $restored);
        self::assertSame(PlainMessage::class, $restored->messageClass);
        self::assertSame('symfony', $restored->serializerName);
        self::assertSame('the-payload', $restored->payload);
    }

    public function testFromTaskReturnsNullWhenNotEnveloped(): void
    {
        self::assertNull(JobEnvelope::fromTask('payload', []));
        self::assertNull(JobEnvelope::fromTask('payload', ['unrelated' => ['x']]));
    }

    public function testFromTaskReturnsNullWhenSerializerHeaderMissing(): void
    {
        self::assertNull(JobEnvelope::fromTask('payload', ['x-job-class' => [PlainMessage::class]]));
    }

    public function testFromTaskThrowsForUnknownClass(): void
    {
        $this->expectException(JobSerializationException::class);
        JobEnvelope::fromTask('payload', [
            'x-job-class' => ['No\\Such\\Class'],
            'x-job-serializer' => ['native'],
        ]);
    }
}
