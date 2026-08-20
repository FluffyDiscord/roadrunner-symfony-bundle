<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc;

use Spiral\RoadRunner\GRPC\ContextInterface;

readonly class GrpcMetadata
{
    /** @var array<string, list<string>> */
    private array $values;

    /**
     * @param array<string, list<string>> $values
     */
    public function __construct(array $values)
    {
        $this->values = self::lowerCaseKeys($values);
    }

    public static function fromContext(ContextInterface $context): self
    {
        $metadata = $context->getValue(self::class);

        if (!$metadata instanceof self) {
            throw new \LogicException('No GrpcMetadata in this context: it is only available inside a call served by the RoadRunner bundle gRPC worker');
        }

        return $metadata;
    }

    public function getFirst(string $key): ?string
    {
        $values = $this->getAll($key);

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getAll(string $key): array
    {
        return $this->values[strtolower($key)] ?? [];
    }

    public function has(string $key): bool
    {
        return array_key_exists(strtolower($key), $this->values);
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function getBearerToken(): ?string
    {
        $authorization = $this->getFirst('authorization');

        if ($authorization === null) {
            return null;
        }

        $hasBearerPrefix = stripos($authorization, 'bearer ') === 0;

        if (!$hasBearerPrefix) {
            return null;
        }

        $token = trim(substr($authorization, strlen('bearer ')));

        return $token === '' ? null : $token;
    }

    /**
     * @param array<string, list<string>> $values
     * @return array<string, list<string>>
     */
    private static function lowerCaseKeys(array $values): array
    {
        $lowerCased = [];

        foreach ($values as $key => $keyValues) {
            $lowerKey = strtolower($key);
            $existing = $lowerCased[$lowerKey] ?? [];
            $lowerCased[$lowerKey] = array_merge($existing, $keyValues);
        }

        return $lowerCased;
    }
}
