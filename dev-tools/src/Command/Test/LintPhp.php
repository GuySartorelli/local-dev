<?php

namespace DevTools\Command\Test;

use DevTools\Command\BaseCommand;
use DevTools\Command\FindsModule;
use DevTools\Utility\Environment;
use DevTools\Utility\ProcessOutputter;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class LintPhp extends BaseCommand
{
    use FindsModule;

    protected static bool $environmentOptional = true;

    protected static $defaultName = 'test:lint-php';

    protected static $defaultDescription = 'Lint PHP with codesniffer. Can be used in or out of environments.';

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet') && !$input->getOption('verbose')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        parent::initialize($input, $output);
        $this->normaliseInput($input);
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

        $isFix = $input->getOption('fix');
        $standard = $input->getOption('standard');
        $lintPaths = $input->getArgument('lint-path');
        $command = 'vendor/bin/' . ($isFix ? 'phpcbf' : 'phpcs');

        if (!$env->exists()) {
            chdir(Path::join($this->findVendorDir(), '../'));
            if (!is_executable($command)) {
                throw new LogicException("'$command' doesn't exist or is not executable.");
            }
        }

        $command = $command . ' ' . implode(' ', $lintPaths);
        if ($standard) {
            $command .= ' --standard=' . $standard;
        }

        $io->writeln(self::STEP_STYLE . ($isFix ? 'Fixing' : 'Linting') . ' PHP</>');
        if ($env->exists()) {
            $failureCode = $this->runDockerCommand($command, $this->getVar('output'));
        } else {
            $io->writeln(self::STEP_STYLE . "Running command '$command'</>");
            $process = new Process(explode(' ', $command));
            $outputter = new ProcessOutputter($output);
            $outputter->startCommand();
            $process = $this->getHelper('process')->run(new NullOutput(), $process, callback: [$outputter, 'output']);
            $outputter->endCommand();
            $failureCode = $process->isSuccessful() ? false : Command::FAILURE;
        }
        if ($failureCode) {
            return $failureCode;
        }

        $io->success('Successfully ' . ($isFix ? 'Fixed' : 'Linted') . ' PHP.');
        return Command::SUCCESS;
    }

    private function normaliseInput(InputInterface $input): void
    {
        /** @var Environment $env */
        $env = $this->getVar('env');
        $paths = (array)$input->getArgument('lint-path');

        $this->normaliseModuleInput($input);

        $module = $input->getOption('module');
        $input->setOption('module', $module);
        if ($module) {
            $moduleDir = $this->getModuleDir($module);
            // TODO see if we can get the module dir in the even we don't have an env
            $absoluteModuleDir = Path::join($env->getWebRoot(), $moduleDir);
            // e.g. "dynamodb" would now be "silverstripe/dynamodb"
            $input->setOption('module', str_replace('vendor/', '', $moduleDir));
            // Make lint paths relative to the module path
            // or use the module path itself as the lint path if there weren't any passed in
            if (empty($paths)) {
                if (is_dir(Path::join($absoluteModuleDir, 'src'))) {
                    $paths[] = Path::join($moduleDir, 'src');
                }
                if (is_dir(Path::join($absoluteModuleDir, 'code'))) {
                    $paths[] = Path::join($moduleDir, 'code');
                }
                if (is_dir(Path::join($absoluteModuleDir, 'tests'))) {
                    $paths[] = Path::join($moduleDir, 'tests');
                }
                if (file_exists(Path::join($absoluteModuleDir, '_config.php'))) {
                    $paths[] = Path::join($moduleDir, '_config.php');
                }

                if (empty($paths)) {
                    throw new LogicException("Couldn't find appropriate default lint paths for '$module'");
                }
            } else {
                $paths = array_map(fn($path) => Path::join($moduleDir, $path), $paths);
            }

            if (!$input->getOption('standard')) {
                if (file_exists(Path::join($absoluteModuleDir, 'phpcs.xml.dist'))) {
                    $input->setOption('standard', Path::join($moduleDir, 'phpcs.xml.dist'));
                } elseif (file_exists(Path::join($absoluteModuleDir, 'phpcs.xml'))) {
                    $input->setOption('standard', Path::join($moduleDir, 'phpcs.xml'));
                }
            }
        }

        $input->setArgument('lint-path', $paths);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['lint-php', 'lint']);

        $desc = static::$defaultDescription;
        $this->setHelp($desc);
        $this->addArgument(
            'lint-path',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'A path(s) to lint. If run inside an environment, this is relative to the project root for the env. If module is defined, this is relative to the module. If not set, there are a series of default paths that will be linted.'
        );
        $this->addOption(
            'env-path',
            null,
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment, if any.',
            './'
        );
        $this->addOption(
            'fix',
            'f',
            InputOption::VALUE_NEGATABLE,
            'Whether to run phpcbf instead of phpcs to fix any auto-fixable issues.'
        );
        $this->addOption(
            'standard',
            's',
            InputOption::VALUE_OPTIONAL,
            'A specific standard to lint against. Defaults to the standard defined in the module, if any.'
        );
        $this->addOption(
            'module',
            'm',
            InputOption::VALUE_OPTIONAL,
            'A module to run linting against. Defaults to the module of your current working dir, if any.'
        );
    }
}
