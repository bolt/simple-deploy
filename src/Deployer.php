<?php

namespace Bolt\SimpleDeploy;

use Bolt\Collection\Bag;
use Bolt\Collection\MutableBag;
use Bolt\Configuration\PathResolver;
use Bolt\Filesystem\Adapter;
use Bolt\Filesystem\Exception\DirectoryCreationException;
use Bolt\Filesystem\Exception\InvalidArgumentException;
use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\Filesystem;
use Bolt\Filesystem\FilesystemInterface;
use Bolt\Filesystem\Finder;
use Bolt\Filesystem\Handler\Directory;
use Bolt\Filesystem\Handler\HandlerInterface;
use Bolt\Filesystem\Manager;
use Bolt\Nut\Style\NutStyle;
use Bolt\SimpleDeploy\Configuration\DeployTarget;
use SplFileInfo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Finder\Finder as SymfonyFinder;
use Symfony\Component\Finder\SplFileInfo as FileInfo;
use Webmozart\PathUtil\Path;

/**
 * A simple service to upload a developer build to a remote environment via
 * FTP/SFTP.
 *
 * WARNING: This class MUST NOT be referenced for examples of how to use either
 *          Bolt's internal filesystem, or Flysystem's API.
 *
 *          IT IS WRONG TO USE THE APPROACHES TAKEN HERE! e.g.
 *          Adapters MUST NOT be accessed directly … EVER!
 *
 *          If you copy this class' code in your project, you are responsible
 *          for the result! Neither the Bolt or Flysystem projects support the
 *          approach used here, we're just making an internal compromise to
 *          produce a simple, needed, outcome.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
final class Deployer
{
    /** @var Manager */
    private $filesystem;
    /** @var PathResolver */
    private $pathResolver;
    /** @var NutStyle */
    private $io;

    /** @var Adapter\Ftp[]|Adapter\Sftp[] */
    private $adapters;
    /** @var MutableBag */
    private $errors;

    /**
     * Constructor.
     *
     * @param Manager      $filesystem
     * @param PathResolver $pathResolver
     * @param NutStyle     $io
     */
    public function __construct(Manager $filesystem, PathResolver $pathResolver, NutStyle $io)
    {
        $this->filesystem = $filesystem;
        $this->pathResolver = $pathResolver;
        $this->io = $io;
        $this->adapters = new MutableBag();
        $this->errors = new MutableBag();
    }

    /**
     * Return any errors that were logged during a transaction.
     *
     * @return MutableBag
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Check that the connection to the remote server is valid.
     *
     * @param DeployTarget $targetConfig
     *
     * @return bool
     */
    public function checkConnection(DeployTarget $targetConfig)
    {
        $adapter = $this->getAdapter($targetConfig);
        $hostName = $targetConfig->getOptions()->get('host');

        // Remote connection check
        $this->io->title('Connecting to remote host');
        try {
            if (!$adapter->isConnected()) {
                $adapter->connect();
            }
        } catch (\LogicException $e) {
            $this->io->error(sprintf('Connection to remote host failed: %s', $e->getMessage()));

            return false;
        }
        $this->io->success(sprintf('Connection to \'%s\' was successful!', $hostName));

        return true;
    }

    /**
     * Check that the remote target has write access for the given user account.
     *
     * @param DeployTarget $targetConfig
     *
     * @return bool
     */
    public function checkWriteAccess(DeployTarget $targetConfig)
    {
        $options = $targetConfig->getOptions();
        $hostName = $options->get('host');
        $filesystem = $this->getRemoteFilesystem($targetConfig);
        $protocol = $targetConfig->getProtocol();
        $adapter = $this->getAdapter($targetConfig);

        $rootDir = ltrim($options->get('root'), '~\\');
        if (strpos($rootDir, '/') !== 0) {
            $rootDir = $adapter->getPathPrefix() . $rootDir;
        }
        $realRoot = preg_replace('#^.\/#', '~/', $rootDir);
        $testDir = '.delete-me';
        $testFile = $testDir . '/test.txt';
        try {
            $filesystem->createDir($testDir);
            $filesystem->put($testFile, 'This file and its directory were placed here by Bolt Simple Deploy to test access.');
        } catch (DirectoryCreationException $e) {
            $this->io->error(sprintf(
                'Failed creating a test directory on the remote root, "%s/%s" does seem to be writable.',
                $realRoot,
                $testFile
            ));

            return false;
        } catch (IOException $s) {
            $this->io->error(sprintf(
                'Failed creating a test file on the remote directory, "%s/%s" does seem to be writable.',
                $realRoot,
                $testFile
            ));

            return false;
        }
        $filesystem->deleteDir($testDir);
        $this->io->success(sprintf('Target %s://%s:%s looks writable!', $protocol, $hostName, $realRoot));

        return true;
    }

    /**
     * Get the contents of the remote deployment target.
     *
     * @param DeployTarget $targetConfig
     *
     * @return Bag
     */
    public function getTargetContents(DeployTarget $targetConfig)
    {
        $files = $dirs = [];
        /** @var FilesystemInterface $filesystem */
        $filesystem = $this->getRemoteFilesystem($targetConfig);
        /** @var Directory $remoteDir */
        $remoteDir = $filesystem->getDir('');
        $dirItems = $remoteDir->getContents();
        /** @var HandlerInterface $dirItem */
        foreach ($dirItems as $dirItem) {
            if ($dirItem->isDir()) {
                $dirs[] = $dirItem->getPath() . '/';

                continue;
            }
            $files[] = $dirItem->getPath();
        }

        return Bag::fromRecursive(['files' => $files, 'dirs' => $dirs]);
    }

    /**
     * Upload the required local files to the remote host.
     *
     * @param DeployTarget $targetConfig
     * @param ProgressBar  $progressBar
     * @param bool         $force
     *
     * @return bool
     */
    public function pushRemote(DeployTarget $targetConfig, ProgressBar $progressBar, $force = false)
    {
        // Catalogue
        $catalogue = $this->getCatalogue();
        if ($catalogue === null) {
            $this->io->error('Failed getting catalogue.');

            return false;
        }

        // Upload
        $result = $this->uploadCatalogue($targetConfig, $catalogue, $progressBar, $force);
        if ($result) {
            $this->clearRemoteCache($targetConfig);

            return $this->setupExecutables($targetConfig);
        }
        $this->io->error('Upload did not complete successfully!');

        return false;
    }

    /**
     * Return a catalogue of files & directories to upload.
     *
     * @return Finder|null
     */
    private function getCatalogue()
    {
        $this->io->title('Building deployment catalogue');

        $filesystem = $this->filesystem->getFilesystem('root');
        $rootDir = $filesystem->getDir('');
        $catalogue = $rootDir
            ->find()
            ->notName('.deploy.yml')
            ->notName('deploy.yml')
            ->notName('*_local.yml')
            ->notName('*.yml.dist')
            ->exclude(['node_modules', 'bower_components', '.sass-cache', 'Test', 'test', 'Tests', 'tests', 'tmp', 'fixtures'])
            ->ignoreDotFiles()
            ->ignoreVCS()
        ;
        if ($filesystem->has('.bolt.yml')) {
            $catalogue->append($rootDir->find()->name('.bolt.yml'));
        }
        if ($filesystem->has('.bolt.php')) {
            $catalogue->append($rootDir->find()->name('.bolt.php'));
        }

        $this->io->note('This make take a while');
        $count = $catalogue->count();
        if ($count === 0) {
            $this->io->error('No files or directories found to deploy.');

            return null;
        }
        $this->io->success('Found ' . number_format($count, 0, '.', ',') . ' files & directories to upload');

        return $catalogue;
    }

    /**
     * Upload the local copy of the catalogue to the remote target.
     *
     * @param DeployTarget $targetConfig
     * @param Finder       $sync
     * @param ProgressBar  $progressBar
     * @param bool         $force
     *
     * @return bool
     */
    private function uploadCatalogue(DeployTarget $targetConfig, Finder $sync, ProgressBar $progressBar, $force)
    {
        $this->io->title('Performing Upload');

        $this->io->note('Preparing upload connection');
        $progressBar->start($sync->count());
        $connection = $this->getAdapter($targetConfig)->getConnection();
        $directoryPerm = $targetConfig->getOptions()->get('directoryPerm');

        /** @var HandlerInterface $include */
        foreach ($sync as $include) {
            $progressBar->setMessage('Uploading');
            $progressBar->setMessage(strtok($include->getDirname(), '/') . '/', 'filename');
            $progressBar->advance();
            /** @var Filesystem $includeFs */
            $includeFs = $include->getFilesystem();
            if (method_exists($includeFs, 'getAdapter') && !$includeFs->getAdapter() instanceof Adapter\Local) {
                continue;
            }
            $targetPath = $targetConfig->getName() . '://' . $include->getPath();
            if ($include->isDir()) {
                try {
                    $this->filesystem->createDir($targetPath);
                } catch (IOException $e) {
                    $this->io->error('Failed to create target directory: ' . $targetPath);
                    throw $e;
                }
                if ($force) {
                    list($mountPoint, $path) = $this->getEntreePath($targetPath);
                    $connection->chmod($directoryPerm, $path);
                }

                continue;
            }
            try {
                $this->filesystem->copy($include->getFullPath(), $targetPath, $force);
            } catch (IOException $e) {
                $this->io->error(sprintf('Failed to copy %s to %s', $include->getFullPath(), $targetPath));
                throw $e;
            }
        }

        $progressBar->setMessage('Finished', 'message');
        $progressBar->setMessage('transfer', 'filename');
        $progressBar->finish();
        $this->io->newLine(2);
        $this->io->success('Upload completed.');

        return true;
    }

    /**
     * Clear the contents of the target cache.
     *
     * @param DeployTarget $targetConfig
     */
    private function clearRemoteCache(DeployTarget $targetConfig)
    {
        $this->io->title('Clearing remote cache');

        $filesystem = $this->getRemoteFilesystem($targetConfig);
        $rootDir = $this->pathResolver->resolve('%root%');
        $cacheDir = $this->pathResolver->resolve('%cache%');
        $relativePath = Path::makeRelative($cacheDir, $rootDir);

        $targetCacheDir = $filesystem->getDir($relativePath);
        $cached = $targetCacheDir
            ->find()
            ->depth(0)
            ->ignoreDotFiles(true)
        ;

        foreach ($cached as $item) {
            try {
                $item->delete();
            } catch (IOException $e) {
                $this->io->error(sprintf('Failed to remove %s from target cache', $item->getPath()));
            }
        }

        $this->io->success('Cache cleared on target host.');
    }

    /**
     * Conditionally create symlinks/fake-links, and attempt to make them able
     * to be executed.
     *
     * @param DeployTarget $targetConfig
     *
     * @return bool
     */
    private function setupExecutables(DeployTarget $targetConfig)
    {
        $this->io->title('Configuring Executables');

        // Nut
        $this->setupExecutableNut($targetConfig);

        // Links in vendor/bin/
        $symlinks = $this->setupExecutablesVendorBin($targetConfig);

        if ($this->errors->count() > 0) {
            $this->io->error($this->errors->toArray());

            return false;
        }
        $this->io->success(sprintf(
            'Checked and/or updated %s executable files.',
            $symlinks->count() - $this->errors->count() + 1
        ));

        return true;
    }

    /**
     * Correctly set-up Nut.
     *
     * @param DeployTarget $targetConfig
     */
    private function setupExecutableNut(DeployTarget $targetConfig)
    {
        $rootDir = $this->pathResolver->resolve('%root%');
        $isFtp = $targetConfig->getProtocol() === 'ftp';
        $nutPath = $this->pathResolver->resolve('%app%/nut');

        $nut = new SplFileInfo($nutPath);
        if (!$nut->isLink()) {
            return;
        }

        $link = str_replace($rootDir . '/', '', $nutPath);
        if ($isFtp) {
            $this->symlinkFtp($targetConfig, $nut, $nut->getLinkTarget(), $link, true);

            return;
        }
        $target = str_replace($rootDir . '/', '', $nut->getRealPath());
        $this->symlinkSftp($targetConfig, $nut, $target, $link);
    }

    /**
     * Correctly set-up Nut.
     *
     * @param DeployTarget $targetConfig
     *
     * @return SymfonyFinder
     */
    private function setupExecutablesVendorBin(DeployTarget $targetConfig)
    {
        $rootDir = $this->pathResolver->resolve('%root%');
        $binDir = $this->pathResolver->resolve('%root%/vendor/bin', false);
        $isFtp = $targetConfig->getProtocol() === 'ftp';

        $symlinks = (new SymfonyFinder())
            ->files()
            ->in($binDir)
            ->filter(function (FileInfo $input) {
                return $input->isLink();
            })
            ->depth('== 0')
        ;
        /** @var FileInfo $symlink */
        foreach ($symlinks as $symlink) {
            $target = str_replace($rootDir . '/vendor/', '../', $symlink->getLinkTarget());
            $link = 'vendor/bin/' . $symlink->getFileName();
            if ($isFtp) {
                $this->symlinkFtp($targetConfig, $symlink, $symlink->getLinkTarget(), $link);

                continue;
            }
            $this->symlinkSftp($targetConfig, $symlink, $target, $link);
        }

        return $symlinks;
    }

    /**
     * Create missing symlink, and try to make its target is executable.
     *
     * @param DeployTarget $targetConfig
     * @param SplFileInfo  $symlink
     * @param string       $target
     * @param string       $link
     */
    private function symlinkSftp(DeployTarget $targetConfig, SplFileInfo $symlink, $target, $link)
    {
        $adapter = $this->getAdapter($targetConfig);
        /** @var \phpseclib\Net\SFTP $connection */
        $connection = $adapter->getConnection();

        // Flysystem will at best ignore symlinks, we need to use the connection directly :-/
        $exists = $connection->file_exists($link);
        if (!$exists && $connection->symlink($symlink->getLinkTarget(), $link) === false) {
            $errors[] = sprintf('Failed to create symlink on remote. %s -> %s', $link, $symlink->getLinkTarget());
        }

        $exists = $adapter->has($target);
        if ($exists && $connection->chmod($targetConfig->getOptions()->get('directoryPerm'), $target) === false) {
            $errors[] = sprintf('Failed to set execute permission on remote file %s', $target);
        }
    }

    /**
     * Create a fake link, i.e. simple Bash/PHP file, that required the
     * intended target.
     *
     * The FTP protocol does not support symlink transfer or creation, even
     * better a scary number of web hosting providers *only allow* FTP access,
     * i.e. no shell or even SFTP.
     *
     * @param DeployTarget $targetConfig
     * @param SplFileInfo  $symlink
     * @param string       $target
     * @param string       $link
     * @param bool         $requireAutoloader
     */
    private function symlinkFtp(DeployTarget $targetConfig, SplFileInfo $symlink, $target, $link, $requireAutoloader = false)
    {
        $template = <<<EOF
#!/usr/bin/env php
<?php

%AUTOLOADER%

return require __DIR__ . '/%TARGET%';
EOF;
        $template = $requireAutoloader
            ? str_replace('%AUTOLOADER%', "require __DIR__ . '../vendor/autoload.php'", $template)
            : str_replace('%AUTOLOADER%', '', $template)
        ;
        $filesystem = $this->getRemoteFilesystem($targetConfig);
        $created = $filesystem->put($link, str_replace('%TARGET%', $target, $template));
        $adapter = $this->getAdapter($targetConfig);
        $exists = $adapter->has($link);

        if (!$exists && $created === false) {
            $this->errors[] = sprintf('Failed to create symlink on remote. %s -> %s', $link, $symlink->getLinkTarget());
        }

        /** @var resource $connection */
        $connection = $adapter->getConnection();
        if (ftp_chmod($connection, $targetConfig->getOptions()->get('directoryPerm'), $link) === false) {
            $this->errors[] = sprintf('Failed to set execute permission on remote file %s', $link);
        }
    }

    /**
     * Initialise adapter and mount remote filesystem.
     *
     * @param DeployTarget $targetConfig
     *
     * @return FilesystemInterface
     */
    private function getRemoteFilesystem(DeployTarget $targetConfig)
    {
        if (!$this->filesystem->hasFilesystem($targetConfig->getName())) {
            $this->mountRemoteFilesystem($targetConfig);
        }

        return $this->filesystem->getFilesystem($targetConfig->getName());
    }

    /**
     * Mount the remote filesystem with the appropriate adapter.
     *
     * @param DeployTarget $targetConfig
     */
    private function mountRemoteFilesystem(DeployTarget $targetConfig)
    {
        $remoteFilesystem = new Filesystem($this->getAdapter($targetConfig), ['visibility' => 'public']);
        $this->filesystem->mountFilesystem($targetConfig->getName(), $remoteFilesystem);
    }

    /**
     * Return the required adapter for the deployment configuration.
     *
     * @param DeployTarget $targetConfig
     *
     * @return Adapter\Ftp|Adapter\Sftp
     */
    private function getAdapter(DeployTarget $targetConfig)
    {
        $target = $targetConfig->getName();
        if (!$this->adapters->has($target)) {
            $this->adapters->set($target, AdapterFactory::create($targetConfig));
        }

        return $this->adapters->get($target);
    }

    /**
     * @param string $path
     *
     * @return array
     */
    private function getEntreePath($path)
    {
        if (!is_string($path)) {
            throw new InvalidArgumentException(sprintf('Path should be a string, %s provided.', is_object($path) ? get_class($path) : gettype($path)));
        }

        if (!preg_match('#^.+\:\/\/.*#', $path)) {
            throw new InvalidArgumentException('No mount point detected in path: ' . $path);
        }

        return explode('://', $path, 2);
    }
}
