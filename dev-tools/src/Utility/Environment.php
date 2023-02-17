<?php

namespace DevTools\Utility;

use LogicException;
use stdClass;
use Symfony\Component\Filesystem\Path;

final class Environment
{
    private string $baseDir;

    private string $suffix;

    private string $name;

    private ComposerJsonService $composerService;

    public const ATTACHED_ENV_FILE = '.dev-tools-env';

    /**
     * @throws LogicException if not a new environment and $path is not in a valid environment.
     */
    public function __construct(string $path, bool $isNew = false, bool $allowMissing = false)
    {
        $this->setBaseDir($path, $isNew, $allowMissing);
        $this->setName();
        $this->suffix = substr($this->name, -2);
        $this->composerService = new ComposerJsonService($this->getWebRoot());
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getComposerJson(bool $associative = false)
    {
        // TODO: Use the service directly instead of implementing here
        return $this->composerService->getComposerJson($associative);
    }

    public function setComposerJson(stdClass|array $content)
    {
        // TODO: Use the service directly instead of implementing here
        return $this->composerService->setComposerJson($content);
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

    public function exists(): bool
    {
        return is_dir($this->getBaseDir());
    }

    private function setBaseDir(string $candidate, bool $isNew, bool $allowMissing): void
    {
        if ($isNew) {
            $this->baseDir = $candidate;
            return;
        }

        $this->baseDir = (string)$this->findBaseDirForEnv($candidate);

        if (!$this->baseDir && !$allowMissing) {
            throw new LogicException("Environment path '$candidate' is not inside a valid environment.");
        }
    }

    private function findBaseDirForEnv(string $candidate): ?string
    {
        if (!is_dir($candidate)) {
            throw new LogicException("'$candidate' is not a directory.");
        }

        $stopAtDirs = [
            '/',
            '/home',
        ];

        // Recursively check the proposed path and its parents for the requisite structure
        // Don't check root
        while ($candidate && !in_array($candidate, $stopAtDirs)) {
            // environments created using the attach command will have a special .dev-tools-env file
            if (file_exists(Path::join($candidate, self::ATTACHED_ENV_FILE))) {
                return $candidate;
            }

            // All environment directories end with an underscore and two digits (e.g. "_00")
            if (preg_match('/_(\d{2})$/', $candidate, $matches)) {
                $suffix = $matches[1];
                // Check that the 'www', 'logs', and 'dockerXX' directories are all present.
                $found = 0;
                foreach (scandir($candidate) as $toCheck) {
                    if (
                        preg_match("/^(www|logs|docker-$suffix)$/", $toCheck)
                        && is_dir(Path::join($candidate, $toCheck))
                    ) {
                        $found++;
                    }
                }
                if ($found === 3) {
                    return $candidate;
                }
            }
            // If the directory is invalid, check its parent next.
            $candidate = Path::getDirectory($candidate);
        }

        return null;
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
