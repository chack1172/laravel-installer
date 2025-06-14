<?php

namespace Laravel\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class DevToolsCommand extends Command
{
    use Concerns\ConfiguresPrompts;
    use Concerns\InteractsWithConsole {
        runCommands as baseRunCommands;
    }

    private string $directory = '.';

    private InputInterface $input;

    private OutputInterface $output;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('dev-tools')
            ->addOption('commit', null, InputOption::VALUE_NONE, 'Commit changes on Git repository')
            ->addOption('pint', null, InputOption::VALUE_NONE, 'Install the Pint code style fixer')
            ->addOption('pint-preset', null, InputOption::VALUE_REQUIRED, 'Define the pint preset')
            ->addOption('scripts', null, InputOption::VALUE_NONE, 'Write all scripts in composer.json');
    }

    /**
     * Interact with the user before validating the input.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $this->verifyInsideComposerProject();

        if (! $input->getOption('scripts')) {
            $input->setOption('scripts', confirm(
                label: 'Would you like to add scripts in composer.json?',
                default: true,
            ));
        }

        if (! $input->getOption('commit')) {
            $input->setOption('commit', confirm(
                label: 'Would you like to commit Git changes for each added tool?',
                default: true,
            ));
        }
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->initComposer($this->directory);

        $this->installPint();

        // Add test script
        $this->changeScripts(function (array $scripts) use ($input) {
            unset($scripts['test']); // Move test script at the end
            $scripts['test'] = [];

            if ($input->getOption('pint')) {
                $scripts['test'][] = '@test:lint';
            }

            return $scripts;
        });

        return 0;
    }

    /**
     * Install Pint into the application.
     * 
     * @return void 
     */
    protected function installPint(): void
    {
        if (! $this->input->getOption('pint') && $this->input->isInteractive()) {
            if (! confirm(
                label: 'Would you like to install Laravel Pint for code style fix?',
                default: true,
            )) {
                return;
            }
            $this->input->setOption('pint', true);
        }

        // Install package
        $installed = false;
        if (!$this->composer->hasPackage('laravel/pint')) {
            $composerBinary = $this->findComposer();

            $commands = [
                $composerBinary.' require laravel/pint --dev'
            ];

            $this->runCommands($commands);
            $installed = true;
        }

        $preset = $this->input->getOption('pint-preset');
        if (! $preset) {
            $preset = select(
                label: 'Which Pint preset do you preferaa?',
                options: ['laravel', 'per', 'psr12', 'symfony', 'empty'],
                default: 'laravel',
            );
        }

        $this->copyAsset('pint.json', 'pint.json', [
            ':preset' => $preset,
        ]);

        // Add lint scripts
        $this->changeScripts(function (array $scripts) {
            $scripts['lint'] = 'pint';
            $scripts['test:list'] = 'pint --test';

            return $scripts;
        });

        $this->commitChanges($installed ? 'Install Pint' : 'Added Pint config and scripts');
    }

    /**
     * Verify that we are in a project with a composer.json.
     * 
     * @return void
     * 
     * @throw \RuntimeException
     */
    private function verifyInsideComposerProject(): void
    {
        if (!file_exists($this->directory . '/composer.json')) {
            throw new RuntimeException('File composer.json not found!');
        }
    }

    /**
     * Modify the scripts in the "composer.json" file using the given callback.
     *
     * @param  callable(array):array  $callback
     * @return void
     *
     * @throw \RuntimeException
     */
    protected function changeScripts(callable $callback): void
    {
        if (! $this->input->getOption('scripts')) {
            return;
        }

        $this->composer->modify(function ($content) use ($callback) {
            $content['scripts'] = $callback($content['scripts']);
            return $content;
        });
    }

    /**
     * Commit any changes in the current working directory.
     *
     * @param  string  $message
     * @return void
     */
    protected function commitChanges(string $message): void
    {
        if (! $this->input->getOption('commit')) {
            return;
        }

        $commands = [
            'git add .',
            "git commit -q -m \"$message\"",
        ];

        $this->runCommands($commands);
    }

    /**
     * Run the given commands.
     *
     * @param  array  $commands
     * @param  array  $env
     * @return \Symfony\Component\Process\Process
     */
    protected function runCommands(array $commands, array $env = []): Process
    {
        return $this->baseRunCommands($commands, $this->input, $this->output, $this->directory, $env);
    }

    protected function copyAsset(string $assetFile, string $targetFile, array $replaces = [])
    {
        $targetPath = $this->directory . '/' . $targetFile;

        if (file_exists($targetPath)) {
            if (! confirm(
                label: "File {$assetFile} already exists. Would you like to replace it?",
                default: false,
            )) {
                return;
            }
        }
        
        if (! copy(dirname(__DIR__) . '/assets/' . $assetFile, $targetPath)) {
            throw new RuntimeException("Cannot copy asset file {$assetFile}!");
        }

        if (count($replaces) > 0) {
            $content = file_get_contents($targetPath);
            $content = str_replace(array_keys($replaces), array_values($replaces), $content);
            file_put_contents($targetPath, $content);
        }
    }
}
