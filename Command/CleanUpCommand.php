<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('jms-job-queue:clean-up')
            ->setDescription('Cleans up jobs which exceed the maximum retention time.')
            ->addOption('max-retention', null, InputOption::VALUE_REQUIRED, 'The maximum retention time (value must be parsable by DateTime).', '30 days')
            ->addOption('per-call', null, InputOption::VALUE_REQUIRED, 'The maximum number of jobs to clean-up per call.', 1000)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->getContainer()->get('doctrine');

        /** @var EntityManager $em */
        $em = $registry->getManagerForClass('JMSJobQueueBundle:Job');

        $count = 0;
        do {
            $jobs = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL")
                ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention')))
                ->setMaxResults(100)
                ->getResult();

            foreach ($jobs as $job) {
                /** @var Job $job */

                $count++;
                $incomingDepsCount = $em->getConnection()->executeQuery("SELECT source_job_id FROM jms_job_dependencies WHERE dest_job_id = :job_id", ['job_id' => $job->getId()])
                    ->fetchAll(\PDO::FETCH_COLUMN);

                if (count($incomingDepsCount) > 0) {
                    continue;
                }

                $em->remove($job);
            }

            $em->flush();

            if ($count >= $input->getOption('per-call')) {
                break;
            }
        } while (! empty($jobs));
    }
}
