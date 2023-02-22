<?php

namespace DevTools\Command\Test;

use DevTools\Command\BaseCommand;
use DevTools\Command\FindsModule;
use DevTools\Utility\Environment;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Phpunit extends BaseCommand
{
    use FindsModule;

    protected static $defaultName = 'test:phpunit';

    protected static $defaultDescription = 'Run PHPUnit in the webserver docker container.';

    protected static bool $notifyOnCompletion = true;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet') && !$input->getOption('verbose')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        $this->normaliseModuleInput($input);
        if (empty($input->getOption('module')) && empty($input->getOption('test-class'))) {
            throw new RuntimeException('At least one of "module" or "test-class" must be passed in.');
        }
        parent::initialize($input, $output);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $env */
        $env = $this->getVar('env');

        $io->writeln(self::STEP_STYLE . 'Finding directory to run tests in, or exact test file.</>');
        $arg = $this->getPhpunitArg();

        $io->writeln(self::STEP_STYLE . 'Removing old cache.</>');
        $cachePath = Path::join($env->getWebRoot(), 'silverstripe-cache');
        $fileSystem = new Filesystem();
        if ($fileSystem->exists($cachePath)) {
            $fileSystem->remove($cachePath);
        }
        $fileSystem->mkdir($cachePath);

        $command = "vendor/bin/phpunit $arg";
        if ($testMethod = $input->getOption('test-method')) {
            $command .= " --filter=$testMethod";
        }

        $module = (string)$input->getOption('module');
        if ($module === 'dynamodb' || $module === 'silverstripe/dynamodb') {
            $command .= ' --stderr';
        }

        $io->writeln(self::STEP_STYLE . 'Running PHPUnit.</>');
        $failureCode = $this->runDockerCommand($command, $this->getVar('output'));
        if ($failureCode) {
            return $failureCode;
        }

        $io->success('PHPUnit ran successfully.');
        return Command::SUCCESS;
    }

    private function getPhpunitArg(): string
    {
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        /** @var Environment $env */
        $env = $this->getVar('env');

        $testClass = $input->getOption('test-class');
        $module = $input->getOption('module');
        $searchDir = 'vendor';

        // Search for the directory the module is in
        if ($module) {
            $searchDir = $this->getModuleDir($module);
            if (!$testClass) {
                return $searchDir;
            }
        }

        // TODO: Make (or reuse an existing) recursive function instead of duplicating logic here and in FindsModule

        // We need to find the file for this test class. We'll assume PSR-4 compliance.
        // Recursively check everything from the search dir down until we either find it or fail to find it
        $candidates = [Path::makeAbsolute($searchDir, $env->getWebRoot())];
        $checked = [];
        while (!empty($candidates)) {
            $candidate = array_shift($candidates);
            $checked[$candidate] = null;
            foreach (scandir($candidate) as $toCheck) {
                if ($toCheck === '.' || $toCheck === '..') {
                    continue;
                }

                $currentPath = Path::join($candidate, $toCheck);

                // If this file is the right file, we found it!
                if (!is_dir($currentPath) && $toCheck === $testClass . '.php') {
                    return Path::makeRelative($currentPath, $env->getWebRoot());
                }

                // If this is a directory, we need to check it too.
                if (is_dir($currentPath) && !array_key_exists($currentPath, $checked)) {
                    $candidates[] = $currentPath;
                }
            }
        }
        // If we get to this point, we weren't able to find that test class.
        throw new InvalidArgumentException("Test class '$testClass' was not found.");
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['phpunit']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Provides a helper for running commands in docker.
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to run tests in.',
            './'
        );
        $this->addOption(
            'module',
            'm',
            InputOption::VALUE_OPTIONAL,
            'A specific module for which to run tests. Can be used to narrow the search for test classes, or used without "test-class" to run all tests for that module.'
        );
        $this->addOption(
            'test-class',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Tags for specific tests to be run. Leave blank (i.e. --tags=) to run all tests.'
        );
        $this->addOption(
            'test-method',
            't',
            InputOption::VALUE_OPTIONAL,
            'Run specific test method(s).'
        );
    }
}
