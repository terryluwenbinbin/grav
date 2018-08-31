<?php
/**
 * @package    Grav.Common.Scheduler
 * @author     Originally based on peppeocchi/php-cron-scheduler modified for Grav integration
 * @copyright  Copyright (C) 2015 - 2018 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Scheduler;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use RocketTheme\Toolbox\File\YamlFile;

class Scheduler
{
    /**
     * The queued jobs.
     *
     * @var array
     */
    private $jobs = [];
    private $saved_jobs = [];
    private $jobs_run = [];
    private $outputSchedule = [];
    private $config;
    private $status_path;

    /**
     * Create new instance.
     *
     * @param  array  $config
     */
    public function __construct()
    {
        $config = Grav::instance()['config']->get('scheduler.defaults', []);
        $this->config = $config;
        $this->loadSavedJobs();

        $this->status_path = Grav::instance()['locator']->findResource('user://data/scheduler', true, true);
        if (!file_exists($this->status_path)) {
            Folder::create($this->status_path);
        }

    }

    /**
     * Load saved jobs from config/scheduler.yaml file
     */
    public function loadSavedJobs()
    {
        if (!$this->jobs) {
            $saved_jobs = (array) Grav::instance()['config']->get('scheduler.custom_jobs', []);

            foreach ($saved_jobs as $id => $j) {
                $args = isset($j['args']) ? $j['args'] : [];
                $id = Grav::instance()['inflector']->hyphenize($id);
                $job = $this->addCommand($j['command'], $args, $id);

                if (isset($j['at'])) {
                    $job->at($j['at']);
                }

                if (isset($j['output'])) {
                    $mode = isset($j['output_mode']) && $j['output_mode'] === 'append' ? true : false;
                    $job->output($j['output'], $mode);
                }

                // store in saved_jobs
                $this->saved_jobs[] = $job;
            }
        }
    }

    /**
     * Get the queued jobs as background/foreground
     *
     * @param bool $all
     * @return array
     */
    public function getQueuedJobs($all = false)
    {
        $background = [];
        $foreground = [];
        foreach ($this->jobs as $job) {
            if ($all || $job->enabled()) {
                if ($job->runInBackground()) {
                    $background[] = $job;
                } else {
                    $foreground[] = $job;
                }
            }

        }
        return [$background, $foreground];
    }

    /**
     * Get all jobs if they are disabled or not as one array
     *
     * @param bool $all
     * @return array
     */
    public function getAllJobs()
    {
        list($background, $foreground) = $this->getQueuedJobs(true);
        return array_merge($background, $foreground);
    }

    /**
     * Queues a PHP function execution.
     *
     * @param  callable  $fn  The function to execute
     * @param  array  $args  Optional arguments to pass to the php script
     * @param  string  $id   Optional custom identifier
     * @return Job
     */
    public function addFunction(callable $fn, $args = [], $id = null)
    {
        $job = new Job($fn, $args, $id);
        $this->queueJob($job->configure($this->config));
        return $job;
    }

    /**
     * Queue a raw shell command.
     *
     * @param  string  $command  The command to execute
     * @param  array  $args      Optional arguments to pass to the command
     * @param  string  $id       Optional custom identifier
     * @return Job
     */
    public function addCommand($command, $args = [], $id = null)
    {
        $job = new Job($command, $args, $id);
        $this->queueJob($job->configure($this->config));
        return $job;
    }

    /**
     * Run the scheduler.
     *
     * @param  \DateTime  $runTime  Optional, run at specific moment
     * @return array  Executed jobs
     */
    public function run(\Datetime $runTime = null)
    {
        list($background, $foreground) = $this->getQueuedJobs(false);
        $alljobs = array_merge($background, $foreground);

        if (is_null($runTime)) {
            $runTime = new \DateTime('now');
        }

        // Star processing jobs
        foreach ($alljobs as $job) {
            if ($job->isDue($runTime)) {
                $job->run();
                $this->jobs_run[] = $job;
            }
        }

        // Finish handling any background jobs
        foreach($background as $job) {
            $job->finalize();
        }

        // Store states
        $this->saveJobStates();
    }

    /**
     * Reset all collected data of last run.
     *
     * Call before run() if you call run() multiple times.
     */
    public function resetRun()
    {
        // Reset collected data of last run
        $this->executedJobs = [];
        $this->failedJobs = [];
        $this->outputSchedule = [];
        return $this;
    }

    /**
     * Get the scheduler verbose output.
     *
     * @param  string  $type  Allowed: text, html, array
     * @return mixed  The return depends on the requested $type
     */
    public function getVerboseOutput($type = 'text')
    {
        switch ($type) {
            case 'text':
                return implode("\n", $this->outputSchedule);
            case 'html':
                return implode('<br>', $this->outputSchedule);
            case 'array':
                return $this->outputSchedule;
            default:
                throw new \InvalidArgumentException('Invalid output type');
        }
    }

    /**
     * Remove all queued Jobs.
     */
    public function clearJobs()
    {
        $this->jobs = [];
        return $this;
    }

    /**
     * Helper to get the full Cron command
     *
     * @return string
     */
    public function getCronCommand()
    {
        $phpBinaryFinder = new PhpExecutableFinder();
        $php = $phpBinaryFinder->find();
        $command = 'cd ' . GRAV_ROOT . ';' . $php . ' bin/grav scheduler';

        return "* * * * * {$command} 1>> /dev/null 2>&1";
    }

    /**
     * Helper to determine if cron job is setup
     *
     * @return int
     */
    public function isCrontabSetup()
    {
        $process = new Process('crontab -l');
        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();

            if (preg_match('$bin\/grav schedule$', $output)) {
                return 1;
            } else {
                return 0;
            }
        } else {
            return 2;
        }
    }

    /**
     * Get the Job states file
     *
     * @return \RocketTheme\Toolbox\File\FileInterface|YamlFile
     */
    public function getJobStates()
    {
        $file = YamlFile::instance($this->status_path . '/status.yaml');
        return $file;
    }

    /**
     * Save job states to statys file
     */
    private function saveJobStates()
    {
        $now = time();
        $new_states = [];

        foreach ($this->jobs_run as $job) {
            if ($job->isSuccessful()) {
                $new_states[$job->getId()] = ['state' => 'success', 'last-run' => $now];
                $this->pushExecutedJob($job);
            } else {
                $new_states[$job->getId()] = ['state' => 'failure', 'last-run' => $now, 'error' => $job->getOutput()];
                $this->pushFailedJob($job);
            }
        }
        $saved_states = $this->getJobStates();
        $saved_states->save(array_merge($saved_states->content(), $new_states));
    }

    /**
     * Queue a job for execution in the correct queue.
     *
     * @param  Job  $job
     * @return void
     */
    private function queueJob(Job $job)
    {
        $this->jobs[] = $job;

        // Store jobs
    }

    /**
     * Add an entry to the scheduler verbose output array.
     *
     * @param  string  $string
     * @return void
     */
    private function addSchedulerVerboseOutput($string)
    {
        $now = '[' . (new \DateTime('now'))->format('c') . '] ';
        $this->outputSchedule[] = $now . $string;
        // Print to stdoutput in light gray
        // echo "\033[37m{$string}\033[0m\n";
    }

    /**
     * Push a succesfully executed job.
     *
     * @param  Job  $job
     * @return Job
     */
    private function pushExecutedJob(Job $job)
    {
        $this->executedJobs[] = $job;
        $command = $job->getCommand();
        $args = $job->getArguments();
        // If callable, log the string Closure
        if (is_callable($command)) {
            $command = is_string($command) ? $command : 'Closure';
        }
        $this->addSchedulerVerboseOutput("<green>Success</green>: <white>{$command} {$args}</white>");
        return $job;
    }

    /**
     * Push a failed job.
     *
     * @param  Job  $job
     * @return Job
     */
    private function pushFailedJob(Job $job)
    {
        $this->failedJobs[] = $job;
        $command = $job->getCommand();
        // If callable, log the string Closure
        if (is_callable($command)) {
            $command = is_string($command) ? $command : 'Closure';
        }
        $output = trim($job->getOutput());
        $this->addSchedulerVerboseOutput("<red>Error</red>:   <white>{$command}</white> → <normal>{$output}</normal>");
        return $job;
    }
}
