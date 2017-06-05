<?php

namespace Bolt\SimpleDeploy\Nut;

use Bolt\Collection\Bag;
use Bolt\Configuration\PathResolver;
use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\FilesystemInterface;
use Bolt\Filesystem\Handler\HandlerInterface;
use Bolt\Filesystem\Manager;
use Bolt\Nut\BaseCommand;
use Bolt\SimpleDeploy\Configuration;
use Bolt\SimpleDeploy\Console\Helper\UploadProgressBar;
use Bolt\SimpleDeploy\Deployer;
use Bolt\SimpleDeploy\Exception\InvalidTargetException;
use Doctrine\Common\Cache\FlushableCache;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nut FTP/SFTP deployment command.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
final class SetupDeploy extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('setup:deploy')
            ->setDescription('A simple tool to deploy a site build from a local workstation to a (S)FTP enabled host')
            ->addArgument('target', InputArgument::REQUIRED, 'Name of the deployment setting to use from .deploy.yml')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only check the connection settings for a given deployment')
            ->addOption('edit', null, InputOption::VALUE_NONE, 'Interactively create or edit a deployment configuration')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Update files & permissions even if unchanged')
            ->addOption('cinereus', 'k', InputOption::VALUE_NONE)
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled()
    {
        return file_exists(__DIR__ . '/../../../../../.deploy.yml');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Manager $filesystem */
        $filesystem = $this->app['filesystem'];
        /** @var PathResolver $pathResolver */
        $pathResolver = $this->app['path_resolver'];

        $this->io->setVerbosity($output->getVerbosity());
        $checkOnly = $input->getOption('check');
        $edit = $input->getOption('edit');
        $force = $input->getOption('force');
        $cinereus = $input->getOption('cinereus');
        $target = $input->getArgument('target');

        // Interactive deployment configuration creation
        if ($edit) {
            $generator = new Configuration\Editor($filesystem->getFilesystem('root'), $pathResolver, $this->io);

            return (int) !$generator->edit($target);
        }

        $targetConfig = $this->getTargetConfiguration($target);
        $deployment = new Deployer($filesystem, $pathResolver, $this->io);

        // Cache clean-up
        $result = $this->cacheNuclearFlush();
        if ($result !== 0) {
            return $result;
        }

        // Connection check
        if ($deployment->checkConnection($targetConfig) === false) {
            return 1;
        }
        // Write access check
        $this->io->title('Checking write access');
        if ($deployment->checkWriteAccess($targetConfig) === false) {
            return 1;
        }

        // Correct directory check
        $this->io->title('Directory location check');
        $content = $deployment->getTargetContents($targetConfig);
        /** @var Bag $files */
        $files = $content->get('files');
        /** @var Bag $dirs */
        $dirs = $content->get('dirs');
        if ($dirs->isEmpty() && $files->isEmpty()) {
            $this->io->note('Target directory is empty.');
        } else {
            $this->io->note([
                'Target directory not empty, and contains the following:',
                $dirs->join(' '),
                $files->join(' '),
            ]);
        }
        if (!$this->io->confirm('Does this look like the correct location has been configured as the target root directory', false)) {
            $this->io->error(sprintf('You need to adjust the \'root:\' value under the \'options:\' sub key of this deployment\'s configuration.'));

            return 1;
        }
        $this->io->success('Target location assumed correct!');

        // Exit if we're only doing a check
        if ($checkOnly) {
            return 0;
        }

        // Deployment
        $this->io->setVerbosity($output->getVerbosity());
        $progressBar = new UploadProgressBar($this->io, 0, $cinereus);
        $result = $deployment->pushRemote($targetConfig, $progressBar, $force);
        if ($result === false) {
            return 1;
        }

        // Complete
        $this->io->title('Finishing up');
        if ($cinereus) {
            $this->io->success(sprintf('Deployment complete, and all Drop Bears captured!', $target));
        } else {
            $this->io->success(sprintf('Deployment to "%s" is complete.', $target));
        }

        return 0;
    }

    /**
     * @param string $target
     *
     * @return Configuration\DeployTarget
     */
    private function getTargetConfiguration($target)
    {
        /** @var Manager $filesystem */
        $filesystem = $this->app['filesystem'];
        /** @var FilesystemInterface $rootFS */
        $rootFS = $filesystem->getFilesystem('root');
        $loader = Configuration\Loader::create($rootFS);

        try {
            $config = $loader->loadTarget($target);
        } catch (InvalidTargetException $e) {
            $config = $loader->load();
            throw new InvalidTargetException(sprintf(
                'The chosen deployment target "%s" does not exist in your .deploy.yml file. Configured deployment targets are "%s"',
                $target,
                implode(', ', array_keys($config->getTargets()))
            ));
        }

        return $config;
    }

    /**
     * Nuclear flush option.
     */
    private function cacheNuclearFlush()
    {
        /** @var Manager $filesystem */
        $filesystem = $this->app['filesystem'];
        /** @var FlushableCache $cache */
        $cache = $this->app['cache'];

        $this->io->title('Fully flushing Bolt\'s caches for deployment');
        $this->io->warning([
            'This will clear all volatile cache data, including user session data, potentially causing data loss for currently connected web clients.',
            'DO NOT perform on a live system!',
        ]);
        $answer = $this->io->confirm('Continue?', false);
        if (!$answer) {
            return $this->cancel();
        }

        $error = 'Failed to clear cache. You need to delete it manually and re-run this command.';
        $result = $cache->flushAll();
        if (!$result) {
            $this->io->error($error);

            return 1;
        }
        $cacheDir = $filesystem->getFilesystem('cache')->getDir('');
        $files = $cacheDir->find()
            ->notName('.gitignore')
            ->ignoreDotFiles(false)
            ->depth('< 1')
        ;

        /** @var HandlerInterface $file */
        foreach ($files as $file) {
            try {
                $file->delete();
            } catch (IOException $e) {
                $this->io->error($error);

                return 1;
            }
        }

        return 0;
    }

    /**
     * Write a cancellation message.
     *
     * @return int
     */
    private function cancel()
    {
        $this->io->block('Deployment cancelled.', 'CANCELLED', 'fg=black;bg=yellow', ' ', true);

        return 1;
    }
}
