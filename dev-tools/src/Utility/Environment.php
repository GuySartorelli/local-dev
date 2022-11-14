<?php

namespace DevTools\Utility;

use LogicException;
use stdClass;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class Environment
{
    private string $baseDir;

    private string $suffix;

    private string $name;

    public const ATTACHED_ENV_FILE = '.dev-tools-env';

    /**
     * @throws LogicException if not a new environment and $path is not in a valid environment.
     */
    public function __construct(string $path, bool $isNew = false)
    {
        $this->setBaseDir($path, $isNew);
        $this->setName();
        $this->suffix = substr($this->name, -2);
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getComposerJson(bool $associative = false)
    {
        $filePath = Path::join($this->getWebRoot(), 'composer.json');
        $fileSystem = new Filesystem();
        if (!$fileSystem->exists($filePath)) {
            throw new FileNotFoundException(path: $filePath);
        }
        return json_decode(file_get_contents($filePath), $associative);
    }

    public function setComposerJson(stdClass $content)
    {
        $filePath = Path::join($this->getWebRoot(), 'composer.json');
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile($filePath, json_encode($content, JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES));
    }

    public function getWebRoot(): string
    {
        return $this->isAttachedEnv() ? $this->getBaseDir() : Path::join($this->getBaseDir(), 'www');
    }

    public function getDockerDir(): string
    {
        return Path::join($this->getBaseDir(), 'docker-' . $this->getSuffix());
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSuffix(): string
    {
        return $this->suffix;
    }

    public function getIpAddress(): string
    {
        return '10.0.' . (int)$this->getSuffix() . '.50';
    }

    public function getHostName(): string
    {
        $suffix = Config::getEnv('DT_DEFAULT_HOST_SUFFIX');
        return $this->getName() . '.' . $suffix;
    }

    public function getBaseURL(): string
    {
        return "http://{$this->getHostName()}";
    }

    public function isAttachedEnv()
    {
        return file_exists(Path::join($this->getBaseDir(), self::ATTACHED_ENV_FILE));
    }

    private function setBaseDir(string $candidate, bool $isNew): void
    {
        if ($isNew) {
            $this->baseDir = $candidate;
            return;
        }

        if (!is_dir($candidate)) {
            throw new LogicException("'$candidate' is not a directory.");
        }

        $origDir = $candidate;
        $stopAtDirs = [
            '/',
            '/home',
        ];

        // Recursively check the proposed path and its parents for the requisite structure
        // Don't check root
        while ($candidate && !in_array($candidate, $stopAtDirs)) {
            // environments created using the attach command will have a special .dev-tools-env file
            if (file_exists(Path::join($candidate, self::ATTACHED_ENV_FILE))) {
                $this->baseDir = $candidate;
                return;
            }

            // All environment directories end with an underscore and two digits (e.g. "_00")
            if (preg_match('/_(\d{2})$/', $candidate, $matches)) {
                $suffix = $matches[1];
                // Check that the 'www', 'logs', and 'dockerXX' directories are all present.
                $found = 0;
                foreach(scandir($candidate) as $toCheck) {
                    if (
                        preg_match("/^(www|logs|docker-$suffix)$/", $toCheck)
                        && is_dir(Path::join($candidate, $toCheck))
                    ) {
                        $found++;
                    }
                }
                if ($found === 3) {
                    $this->baseDir = $candidate;
                    return;
                }
            }
            // If the directory is invalid, check its parent next.
            $candidate = Path::getDirectory($candidate);
        }

        throw new LogicException("Environment path '$origDir' is not inside a valid environment.");
    }

    private function setName()
    {
        $this->name = $this->getAttachedEnvName() ?? basename($this->getBaseDir());
    }

    private function getAttachedEnvName()
    {
        $path = Path::join($this->getBaseDir(), self::ATTACHED_ENV_FILE);
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }
}
