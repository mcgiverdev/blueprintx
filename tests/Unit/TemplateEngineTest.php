<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Kernel\DriverManager;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
    public function test_it_registers_hexagonal_templates_and_renders_output(): void
    {
        $container = new Container();
        $engine = new TemplateEngine([], ['debug' => false, 'cache' => false]);

        $definitions = [
            'hexagonal' => [
                'driver' => \BlueprintX\Kernel\Drivers\HexagonalDriver::class,
            ],
        ];

        $manager = new DriverManager(
            $engine,
            $container,
            $definitions,
            [
                'default_architecture' => 'hexagonal',
                'package_templates_path' => dirname(__DIR__, 2) . '/resources/templates',
                'override_templates_path' => dirname(__DIR__, 2) . '/resources/templates',
            ]
        );

        $driver = $manager->resolve('hexagonal');

        $output = $engine->render('@hexagonal/example.txt.twig', ['name' => 'blueprintx']);

        $this->assertSame('Hello Blueprintx!', trim($output));
        $this->assertContains('domain', $driver->layers());
    }
}
