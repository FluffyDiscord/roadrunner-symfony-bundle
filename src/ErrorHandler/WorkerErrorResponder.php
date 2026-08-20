<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

use FluffyDiscord\RoadRunnerBundle\Http\InformationalHeaders;
use Nyholm\Psr7;
use Spiral\RoadRunner\Http\PSR7Worker;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;

class WorkerErrorResponder
{
    public function __construct(
        private readonly bool $debug,
    )
    {
    }

    public function sendThrowableResponse(PSR7Worker $worker, \Throwable $throwable): void
    {
        try {
            $worker->respond($this->createErrorResponse($throwable));
        } catch (\Throwable) {
            try {
                $worker->getWorker()->error((string)$throwable);
            } catch (\Throwable) {
            }
        }
    }

    protected function createErrorResponse(\Throwable $throwable): Psr7\Response
    {
        if (!$this->debug) {
            return new Psr7\Response(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $flattenException = $this->renderHtmlError($throwable);
        } catch (\Throwable) {
            $minimalPageHeaders = InformationalHeaders::getUnsentHeaders(['Content-Type' => ['text/html; charset=utf-8']]);

            return new Psr7\Response(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $minimalPageHeaders,
                MinimalErrorPage::render(Response::HTTP_INTERNAL_SERVER_ERROR, null, (string)$throwable),
            );
        }

        $renderedHeaders = [];

        foreach ($flattenException->getHeaders() as $name => $value) {
            $values = \is_array($value) ? $value : [$value];
            $renderedHeaders[(string)$name] = array_values(array_filter($values, 'is_string'));
        }

        return new Psr7\Response(
            $flattenException->getStatusCode(),
            InformationalHeaders::getUnsentHeaders($renderedHeaders),
            $flattenException->getAsString(),
        );
    }

    protected function renderHtmlError(\Throwable $throwable): FlattenException
    {
        return new HtmlErrorRenderer(true)->render($throwable);
    }
}
