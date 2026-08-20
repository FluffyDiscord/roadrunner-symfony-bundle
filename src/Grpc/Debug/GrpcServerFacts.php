<?php

namespace FluffyDiscord\RoadRunnerBundle\Grpc\Debug;

readonly class GrpcServerFacts
{
    /**
     * @param list<string> $protoFiles
     */
    public function __construct(
        public bool    $isConfigured,
        public ?string $listen,
        public bool    $tlsEnabled,
        public ?string $clientAuthType,
        public array   $protoFiles,
    )
    {
    }

    /**
     * @param array<string, mixed>|null $grpcSection
     */
    public static function fromConfigSection(?array $grpcSection): self
    {
        if ($grpcSection === null) {
            return new self(false, null, false, null, []);
        }

        $listen = $grpcSection['listen'] ?? null;
        $tls = $grpcSection['tls'] ?? [];
        $certificate = is_array($tls) ? ($tls['cert'] ?? null) : null;
        $clientAuthType = is_array($tls) ? ($tls['client_auth_type'] ?? null) : null;
        $tlsEnabled = is_string($certificate) && $certificate !== '';

        return new self(
            true,
            is_string($listen) ? $listen : null,
            $tlsEnabled,
            is_string($clientAuthType) ? $clientAuthType : null,
            self::readProtoFiles($grpcSection['proto'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function readProtoFiles(mixed $proto): array
    {
        if (is_string($proto)) {
            return [$proto];
        }

        if (!is_array($proto)) {
            return [];
        }

        $files = [];

        foreach ($proto as $file) {
            if (is_string($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
