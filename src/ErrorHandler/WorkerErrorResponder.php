<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

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
            return new Psr7\Response(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/html; charset=utf-8'],
                MinimalErrorPage::render(Response::HTTP_INTERNAL_SERVER_ERROR, null, (string)$throwable),
            );
        }

        return new Psr7\Response(
            $flattenException->getStatusCode(),
            $flattenException->getHeaders(),
            $flattenException->getAsString(),
        );
    }

    protected function renderHtmlError(\Throwable $throwable): FlattenException
    {
        return new HtmlErrorRenderer(true)->render($throwable);
    }
}
