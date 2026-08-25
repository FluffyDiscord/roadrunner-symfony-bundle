<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Grpc\Fixtures;

use Google\Protobuf\Internal\Message;

/**
 * A request type whose wire parsing always fails, so the worker's decode-failure path can be
 * driven without depending on which byte strings the installed protobuf release rejects. The
 * constructor deliberately skips Message's descriptor lookup — only generated code may derive
 * from Message, and this fixture is never serialised, only failed.
 */
class UndecodableRequest extends Message
{
    public function __construct($data = null)
    {
    }

    public function mergeFromString($data, ...$arguments)
    {
        throw new \Exception('Error occurred during parsing');
    }
}
