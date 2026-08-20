<?php

namespace FluffyDiscord\RoadRunnerBundle\Tests\ErrorHandler;

use FluffyDiscord\RoadRunnerBundle\ErrorHandler\FatalError;
use PHPUnit\Framework\TestCase;

/**
 * @see \FluffyDiscord\RoadRunnerBundle\ErrorHandler\FatalError
 */
class FatalErrorTest extends TestCase
{
    public function testKeepsRealFatalError(): void
    {
        $fatal = [
            'type'    => \E_ERROR,
            'message' => 'Allowed memory size of 1 bytes exhausted',
            'file'    => '/app/src/Controller.php',
            'line'    => 42,
        ];

        $this->assertSame($fatal, FatalError::getFatalError($fatal));
    }

    public function testDiscardsStaleDeprecation(): void
    {
        $deprecation = [
            'type'    => \E_USER_DEPRECATED,
            'message' => 'Method "X::getDetails()" might add "?object" as a native return type declaration in the future.',
            'file'    => '/app/vendor/symfony/error-handler/DebugClassLoader.php',
            'line'    => 363,
        ];

        $this->assertNull(FatalError::getFatalError($deprecation));
    }

    public function testDiscardsStaleWarningAndNotice(): void
    {
        $warning = ['type' => \E_WARNING, 'message' => 'w', 'file' => '/app/x.php', 'line' => 1];
        $notice = ['type' => \E_NOTICE, 'message' => 'n', 'file' => '/app/x.php', 'line' => 2];

        $this->assertNull(FatalError::getFatalError($warning));
        $this->assertNull(FatalError::getFatalError($notice));
    }

    public function testHandlesMissingError(): void
    {
        $this->assertNull(FatalError::getFatalError(null));
    }

    public function testLastErrorIsIgnoredWhenItIsNotFatal(): void
    {
        @trigger_error('a deprecation raised long before die()', \E_USER_DEPRECATED);

        $this->assertNull(FatalError::getLastFatalError());
    }
}
