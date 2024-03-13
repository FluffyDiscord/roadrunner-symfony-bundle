<?php

namespace FluffyDiscord\RoadRunnerBundle\Cache;

use FluffyDiscord\RoadRunnerBundle\Exception\SodiumKeypairException;
use FluffyDiscord\RoadRunnerBundle\Exception\SodiumNotEnabledException;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\KeyValue\Factory;
use Spiral\RoadRunner\KeyValue\Serializer\DefaultSerializer;
use Spiral\RoadRunner\KeyValue\Serializer\IgbinarySerializer;
use Spiral\RoadRunner\KeyValue\Serializer\SodiumSerializer;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

class KVCacheAdapter extends Psr16Adapter
{
    public static function create(
        string       $namespace,
        RPCInterface $rpc,
        string       $name,
        string       $projectDir,
        ?string      $serializerClass,
        ?string      $keypairPath,
    ): self
    {
        $serializer = null;
        if ($serializerClass === null && function_exists("igbinary_serialize")) {
            $serializer = new IgbinarySerializer();
        }

        if ($serializerClass !== null) {
            if (!class_exists($serializerClass)) {
                throw new \LogicException(sprintf('Serializer class "%s" does not exist', $serializerClass));
            }

            $serializer = new $serializerClass();
        }

        $factory = new Factory(
            $rpc,
            $serializer ?? new DefaultSerializer(),
        );

        if ($keypairPath !== null) {
            $keypairPath = "{$projectDir}/{$keypairPath}";
            if (!file_exists($keypairPath)) {
                throw new SodiumKeypairException(sprintf('Unable to find keypair at: %s', $keypairPath));
            }

            $keypairContent = @file_get_contents($keypairPath);
            if ($keypairContent === false) {
                throw new SodiumKeypairException("Unable to read keypair content. Check it's permissions.");
            }

            try {
                $sodiumSerializer = new SodiumSerializer(
                    $factory->getSerializer(),
                    $keypairContent,
                );
            } catch (\LogicException $logicException) {
                throw new SodiumNotEnabledException($logicException->getMessage(), previous: $logicException);
            }

            $factory = $factory->withSerializer($sodiumSerializer);
        }

        return new self($factory->select($name), $namespace);
    }
}
