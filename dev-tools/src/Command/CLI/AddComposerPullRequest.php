<?php

namespace DevTools\Command\CLI;

use DevTools\Command\BaseCommand;
use DevTools\Utility\ComposerJsonService;
use DevTools\Utility\GitHubService;
use DevTools\Utility\ProcessOutputter;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class AddComposerPullRequest extends BaseCommand
{
    protected static $defaultName = 'cli:add-pr';

    protected static $defaultDescription = 'Add a PR as an aliased fork to composer.json.';

    protected static bool $hasEnvironment = false;

    protected ProcessHelper $processHelper;

    protected ProcessOutputter $outputter;

    private ComposerJsonService $composerService;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->processHelper = $this->getHelper('process');
        $this->outputter = new ProcessOutputter($output);
        $fileSystem = new Filesystem();

        if (!$fileSystem->exists('composer.json')) {
            throw new RuntimeException('There is no composer.json file here to add the PR(s) to.');
        }
        $this->setVar('prs', $prs = $input->getArgument('pr'));
        if (empty($prs)) {
            throw new RuntimeException('At least one PR must be included.');
        }

        $this->composerService = new ComposerJsonService('./');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $prs = $this->getVar('prs');

        $io->writeln(self::STEP_STYLE . 'Getting PR details</>');
        $prDetails = GitHubService::getPullRequestDetails($prs);

        $io->writeln(self::STEP_STYLE . 'Adding forks for PR(s)</>');
        $this->composerService->addForks($prDetails);

        $io->writeln(self::STEP_STYLE . 'Setting constraints for PR(s)</>');
        $this->composerService->addForkedDeps($prDetails);

        $io->success('Added PR(s) successfully.');
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['add-pr']);

        $desc = static::$defaultDescription;
        $this->setHelp($desc);
        $this->addArgument(
            'pr',
            InputArgument::IS_ARRAY,
            'A URL to the PR. Multiple PRs can be added.',
        );
    }
}
