<?php

namespace WpStarter\Console\Scheduling;

use Closure;
use DateTimeInterface;
use WpStarter\Bus\UniqueLock;
use WpStarter\Console\Application;
use WpStarter\Container\Container;
use WpStarter\Contracts\Bus\Dispatcher;
use WpStarter\Contracts\Cache\Repository as Cache;
use WpStarter\Contracts\Container\BindingResolutionException;
use WpStarter\Contracts\Queue\ShouldBeUnique;
use WpStarter\Contracts\Queue\ShouldQueue;
use WpStarter\Queue\CallQueuedClosure;
use WpStarter\Support\ProcessUtils;
use WpStarter\Support\Str;
use WpStarter\Support\Traits\Macroable;
use RuntimeException;

class Schedule
{
    use Macroable;

    const SUNDAY = 0;
    const MONDAY = 1;
    const TUESDAY = 2;
    const WEDNESDAY = 3;
    const THURSDAY = 4;
    const FRIDAY = 5;
    const SATURDAY = 6;

    /**
     * All of the events on the schedule.
     *
     * @var \WpStarter\Console\Scheduling\Event[]
     */
    protected $events = [];

    /**
     * The event mutex implementation.
     *
     * @var \WpStarter\Console\Scheduling\EventMutex
     */
    protected $eventMutex;

    /**
     * The scheduling mutex implementation.
     *
     * @var \WpStarter\Console\Scheduling\SchedulingMutex
     */
    protected $schedulingMutex;

    /**
     * The timezone the date should be evaluated on.
     *
     * @var \DateTimeZone|string
     */
    protected $timezone;

    /**
     * The job dispatcher implementation.
     *
     * @var \WpStarter\Contracts\Bus\Dispatcher
     */
    protected $dispatcher;

    /**
     * Create a new schedule instance.
     *
     * @param  \DateTimeZone|string|null  $timezone
     * @return void
     *
     * @throws \RuntimeException
     */
    public function __construct($timezone = null)
    {
        $this->timezone = $timezone;

        if (! class_exists(Container::class)) {
            throw new RuntimeException(
                'A container implementation is required to use the scheduler. Please install the wpstarter/container package.'
            );
        }

        $container = Container::getInstance();

        $this->eventMutex = $container->bound(EventMutex::class)
                                ? $container->make(EventMutex::class)
                                : $container->make(CacheEventMutex::class);

        $this->schedulingMutex = $container->bound(SchedulingMutex::class)
                                ? $container->make(SchedulingMutex::class)
                                : $container->make(CacheSchedulingMutex::class);
    }

    /**
     * Add a new callback event to the schedule.
     *
     * @param  string|callable  $callback
     * @param  array  $parameters
     * @return \WpStarter\Console\Scheduling\CallbackEvent
     */
    public function call($callback, array $parameters = [])
    {
        $this->events[] = $event = new CallbackEvent(
            $this->eventMutex, $callback, $parameters, $this->timezone
        );

        return $event;
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \WpStarter\Console\Scheduling\Event
     */
    public function command($command, array $parameters = [])
    {
        if (class_exists($command)) {
            $command = Container::getInstance()->make($command);

            return $this->exec(
                Application::formatCommandString($command->getName()), $parameters,
            )->description($command->getDescription());
        }

        return $this->exec(
            Application::formatCommandString($command), $parameters
        );
    }

    /**
     * Add a new job callback event to the schedule.
     *
     * @param  object|string  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @return \WpStarter\Console\Scheduling\CallbackEvent
     */
    public function job($job, $queue = null, $connection = null)
    {
        return $this->call(function () use ($job, $queue, $connection) {
            $job = is_string($job) ? Container::getInstance()->make($job) : $job;

            if ($job instanceof ShouldQueue) {
                $this->dispatchToQueue($job, $queue ?? $job->queue, $connection ?? $job->connection);
            } else {
                $this->dispatchNow($job);
            }
        })->name(is_string($job) ? $job : get_class($job));
    }

    /**
     * Dispatch the given job to the queue.
     *
     * @param  object  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function dispatchToQueue($job, $queue, $connection)
    {
        if ($job instanceof Closure) {
            if (! class_exists(CallQueuedClosure::class)) {
                throw new RuntimeException(
                    'To enable support for closure jobs, please install the wpstarter/queue package.'
                );
            }

            $job = CallQueuedClosure::create($job);
        }

        if ($job instanceof ShouldBeUnique) {
            return $this->dispatchUniqueJobToQueue($job, $queue, $connection);
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given unique job to the queue.
     *
     * @param  object  $job
     * @param  string|null  $queue
     * @param  string|null  $connection
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function dispatchUniqueJobToQueue($job, $queue, $connection)
    {
        if (! Container::getInstance()->bound(Cache::class)) {
            throw new RuntimeException('Cache driver not available. Scheduling unique jobs not supported.');
        }

        if (! (new UniqueLock(Container::getInstance()->make(Cache::class)))->acquire($job)) {
            return;
        }

        $this->getDispatcher()->dispatch(
            $job->onConnection($connection)->onQueue($queue)
        );
    }

    /**
     * Dispatch the given job right now.
     *
     * @param  object  $job
     * @return void
     */
    protected function dispatchNow($job)
    {
        $this->getDispatcher()->dispatchNow($job);
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \WpStarter\Console\Scheduling\Event
     */
    public function exec($command, array $parameters = [])
    {
        if (count($parameters)) {
            $command .= ' '.$this->compileParameters($parameters);
        }

        $this->events[] = $event = new Event($this->eventMutex, $command, $this->timezone);

        return $event;
    }

    /**
     * Compile parameters for a command.
     *
     * @param  array  $parameters
     * @return string
     */
    protected function compileParameters(array $parameters)
    {
        return ws_collect($parameters)->map(function ($value, $key) {
            if (is_array($value)) {
                return $this->compileArrayInput($key, $value);
            }

            if (! is_numeric($value) && ! preg_match('/^(-.$|--.*)/i', $value)) {
                $value = ProcessUtils::escapeArgument($value);
            }

            return is_numeric($key) ? $value : "{$key}={$value}";
        })->implode(' ');
    }

    /**
     * Compile array input for a command.
     *
     * @param  string|int  $key
     * @param  array  $value
     * @return string
     */
    public function compileArrayInput($key, $value)
    {
        $value = ws_collect($value)->map(function ($value) {
            return ProcessUtils::escapeArgument($value);
        });

        if (Str::startsWith($key, '--')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key}={$value}";
            });
        } elseif (Str::startsWith($key, '-')) {
            $value = $value->map(function ($value) use ($key) {
                return "{$key} {$value}";
            });
        }

        return $value->implode(' ');
    }

    /**
     * Determine if the server is allowed to run this event.
     *
     * @param  \WpStarter\Console\Scheduling\Event  $event
     * @param  \DateTimeInterface  $time
     * @return bool
     */
    public function serverShouldRun(Event $event, DateTimeInterface $time)
    {
        return $this->schedulingMutex->create($event, $time);
    }

    /**
     * Get all of the events on the schedule that are due.
     *
     * @param  \WpStarter\Contracts\Foundation\Application  $app
     * @return \WpStarter\Support\Collection
     */
    public function dueEvents($app)
    {
        return ws_collect($this->events)->filter->isDue($app);
    }

    /**
     * Get all of the events on the schedule.
     *
     * @return \WpStarter\Console\Scheduling\Event[]
     */
    public function events()
    {
        return $this->events;
    }

    /**
     * Specify the cache store that should be used to store mutexes.
     *
     * @param  string  $store
     * @return $this
     */
    public function useCache($store)
    {
        if ($this->eventMutex instanceof CacheAware) {
            $this->eventMutex->useStore($store);
        }

        if ($this->schedulingMutex instanceof CacheAware) {
            $this->schedulingMutex->useStore($store);
        }

        return $this;
    }

    /**
     * Get the job dispatcher, if available.
     *
     * @return \WpStarter\Contracts\Bus\Dispatcher
     *
     * @throws \RuntimeException
     */
    protected function getDispatcher()
    {
        if ($this->dispatcher === null) {
            try {
                $this->dispatcher = Container::getInstance()->make(Dispatcher::class);
            } catch (BindingResolutionException $e) {
                throw new RuntimeException(
                    'Unable to resolve the dispatcher from the service container. Please bind it or install the wpstarter/bus package.',
                    $e->getCode(), $e
                );
            }
        }

        return $this->dispatcher;
    }
}
