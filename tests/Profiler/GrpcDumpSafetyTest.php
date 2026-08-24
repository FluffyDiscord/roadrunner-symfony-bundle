<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\Profiler;

use FluffyDiscord\RoadRunnerBundle\Profiler\GrpcRequest;
use FluffyDiscord\RoadRunnerBundle\Tests\BaseTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DumpDataCollector;
use Symfony\Component\VarDumper\Cloner\VarCloner;

/**
 * TC-31 — the reason the subscriber pops the virtual request BEFORE Profiler::collect():
 * DumpDataCollector writes buffered dump() output to php://output (the goridge relay under
 * RoadRunner) when the collected request is still the stack's main request and the response
 * carries no HTML body. This pins the real collector's behavior for both stack states.
 */
class GrpcDumpSafetyTest extends BaseTestCase
{
    private function makeCollectorWithABufferedDump(RequestStack $requestStack): DumpDataCollector
    {
        $collector = new DumpDataCollector(requestStack: $requestStack);

        ob_start();

        try {
            $collector->dump(new VarCloner()->cloneVar('dumped-value'));
        } finally {
            ob_end_clean();
        }

        return $collector;
    }

    public function testCollectAfterPopWritesNothingToOutput(): void
    {
        $requestStack = new RequestStack();
        $request = new GrpcRequest(microtime(true));
        $requestStack->push($request);
        $collector = $this->makeCollectorWithABufferedDump($requestStack);
        $requestStack->pop();

        ob_start();
        $collector->collect($request, new Response('', 200));
        $printed = ob_get_clean();

        self::assertSame('', $printed);
    }

    public function testCollectWhileStillPushedWouldWriteToOutput(): void
    {
        $requestStack = new RequestStack();
        $request = new GrpcRequest(microtime(true));
        $requestStack->push($request);
        $collector = $this->makeCollectorWithABufferedDump($requestStack);

        ob_start();
        $collector->collect($request, new Response('', 200));
        $printed = ob_get_clean();

        self::assertStringContainsString('dumped-value', (string) $printed);
    }
}
