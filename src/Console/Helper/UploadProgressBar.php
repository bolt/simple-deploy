<?php

namespace Bolt\SimpleDeploy\Console\Helper;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Custom upload progress bar.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class UploadProgressBar extends ProgressBar
{
    /**
     * Constructor.
     *
     * @param OutputInterface $output   An OutputInterface instance
     * @param int             $max      Maximum steps (0 if unknown)
     * @param bool            $cinereus Phascolarctos cinereus
     */
    public function __construct(OutputInterface $output, $max = 0, $cinereus = false)
    {
        parent::__construct($output, $max);

        $format = ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE)
            ? ' [%bar%] %percent:3s%% %elapsed:6s% (%message% %filename%)'
            : ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% (%message% %filename%)'
        ;
        $this->setFormatDefinition('upload', $format);
        $this->setFormat('upload');

        $unix = '\\' !== DIRECTORY_SEPARATOR;
        if ($unix && $cinereus) {
            $this->setEmptyBarCharacter(' ');
            $this->setProgressCharacter('🐨'); // Phascolarctos cinereus character \u1F428
            $this->setBarCharacter('.');
        } elseif ($unix) {
            $this->setEmptyBarCharacter('░'); // light shade character \u2591
            $this->setProgressCharacter('');
            $this->setBarCharacter('▓'); // dark shade character \u2593
        }
    }
}
