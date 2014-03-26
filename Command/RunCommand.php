<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Util\Debug;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Repository\JobRepository;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Process\Exception\ProcessFailedException;

use JMS\JobQueueBundle\Exception\LogicException;
use JMS\JobQueueBundle\Exception\InvalidArgumentException;
use JMS\JobQueueBundle\Event\NewOutputEvent;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends \Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand
{
    private $env;
    private $verbose;
    private $output;
    private $registry;
    private $dispatcher;
    private $runningJobs = array();

    const DEFAULT_QUEUE = 'DEFAULT_QUEUE';

    const PROCESS = 'process';

    const JOB = 'job';

    protected function configure()
    {
        $this
            ->setName('jms-job-queue:run')
            ->setDescription('Runs jobs from the queue.')
            ->addOption('queue-name', 'qn', InputOption::VALUE_REQUIRED, 'the queue name', self::DEFAULT_QUEUE)
            ->addOption('max-runtime', 'r', InputOption::VALUE_REQUIRED, 'The maximum runtime in seconds.', 900)
            ->addOption('max-concurrent-jobs', 'j', InputOption::VALUE_REQUIRED, 'The maximum number of concurrent jobs.', 5)
        ;
    }

    protected function preFindStartableJob() {
    }

    public function shutdownGracefully() {
        foreach($this->runningJobs as $job) {
            /**
             * @var Process $process
             */
            $process = $job[self::PROCESS];
            /**
             * @var Job $job
             */
            $job = $job[self::JOB];
            if ($process->isRunning()) {
                $process->stop(10, SIGTERM);
                if ($job->getIsIdempotent()) {
                    $job->reset();
                    $em = $this->getEntityManager();
                    $em->persist($job);
                    $em->flush($job);
                }
            }
        }
    }

    protected function init() {

    }

    protected function onFailure(Job $job) {
    }

    protected function onSuccess(Job $job) {
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init();
        $startTime = time();

        $maxRuntime = (integer) $input->getOption('max-runtime');
        if ($maxRuntime <= 0) {
            throw new InvalidArgumentException('The maximum runtime must be greater than zero.');
        }

        $maxConcurrentJobs = (integer) $input->getOption('max-concurrent-jobs');
        if ($maxConcurrentJobs <= 0) {
            throw new InvalidArgumentException('The maximum number of concurrent jobs must be greater than zero.');
        }

        $queueName = (string) $input->getOption('queue-name');
        if (empty($queueName)) {
            throw new InvalidArgumentException('The queue name must be specified');
        }

        $this->env = $input->getOption('env');
        $this->verbose = $input->getOption('verbose');
        $this->output = $output;
        $this->registry = $this->getContainer()->get('doctrine');
        $this->dispatcher = $this->getContainer()->get('event_dispatcher');
        $this->getEntityManager()->getConnection()->getConfiguration()->setSQLLogger(null);

        //$this->cleanUpStaleJobs();  -- can't clean up stale jobs when jobs are running across multiple nodes

        while (time() - $startTime < $maxRuntime) {
            $this->checkRunningJobs();

            $excludedIds = array();
            while (count($this->runningJobs) < $maxConcurrentJobs) {
                try {
                    $this->preFindStartableJob();
                    $this->getRepository()->startTrasaction();
                    if (null === $pendingJob = $this->getRepository()->findStartableJob($queueName, $excludedIds)) {
                        $this->getRepository()->commitTransaction();
                        sleep(2);
                        continue 2; // Check if the maximum runtime has been exceeded.
                    }
                    $this->startJob($pendingJob);
                    $this->getRepository()->commitTransaction();
                } catch(Exception $e) {
                    $this->getRepository()->rollbackTransaction();
                }
                sleep(1);
                $this->checkRunningJobs();
            }

            sleep(2);
        }

        if (count($this->runningJobs) > 0) {
            while (count($this->runningJobs) > 0) {
                $this->checkRunningJobs();
                sleep(2);
            }
        }

        return 0;
    }

    private function checkRunningJobs()
    {
        foreach ($this->runningJobs as $i => &$data) {
            $newOutput = substr($data[self::PROCESS]->getOutput(), $data['output_pointer']);
            $data['output_pointer'] += strlen($newOutput);

            $newErrorOutput = substr($data[self::PROCESS]->getErrorOutput(), $data['error_output_pointer']);
            $data['error_output_pointer'] += strlen($newErrorOutput);

            if ( ! empty($newOutput)) {
                $event = new NewOutputEvent($data[self::JOB], $newOutput, NewOutputEvent::TYPE_STDOUT);
                $this->dispatcher->dispatch('jms_job_queue.new_job_output', $event);
                $newOutput = $event->getNewOutput();
            }

            if ( ! empty($newErrorOutput)) {
                $event = new NewOutputEvent($data[self::JOB], $newErrorOutput, NewOutputEvent::TYPE_STDERR);
                $this->dispatcher->dispatch('jms_job_queue.new_job_output', $event);
                $newErrorOutput = $event->getNewOutput();
            }

            if ($this->verbose) {
                if ( ! empty($newOutput)) {
                    $this->output->writeln('Job '.$data[self::JOB]->getId().': '.str_replace("\n", "\nJob ".$data[self::JOB]->getId().": ", $newOutput));
                }

                if ( ! empty($newErrorOutput)) {
                    $this->output->writeln('Job '.$data[self::JOB]->getId().': '.str_replace("\n", "\nJob ".$data[self::JOB]->getId().": ", $newErrorOutput));
                }
            }

            // Check whether this process exceeds the maximum runtime, and terminate if that is
            // the case.
            $runtime = time() - $data[self::JOB]->getStartedAt()->getTimestamp();
            if ($data[self::JOB]->getMaxRuntime() > 0 && $runtime > $data[self::JOB]->getMaxRuntime()) {
                $data[self::PROCESS]->stop(5);

                $this->output->writeln($data[self::JOB].' terminated; maximum runtime exceeded.');
                $this->getRepository()->closeJob($data[self::JOB], Job::STATE_TERMINATED);
                unset($this->runningJobs[$i]);

                continue;
            }

            if ($data[self::PROCESS]->isRunning()) {
                // For long running processes, it is nice to update the output status regularly.
                $data[self::JOB]->addOutput($newOutput);
                $data[self::JOB]->addErrorOutput($newErrorOutput);
                $data[self::JOB]->checked();
                $em = $this->getEntityManager();
                $em->persist($data[self::JOB]);
                $em->flush($data[self::JOB]);

                continue;
            }

            $this->output->writeln($data[self::JOB].' finished with exit code '.$data[self::PROCESS]->getExitCode().'.');

            // If the Job exited with an exception, let's reload it so that we
            // get access to the stack trace. This might be useful for listeners.
            $this->getEntityManager()->refresh($data[self::JOB]);

            $data[self::JOB]->setExitCode($data[self::PROCESS]->getExitCode());
            $data[self::JOB]->setOutput($data[self::PROCESS]->getOutput());
            $data[self::JOB]->setErrorOutput($data[self::PROCESS]->getErrorOutput());
            $data[self::JOB]->setRuntime(time() - $data['start_time']);
            $newState = 0 === $data[self::PROCESS]->getExitCode() ? Job::STATE_FINISHED : Job::STATE_FAILED;
            if ($newState == Job::STATE_FAILED) {
                $this->onFailure($data[self::JOB]);
            } else {
                $this->onSuccess($data[self::JOB]);
            }
            $this->getRepository()->closeJob($data[self::JOB], $newState);
            unset($this->runningJobs[$i]);
        }

        gc_collect_cycles();
    }

    private function startJob(Job $job)
    {
        $event = new StateChangeEvent($job, Job::STATE_RUNNING);
        $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
        $newState = $event->getNewState();

        if (Job::STATE_CANCELED === $newState) {
            $this->getRepository()->closeJob($job, Job::STATE_CANCELED);

            return;
        }

        if (Job::STATE_RUNNING !== $newState) {
            throw new \LogicException(sprintf('Unsupported new state "%s".', $newState));
        }

        $job->setState(Job::STATE_RUNNING);
        $em = $this->getEntityManager();
        $em->persist($job);
        $em->flush($job);

        $pb = $this->getCommandProcessBuilder();
        $pb
            ->add($job->getCommand())
            ->add('--jms-job-id='.$job->getId())
        ;

        foreach ($job->getArgs() as $arg) {
            $pb->add($arg);
        }
        $proc = $pb->getProcess();
        $proc->start();
        $this->output->writeln(sprintf('Started %s.', $job));

        $this->runningJobs[] = array(
            self::PROCESS => $proc,
            self::JOB => $job,
            'start_time' => time(),
            'output_pointer' => 0,
            'error_output_pointer' => 0,
        );
    }

    /**
     * Cleans up stale jobs.
     *
     * A stale job is a job where this command has exited with an error
     * condition. Although this command is very robust, there might be cases
     * where it might be terminated abruptly (like a PHP segfault, a SIGTERM signal, etc.).
     *
     * In such an error condition, these jobs are cleaned-up on restart of this command.
     */
    private function cleanUpStaleJobs()
    {
        $repo = $this->getRepository();
        foreach ($repo->findBy(array('state' => Job::STATE_RUNNING)) as $job) {
            // If the original job has retry jobs, then one of them is still in
            // running state. We can skip the original job here as it will be
            // processed automatically once the retry job is processed.
            if ( ! $job->isRetryJob() && count($job->getRetryJobs()) > 0) {
                continue;
            }

            $pb = $this->getCommandProcessBuilder();
            $pb
                ->add('jms-job-queue:mark-incomplete')
                ->add($job->getId())
                ->add('--env='.$this->env)
                ->add('--verbose')
            ;

            // We use a separate process to clean up.
            $proc = $pb->getProcess();
            if (0 !== $proc->run()) {
                $ex = new ProcessFailedException($proc);

                $this->output->writeln(sprintf('There was an error when marking %s as incomplete: %s', $job, $ex->getMessage()));
            }
        }
    }

    private function getCommandProcessBuilder()
    {
        $pb = new ProcessBuilder();

        // PHP wraps the process in "sh -c" by default, but we need to control
        // the process directly.
        if ( ! defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $pb->add('exec');
        }

        $pb
            ->add('php')
            ->add($this->getContainer()->getParameter('kernel.root_dir').'/console')
            ->add('--env='.$this->env)
        ;

        if ($this->verbose) {
            $pb->add('--verbose');
        }

        return $pb;
    }

    /**
     * @return EntityManager
     */
    private function getEntityManager()
    {
        return $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }

    /**
     * @return JobRepository
     */
    private function getRepository()
    {
        return $this->getEntityManager()->getRepository('JMSJobQueueBundle:Job');
    }
}