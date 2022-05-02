<?php

namespace DevTools\Utility;

use InvalidArgumentException;
use LogicException;
use stdClass;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Utitilty class for getting and setting config
 */
final class Config
{
    /**
     * Full path of the JSON config file
     */
    private static string $fileName;

    /**
     * Key to the port suffixes in the config file
     */
    private const SUFFIX_KEY = 'portSuffixes';

    /**
     * Key to the main json configuration in the config file
     */
    private const CONFIG_KEY = 'config';

    /**
     * Initialise configuration
     */
    public static function init(): void
    {
        $baseDir = self::getBaseDir();
        // Boot environment variables
        $envConfig = new Dotenv();
        $envConfig->bootEnv(Path::join([$baseDir, '.env']));
        // Set up JSON config file
        self::$fileName = Path::join([$baseDir, 'projectsConfig.json']);
        if (!file_exists(self::$fileName)) {
            touch(self::$fileName);
            file_put_contents(self::$fileName, json_encode([
                self::SUFFIX_KEY => self::prepareEmptySuffixes(),
                self::CONFIG_KEY => new stdClass(),
            ]));
        }
    }

    /**
     * Get the base directory of the dev tools
     */
    public static function getBaseDir(): string
    {
        return __DIR__ . '/../../';
    }

    /**
     * Get the value of the environment variable, or null if not set
     */
    public static function getEnv(string $key, bool $allowNull = false): mixed
    {
        $value = isset($_ENV[$key]) ? $_ENV[$key] : null;
        if ($value === null && !$allowNull) {
            throw new LogicException("Environment value '$key' must be defined in the .env file.");
        }
        return $value;
    }

    /**
     * Get the next available suffix in the list
     */
    public static function getNextAvailableSuffix(): string
    {
        $suffixes = self::getConfig()[self::SUFFIX_KEY];
        ksort($suffixes);
        $available = array_filter($suffixes, fn(bool $taken): bool => !$taken);
        $next = array_key_first($available);
        return $next;
    }

    /**
     * Mark the suffix as taken, to avoid duplicates
     *
     * @throws InvalidArgumentException if suffix is invalid
     * @throws LogicExtension if suffix is already taken
     */
    public static function takeSuffix(string $suffix): void
    {
        self::validateSuffix($suffix);
        // Check if suffix already taken
        $config = self::getConfig();
        if (!empty($config[self::SUFFIX_KEY][$suffix])) {
            throw new LogicException("Port suffix $suffix is already taken.");
        }
        // Set the suffix
        $config[self::SUFFIX_KEY][$suffix] = true;
        self::setConfig($config);
    }

    /**
     * Mark the suffix as available
     *
     * @throws InvalidArgumentException if suffix is invalid
     */
    public static function releaseSuffix(string $suffix): void
    {
        self::validateSuffix($suffix);
        $config = self::getConfig();
        $config[self::SUFFIX_KEY][$suffix] = false;
        self::setConfig($config);
    }

    /**
     * Get a given json configuration option (or null if not set)
     */
    public static function getJsonConfig(string $name): mixed
    {
        $config = self::getConfig();
        if (isset($config[self::CONFIG_KEY][$name])) {
            return $config[self::CONFIG_KEY][$name];
        }
        return null;
    }

    /**
     * Set a given json configuration option
     */
    public static function setJsonConfig(string $name, mixed $value): void
    {
        $config = self::getConfig();
        $config[self::CONFIG_KEY][$name] = $value;
        self::setConfig($config);
    }

    /**
     * Prepare an array of suffixes from "00" to "99" all set to false
     */
    private static function prepareEmptySuffixes(): array
    {
        $suffixes = [];
        for ($i = 0; $i < 10; $i++) {
            for ($j = 0; $j < 10; $j++) {
                $suffixes["$i$j"] = false;
            }
        }
        return $suffixes;
    }

    /**
     * Validate that a given suffix is a string composed of two digits
     *
     * @throws InvalidArgumentException
     */
    private static function validateSuffix(string $suffix): void
    {
        // Validate suffix
        if (!preg_match('/\d{2}/', $suffix)) {
            throw new InvalidArgumentException('$suffix must be a 2 digit string.');
        }
    }

    /**
     * Get the full JSON configuration
     */
    private static function getConfig(): array
    {
        return json_decode(file_get_contents(self::$fileName), true);
    }

    /**
     * Set the full JSON configuration
     */
    private static function setConfig(array $config): void
    {
        file_put_contents(self::$fileName, json_encode($config));
    }
}
