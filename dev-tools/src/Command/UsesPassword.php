<?php

namespace DevTools\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;

trait UsesPassword
{
    private function getPassword(): ?string
    {
        $input = $this->getVar('input');
        $output = $this->getVar('output');
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $user = get_current_user();
        $question1 = new Question("[sudo] password for $user: ");
        $question1->setHidden(true);
        return $helper->ask($input, $output, $question1);
    }
}
