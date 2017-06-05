<?php

namespace Bolt\SimpleDeploy\Configuration;

use Bolt\Collection\Bag;
use Bolt\Collection\MutableBag;
use Bolt\Configuration\PathResolver;
use Bolt\Filesystem\FilesystemInterface;
use Bolt\Filesystem\Handler\YamlFile;
use Bolt\Nut\Helper\Table;
use Bolt\Nut\Style\NutStyle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Configuration editor.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Editor
{
    /** @var FilesystemInterface */
    private $filesystem;
    /** @var PathResolver */
    private $pathResolver;
    /** @var NutStyle */
    private $io;

    /** @var Table */
    protected $settingsTable;

    /**
     * Constructor.
     *
     * @param FilesystemInterface $filesystem
     * @param PathResolver        $pathResolver
     * @param NutStyle            $io
     */
    public function __construct(FilesystemInterface $filesystem, PathResolver $pathResolver, NutStyle $io)
    {
        $this->filesystem = $filesystem;
        $this->pathResolver = $pathResolver;
        $this->io = $io;
    }

    /**
     * Create a new deployment configuration, and file if required.
     *
     * @param string $target
     *
     * @return bool
     */
    public function edit($target)
    {
        $deployFile = $this->getDeployFile();
        $yaml = $deployFile->exists()
            ? MutableBag::fromRecursive((array) $deployFile->parse())
            : new MutableBag()
        ;

        $config = $this->editDeployment($yaml, $target);
        if ($config) {
            return $this->saveDeployment($deployFile, $yaml, $config, $target);
        }

        return false;
    }

    /**
     * Interactively edit configuration values for a deployment.
     *
     * @param MutableBag $yaml
     * @param string     $target
     *
     * @return MutableBag|null
     */
    private function editDeployment(MutableBag $yaml, $target)
    {
        $config = $this->getEditableConfig($yaml, $target);
        do {
            $this->renderSettings($config);

            $choice = $this->askSettingToModify($config);
            if ($choice === null) {
                $required = $config->filter(function ($k, $v) { return $v['required'] && $v['value'] === null; })->keys();
                if ($required->isEmpty()) {
                    break;
                }
                $required->prepend('The following required settings are missing values:');
                $this->io->error($required->toArray());
                if ($this->io->confirmThenRemove('Continue')) {
                    continue;
                }
                $this->io->error('Incomplete configuration values.');

                return null;
            }

            $this->askAndSetNewSettingValue($config, $choice);
        } while (true);

        return $config;
    }

    /**
     * Save the updated YAML to a .deploy.yml file.
     *
     * @param YamlFile   $deployFile
     * @param MutableBag $yaml
     * @param MutableBag $config
     * @param string     $target
     *
     * @return bool
     */
    private function saveDeployment(YamlFile $deployFile, MutableBag $yaml, MutableBag $config, $target)
    {
        $yaml->setPath("$target/protocol", $config->getPath('protocol/value'));
        $yaml->setPath("$target/options/host", $config->getPath('host/value'));
        $yaml->setPath("$target/options/root", $config->getPath('root/value'));
        $yaml->setPath("$target/options/username", $config->getPath('username/value'));
        if ($password = $config->getPath('password/value')) {
            $yaml->setPath("$target/options/password", $password);
        }

        $deployFile->dump($yaml->toArrayRecursive(), ['inline' => 4, 'exceptionsOnInvalidType' => true]);

        $this->io->success(sprintf('Successfully saved "%s" to %s', $target, $deployFile->getFullPath()));

        return true;
    }

    /**
     * Render an editing choices table.
     *
     * @param Bag $config
     */
    private function renderSettings(Bag $config)
    {
        if (!$this->settingsTable) {
            $this->settingsTable = new Table($this->io);
            $style = clone Table::getStyleDefinition('symfony-style-guide');
            $this->settingsTable->setStyle($style);

            $this->settingsTable->setHeaders(['#', 'Setting', 'Value', 'Description']);

            $style = clone $style;
            $style->setCellHeaderFormat('<info>%s</info>');
            $this->settingsTable->setColumnStyle(0, $style);
        }

        $i = 0;
        $rows = [];
        foreach ($config as $setting => $data) {
            $value = $data->get('hidden')
                ? preg_replace('/./', '*', $data->get('value'))
                : $data->get('value')
            ;
            $value = '<comment>' . $value . '</comment>';
            $rows[] = ['<info>' . ++$i . '</info>', "<info>$setting</info>", $value, $data->get('info')];
        }
        $this->settingsTable->setRows($rows);

        $this->settingsTable->overwrite();
    }

    /**
     * Ask which setting to modify.
     *
     * @param Bag $config
     *
     * @return null|string The setting name to modify or null to finish.
     */
    private function askSettingToModify(Bag $config)
    {
        $count = $config->count();
        $indexed = $config->keys();

        $question = new Question('Enter # or setting name to modify or empty to continue');
        $question->setValidator(function ($value) use ($config, $indexed, $count) {
            if ($value === null) {
                return $value;
            }

            if (!is_numeric($value) && $config->has($value) !== false) {
                return $value;
            } elseif ($value > 0 && $value <= $count) {
                return $indexed[$value - 1];
            }

            throw new \Exception("Please enter a name or a number between 1 and $count.");
        });
        $question->setAutocompleterValues($config->keys());

        return $this->io->askQuestionThenRemove($question);
    }

    /**
     * Ask for the new value for the given setting name and set it.
     *
     * @param MutableBag $config
     * @param string     $choice
     */
    protected function askAndSetNewSettingValue(MutableBag $config, $choice)
    {
        /** @var MutableBag $choices */
        $choices = $config->getPath("$choice/choices");
        $choiceValue = $config->getPath("$choice/value");
        $previous = $choices && $choiceValue
            ? $choices->indexOf($choiceValue)
            : $choiceValue
        ;
        $q = 'Enter new value for ' . $choice;
        $question = $choices ? new ChoiceQuestion($q, $choices->toArray(), $previous) : new Question($q, $previous);
        $question->setAutocompleterValues($choices ? $choices->toArray() : null);

        if ($config->getPath("$choice/hidden")) {
            $question->setHidden(true);
        }

        $question->setValidator(function ($value) use ($config, $choices, $choice, $previous) {
            if ($choices === null || $choices->hasItem($value)) {
                return $config->setPath("$choice/value", $value);
            } elseif ($choices->has($value)) {
                return $config->setPath("$choice/value", $choices->get($value));
            }

            throw new \Exception(sprintf(
                'Invalid choice: %s' . PHP_EOL . 'Valid choices are: %s',
                $value,
                $choices->join(', ')
            ));
        });
        $this->io->askQuestionThenRemove($question);
    }

    /**
     * Return a bag of parameters suitable for editing.
     *
     * @param MutableBag $yaml
     * @param string     $target
     *
     * @return MutableBag
     */
    private function getEditableConfig(MutableBag $yaml, $target)
    {
        $definition = new Definition\TargetDefinition($target);
        /** @var ArrayNode $rootNode */
        $rootNode = $definition->getConfigTreeBuilder()->buildTree();
        $rootChildren = Bag::fromRecursive($rootNode->getChildren());
        $optionsChildren = Bag::fromRecursive($rootChildren->get('options')->getChildren());

        return MutableBag::fromRecursive([
            'protocol' => [
                'info'     => $rootChildren->get('protocol')->getInfo(),
                'required' => $rootChildren->get('protocol')->isRequired(),
                'choices'  => ['ftp', 'sftp'],
                'value'    => $yaml->getPath("$target/protocol"),
            ],
            'host' => [
                'info'     => $optionsChildren->get('host')->getInfo(),
                'required' => $optionsChildren->get('host')->isRequired(),
                'value'    => $yaml->getPath("$target/options/host"),
            ],
            'root' => [
                'info'     => $optionsChildren->get('root')->getInfo(),
                'required' => $optionsChildren->get('root')->isRequired(),
                'value'    => $yaml->getPath("$target/options/root"),
            ],
            'username' => [
                'info'     => $optionsChildren->get('username')->getInfo(),
                'required' => $optionsChildren->get('username')->isRequired(),
                'value'    => $yaml->getPath("$target/options/username"),
            ],
            'password' => [
                'info'     => $optionsChildren->get('password')->getInfo(),
                'required' => $optionsChildren->get('password')->isRequired(),
                'hidden'   => true,
                'value'    => $yaml->getPath("$target/options/password"),
            ],
        ]);
    }

    /**
     * @return YamlFile
     */
    private function getDeployFile()
    {
        /** @var YamlFile $deployFile */
        $deployFile = $this->filesystem->getFile('.deploy.yml', new YamlFile());

        return $deployFile;
    }
}
