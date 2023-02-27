<?php

namespace DevTools\Command\CLI;

use DevTools\Command\BaseCommand;
use DevTools\Utility\ProcessOutputter;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class ComposerInstallInDump extends BaseCommand
{
    protected static $defaultName = 'cli:composer-in-dump';

    protected static $defaultDescription = 'Install a module from the dump dir.';

    protected static bool $hasEnvironment = false;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet') && !$input->getOption('verbose')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        if (!is_file('./composer.json')) {
            throw new RuntimeException('No composer.json file!');
        }
        if (is_file('./composer.lock')) {
            throw new RuntimeException('Already installed. Delete lock file to try again.');
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
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        $rootVersion = $input->getArgument('root-version');

        $io->writeln(self::STEP_STYLE . "Installing with root version '$rootVersion'</>");
        $command = 'composer install';
        if ($input->getOption('prefer-source')) {
            $command .= ' --prefer-source';
        }

        $io->writeln(self::STEP_STYLE . "Running command '$command'</>");
        $process = new Process(explode(' ', $command), env: ['COMPOSER_ROOT_VERSION' => $rootVersion]);
        $process->setTty(true);
        $outputter = new ProcessOutputter($output);
        $outputter->startCommand();
        $process = $this->getHelper('process')->run(new NullOutput(), $process, callback: [$outputter, 'output']);
        $outputter->endCommand();
        $failureCode = $process->isSuccessful() ? false : Command::FAILURE;
        if ($failureCode) {
            return $failureCode;
        }

        $io->success('Installed successfully');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['composer-in-dump']);

        $desc = static::$defaultDescription;
        $this->setHelp($desc);
        $this->addArgument(
            'root-version',
            InputArgument::REQUIRED,
            'The version to use in the COMPOSER_ROOT_VERSION env var.'
        );
        $this->addOption(
            'prefer-source',
            's',
            InputOption::VALUE_NEGATABLE,
            'Use --prefer-source in composer install command.',
            false
        );
    }
}
