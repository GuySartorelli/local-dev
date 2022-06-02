<?php

namespace DevTools\Utility;

use InvalidArgumentException;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class DockerService
{
    private Environment $environment;

    private ?ProcessHelper $processHelper;

    private ?OutputInterface $output;

    public function __construct(Environment $environment, ?ProcessHelper $processHelper = null, ?OutputInterface $output = null)
    {
        $this->environment = $environment;
        $this->processHelper = $processHelper;
        $this->output = $output;
    }

    /**
     * Build and start docker containers in the docker-compose.yml file for the environment
     */
    public function up(): bool
    {
        $upCommand = [
            'docker',
            'compose',
            'up',
            '--build',
            '-d',
        ];
        $originalDir = getcwd();
        chdir($this->environment->getDockerDir());
        $retVal = $this->runCommand($upCommand);
        chdir($originalDir ?: $this->environment->getBaseDir());
        return $retVal;
    }

    /**
     * Completely tear down docker containers, networks, and volumes in the docker-compose.yml file for the environment
     */
    public function down(): bool
    {
        $downCommand = [
            'docker',
            'compose',
            'down',
            '-v',
        ];
        $originalDir = getcwd();
        chdir($this->environment->getDockerDir());
        $retVal = $this->runCommand($downCommand);
        chdir($originalDir ?: $this->environment->getBaseDir());
        return $retVal;
    }

    /**
     * Run some command in the webserver docker container - optionally as root.
     * @throws InvalidArgumentException
     */
    public function exec(string $exec, bool $asRoot = false): bool
    {
        if (empty($exec)) {
            throw new InvalidArgumentException('$exec cannot be an empty string');
        }
        $execCommand = [
            'docker',
            'exec',
            '-t',
            '--workdir',
            '/var/www',
            ...($asRoot ? [] : ['-u', '1000']),
            $this->environment->getName() . '_webserver',
            'env',
            'TERM=xterm-256color',
            'bash',
            '-c',
            $exec,
        ];
        return $this->runCommand($execCommand);
    }

    private function runCommand(array $command): bool|Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        if ($this->processHelper && $this->output) {
            $process = $this->processHelper->run($this->output, $process);
            return $process->isSuccessful();
        } else {
            $process->run();
            return $process->isSuccessful();
        }
    }
}
