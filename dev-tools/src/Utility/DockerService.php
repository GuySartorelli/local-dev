<?php

namespace DevTools\Utility;

use InvalidArgumentException;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class DockerService
{
    private Environment $environment;

    private ?ProcessHelper $processHelper;

    private ?ProcessOutputter $outputter;

    private SymfonyStyle $io;

    public const CONTAINER_WEBSERVER = '_webserver';

    public const CONTAINER_DATABASE = '_database';

    public function __construct(Environment $environment, ?OutputInterface $output = null)
    {
        $this->environment = $environment;
        $this->processHelper = new ProcessHelper();
        $this->processHelper->setHelperSet(new HelperSet([new DebugFormatterHelper()]));
        $this->outputter = new ProcessOutputter($output);
        $this->io = new SymfonyStyle(new ArrayInput([]), $output);
    }

    /**
     * Get an associative array of docker container statuses
     *
     * @return string[]
     */
    public function getContainersStatus()
    {
        $env = $this->environment;
        $processHelper = $this->processHelper;
        $io = $this->io;
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $cmd = [
            'docker',
            'compose',
            'ps',
            '--all',
            '--format=json',
        ];
        $outputFormatter = new ProcessOutputter($output);
        $process = $processHelper->run(
            $output,
            new Process($cmd, $env->getDockerDir()),
            callback: [$outputFormatter, 'output']
        );
        if (!$process->isSuccessful()) {
            $io->warning("Couldn't get status of docker containers.");
            return null;
        }

        $containers = [
            'webserver container' => 'missing',
            'database container' => 'missing',
        ];
        foreach (json_decode($output->fetch(), true) as $container) {
            $name = str_replace($env->getName() . '_', '', $container['Name']) . ' container';
            $containers[$name] = $container['State'];
        }
        return $containers;
    }

    /**
     * Build and start docker containers in the docker-compose.yml file for the environment
     */
    public function up(bool $fullBuild = true): bool
    {
        $upCommand = [
            'docker',
            'compose',
            'up',
        ];
        if ($fullBuild) {
            $upCommand[] = '--build';
        }
        $upCommand[] = '-d';
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
     * Restart the container(s).
     *
     * If no container is passed in, it restarts everything.
     */
    public function restart(string $container = '', ?int $timeout = null): bool
    {
        $command = [
            'docker',
            'compose',
            'restart',
        ];
        if ($timeout !== null) {
            $command[] = "-t$timeout";
        }
        if ($container) {
            $command[] = ltrim($container, '_');
        }
        $originalDir = getcwd();
        chdir($this->environment->getDockerDir());
        $retVal = $this->runCommand($command);
        chdir($originalDir ?: $this->environment->getBaseDir());
        return $retVal;
    }

    /**
     * Copies a file from a docker container to the host's filesystem.
     *
     * @param string $container Which container to copy from.
     * Usually one of self::CONTAINER_WEBSERVER or self::CONTAINER_DATABASE
     * @param string $copyFrom Full file path to copy from in the container.
     * @param string $copyTo Full file path to copy to on the host.
     */
    public function copyFromContainer(string $container, string $copyFrom, string $copyTo): bool
    {
        $command = [
            'docker',
            'cp',
            $this->environment->getName() . $container . ":$copyFrom",
            $copyTo,
        ];
        return $this->runCommand($command);
    }

    /**
     * Run some command in the webserver docker container - optionally as root.
     * @throws InvalidArgumentException
     */
    public function exec(string $exec, bool $asRoot = false, bool $interactive = false, $container = self::CONTAINER_WEBSERVER): bool
    {
        if (empty($exec)) {
            throw new InvalidArgumentException('$exec cannot be an empty string');
        }
        $execCommand = [
            'docker',
            'exec',
            '-t',
            ...($interactive ? ['-i'] : []),
            ...($container === self::CONTAINER_WEBSERVER ? ['--workdir', '/var/www'] : []),
            ...($asRoot ? [] : ['-u', '1000']),
            $this->environment->getName() . $container,
            'env',
            'TERM=xterm-256color',
            'bash',
            '-c',
            $exec,
        ];
        return $this->runCommand($execCommand, $interactive);
    }

    private function runCommand(array $command, bool $interactive = false): bool|Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        if (!$interactive && $this->outputter) {
            $this->outputter->startCommand();
            $process = $this->processHelper->run(new NullOutput(), $process, callback: [$this->outputter, 'output']);
            $this->outputter->endCommand();
            return $process->isSuccessful();
        } else {
            if ($interactive) {
                $process->setTty(true);
            }
            $process->run();
            return $process->isSuccessful();
        }
    }
}
