<?php

namespace DevTools\Command;

use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

class Docker extends BaseCommand
{
    protected static $defaultName = 'docker';

    protected static $defaultDescription = 'Run commands in the webserver docker container.';

    protected static bool $notifyOnCompletion = true;

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        if (empty($input->getArgument('exec'))) {
            throw new RuntimeException('"exec" argument must not be empty.');
        }
        parent::initialize($input, $output);
        $this->processHelper = $this->getHelper('process');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getOption('env-path')), getcwd());
        try {
            $this->setVar('env', new Environment($proposedPath));
        } catch (LogicException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $command = $input->getArgument('exec');

        // Run the command
        $failureCode = $this->runDockerCommand(implode(' ', $command), $input->getOption('as-root'));
        if ($failureCode) {
            return $failureCode;
        }

        if (!$this->isSubCommand) {
            $io->success('Command successfully run in docker container.');
        }
        return Command::SUCCESS;
    }

    protected function runDockerCommand(string $command, bool $asRoot = false): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $dockerService = new DockerService($this->getVar('env'), $this->processHelper, $io);
        $io->writeln(self::STEP_STYLE . "Running command in docker container: '$command'</>");

        $success = $dockerService->exec($command, $asRoot);
        if (!$success) {
            $io->error('Problem occured while running command in docker container.');
            return Command::FAILURE;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Provides a helper for running commands in docker.
        HELP);
        $this->addArgument(
            'exec',
            InputArgument::IS_ARRAY,
            'The command to run in the docker container.',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
        $this->addOption(
            'as-root',
            'r',
            InputOption::VALUE_NEGATABLE,
            'Whether to run the command as the root user.',
            false
        );
    }
}
