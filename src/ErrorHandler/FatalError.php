<?php

namespace FluffyDiscord\RoadRunnerBundle\ErrorHandler;

final class FatalError
{
    /**
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public static function getLastFatalError(): ?array
    {
        $lastError = error_get_last();

        return self::getFatalError($lastError);
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $lastError
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public static function getFatalError(?array $lastError): ?array
    {
        if ($lastError === null) {
            return null;
        }

        $fatalTypes = self::getFatalErrorTypes();
        $isFatal = \in_array($lastError['type'], $fatalTypes, true);

        if (!$isFatal) {
            return null;
        }

        return $lastError;
    }

    /**
     * @return list<int>
     */
    private static function getFatalErrorTypes(): array
    {
        return [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR];
    }
}
