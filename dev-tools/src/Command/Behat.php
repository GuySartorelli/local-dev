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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class Behat extends BaseCommand
{
    protected static $defaultName = 'behat';

    protected static $defaultDescription = 'Run behat in the webserver docker container.';

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (empty($input->getArgument('modules'))) {
            throw new RuntimeException('"modules" argument must not be empty.');
        }
        parent::initialize($input, $output);
        $this->processHelper = $this->getHelper('process');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getOption('env-path')), getcwd());
        try {
            $env = new Environment($proposedPath);
            $this->setVar('env', $env);
        } catch (LogicException $e) {
            $output->writeln($e->getMessage());
            return Command::INVALID;
        }

        $output->writeln('Clearing artifacts dir');
        $artifactsPath = Path::join($env->getWebRoot(), 'artifacts');
        $fileSystem = new Filesystem();
        if ($fileSystem->exists($artifactsPath)) {
            $fileSystem->remove($artifactsPath);
        }
        $fileSystem->mkdir($artifactsPath);

        $output->writeln('Killing any old chromedriver instances.');
        $failureCode = $this->runDockerCommand('DRIVER_PID=$(pgrep chromedriver); if [ -n "$DRIVER_PID" ]; then kill -15 $DRIVER_PID > /dev/null; fi');
        if ($failureCode) {
            return $failureCode;
        }

        $output->writeln('Starting up chromedriver and runnig behat.');
        $suites = implode(' ', $input->getArgument('modules'));
        $tags = $input->getOption('tags');
        if ($tags) {
            $tags = "--tags=$tags";
        }
        $failureCode = $this->runDockerCommand("$(chromedriver --log-path=artifacts/chromedriver.log --log-level=INFO > /dev/null 2>&1 &) && vendor/bin/behat $suites $tags");
        if ($failureCode) {
            return $failureCode;
        }

        $output->writeln('Behat ran successfully.');
        return Command::SUCCESS;
    }

    protected function runDockerCommand(string $command): int|bool
    {
        $output = $this->getVar('output');
        $dockerService = new DockerService($this->getVar('env'), $this->processHelper, $output);
        $output->writeln("Running command in docker container: '$command'");

        $success = $dockerService->exec($command);
        if (!$success) {
            $output->writeln('ERROR: Problem occured while running command in docker container.');
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
            'modules',
            InputArgument::IS_ARRAY,
            'The modules to run behat for.',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
        $this->addOption(
            'tags',
            't',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            'gsat'
        );
    }
}