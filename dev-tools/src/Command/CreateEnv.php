<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class CreateEnv extends Command
{
    use UsesPassword;

    protected static $defaultName = 'create-env';

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

    private Filesystem $filesystem;

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $password = $this->getPassword($input, $output);
        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');
        $output->setVerbosity(Output::VERBOSITY_DEBUG);
        // TODO refactor this into smaller methods
        $this->normaliseRecipe($input);
        $suffix = Config::getNextAvailableSuffix();
        $ipAddress = '10.0.' . (int)$suffix .'.50';
        Config::takeSuffix($suffix);

        // Prepare environment dir
        $envName = $this->getEnvName($input) . '_' . $suffix;
        $envPath = Path::join($input->getOption('project-path'), $envName);
        if (is_dir($envPath)) {
            $output->writeln('ERROR: Environment path already exists: ' . $envPath);
            return Command::FAILURE;
        }
        $output->writeln("Making directory '$envPath'");
        try {
            $logsDir = Path::join($envPath, 'logs');
            $this->filesystem->mkdir([$envPath, $logsDir, Path::join($logsDir, 'apache2')]);
        } catch (IOException $e) {
            $output->writeln('ERROR: Couldn\'t create environment directory: ' . $e->getMessage());
            Config::releaseSuffix($suffix);
            return Command::FAILURE;
        }

        // Prepare composer command
        $composerArgs = [
            '--no-interaction',
            ...explode(' ', $input->getOption('composer-args') ?? '')
        ];
        if ($input->getOption('prefer-source')) {
            $composerArgs[] = '--prefer-source';
        }
        $webDir = Path::join($envPath, 'www');
        $composerCommand = [
            'composer',
            'create-project',
            $input->getOption('recipe') . ':' . $input->getOption('constraint'),
            $webDir,
            ...$composerArgs,
        ];

        // Run composer command
        $process = new Process($composerCommand);
        $process->setTimeout(null);
        $result = $processHelper->run($output, $process);
        if (!$result->isSuccessful()) {
            // TODO revert to original state.
            $output->writeln('ERROR: Couldn\'t create composer project.');
            Config::releaseSuffix($suffix);
            $this->filesystem->remove($envPath);
            return Command::FAILURE;
        }

        $hostname = $envName . '.' . $input->getOption('host-suffix');

        try {
            // Setup environment-specific web files
            $output->writeln('Preparing extra webroot files');
            $this->filesystem->mirror(Path::join(Config::getBaseDir(), 'webroot'), $webDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                '.env',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($webDir, $file);
                $this->replacePlaceholders($filePath, $envName, $envPath, $suffix, $hostname, $ipAddress);
            }

            // Setup docker files
            $dockerDir = Path::join($envPath, 'docker-' . $suffix);
            $output->writeln('Preparing docker directory');
            $copyFrom = Path::join(Config::getBaseDir(), 'docker/');
            $this->filesystem->mirror($copyFrom, $dockerDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                'docker_apache_default',
                'docker-compose.yml',
                'entrypoint',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($dockerDir, $file);
                $this->replacePlaceholders($filePath, $envName, $envPath, $suffix, $hostname, $ipAddress);
            }
        } catch (IOException $e) {
            $output->writeln('ERROR: Couldn\'t set up docker or webroot files: ' . $e->getMessage());
            $this->filesystem->remove($envPath);
            Config::releaseSuffix($suffix);
            return Command::FAILURE;
        }

        // Start docker image
        $startCommand = [
            'docker',
            'compose',
            'up',
            '--build',
            '-d',
        ];
        $process = new Process($startCommand);
        $process->setTimeout(null);
        $originalDir = getcwd();
        chdir($dockerDir);
        $result = $processHelper->run($output, $process);
        chdir($originalDir ?: $envPath);
        if (!$result->isSuccessful()) {
            // TODO Revert??
            $output->writeln('ERROR: Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        $output->writeln('Updating hosts file');
        $success = $this->updateHosts($output, $hostname, $ipAddress, $password);
        if (!$success) {
            $output->writeln('ERROR: Couldn\'t add to hosts entry.');
            return Command::FAILURE;
        }

        $output->writeln('Completed successfully. Build the db by going to http://' . $hostname . '/dev/build');
        return Command::SUCCESS;
    }

    protected function updateHosts(OutputInterface $output, string $hostname, string $ipAddress, string $password): bool
    {
        // Update hosts file
        // TODO verify this entry hasn't already been added
        $hostsEntry = "$ipAddress    $hostname";
        exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsEntry . '" >> /etc/hosts\' 2> /dev/null', $execOut, $hadError);
        if ($execOut) {
            $output->writeln($execOut);
        }
        return !$hadError;
    }

    /**
     * Replace context-specific placeholders with the relevant bits
     *
     * @throws IOException
     */
    protected function replacePlaceholders(string $filePath, string $envName, string $envPath, string $suffix, string $hostname, string $ipAddress): void
    {
        $ipParts = explode('.', $ipAddress);
        array_pop($ipParts);
        $ipPrefix = implode('.', $ipParts);
        $hostParts = explode('.', $hostname);
        $content = file_get_contents($filePath);
        $content = str_replace('${PROJECT_NAME}', $envName, $content);
        $content = str_replace('${SUFFIX}', $suffix, $content);
        $content = str_replace('${HOST_NAME}', $hostname, $content);
        $content = str_replace('${HOST_SUFFIX}', array_pop($hostParts), $content);
        $content = str_replace('${PROJECT_DIR}', $envPath, $content);
        $content = str_replace('${IP_PREFIX}', $ipPrefix, $content);
        $this->filesystem->dumpFile($filePath, $content);
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    protected function normaliseRecipe(InputInterface $input): void
    {
        $recipe = $input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
    }

    /**
     * Gets the environment name based on the input arguments and options
     */
    protected function getEnvName(InputInterface $input): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        // Use env name if defined
        if ($name = $input->getArgument('env-name')) {
            // TODO validate against duplicate environment names
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
        $this->filesystem = new Filesystem();

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
        $this->addOption(
            'host-suffix',
            's',
            InputOption::VALUE_REQUIRED,
            'The suffix for the site host.',
            Config::getEnv('DT_DEFAULT_HOST_SUFFIX')
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
            'null',
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
