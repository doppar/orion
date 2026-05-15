<?php

namespace Phaseolies\DI {
    if (!class_exists(Container::class)) {
        class Container
        {
            private static ?self $instance = null;

            private array $bindings = [];

            public function singleton(string $abstract, callable|string|null $concrete = null): void
            {
                $this->bindings[$abstract] = $concrete ?? $abstract;
            }

            public function get(string $abstract): mixed
            {
                if (!array_key_exists($abstract, $this->bindings)) {
                    throw new \RuntimeException("Target [{$abstract}] is not bound in container");
                }

                $binding = $this->bindings[$abstract];

                return is_callable($binding) ? $binding() : $binding;
            }

            public function flush(): void
            {
                $this->bindings = [];
            }

            public static function setInstance(self $instance): void
            {
                self::$instance = $instance;
            }

            public static function getInstance(): self
            {
                return self::$instance ??= new self();
            }

            public static function forgetInstance(): void
            {
                self::$instance = null;
            }
        }
    }
}

namespace Phaseolies\Facade {
    use Phaseolies\DI\Container;

    if (!class_exists(BaseFacade::class)) {
        abstract class BaseFacade
        {
            protected static $app;

            abstract protected static function getFacadeAccessor();

            public static function setFacadeApplication($app)
            {
                static::$app = $app;
            }

            protected static function resolveInstance()
            {
                if (static::$app) {
                    return static::$app->get(static::getFacadeAccessor());
                }

                return Container::getInstance()->get(static::getFacadeAccessor());
            }

            public static function __callStatic($method, $args)
            {
                $instance = static::resolveInstance();

                if (!$instance) {
                    throw new \RuntimeException('A facade root has not been set.');
                }

                return $instance->$method(...$args);
            }
        }
    }
}

namespace Doppar\Orion\Tests\Unit {
    use Doppar\Orion\Support\Facades\Process;
    use InvalidArgumentException;
    use Phaseolies\DI\Container;
    use PHPUnit\Framework\TestCase;

    class ProcessFacadeTest extends TestCase
    {
        private Container $container;

        protected function setUp(): void
        {
            parent::setUp();

            $this->container = new Container();
            $this->container->flush();
            Container::setInstance($this->container);

            Process::setFacadeApplication(null);
            FakeProcessFactory::reset();
            FakePipelineFactory::reset();
            FakePoolFactory::reset();
        }

        protected function tearDown(): void
        {
            Process::setFacadeApplication(null);
            $this->container->flush();
            Container::forgetInstance();

            parent::tearDown();
        }

        public function testPingUsesContainerFallbackWhenFacadeApplicationIsNotSet(): void
        {
            $this->bindOrionServices($this->container);

            $process = Process::ping('echo container');

            $this->assertInstanceOf(FakePendingProcess::class, $process);
            $this->assertSame('echo container', $process->command);
            $this->assertSame(['echo container'], FakeProcessFactory::$createdCommands);
        }

        public function testPingUsesFacadeApplicationWhenSet(): void
        {
            $appContainer = new Container();
            $appContainer->flush();
            $this->bindOrionServices($appContainer);

            Process::setFacadeApplication(new FakeFacadeApplication($appContainer));

            $process = Process::ping('echo app');

            $this->assertInstanceOf(FakePendingProcess::class, $process);
            $this->assertSame('echo app', $process->command);
            $this->assertSame(['echo app'], FakeProcessFactory::$createdCommands);
        }

        public function testPingSilentlyUsesResolvedProcessService(): void
        {
            $this->bindOrionServices($this->container);

            $process = Process::pingSilently();

            $this->assertInstanceOf(FakePendingProcess::class, $process);
            $this->assertTrue($process->silent);
            $this->assertSame(1, FakeProcessFactory::$silentCalls);
        }

        public function testPipelineUsesResolvedPipelineService(): void
        {
            $this->bindOrionServices($this->container);

            $pipeline = Process::pipeline();

            $this->assertInstanceOf(FakePipelineInstance::class, $pipeline);
            $this->assertSame(1, FakePipelineFactory::$createCalls);
        }

        public function testPoolUsesResolvedPoolService(): void
        {
            $this->bindOrionServices($this->container);

            $pool = Process::pool();

            $this->assertInstanceOf(FakePoolInstance::class, $pool);
            $this->assertSame(1, FakePoolFactory::$createCalls);
        }

        public function testAsConcurrentlyUsesResolvedPoolAndWorkingDirectory(): void
        {
            $this->bindOrionServices($this->container);

            $results = Process::asConcurrently(['echo one', 'echo two'], '/tmp/orion-tests');

            $this->assertSame([
                [
                    'cwd' => '/tmp/orion-tests',
                    'commands' => ['echo one', 'echo two'],
                ],
            ], $results);
            $this->assertSame(1, FakePoolFactory::$createCalls);
        }

        public function testPingPreservesArrayCommands(): void
        {
            $this->bindOrionServices($this->container);

            $process = Process::ping(['echo', 'array-command']);

            $this->assertInstanceOf(FakePendingProcess::class, $process);
            $this->assertSame(['echo', 'array-command'], $process->command);
            $this->assertSame([['echo', 'array-command']], FakeProcessFactory::$createdCommands);
        }

        public function testPingRejectsDangerousStringCommands(): void
        {
            $this->bindOrionServices($this->container);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Potential command injection detected');

            Process::ping('echo bad; rm -rf /');
        }

        public function testPingRejectsDangerousArrayCommands(): void
        {
            $this->bindOrionServices($this->container);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Potential command injection detected');

            Process::ping(['echo', 'bad;']);
        }

        public function testPingRejectsNonStringArraySegments(): void
        {
            $this->bindOrionServices($this->container);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Command array may only contain strings');

            Process::ping(['echo', 123]);
        }

        public function testAsConcurrentlyRejectsDangerousCommands(): void
        {
            $this->bindOrionServices($this->container);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Potential command injection detected');

            Process::asConcurrently(['echo safe', 'echo unsafe && whoami']);
        }

        private function bindOrionServices(Container $container): void
        {
            $container->singleton('orion.process', fn() => new FakeProcessFactory());
            $container->singleton('orion.pipeline', fn() => new FakePipelineFactory());
            $container->singleton('orion.pool', fn() => new FakePoolFactory());
        }
    }

    class FakeFacadeApplication
    {
        public function __construct(private Container $container)
        {
        }

        public function get(string $key): mixed
        {
            return $this->container->get($key);
        }
    }

    class FakeProcessFactory
    {
        public static array $createdCommands = [];
        public static int $silentCalls = 0;

        public static function reset(): void
        {
            static::$createdCommands = [];
            static::$silentCalls = 0;
        }

        public static function create($command): FakePendingProcess
        {
            static::$createdCommands[] = $command;

            return new FakePendingProcess($command, false);
        }

        public static function pingSilently(): FakePendingProcess
        {
            static::$silentCalls++;

            return new FakePendingProcess(null, true);
        }
    }

    class FakePendingProcess
    {
        public function __construct(
            public string|array|null $command,
            public bool $silent
        ) {
        }
    }

    class FakePipelineFactory
    {
        public static int $createCalls = 0;

        public static function reset(): void
        {
            static::$createCalls = 0;
        }

        public static function create(): FakePipelineInstance
        {
            static::$createCalls++;

            return new FakePipelineInstance();
        }
    }

    class FakePipelineInstance
    {
    }

    class FakePoolFactory
    {
        public static int $createCalls = 0;

        public static function reset(): void
        {
            static::$createCalls = 0;
        }

        public static function create(): FakePoolInstance
        {
            static::$createCalls++;

            return new FakePoolInstance();
        }
    }

    class FakePoolInstance
    {
        private ?string $cwd = null;

        private array $commands = [];

        public function inDirectory(string $cwd): self
        {
            $this->cwd = $cwd;

            return $this;
        }

        public function add(string $command): self
        {
            $this->commands[] = $command;

            return $this;
        }

        public function waitForAll(): array
        {
            return [[
                'cwd' => $this->cwd,
                'commands' => $this->commands,
            ]];
        }
    }
}
