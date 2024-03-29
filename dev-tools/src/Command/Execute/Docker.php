<?php

namespace DevTools\Command\Execute;

use DevTools\Command\BaseCommand;
use DevTools\Utility\DockerService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Docker extends BaseCommand
{
    protected static $defaultName = 'execute:docker';

    protected static $defaultDescription = 'Run commands in the webserver docker container.';

    protected static bool $notifyOnCompletion = true;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet') && !$input->getOption('verbose')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        if (empty($input->getArgument('exec'))) {
            throw new RuntimeException('"exec" argument must not be empty.');
        }
        parent::initialize($input, $output);
        if (!in_array($input->getOption('container'), ['database', 'webserver'])) {
            /** @var SymfonyStyle $io */
            $io = $this->getVar('io');
            $io->warning('"container" option is non-standard - the container may not exist.');
        }
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $command = $input->getArgument('exec');

        switch ($input->getOption('container')) {
            case 'database':
                $container = DockerService::CONTAINER_DATABASE;
                break;
            case 'webserver':
                $container = DockerService::CONTAINER_WEBSERVER;
                break;
            default:
                $container = $input->getOption('container');
                if (!str_starts_with($container, '_')) {
                    $container = '_' . $container;
                }
        }

        // Run the command
        $failureCode = $this->runDockerCommand(
            implode(' ', $command),
            $this->getVar('output'),
            $input->getOption('as-root'),
            interactive: $input->getOption('interactive'),
            container: $container
        );
        if ($failureCode) {
            return $failureCode;
        }

        if (!$this->isSubCommand) {
            $io->success('Command successfully run in docker container.');
        }
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['docker']);

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
        $this->addOption(
            'interactive',
            'i',
            InputOption::VALUE_NEGATABLE,
            'Whether the docker command should be interactive.',
            false
        );
        $this->addOption(
            'container',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Which container to run the command in. Must be one of "database" or "webserver".',
            'webserver'
        );
    }
}
