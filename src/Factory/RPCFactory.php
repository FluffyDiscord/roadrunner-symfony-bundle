<?php

namespace FluffyDiscord\RoadRunnerBundle\Factory;

use FluffyDiscord\RoadRunnerBundle\Exception\InvalidRPCConfigurationException;
use Spiral\Goridge\RPC\RPC;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\EnvironmentInterface;

class RPCFactory
{
    public static function fromEnvironment(EnvironmentInterface $environment): RPCInterface
    {
        $rpc = $_ENV["RR_RPC"] ?? $_SERVER["RR_RPC"] ?? null;

        if ($rpc === null) {
            throw new InvalidRPCConfigurationException("Please set 'RR_RPC' .env variable and enable 'rpc' plugin in your RoadRunner Yaml configuration, eg '.rr.yaml'");
        }

        $address = $environment->getRPCAddress();
        if ($address === '') {
            throw new InvalidRPCConfigurationException("RPC address from environment is empty");
        }
        return RPC::create($address);
    }
}
