<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\ProcessOutputter;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class CreateEnv extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'up';

    protected static $defaultDescription = 'Sets up a new docker environment with a webhost.';

    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'installer' => 'silverstripe/installer',
    ];

    /**
     * Characters that cannot be used for an environment name
     */
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;';

    protected static bool $notifyOnCompletion = true;

    protected Filesystem $filesystem;

    protected ProcessHelper $processHelper;

    protected ProcessOutputter $outputter;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->processHelper = $this->getHelper('process');
        $this->outputter = new ProcessOutputter($output);
        $this->normaliseRecipe();
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $suffix = Config::getNextAvailableSuffix();
        $envName = $this->getEnvName() . '_' . $suffix;
        $this->setVar('env', $environment = new Environment(Path::join($input->getOption('project-path'), $envName), true));

        // Take this first, so that if there's an uncaught exception it doesn't prevent creating
        // the next environment.
        Config::takeSuffix($suffix);

        // Raw environment dir setup
        $failureCode = $this->prepareEnvDir();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            return $failureCode;
        }

        // Create webdir so that docker has it for use in its volume
        $this->filesystem->mkdir($environment->getWebRoot());

        // Docker stuff
        $failureCode = $this->spinUpDocker();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            $this->filesystem->remove($environment->getBaseDir());
            return $failureCode;
        }

        // Prepare webroot
        $failureCode = $this->prepareWebRoot();
        if ($failureCode) {
            return $failureCode;
        }

        // Update hosts file
        $failureCode = $this->updateHosts();
        if ($failureCode) {
            return $failureCode;
        }

        // Run dev/build
        $this->buildDatabase();

        $io->success('Completed successfully.');
        $url = $environment->getBaseURL();
        $io->writeln(self::STEP_STYLE . "Navigate to <href=$url>$url</></>");
        return Command::SUCCESS;
    }

    protected function buildDatabase(): bool
    {
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');

        if (!str_contains($input->getOption('composer-args') ?? '', '--no-install')) {
            // run vendor/bin/sake dev/build in the docker container
            $io->writeln(self::STEP_STYLE . 'Building database.</>');
            /** @var BaseCommand $sake */
            $sake = $this->getApplication()->find('sake');
            $sake->setIsSubCommand(true);
            $args = [
                '--env-path' => $environment->getBaseDir(),
                'task' => ['dev/build'],
            ];
            $sakeReturn = $sake->run(new ArrayInput($args), $this->getVar('output'));
        } else {
            $sakeReturn = Command::INVALID;
        }
        if ($sakeReturn !== Command::SUCCESS) {
            $url = "{$environment->getBaseURL()}/dev/build>";
            $io->warning([
                'Unable to build the db.',
                "Build the db by going to <href=$url>$url</>",
                'Or run: dev-tools sake dev/build -p ' . $environment->getBaseDir(),
            ]);
        }
        return $sakeReturn === Command::SUCCESS;
    }

    protected function prepareEnvDir(): bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $envPath = $environment->getBaseDir();
        if (is_dir($envPath)) {
            $io->error('Environment path already exists: ' . $envPath);
            return Command::INVALID;
        }
        $io->writeln(self::STEP_STYLE . "Making directory '$envPath'</>");
        try {
            $logsDir = Path::join($envPath, 'logs');
            $this->filesystem->mkdir([$envPath, $logsDir, Path::join($logsDir, 'apache2')]);
        } catch (IOException $e) {
            $io->error('Couldn\'t create environment directory: ' . $e->getMessage());
            return Command::FAILURE;
        }
        return false;
    }

    protected function prepareWebRoot(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $webDir = $environment->getWebRoot();

        $failureCode = $this->buildComposerProject();
        if ($failureCode) {
            return $failureCode;
        }
        try {
            // Setup environment-specific web files
            $io->writeln(self::STEP_STYLE . 'Preparing extra webroot files</>');
            $this->filesystem->mirror(Path::join(Config::getCopyDir(), 'webroot'), $webDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                '.env',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($webDir, $file);
                if (!$this->filesystem->exists($filePath)) {
                    $io->error("File '$file' doesn't exist!");
                    return Command::FAILURE;
                }
                $this->replacePlaceholders($filePath);
            }
        } catch (IOException $e) {
            $io->error('Couldn\'t set up webroot files: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return false;
    }

    protected function buildComposerProject(): int|bool
    {
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $io->writeln(self::STEP_STYLE . 'Building composer project</>');

        // Prepare composer command
        $composerArgs = [
            '--no-interaction',
            ...explode(' ', $input->getOption('composer-args') ?? '')
        ];
        if ($input->getOption('prefer-source')) {
            $composerArgs[] = '--prefer-source';
        }
        $composerCommand = [
            'composer',
            'create-project',
            $input->getOption('recipe') . ':' . $input->getOption('constraint'),
            $environment->getWebRoot(),
            ...$composerArgs,
        ];

        // Run composer command
        $process = new Process($composerCommand);
        $process->setTimeout(null);
        $result = $this->processHelper->run(new NullOutput(), $process, null, [$this->outputter, 'output']);
        if (!$result->isSuccessful()) {
            $io->error('Couldn\'t create composer project.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function spinUpDocker(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $io->writeln(self::STEP_STYLE . 'Spinning up docker</>');

        try {
            // Setup docker files
            $dockerDir = $environment->getDockerDir();
            $io->writeln(self::STEP_STYLE . 'Preparing docker directory</>');
            $copyFrom = Path::join(Config::getCopyDir(), 'docker/');
            $this->filesystem->mirror($copyFrom, $dockerDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                'docker_apache_default',
                'docker-compose.yml',
                'entrypoint',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($dockerDir, $file);
                $this->replacePlaceholders($filePath);
            }
        } catch (IOException $e) {
            $io->error('Couldn\'t set up docker or webroot files: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $dockerService = new DockerService($environment, $this->processHelper, $io);
        $success = $dockerService->up();
        if (!$success) {
            $io->error('Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function updateHosts(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $hostname = $environment->getHostName();
        $hostsEntry = "{$environment->getIpAddress()}    $hostname";
        $hadError = true;

        $io->writeln(self::STEP_STYLE . 'Updating hosts file</>');
        if ($password = $this->getVar('password')) {
            // Update hosts file
            exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsEntry . '" >> /etc/hosts\' 2> /dev/null', $execOut, $hadError);
            if ($execOut) {
                $io->writeln($execOut);
            }
        }
        if ($hadError) {
            $io->warning([
                'Couldn\'t add to hosts entry. Please manually add the following line to /etc/hosts',
                $hostsEntry,
            ]);
            return Command::FAILURE;
        }
        return false;
    }

    /**
     * Replace context-specific placeholders with the relevant bits
     *
     * @throws IOException
     */
    protected function replacePlaceholders(string $filePath): void
    {
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $hostname = $environment->getHostName();
        $ipParts = explode('.', $environment->getIpAddress());
        array_pop($ipParts);
        $ipPrefix = implode('.', $ipParts);
        $hostParts = explode('.', $hostname);

        $content = file_get_contents($filePath);
        $content = str_replace('${PROJECT_NAME}', $environment->getName(), $content);
        $content = str_replace('${SUFFIX}', $environment->getSuffix(), $content);
        $content = str_replace('${HOST_NAME}', $hostname, $content);
        $content = str_replace('${HOST_SUFFIX}', array_pop($hostParts), $content);
        $content = str_replace('${IP_PREFIX}', $ipPrefix, $content);

        $this->filesystem->dumpFile($filePath, $content);
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    protected function normaliseRecipe(): void
    {
        $input = $this->getVar('input');
        $recipe = $input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
    }

    /**
     * Gets the environment name based on the input arguments and options
     */
    protected function getEnvName(): string
    {
        $input = $this->getVar('input');
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        // Use env name if defined
        if ($name = $input->getArgument('env-name')) {
            if (preg_match($invalidCharsRegex, $name)) {
                throw new LogicException(
                    'env-name must not contain the following characters: ' . static::$invalidEnvNameChars
                );
            }
            return $name;
        }

        // Normalise recipe by replacing 'invalid' chars with hyphen
        $recipeParts = explode('-', preg_replace($invalidCharsRegex, '-', $input->getOption('recipe')));
        $recipe = end($recipeParts);
        // Normalise constraints to remove stability flags
        $constraint = preg_replace('/^(dev-|v(?=\d))|-dev|(#|@).*?$/', '', $input->getOption('constraint'));
        $constraint = preg_replace($invalidCharsRegex, '-', trim($constraint, '~^'));
        return $recipe . '_' . $constraint;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Creates a new environment in the project path using the env name and a unique integer value.
        The integer value is used to ensure directories are unique, as well as for a port for the database server.
        The environment directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'env-name',
            InputArgument::OPTIONAL,
            'The name of the environment. This will be used for the directory and the webhost. '
            . 'Defaults to a name generated based on the recipe and constraint. '
            . 'Must not contain the following characters: ' . static::$invalidEnvNameChars
        );
        $this->addOption(
            'project-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The path of the parent directory where the env directory will be created.',
            Config::getEnv('DT_DEFAULT_PROJECTS_PATH')
        );
        $recipeDescription = '';
        foreach (static::$recipeShortcuts as $shortcut => $recipe) {
            $recipeDescription .= "\"$shortcut\" ($recipe), ";
        }
        $this->addOption(
            'recipe',
            'r',
            InputOption::VALUE_REQUIRED,
            'The recipe to install. Options: ' . $recipeDescription . 'custom value (e.g. "silverstripe/recipe-cms")',
            Config::getEnv('DT_DEFAULT_INSTALL_RECIPE')
        );
        $this->addOption(
            'constraint',
            'c',
            InputOption::VALUE_REQUIRED,
            'The version constraint to use for the installed recipe.',
            Config::getEnv('DT_DEFAULT_INSTALL_VERSION')
        );
        $this->addOption(
            'composer-args',
            'a',
            InputOption::VALUE_OPTIONAL,
            'Any additional arguments to be passed to the composer create-project command.',
            ''
        );
        $this->addOption(
            'prefer-source',
            null,
            InputOption::VALUE_NEGATABLE,
            'Whether to use --prefer-source for composer commands.',
            Config::getEnv('DT_PREFER_SOURCE')
        );
    }
}
