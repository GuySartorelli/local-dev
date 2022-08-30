<?php

namespace DevTools\Utility;

use DevTools\Command\BaseCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PHPService
{
    private DockerService $docker;

    private OutputInterface $output;

    private BufferedOutput $dockerOutput;

    public function __construct(Environment $environment, ?OutputInterface $output = null)
    {
        $this->dockerOutput = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->docker = new DockerService($environment, $this->dockerOutput);
        $this->output = $output;
    }

    /**
     * Get the PHP version of the current webserver container
     */
    public function getVersion(): string
    {
        $failure = $this->runDockerCommand('echo $(php -r "echo PHP_MAJOR_VERSION . \'.\' . PHP_MINOR_VERSION;")');
        $version = trim($this->dockerOutput->fetch());
        if ($failure || $version === '') {
            throw new RuntimeException("Error fetching PHP version: $version");
        }
        return $version;
    }

    /**
     * Get the statis of XDebug in the current webserver container
     */
    public function debugIsEnabled(?string $version = null): bool
    {
        $version ??= $this->getVersion();
        $path = $this->getDebugPath($version);
        $failure = $this->runDockerCommand("cat {$path}");
        $debug = trim($this->dockerOutput->fetch());
        if ($failure) {
            throw new RuntimeException("Error fetching debug status: $debug");
        }
        return $debug !== '' && !str_starts_with($debug, ';');
    }

    /**
     * Get the path for the XDebug config for the given PHP version
     */
    public function getDebugPath(string $phpVersion): string
    {
        return "/etc/php/{$phpVersion}/mods-available/xdebug.ini";
    }

    /**
     * Check whether some PHP version is available to be used
     */
    public static function versionIsAvailable(string $version): bool
    {
        $versions = explode(',', Config::getEnv('DT_PHP_VERSIONS'));
        return in_array($version, $versions);
    }

    /**
     * Check which PHP versions are available to be used
     */
    public static function getAvailableVersions(): array
    {
        return explode(',', Config::getEnv('DT_PHP_VERSIONS'));
    }

    private function runDockerCommand(string $command): int|bool
    {
        if ($this->output && $this->output->isVeryVerbose()) {
            $this->output->writeln(BaseCommand::STEP_STYLE . "Running command in docker container: '$command'</>");
        }

        $success = $this->docker->exec($command);
        if (!$success && $this->output) {
            $io = new SymfonyStyle(new ArrayInput([]), $this->output);
            $io->error('Problem occured while running command in docker container.');
            return Command::FAILURE;
        }

        return false;
    }
}
