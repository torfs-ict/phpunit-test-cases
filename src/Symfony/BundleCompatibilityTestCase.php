<?php

declare(strict_types=1);

namespace TorfsICT\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class BundleCompatibilityTestCase extends TestCase
{
    private string $root;
    private string $directory;

    /**
     * @param string[] $command
     */
    protected function process(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->run();
        $this->assertEquals(0, $process->getExitCode());
    }

    protected function commit(string $cwd, string $message): void
    {
        $this->process(['git', 'add', '.'], $cwd);
        $this->process(['git', 'commit', '-m', $message], $cwd);
    }

    /**
     * @dataProvider symfonyVersionProvider
     */
    final public function testCompatibility(string $symfony): void
    {
        $package = $this->packageNameProvider();
        $endpoint = $this->flexEndpointProvider();

        $cwd = $this->root.'/.symfony';
        $this->directory = 'symfony-'.$symfony;

        // Create a new Symfony project
        $this->process(['symfony', 'new', $this->directory, '--version', $symfony, '--no-interaction'], $cwd);

        $cwd = $cwd.'/'.$this->directory;

        // Install the webapp-pack
        $this->process(['composer', 'require', 'symfony/webapp-pack:*', '--no-interaction'], $cwd);
        $this->commit($cwd, 'Installed webapp-pack');

        // Add a path repository for composer to our bundle
        $this->process(['composer', 'config', 'extra.symfony.allow-contrib', 'true'], $cwd);
        $composer = $cwd.'/composer.json';
        $json = json_decode((string) file_get_contents($composer), true);
        assert(is_array($json));
        $json = array_merge_recursive($json, [
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $this->packageRootProvider(),
                    'options' => [
                        'symlink' => true,
                        'versions' => [
                            $package => $this->packageVersionProvider(),
                        ],
                    ],
                ],
            ],
        ]);

        if (null !== $endpoint) {
            $json = array_merge_recursive($json, [
                'extra' => [
                    'symfony' => [
                        'endpoint' => [
                            $endpoint,
                            'flex://defaults',
                        ],
                    ],
                ],
            ]);
        }

        file_put_contents($composer, json_encode($json, JSON_PRETTY_PRINT));
        $this->commit($cwd, 'Added repository to composer');

        // Install our bundle
        $this->process(['composer', 'require', $package, '--no-interaction'], $cwd);
        $this->commit($cwd, 'Installed our bundle');

        $this->postCompatibilityTest($symfony);
    }

    protected function assertServiceExists(string $service, ?string $environment = null): void
    {
        $cmd = ['bin/console', 'debug:container'];
        if (null !== $environment) {
            $cmd[] = '-e';
            $cmd[] = $environment;
        }
        $cmd[] = $service;

        $cwd = $this->root.'/.symfony/'.$this->directory;

        $process = new Process($cmd, $cwd);
        $process->run();
        $message = sprintf('The Symfony service "%s" is not registered.', $service);
        $this->assertEquals(0, $process->getExitCode(), $message);
    }

    protected function setUp(): void
    {
        $this->root = $this->directory = (string) realpath(__DIR__.'/../../');

        foreach ($this->symfonyVersionProvider() as $arguments) {
            list($version) = $arguments;
            $directory = $this->root.'/.symfony/symfony-'.$version;
            $this->process(['rm', '-rf', $directory], $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    abstract public function postCompatibilityTest(string $version): void;

    abstract public function flexEndpointProvider(): ?string;

    abstract public function packageRootProvider(): string;

    abstract public function packageNameProvider(): string;

    abstract public function packageVersionProvider(): string;

    /**
     * @return array<int, string[]>
     */
    abstract public function symfonyVersionProvider(): array;
}
