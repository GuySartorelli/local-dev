<?php

namespace DevTools\Utility;

use LogicException;
use Symfony\Component\Filesystem\Path;

final class Environment
{
    private string $baseDir;

    private string $suffix;

    private string $name;

    /**
     * @throws LogicException if not a new environment and $path is not in a valid environment.
     */
    public function __construct(string $path, bool $isNew = false)
    {
        $this->getEnvBasePath($path, $isNew);
        $this->name = basename($this->baseDir);
        $this->suffix = substr($this->name, -2);
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getWebRoot(): string
    {
        return Path::join($this->getBaseDir(), 'www');
    }

    public function getDockerDir(): string
    {
        return Path::join($this->getBaseDir(), 'docker' . $this->getSuffix());
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

    private function getEnvBasePath(string $candidate, bool $isNew): void
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
}
