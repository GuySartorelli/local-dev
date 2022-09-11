<?php

namespace DevTools\Command\Database;

use DevTools\Command\BaseCommand;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Dump extends BaseCommand
{
    protected static $defaultName = 'database:dump';

    protected static $defaultDescription = 'Dump the database to a file.';

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $dumpDir = Path::makeAbsolute(Path::canonicalize($input->getArgument('dump-dir')), getcwd());
        $fileSystem = new Filesystem();
        if (!$fileSystem->exists($dumpDir)) {
            throw new LogicException("dump-dir '$dumpDir' does not exist.");
        }
        $this->setVar('dump-dir', $dumpDir);
        parent::initialize($input, $output);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $env */
        $env = $this->getVar('env');

        $io->writeln(self::STEP_STYLE . 'Dumping database.</>');
        $filename = $input->getArgument('filename') ?: $env->getName() . '.' . date('Y-m-d\THis');
        $filePath = "/tmp/$filename.sql.gz";
        $failureCode = $this->runDockerCommand(
            "mysqldump -u root --password=root SS_mysite | gzip > $filePath",
            $this->getVar('output'),
            suppressMessages: !$io->isVerbose(),
            container: DockerService::CONTAINER_DATABASE
        );
        if ($failureCode) {
            return $failureCode;
        }

        $io->writeln(self::STEP_STYLE . 'Copying database to host.</>');
        $dockerService = new DockerService($env, $this->getVar('output'));
        $dumpDir = Path::canonicalize($input->getArgument('dump-dir'));
        $success = $dockerService->copyFromContainer(
            DockerService::CONTAINER_DATABASE,
            $filePath,
            Path::join($dumpDir, $filename . '.sql.gz')
        );
        if (!$success) {
            $io->error('Problem occured while copying file from docker container.');
            return Command::FAILURE;
        }

        $io->writeln(self::STEP_STYLE . 'Cleaning up inside container.</>');
        $failureCode = $this->runDockerCommand(
            "rm $filePath",
            $this->getVar('output'),
            suppressMessages: !$io->isVerbose(),
            container: DockerService::CONTAINER_DATABASE
        );
        if ($failureCode) {
            return $failureCode;
        }

        $io->success('Database dumped successfully.');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['db-dump']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Provides a helper for running commands in docker.
        HELP);
        $this->addArgument(
            'dump-dir',
            InputArgument::REQUIRED,
            'The path for a directory where the database should be dumped to.',
        );
        $this->addArgument(
            'filename',
            InputArgument::OPTIONAL,
            'The name for the dumped database file (minus extension). Default is the project name and the current datetime.',
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to dump.',
            './'
        );
    }
}
