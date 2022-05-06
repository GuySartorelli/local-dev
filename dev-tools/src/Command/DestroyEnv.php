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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class DestroyEnv extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'destroy-env';

    protected static $defaultDescription = 'Basically undoes the create-env command.';

    private Filesystem $filesystem;

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->processHelper = $this->getHelper('process');
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('env-path')), getcwd());
        $this->setVar('env-path', $envPath = $this->getEnvBasePath($proposedPath));
        if (!$envPath) {
            $output->writeln("Environment path '$proposedPath' is not inside a valid environment to destroy.");
            return Command::INVALID;
        }
        $this->setVar('env-name', $envName = basename($envPath));
        $this->setVar('suffix', $suffix = substr($envName, -2));

        // Pull down docker
        $failureCode = $this->pullDownDocker();
        if ($failureCode) {
            return $failureCode;
        }

        // Delete environment directory
        try {
            $output->writeln('Removing environment directory');
            $this->filesystem->remove($envPath);
        } catch (IOException $e) {
            $output->writeln('ERROR: Couldn\'t delete environment directory: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Release suffix now that we aren't using it for the directory.
        // This way if we can't remove the hosts entry we can still re-use this suffix.
        Config::releaseSuffix($suffix);

        // Remove hosts entry
        $failureCode = $this->cleanUpHosts();
        if ($failureCode) {
            return $failureCode;
        }

        $output->writeln("Env $envName successfully destroyed.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): int|bool
    {
        $output = $this->getVar('output');
        $envPath = $this->getVar('env-path');
        $output->writeln('Taking down docker');

        $stopCommand = [
            'docker',
            'compose',
            'down',
            '-v',
        ];

        $originalDir = getcwd();
        chdir(Path::join($envPath, 'docker-' . $this->getVar('suffix')));
        $process = new Process($stopCommand);
        $process->setTimeout(null);
        $result = $this->processHelper->run($output, $process);
        chdir($originalDir ?: $envPath);

        if (!$result->isSuccessful()) {
            $output->writeln('ERROR: Problem occured while stopping docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function cleanUpHosts(): int|bool
    {
        $output = $this->getVar('output');
        $output->writeln('Updating hosts file');
        $envName = $this->getVar('env-name');
        $ipAddress = '10.0.' . (int)$this->getVar('suffix') . '.50';

        $hadError = true;
        if ($password = $this->getVar('password')) {
            // Update hosts file
            $hostsContent = file_get_contents('/etc/hosts');
            preg_replace('/^' . preg_quote($ipAddress, '/') . '\h+' . preg_quote($envName, '/') . '\..*$/', '', $hostsContent);
            exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsContent . '" > /etc/hosts\' 2> /dev/null', $execOut, $hadError);
            if ($execOut) {
                $output->writeln($execOut);
            }
        }

        if ($hadError) {
            $output->writeln('ERROR: Couldn\'t remove hosts entry. Please manually remove the relevant line in /etc/hosts');
            return Command::FAILURE;
        }

        return false;
    }

    /**
     * Get the base directory for the environment.
     * Returns null if the candidate directory isn't in a valid environment.
     */
    protected function getEnvBasePath(string $candidate): ?string
    {
        if (!is_dir($candidate)) {
            return null;
        }

        $stopAtDirs = [
            '/',
            '/home',
        ];

        // Recursively check the proposed path and its parents for the requisite structure
        // Don't check root
        while ($candidate && !in_array($candidate, $stopAtDirs)) {
            // All environment directories end with an underscore and two digits (e.g. "_00")
            if (preg_match('/_(\d{2})$/', $candidate, $matches)) {
                $suffix = $matches[1];
                // Check that the 'www', 'logs', and 'dockerXX' directories are all present.
                $found = 0;
                foreach(scandir($candidate) as $toCheck) {
                    if (
                        preg_match("/^(www|logs|docker-$suffix)$/", $toCheck)
                        && is_dir(Path::join($candidate, $toCheck))
                    ) {
                        $found++;
                    }
                }
                if ($found === 3) {
                    return $candidate;
                }
            }
            // If the directory is invalid, check its parent next.
            $candidate = Path::getDirectory($candidate);
        }

        return null;
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
        Removes a project by pulling down the docker containers, network, and destroying the volumes,
        deleting the directory in the project directory, removing the entry in the hosts file, etc.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }
}
