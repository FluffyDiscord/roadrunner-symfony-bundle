<?php

namespace FluffyDiscord\RoadRunnerBundle\Command;

enum TemporalDebugFormat: string
{
    case Txt = 'txt';
    case Json = 'json';
    case Mermaid = 'mermaid';
}
