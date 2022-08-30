<?php

namespace DevTools\Command;

use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Behat extends BaseCommand
{
    protected static $defaultName = 'behat';

    protected static $defaultDescription = 'Run behat in the webserver docker container.';

    protected static bool $notifyOnCompletion = true;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        if (empty($input->getArgument('modules'))) {
            throw new RuntimeException('"modules" argument must not be empty.');
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
            $env = new Environment($proposedPath);
            $this->setVar('env', $env);
        } catch (LogicException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $io->writeln(self::STEP_STYLE . 'Clearing artifacts dir</>');
        $artifactsPath = Path::join($env->getWebRoot(), 'artifacts');
        $fileSystem = new Filesystem();
        if ($fileSystem->exists($artifactsPath)) {
            $fileSystem->remove($artifactsPath);
        }
        $fileSystem->mkdir($artifactsPath);

        $io->writeln(self::STEP_STYLE . 'Killing any old chromedriver instances.</>');
        $failureCode = $this->runDockerCommand('DRIVER_PID=$(pgrep chromedriver); if [ -n "$DRIVER_PID" ]; then kill -15 $DRIVER_PID > /dev/null; fi', $this->getVar('output'));
        if ($failureCode) {
            return $failureCode;
        }

        $io->writeln(self::STEP_STYLE . 'Starting up chromedriver and running behat.</>');
        $suites = implode(' ', $input->getArgument('modules'));
        $tags = $input->getOption('tags');
        if ($tags) {
            $tags = "--tags=$tags";
        }
        $failureCode = $this->runDockerCommand("$(chromedriver --log-path=artifacts/chromedriver.log --log-level=INFO > /dev/null 2>&1 &) && vendor/bin/behat $suites $tags", $this->getVar('output'));
        if ($failureCode) {
            return $failureCode;
        }

        $io->success('Behat ran successfully.');
        return Command::SUCCESS;
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
