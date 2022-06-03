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
use Symfony\Component\Filesystem\Path;

class Sake extends BaseCommand
{
    protected static $defaultName = 'sake';

    protected static $defaultDescription = 'Run sake commands in the webserver docker container.';

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (empty($input->getArgument('task'))) {
            throw new RuntimeException('"task" argument must not be empty.');
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
            $this->setVar('env', new Environment($proposedPath));
        } catch (LogicException $e) {
            $output->writeln($e->getMessage());
            return Command::INVALID;
        }

        $command = array_merge(['vendor/bin/sake'], $input->getArgument('task'));

        // Run the command
        // TODO either subclass the Docker command or call that command from here to make code more DRY
        $failureCode = $this->runDockerCommand(implode(' ', $command));
        if ($failureCode) {
            return $failureCode;
        }

        $output->writeln("Command successfully run in docker container.");
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
        Run commands in the Silverstripe command line utility "sake".
        HELP);
        $this->addArgument(
            'task',
            InputArgument::IS_ARRAY,
            'The sake command (e.g. dev/build flush=1).',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
    }

}