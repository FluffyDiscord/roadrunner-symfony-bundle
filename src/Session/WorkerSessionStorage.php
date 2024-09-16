<?php

namespace FluffyDiscord\RoadRunnerBundle\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;

class WorkerSessionStorage extends NativeSessionStorage
{
    public function __construct(
        array                                       $options,
        \SessionHandlerInterface|AbstractProxy|null $handler,
        ?MetadataBag                                $metaBag,
        private readonly RequestStack               $requestStack,
    )
    {
        parent::__construct($options, $handler, $metaBag);
    }

    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        if (\PHP_SESSION_ACTIVE === session_status()) {
            throw new \RuntimeException('Failed to start the session: already started by PHP.');
        }

        if (filter_var(\ini_get('session.use_cookies'), \FILTER_VALIDATE_BOOL) && headers_sent($file, $line)) {
            throw new \RuntimeException(sprintf('Failed to start the session because headers have already been sent by "%s" at line %d.', $file, $line));
        }

        /*
         * Explanation of the session ID regular expression: `/^[a-zA-Z0-9,-]{22,250}$/`.
         *
         * ---------- Part 1
         *
         * The part `[a-zA-Z0-9,-]` is related to the PHP ini directive `session.sid_bits_per_character` defined as 6.
         * See https://www.php.net/manual/en/session.configuration.php#ini.session.sid-bits-per-character.
         * Allowed values are integers such as:
         * - 4 for range `a-f0-9`
         * - 5 for range `a-v0-9`
         * - 6 for range `a-zA-Z0-9,-`
         *
         * ---------- Part 2
         *
         * The part `{22,250}` is related to the PHP ini directive `session.sid_length`.
         * See https://www.php.net/manual/en/session.configuration.php#ini.session.sid-length.
         * Allowed values are integers between 22 and 256, but we use 250 for the max.
         *
         * Where does the 250 come from?
         * - The length of Windows and Linux filenames is limited to 255 bytes. Then the max must not exceed 255.
         * - The session filename prefix is `sess_`, a 5 bytes string. Then the max must not exceed 255 - 5 = 250.
         *
         * ---------- Conclusion
         *
         * The parts 1 and 2 prevent the warning below:
         * `PHP Warning: SessionHandler::read(): Session ID is too long or contains illegal characters. Only the A-Z, a-z, 0-9, "-", and "," characters are allowed.`
         *
         * The part 2 prevents the warning below:
         * `PHP Warning: SessionHandler::read(): open(filepath, O_RDWR) failed: No such file or directory (2).`
         */
        $sessionId = $this->requestStack->getCurrentRequest()?->cookies->get(session_name());
        if ($sessionId) {
            if (!preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $sessionId)) {
                $sessionId = session_create_id();
            }
        }

        session_id($sessionId);

        // ok to try and start the session
        if (!session_start()) {
            throw new \RuntimeException('Failed to start the session.');
        }

        $this->loadSession();

        return true;

    }

    public function reset(): void
    {
        $this->started = false;
        $this->saveHandler->close();
    }
}