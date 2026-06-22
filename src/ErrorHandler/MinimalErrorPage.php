<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

final class MinimalErrorPage
{
    public const int MESSAGE_MAX = 2048;

    /**
     * @param array<array-key, mixed>|null $error
     * @param string|null $detail
     */
    public static function render(int $statusCode, ?array $error, ?string $detail = null): string
    {
        $title = match ($statusCode) {
            500 => 'Internal Server Error',
            default => 'Error',
        };

        $message = null;
        $location = null;

        if ($error !== null && isset($error['message']) && \is_scalar($error['message'])) {
            $message = (string) $error['message'];
            if (isset($error['file'], $error['line']) && \is_scalar($error['file']) && \is_scalar($error['line'])) {
                $location = (string) $error['file'] . ':' . (string) $error['line'];
            }
        } elseif ($detail !== null && $detail !== '') {
            $message = $detail;
        }

        if ($message !== null && \strlen($message) > self::MESSAGE_MAX) {
            $message = \substr($message, 0, self::MESSAGE_MAX) . '… [truncated]';
        }

        $statusText = htmlspecialchars((string) $statusCode . ' ' . $title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $body = '<h1>' . $statusText . '</h1>';
        if ($message !== null) {
            $body .= '<pre class="msg">' . htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        } else {
            $body .= '<p>The worker terminated while handling this request.</p>';
        }
        if ($location !== null) {
            $body .= '<p class="loc">' . htmlspecialchars($location, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $statusText . '</title>'
            . '<style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
            . 'background:#1b1b1f;color:#e3e3e8;margin:0;padding:2.5rem;line-height:1.5}'
            . 'h1{font-size:1.4rem;margin:0 0 1rem;color:#ff5f56}'
            . '.msg{white-space:pre-wrap;word-break:break-word;background:#2a2a31;border-left:3px solid #ff5f56;'
            . 'padding:1rem;border-radius:4px;font-size:.95rem;overflow:auto}'
            . '.loc{color:#9a9aa6;font-family:monospace;font-size:.85rem;margin-top:.75rem}'
            . '</style></head><body>'
            . $body
            . '</body></html>';
    }
}
