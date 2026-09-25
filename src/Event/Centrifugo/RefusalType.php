<?php

namespace FluffyDiscord\RoadRunnerBundle\Event\Centrifugo;

enum RefusalType: string
{
    case Error = 'error';
    case Disconnect = 'disconnect';
}
