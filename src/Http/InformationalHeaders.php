<?php

namespace FluffyDiscord\RoadRunnerBundle\Http;

class InformationalHeaders
{
    /** @var array<string, array<string>> */
    private static array $sentHeaders = [];

    /**
     * @param array<string, array<string>> $headers
     * @return array<string, array<string>>
     */
    public static function getUnsentHeaders(array $headers): array
    {
        if (self::$sentHeaders === []) {
            return $headers;
        }

        foreach ($headers as $name => $values) {
            $alreadySentValues = self::getSentValues((string)$name);

            if ($alreadySentValues === []) {
                continue;
            }

            $unsentValues = array_values(array_diff($values, $alreadySentValues));

            if ($unsentValues === []) {
                unset($headers[$name]);

                continue;
            }

            $headers[$name] = $unsentValues;
        }

        return $headers;
    }

    /**
     * @param array<string, array<string>> $headers
     */
    public static function rememberSentHeaders(array $headers): void
    {
        foreach ($headers as $name => $values) {
            $normalizedName = strtolower((string)$name);
            $alreadySentValues = self::$sentHeaders[$normalizedName] ?? [];

            self::$sentHeaders[$normalizedName] = array_merge($alreadySentValues, array_values($values));
        }
    }

    public static function forgetSentHeaders(): void
    {
        self::$sentHeaders = [];
    }

    /**
     * @return array<string>
     */
    public static function getSentValues(string $name): array
    {
        return self::$sentHeaders[strtolower($name)] ?? [];
    }

    public static function hasSentHeaders(): bool
    {
        return self::$sentHeaders !== [];
    }

    /**
     * @return array<string>
     */
    public static function getSentHeaderNames(): array
    {
        return array_keys(self::$sentHeaders);
    }
}
