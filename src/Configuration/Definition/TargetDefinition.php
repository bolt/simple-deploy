<?php

namespace Bolt\SimpleDeploy\Configuration\Definition;

use Bolt\Common\Thrower;
use Bolt\SimpleDeploy\Util\Mode;
use phpseclib\System\SSH\Agent;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Deployment target configuration definition.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class TargetDefinition implements ConfigurationInterface
{
    /** @var string */
    private $targetName;

    /**
     * Constructor.
     *
     * @param string $target
     */
    public function __construct($target)
    {
        $this->targetName = $target;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root($this->targetName);

        $rootNode
            ->beforeNormalization()
                ->ifTrue(function ($v) {
                    return !isset($v['protocol']);
                })
                ->thenInvalid(sprintf('Deployment "%s" is missing a "protocol" key', $this->targetName))
            ->end()

            ->children()
                ->scalarNode('protocol')
                    ->info('The protocol you want to use for the deployment')
                    ->isRequired()
                    ->validate()
                    ->ifNotInArray(['ftp', 'sftp'])
                        ->thenInvalid('Protocol must be either "ftp" or "sftp", "%s" given')
                    ->end()
                ->end()
                ->arrayNode('exclude')
                    ->prototype('scalar')
                        ->info('A list of directory names to exclude from the upload')
                    ->end()
                ->end()
                ->arrayNode('options')
                    // Early validation
                    ->beforeNormalization()
                        ->ifTrue(function ($v) {
                            return !isset($v['host']);
                        })
                        ->thenInvalid(sprintf('Deployment "%s" must have an "options/host" value set', $this->targetName))
                    ->end()
                    ->beforeNormalization()
                        ->ifTrue(function ($v) {
                            return !isset($v['root']);
                        })
                        ->thenInvalid(sprintf('Deployment "%s" must have an "options/root" value set', $this->targetName))
                    ->end()

                    ->children()
                        // Destination
                        ->scalarNode('host')
                            ->info('The host name, or IP address of the deployment target')
                        ->end()
                        ->scalarNode('root')
                            ->info('The root directory on the target host that contains the Bolt installation')
                            ->isRequired()
                            ->beforeNormalization()
                                ->always(function ($v) {
                                    if ($v === null) {
                                        $v = './';
                                    } elseif (strpos($v, '~/') === 0) {
                                        $v = str_replace('~/', './', $v);
                                    } elseif (strpos($v, '/') !== 0) {
                                        $v = './' . $v;
                                    }

                                    return $v;
                                })
                            ->end()
                        ->end()

                        // Authentication
                        ->scalarNode('username')
                            ->info('Account user name on the deployment target host')
                        ->end()
                        ->scalarNode('password')
                            ->info('Account password on the deployment target host')
                        ->end()

                        // Common
                        ->integerNode('port')
                            ->info('The optional port number to connect to if the target is not listening on the default')
                        ->end()
                        ->integerNode('timeout')
                            ->info('Time in seconds to wait for a connection attempt')
                            ->defaultValue(30)
                        ->end()

                        // SFTP
                        ->variableNode('agent')->end()
                        ->booleanNode('useAgent')
                            ->info('Set to true to use your local system\'s SSH authentication agent')
                        ->end()
                        ->scalarNode('privateKey')
                            ->info('The full path to your SSH private key file, e.g. /home/your_user/.ssh/id_rsa')
                        ->end()
                        ->scalarNode('hostFingerprint')
                            ->info('The public key fingerprint of the deployment target')
                        ->end()

                        // FTP
                        ->booleanNode('utf8')
                            ->info('Set the connection to UTF-8 mode')
                        ->end()
                        ->booleanNode('passive')
                            ->info('Force FTP to use "passive" mode')
                        ->end()
                        ->booleanNode('ignorePassiveAddress')
                            ->info('Ignore the IP address returned when setting up a passive connection. Useful if a server is behind a NAT device. Requires PHP >= 5.6.18')
                        ->end()
                        ->integerNode('transferMode')
                            ->info('The transfer mode. Must be either ASCII or BINARY')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !($v === FTP_ASCII || $v === FTP_BINARY);
                                })
                                ->thenInvalid('transferMode mut be either "ASCII" or "BINARY"')
                            ->end()
                            ->beforeNormalization()
                                ->ifString()->then(function ($v) {
                                    if (strtoupper($v) === 'ASCII') {
                                        return FTP_ASCII;
                                    } elseif (strtoupper($v) === 'BINARY') {
                                        return FTP_BINARY;
                                    }

                                    return 0;
                                })
                            ->end()
                        ->end()
                        ->booleanNode('ssl')
                            ->info('Connect to the FTP target host over a secure SSL-FTP connection')
                            ->validate()
                                ->ifTrue(function () { return !extension_loaded('openssl'); })
                                ->thenInvalid('SSL-FTP requires the PHP OpenSSL extension to be enabled')
                            ->end()
                        ->end()

                        // File & directory permissions on target.
                        ->arrayNode('permissions')
                            ->children()
                                ->integerNode('file')->end()
                                ->integerNode('dir')->end()
                            ->end()
                        ->end()
                        ->integerNode('permPublic')
                            ->defaultValue(0664)
                        ->end()
                        ->integerNode('directoryPerm')
                            ->defaultValue(0775)
                        ->end()
                    ->end()

                    // Add the SSH agent class if required
                    ->beforeNormalization()
                        ->ifTrue(function ($v) { return isset($v['useAgent']) && $v['useAgent']; })
                        ->then(function ($v) {
                            // \phpseclib\System\SSH\Agent|\phpseclib\Crypt\RSA
                            $v['agent'] = Thrower::call(function () { return new Agent(); });

                            return $v;
                        })
                        ->end()

                    // Normalise permission parameters
                    ->beforeNormalization()
                        ->ifTrue(function ($v) { return isset($v['permissions']['file']); })
                        ->then(function ($v) {
                            $permission = $v['permissions']['file'];
                            unset($v['permissions']['file']);
                            $v['permPublic'] = Mode::resolve($permission);

                            return $v;
                        })
                        ->end()
                    ->beforeNormalization()
                        ->ifTrue(function ($v) { return isset($v['permissions']['dir']); })
                        ->then(function ($v) {
                            $permission = $v['permissions']['dir'];
                            unset($v['permissions']['dir']);
                            $v['directoryPerm'] = Mode::resolve($permission);

                            return $v;
                        })
                        ->end()
                    ->beforeNormalization()
                        ->always(function ($v) {
                            unset($v['permissions']);

                            return $v;
                        })
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
