<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use DevTools\Utility\Environment;
use Github\AuthMethod;
use Github\Client as GithubClient;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class CreateEnvForPR extends CreateEnv
{
    protected static $defaultName = 'up-pr';

    protected static $defaultDescription = 'Sets up a new docker environment with a webhost for a given PR.';

    /**
     * Parse a URL or github-shorthand PR reference into an array containing the org, repo, and pr components.
     */
    private function parsePr(string $prRaw): array
    {
        if (!preg_match('@(?<org>[a-zA-Z0-9_-]*)/(?<repo>[a-zA-Z0-9_-]*)(/pull/|#)(?<pr>[0-9]*)$@', $prRaw, $matches)) {
            throw new InvalidArgumentException("'$prRaw' is not a valid github PR reference.");
        }
        return $matches;
    }

    /**
     * Get the composer name of a project from the composer.json of a repo.
     */
    private function getComposerName(GithubClient $client, array $pr): string
    {
        $composerJson = $client->repo()->contents()->download($pr['org'], $pr['repo'], 'composer.json');
        return json_decode($composerJson, true)['name'];
    }

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $pr = $this->parsePr($input->getOption('pr'));
        $client = new GithubClient();
        if ($token = Config::getEnv('DT_GITHUB_TOKEN')) {
            $client->authenticate($token, AuthMethod::ACCESS_TOKEN);
        }
        $this->setVar('composerName', $this->getComposerName($client, $pr));
        $prDetails = $client->pullRequest()->show($pr['org'], $pr['repo'], $pr['pr']);
        $this->setVar('prDetails', array_merge($pr, [
            'remote' => $prDetails['head']['repo']['ssh_url'],
            'branch' => $prDetails['head']['ref'],
        ]));
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitSoFar = parent::execute($input, $output);
        if ($exitSoFar !== Command::SUCCESS) {
            return $exitSoFar;
        }
        $this->prepareGitStuff();
        return Command::SUCCESS;
    }

    protected function prepareGitStuff()
    {
        $output = $this->getVar('output');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $pr = $this->getVar('prDetails');
        $originalDir = getcwd();

        $output->writeln('Setting remote ' . $pr['remote'] . ' as "pr" and checking out branch ' . $pr['branch']);
        $prPath = Path::join($environment->getWebRoot(), 'vendor', $this->getVar('composerName'));
        chdir($prPath);
        $commands = [];
        if (!in_array($pr['org'], ['creative-commoners', 'silverstripe', 'silverstripe-security'])) {
            // Add the PR remote
            $commands[] = [
                'git',
                'remote',
                'add',
                'pr',
                $pr['remote'],
            ];
        }
        $commands = array_merge($commands, [
            // Add all our normal remotes (just in case) and fetch all
            ['git-set-remotes'],
            // Checkout the PR branch
            [
                'git',
                'checkout',
                '--track',
                'pr/' . $pr['branch'],
            ],
        ]);
        foreach ($commands as $command) {
            $this->processHelper->run(new NullOutput(), new Process($command));
        }
        chdir($originalDir ?: $environment->getBaseDir());

        $output->writeln("cd to '$prPath' when ready.");
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        parent::configure();
        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Works the same as the "up" command, but also checkout out a PR branch in the appropriate vendor package.
        HELP);

        $this->addOption(
            'pr',
            null,
            InputOption::VALUE_REQUIRED,
            'The pull request URL or github referece, e.g. "silverstripe/silverstripe-framework#123" or "https://github.com/silverstripe/silverstripe-framework/pull/123"',
        );
    }
}
