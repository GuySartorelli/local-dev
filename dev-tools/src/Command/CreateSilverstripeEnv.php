<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class CreateSilverstripeEnv extends Command
{
    protected static $defaultName = 'create-env';

    protected static $defaultDescription = 'Sets up a new Silverstripe docker environment with a webhost.';

    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'installer' => 'silverstripe/installer',
    ];

    /**
     * Characters that cannot be used for a project name
     */
    protected static string $invalidProjectNameChars = ' !@#$%^&*()"\',.<>/?:;';

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');
        $output->setVerbosity(Output::VERBOSITY_DEBUG);
        // TODO refactor this into smaller methods
        $this->normaliseRecipe($input);
        $suffix = Config::getNextAvailableSuffix();
        $ipAddress = '10.0.' . (int)$suffix .'.50';
        Config::takeSuffix($suffix);

        // Prepare project dir
        $projectName = $this->getProjectName($input) . '_' . $suffix;
        $projectPath = Path::join($input->getOption('project-path'), $projectName);
        if (is_dir($projectPath)) {
            $output->writeln('ERROR: Project path already exists: ' . $projectPath);
            return Command::FAILURE;
        }
        $output->writeln("Making directory '$projectPath'");
        $success = mkdir($projectPath);
        if (!$success) {
            // TODO revert to original state
            $output->writeln('ERROR: Couldn\'t create project directory.');
            Config::releaseSuffix($suffix);
            return Command::FAILURE;
        }

        // Prepare composer command
        $composerArgs = ['--no-interaction'];
        if ($input->getOption('prefer-source')) {
            $composerArgs[] = '--prefer-source';
        }
        $webDir = Path::join($projectPath, 'www');
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
            rmdir($projectPath);
            return Command::FAILURE;
        }

        $hostname = $projectName . '.' . $input->getOption('host-suffix');

        // Setup environment-specific web files
        $output->writeln('Preparing extra webroot files');
        $filesWithPlaceholders = [
            '.env',
            'behat.yml',
            'launch.json',
        ];
        foreach ($filesWithPlaceholders as $file) {
            $filePath = Path::join($webDir, $file);
            copy(Path::join(Config::getBaseDir(), 'webroot', $file), $filePath);
            $this->replaceStrings($filePath, $projectName, $suffix, $hostname, $ipAddress);
        }

        // Setup docker files
        $dockerDir = Path::join($projectPath, 'docker');
        $output->writeln('Preparing docker directory');
        $copyFrom = Path::join(Config::getBaseDir(), 'docker/');
        exec("cp -R $copyFrom $dockerDir", $execOut, $exit);
        if ($execOut) {
            $output->writeln($execOut);
        }
        if ($exit !== 0) {
            $output->writeln('ERROR: Couldn\'t set up docker files.');
            Config::releaseSuffix($suffix);
            rmdir($projectPath);
            return Command::FAILURE;
        }
        $filesWithPlaceholders = [
            'docker_apache_default',
            'docker-compose.yml',
            'entrypoint',
        ];
        foreach ($filesWithPlaceholders as $file) {
            $filePath = Path::join([$dockerDir, $file]);
            $this->replaceStrings($filePath, $projectName, $suffix, $hostname, $ipAddress);
        }

        // Start docker image
        $startCommand = [
            'docker',
            'compose',
            'up',
            '-d',
        ];
        chdir($dockerDir);
        $process = new Process($startCommand);
        $process->setTimeout(null);
        $result = $processHelper->run($output, $process);
        if (!$result->isSuccessful()) {
            // TODO Revert??
            $output->writeln('ERROR: Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        $output->writeln('Updating hosts file');
        $success = $this->updateHosts($input, $output, $hostname, $ipAddress);
        if (!$success) {
            $output->writeln('ERROR: Couldn\'t add to hosts entry.');
            return Command::FAILURE;
        }

        $output->writeln('Completed successfully. Build the db by going to http://' . $hostname . '/dev/build');
        return Command::SUCCESS;
    }

    protected function updateHosts(InputInterface $input, OutputInterface $output, string $hostname, string $ipAddress): bool
    {
        // Update hosts file
        // TODO verify this entry hasn't already been added
        $hostsEntry = "$ipAddress    $hostname";

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $user = get_current_user();
        $question1 = new Question("[sudo] password for $user: ");
        $question1->setHidden(true);

        $password = $helper->ask($input, $output, $question1);
        exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsEntry . '" >> /etc/hosts\' 2> /dev/null', $execOut, $hadError);
        if ($execOut) {
            $output->writeln($execOut);
        }
        unset($password);
        return !$hadError;
    }

    protected function replaceStrings(string $filePath, string $projectName, string $suffix, string $hostname, string $ipAddress): void
    {
        $ipParts = explode('.', $ipAddress);
        array_pop($ipParts);
        $ipPrefix = implode('.', $ipParts);
        $content = file_get_contents($filePath);
        $content = str_replace('${PROJECT_NAME}', $projectName, $content);
        $content = str_replace('${SUFFIX}', $suffix, $content);
        $content = str_replace('${HOST_NAME}', $hostname, $content);
        $content = str_replace('${IP_PREFIX}', $ipPrefix, $content);
        file_put_contents($filePath, $content);
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
     * Gets the project name based on the input arguments and options
     */
    protected function getProjectName(InputInterface $input): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidProjectNameChars, '/') . ']/';
        // Use project name if defined
        if ($name = $input->getArgument('project-name')) {
            // TODO validate against duplicate project names
            if (preg_match($invalidCharsRegex, $name)) {
                throw new LogicException(
                    'project-name must not contain the following characters: ' . static::$invalidProjectNameChars
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
        Creates a new project in the project path using the project name and a unique integer value.
        The integer value is used to ensure directories are unique, as well as for a port for the database server.
        The project directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'project-name',
            InputArgument::OPTIONAL,
            'The name of the project. This will be used for the directory and the webhost. '
            . 'Defaults to a name generated based on the recipe and constraint. '
            . 'Must not contain the following characters: ' . static::$invalidProjectNameChars
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
            'prefer-source',
            null,
            InputOption::VALUE_NEGATABLE,
            'Whether to use --prefer-source for composer commands.',
            Config::getEnv('DT_PREFER_SOURCE')
        );
    }
}
