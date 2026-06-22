<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Job;

use FluffyDiscord\RoadRunnerBundle\Job\Exception\JobSerializationException;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\IgbinaryJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\NativeJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Job\Serializer\SymfonyJobSerializer;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\Address;
use FluffyDiscord\RoadRunnerBundle\Tests\Job\Fixtures\SendWelcomeEmail;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;

class SerializerTest extends BaseTestCase
{
    private function sampleMessage(): SendWelcomeEmail
    {
        return new SendWelcomeEmail(
            email: 'a@b.test',
            attempts: 3,
            tags: ['welcome', 'vip'],
            address: new Address(city: 'Prague', zip: '11000'),
        );
    }

    public function testNativeRoundTripRestoresPrivateAndNestedState(): void
    {
        $serializer = new NativeJobSerializer();
        $message = $this->sampleMessage();

        $payload = $serializer->serialize($message);
        self::assertIsString($payload);

        $restored = $serializer->deserialize($payload, SendWelcomeEmail::class);

        self::assertInstanceOf(SendWelcomeEmail::class, $restored);
        self::assertNotSame($message, $restored);
        self::assertSame('a@b.test', $restored->email);
        self::assertSame(3, $restored->getAttempts());
        self::assertSame(['welcome', 'vip'], $restored->tags);
        self::assertInstanceOf(Address::class, $restored->address);
        self::assertSame('Prague', $restored->address->city);
        self::assertSame('11000', $restored->address->getZip());
    }

    public function testNativeRejectsCorruptPayload(): void
    {
        $serializer = new NativeJobSerializer();

        $this->expectException(JobSerializationException::class);
        $serializer->deserialize('not-valid-serialized-data', SendWelcomeEmail::class);
    }

    public function testNativeRejectsWrongClass(): void
    {
        $serializer = new NativeJobSerializer();
        $payload = $serializer->serialize(new \stdClass());

        $this->expectException(JobSerializationException::class);
        $serializer->deserialize($payload, SendWelcomeEmail::class);
    }

    public function testIgbinaryRoundTripRestoresPrivateAndNestedState(): void
    {
        if (!function_exists('igbinary_serialize')) {
            self::markTestSkipped('The igbinary extension is required for this test.');
        }

        $serializer = new IgbinaryJobSerializer();
        $message = $this->sampleMessage();

        $payload = $serializer->serialize($message);
        self::assertIsString($payload);

        $restored = $serializer->deserialize($payload, SendWelcomeEmail::class);

        self::assertInstanceOf(SendWelcomeEmail::class, $restored);
        self::assertNotSame($message, $restored);
        self::assertSame('a@b.test', $restored->email);
        self::assertSame(3, $restored->getAttempts());
        self::assertSame(['welcome', 'vip'], $restored->tags);
        self::assertInstanceOf(Address::class, $restored->address);
        self::assertSame('Prague', $restored->address->city);
        self::assertSame('11000', $restored->address->getZip());
    }

    public function testIgbinaryRejectsWrongClass(): void
    {
        if (!function_exists('igbinary_serialize')) {
            self::markTestSkipped('The igbinary extension is required for this test.');
        }

        $serializer = new IgbinaryJobSerializer();
        $payload = $serializer->serialize(new \stdClass());

        $this->expectException(JobSerializationException::class);
        $serializer->deserialize($payload, SendWelcomeEmail::class);
    }

    public function testIgbinaryWithoutExtensionThrows(): void
    {
        if (function_exists('igbinary_serialize')) {
            self::markTestSkipped('The igbinary extension is installed; the missing-extension guard cannot be exercised here.');
        }

        $this->expectException(JobSerializationException::class);
        (new IgbinaryJobSerializer())->serialize($this->sampleMessage());
    }

    public function testSymfonyRoundTripRestoresPrivateAndNestedState(): void
    {
        $serializer = new SymfonyJobSerializer(new Serializer([new PropertyNormalizer()], [new JsonEncoder()]));
        $message = $this->sampleMessage();

        $payload = $serializer->serialize($message);
        self::assertJson($payload);

        $restored = $serializer->deserialize($payload, SendWelcomeEmail::class);

        self::assertInstanceOf(SendWelcomeEmail::class, $restored);
        self::assertSame('a@b.test', $restored->email);
        self::assertSame(3, $restored->getAttempts());
        self::assertSame(['welcome', 'vip'], $restored->tags);
        self::assertInstanceOf(Address::class, $restored->address);
        self::assertSame('11000', $restored->address->getZip());
    }

    public function testSymfonySerializerWithoutWrappedSerializerThrows(): void
    {
        $serializer = new SymfonyJobSerializer(null);

        $this->expectException(JobSerializationException::class);
        $serializer->serialize($this->sampleMessage());
    }

    public function testSerializerNames(): void
    {
        self::assertSame('native', (new NativeJobSerializer())->name());
        self::assertSame('igbinary', (new IgbinaryJobSerializer())->name());
        self::assertSame('symfony', (new SymfonyJobSerializer())->name());
    }

}
