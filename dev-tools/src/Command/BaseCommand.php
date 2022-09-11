<?php

namespace DevTools\Command;

use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use InvalidArgumentException;
use Joli\JoliNotif\Notification;
use Joli\JoliNotif\NotifierFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

abstract class BaseCommand extends Command
{
    private array $commandVars = [];

    protected bool $isSubCommand = false;

    /**
     * If true, notifies when a command has finished.
     */
    protected static bool $notifyOnCompletion = false;

    public const STEP_STYLE = '<fg=blue>';

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('quiet')) {
            // We don't want symfony's definition of quiet - we want OUR definition of quiet.
            // Symfony's quiet is actually silent.
            $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        }
        $this->initiateEnv($input);
        $this->resetVars();
        $this->setVar('input', $input);
        $this->setVar('output', $output);
        $this->setVar('io', new SymfonyStyle($input, $output));
    }

    protected function resetVars(): void
    {
        $this->commandVars = [];
    }

    protected function initiateEnv(InputInterface $input)
    {
        $proposedPath = '';
        if ($input->hasArgument('env-path')) {
            $proposedPath = $input->getArgument('env-path');
        } elseif ($input->hasOption('env-path')) {
            $proposedPath = $input->getOption('env-path');
        }

        if ($proposedPath) {
            $env = new Environment(Path::makeAbsolute(Path::canonicalize($proposedPath), getcwd()));
            $this->setVar('env', $env);
        }
    }

    protected function getVar(string $varName): mixed
    {
        if (!array_key_exists($varName, $this->commandVars)) {
            throw new InvalidArgumentException("var '$varName' has not been set");
        }
        return $this->commandVars[$varName];
    }

    protected function setVar(string $varName, mixed $value): void
    {
        $this->commandVars[$varName] = $value;
    }

    private function notify(int $exitCode): void
    {
        $notifier = NotifierFactory::create();
        $notification = new Notification();

        $notification->setTitle($this->getApplication()->getName());
        if ($exitCode) {
            $notification
                ->setBody("Error occurred when running {$this->getName()}")
                ->setIcon(__DIR__ . '/../../resources/icons/error.png');
        } else {
            $notification
                ->setBody("Successfully ran {$this->getName()}")
                ->setIcon(__DIR__ . '/../../resources/icons/success.png');
        }

        $notifier->send($notification);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = parent::run($input, $output);
        if (static::$notifyOnCompletion && !$this->isSubCommand) {
            $this->notify($exitCode);
        }
        return $exitCode;
    }

    public function setIsSubCommand(bool $value): self
    {
        $this->isSubCommand = $value;
        return $this;
    }

    /**
     * Run a command in the webserver container for the current environment.
     *
     * @return string|integer|boolean
     * If $output is null or a BufferedOutput, the return type will be the output value from docker unless something goes wrong.
     * If anything goes wrong, Command::FAILURE will be returned.
     * If nothing goes wrong and $output was passed with some non-BufferedOutput, false will be returned.
     */
    protected function runDockerCommand(
        string $command,
        OutputInterface $output = null,
        bool $asRoot = false,
        bool $requiresRestart = false,
        bool $suppressMessages = false,
        bool $interactive = false,
    ): string|int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        if (!$output) {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        }
        $dockerService = new DockerService($this->getVar('env'), $output);
        if (!$suppressMessages && (!$this->isSubCommand || $io->isVerbose())) {
            $io->writeln(self::STEP_STYLE . "Running command in docker container: '$command'</>");
        }

        $success = $dockerService->exec($command, $asRoot, $interactive);
        if (!$success) {
            $io->error('Problem occured while running command in docker container.');
            return Command::FAILURE;
        }

        if ($requiresRestart) {
            sleep(1);
            $success = $dockerService->up(false);
            if (!$success) {
                $io->error('Could not restart container.');
                return Command::FAILURE;
            }
        }

        if ($output instanceof BufferedOutput) {
            return $output->fetch();
        }
        return false;
    }
}
