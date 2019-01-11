<?php

namespace Illuminate\Console;

use Closure;
use Illuminate\Support\ProcessUtils;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Illuminate\Contracts\Console\Application as ApplicationContract;

/**
继承Symfony组件的应用类  具体文档位于https://symfony.com/doc/current/components/console.html
 **/
class Application extends SymfonyApplication implements ApplicationContract
{
    /**
     * The Laravel application instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $laravel;

    /**
     * The output from the previous command.
     *
     * @var \Symfony\Component\Console\Output\BufferedOutput
     */
    protected $lastOutput;

    /**
     * The console application bootstrappers.
     *
     * @var array
     */
    protected static $bootstrappers = [];

    /**
     * The Event Dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * Create a new Artisan console application.
     *
     * @param  \Illuminate\Contracts\Container\Container  $laravel
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  string  $version
     * @return void
     */
    public function __construct(Container $laravel, Dispatcher $events, $version)
    {
        parent::__construct('Laravel Framework', $version);

        /**
        laravel框架的容器
        事件调度器
         **/
        $this->laravel = $laravel;
        $this->events = $events;
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);

        $this->events->dispatch(new Events\ArtisanStarting($this));

        /**
         * 这里在框架启动时【先运行服务提供器--  Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class】
         * 这会把命令名称，命名具体类以key,value形式保存在应用容器里的bindings[]栈里
         * function ($artisan) use ($commands) {
            $artisan->resolveCommands($commands);
          }
         * 然后此函数即 protected static $bootstrappers = [function ($artisan) use ($commands) {
        $artisan->resolveCommands($commands);
        }];
         * 便会循环检索栈里的具体命令类更实例化
         * 实例化完成之后，将命令名称，命令对象以key,value保存在Symfony\Component\Console->commands[$command->getName()] = $command;
         * 里
         * laravel是对console【symfony console】做了二次的封装处理，具体使用文档在
         * https://symfony.com/doc/current/components/console.html
         * require __DIR__.'/vendor/autoload.php';

           use Symfony\Component\Console\Application;

           $application = new Application();

            // ... register commands

           $application->run();
         */
        $this->bootstrap();
    }

    /**
     * InputInterface 输入对象  取得终端输入的数据
     * OutputInterface 终端响应对象
     * {@inheritdoc}
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        /**
        获取命令名称 要么获取默认的命令，要么获取输入的命令参数
        假设键入的命令是php artisan route:list则该命令会返回route:list 否则返回默认的命名名称
         **/
        $commandName = $this->getCommandName(
            //输入对象  其实也就是 $argv参数的封装对象
            $input = $input ?: new ArgvInput
        );

        
        $this->events->fire(
            new Events\CommandStarting(
                $commandName, $input, $output = $output ?: new ConsoleOutput
            )
        );

        //调度 具体文档在 https://symfony.com/doc/current/components/console.html
        //Syfmony/Console/Application->run()
        /**
        require __DIR__.'/vendor/autoload.php';

        use Symfony\Component\Console\Application;

        $application = new Application();

        // ... register commands

        $application->run();
         **/
        $exitCode = parent::run($input, $output);

        $this->events->fire(
            new Events\CommandFinished($commandName, $input, $output, $exitCode)
        );

        return $exitCode;
    }

    /**
     * Determine the proper PHP executable.
     *
     * @return string
     */
    public static function phpBinary()
    {
        return ProcessUtils::escapeArgument((new PhpExecutableFinder)->find(false));
    }

    /**
     * Determine the proper Artisan executable.
     *
     * @return string
     */
    public static function artisanBinary()
    {
        return defined('ARTISAN_BINARY') ? ProcessUtils::escapeArgument(ARTISAN_BINARY) : 'artisan';
    }

    /**
     * Format the given command as a fully-qualified executable command.
     *
     * @param  string  $string
     * @return string
     */
    public static function formatCommandString($string)
    {
        return sprintf('%s %s %s', static::phpBinary(), static::artisanBinary(), $string);
    }

    /**
     * Register a console "starting" bootstrapper.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public static function starting(Closure $callback)
    {
        static::$bootstrappers[] = $callback;
    }

    /**
     * Bootstrap the console application.
     *
     * @return void
     */
    protected function bootstrap()
    {
        //执行前面的命令注册匿名函数
        /**
        Artisan::starting(function ($artisan) use ($command) {
        $artisan->resolve($command);
        });
         **/
        foreach (static::$bootstrappers as $bootstrapper) {
            $bootstrapper($this);
        }
    }

    /**
     * Clear the console application bootstrappers.
     *
     * @return void
     */
    public static function forgetBootstrappers()
    {
        static::$bootstrappers = [];
    }

    /**
     * Run an Artisan console command by name.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @param  \Symfony\Component\Console\Output\OutputInterface  $outputBuffer
     * @return int
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $parameters = collect($parameters)->prepend($command);

        $this->lastOutput = $outputBuffer ?: new BufferedOutput;

        $this->setCatchExceptions(false);

        $result = $this->run(new ArrayInput($parameters->toArray()), $this->lastOutput);

        $this->setCatchExceptions(true);

        return $result;
    }

    /**
     * Get the output for the last run command.
     *
     * @return string
     */
    public function output()
    {
        return $this->lastOutput ? $this->lastOutput->fetch() : '';
    }

    /**
     * Add a command to the console.
     *
     * @param  \Symfony\Component\Console\Command\Command  $command
     * @return \Symfony\Component\Console\Command\Command
     */
    public function add(SymfonyCommand $command)
    {
        //对象属于命令类
        if ($command instanceof Command) {
            // $this->laravel = web 的Application =Illuminate\Foundation\Application
            $command->setLaravel($this->laravel);
        }

        return $this->addToParent($command);
    }

    /**
     * Add the command to the parent instance.
     *
     * @param  \Symfony\Component\Console\Command\Command  $command
     * @return \Symfony\Component\Console\Command\Command
     */
    protected function addToParent(SymfonyCommand $command)
    {
        return parent::add($command);
    }

    /**
     * Add a command, resolving through the application.
     *添加一个命令，通过Application解决
     * @param  string  $command
     * @return \Symfony\Component\Console\Command\Command
     */
    public function resolve($command)
    {
        //$this->laravel laravel框架的Application对象 实例化指定的命令类
        return $this->add($this->laravel->make($command));
    }

    /**
     * Resolve an array of commands through the application.
     *
     * @param  array|mixed  $commands
     * @return $this
     */
    public function resolveCommands($commands)
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        foreach ($commands as $command) {
            $this->resolve($command);
        }

        return $this;
    }

    /**
     * Get the default input definitions for the applications.
     *
     * This is used to add the --env option to every available command.
     *
     * @return \Symfony\Component\Console\Input\InputDefinition
     */
    protected function getDefaultInputDefinition()
    {
        return tap(parent::getDefaultInputDefinition(), function ($definition) {
            $definition->addOption($this->getEnvironmentOption());
        });
    }

    /**
     * Get the global environment option for the definition.
     *
     * @return \Symfony\Component\Console\Input\InputOption
     */
    protected function getEnvironmentOption()
    {
        $message = 'The environment the command should run under';

        return new InputOption('--env', null, InputOption::VALUE_OPTIONAL, $message);
    }

    /**
     * Get the Laravel application instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    public function getLaravel()
    {
        return $this->laravel;
    }
}
