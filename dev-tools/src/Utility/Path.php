<?php

namespace DevTools\Utility;

final class Path
{
    /**
     * Join two or more paths together.
     * Ensures correct directory separator and avoids doubling up or missing out on them.
     * i.e. avoids "some//path" and "somepath" where "some/path" is desired.
     */
    public static function join(array $paths): string
    {
        $parts = array_filter(array_map('trim', $paths));
        return self::normalise(implode(DIRECTORY_SEPARATOR, $parts));
    }

    public static function normalise(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $path = preg_replace('#[/\\\\]{2,}#', DIRECTORY_SEPARATOR, $path);
        $user = get_current_user();
        return preg_replace('/^~\//', "/home/$user/", $path);
    }
}
