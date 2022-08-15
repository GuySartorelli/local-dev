<?php

namespace DevTools\Command;

use InvalidArgumentException;
use Joli\JoliNotif\Notification;
use Joli\JoliNotif\NotifierFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private array $commandVars = [];

    /**
     * If true, notifies when a command has finished.
     */
    protected static bool $notifyOnCompletion = false;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('quiet')) {
            // $output->setVerbosity(Output::VERBOSITY_DEBUG);
        }
        $this->resetVars();
        $this->setVar('input', $input);
        $this->setVar('output', $output);
    }

    protected function resetVars(): void
    {
        $this->commandVars = [];
    }

    protected function getVar(string $varName): mixed
    {
        if (!array_key_exists($varName, $this->commandVars)) {
            throw new InvalidArgumentException("var '$varName' has not been set");
        }
        return $this->commandVars[$varName];
    }

    protected function setVar(string $varName, mixed $value): void
    {
        $this->commandVars[$varName] = $value;
    }

    private function notify(int $exitCode): void
    {
        $notifier = NotifierFactory::create();
        $notification = new Notification();

        $notification->setTitle($this->getApplication()->getName());
        if ($exitCode) {
            $notification
                ->setBody("Error occurred when running {$this->getName()}")
                ->setIcon(__DIR__ . '/../../resources/icons/error.png');
        } else {
            $notification
                ->setBody("Successfully ran {$this->getName()}")
                ->setIcon(__DIR__ . '/../../resources/icons/success.png');
        }

        $notifier->send($notification);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = parent::run($input, $output);
        if (static::$notifyOnCompletion) {
            $this->notify($exitCode);
        }
        return $exitCode;
    }
}
