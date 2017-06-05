<?php

namespace Bolt\SimpleDeploy;

use Bolt\Filesystem\Adapter;
use Bolt\SimpleDeploy\Configuration\DeployTarget;

/**
 * Remote filesystem adapter factory.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class AdapterFactory
{
    /**
     * Create a remote filesystem adapter.
     *
     * @param DeployTarget $config
     *
     * @return Adapter\Ftp|Adapter\Sftp
     */
    public static function create(DeployTarget $config)
    {
        if (strtolower($config->getProtocol()) === 'sftp') {
            return new Adapter\Sftp($config->getOptions()->toArray());
        }

        return new Adapter\Ftp($config->getOptions()->toArray());
    }
}
