<?php

namespace DevTools\Command\Execute;

use DevTools\Command\BaseCommand;
use DevTools\Utility\Environment;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

class Sake extends BaseCommand
{
    protected static $defaultName = 'execute:sake';

    protected static $defaultDescription = 'Run sake commands in the webserver docker container.';

    protected static bool $notifyOnCompletion = true;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        if (empty($input->getArgument('task'))) {
            throw new RuntimeException('"task" argument must not be empty.');
        }
        parent::initialize($input, $output);
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

        $command = array_merge(['vendor/bin/sake'], $input->getArgument('task'));

        // Run the command
        $failureCode = $this->runDockerCommand(implode(' ', $command), $this->getVar('output'));
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
        $this->setAliases(['sake']);

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
