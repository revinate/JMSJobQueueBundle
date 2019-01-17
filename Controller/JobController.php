<?php

namespace JMS\JobQueueBundle\Controller;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Util\ClassUtils;
use JMS\DiExtraBundle\Annotation as DI;
use JMS\JobQueueBundle\Entity\Job;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;

class JobController
{
    /**
     * @DI\Inject("doctrine")
     * @var Registry
     */
    private $registry;

    /**
     * @DI\Inject
     * @var RouterInterface
     */
    private $router;

    /**
     * @DI\Inject("%jms_job_queue.statistics%")
     */
    private $statisticsEnabled;

    /**
     * @Route("/", name = "jms_jobs_overview")
     * @Template("@JMSJobQueue/Job/overview.html.twig")
     *
     * @param Request $request
     *
     * @return array
     */
    public function overviewAction(Request $request)
    {
        $state = $request->query->get('state', null);
        $queue = $request->query->get('queue', null);
        $minDelay = $request->query->get('delay', null);
        $page = max(1, (integer) $request->query->get('page', 1));
        $qb = $this->getEm()->createQueryBuilder();
        $qb->select('partial j.{id, state, createdAt, startedAt, checkedAt, executeAfter, closedAt, command, exitCode, runtime, queueName, args, lastGracefullyShutdownAt}')
            ->from('JMSJobQueueBundle:Job', 'j')
            ->where($qb->expr()->isNull('j.originalJob'))
            ->orderBy('j.id', 'desc');
        if (!is_null($state)) {
            $qb->andWhere('j.state = :state')->setParameter('state', $state);
        }
        if (!is_null($queue)) {
            $qb->andWhere('j.queueName = :queue')->setParameter('queue', $queue);
        }
        if (!is_null($minDelay)) {
            $timeInSeconds = strtotime("+$minDelay")-time();
            $qb->andWhere('TIME_DIFF(j.startedAt, j.executeAfter) > :min_delay')->setParameter('min_delay', $timeInSeconds);
        }
        $queueQb = $this->getEm()->createQueryBuilder();
        $states = array(Job::STATE_CANCELED, Job::STATE_FAILED, Job::STATE_FINISHED, Job::STATE_INCOMPLETE, Job::STATE_PENDING, Job::STATE_RUNNING, Job::STATE_TERMINATED);
        $queues = array_map(
            function($job) {
                return $job['queueName'];
            },
            $queueQb->select('distinct j.queueName')->from('JMSJobQueueBundle:Job', 'j')->getQuery()->execute());
        $jobs = $qb->setMaxResults(20)->setFirstResult(($page - 1) * 20)->getQuery()->getResult();

        return array(
            'jobPager' => $jobs,
            'states' => $states,
            'queues' => $queues,
            'page' => $page
        );
    }

    /**
     * @Route("/{id}", name = "jms_jobs_details", options={"expose"=true})
     * @Template("@JMSJobQueue/Job/details.html.twig")
     */
    public function detailsAction(Job $job)
    {
        $relatedEntities = array();
        foreach ($job->getRelatedEntities() as $entity) {
            $class = ClassUtils::getClass($entity);
            $relatedEntities[] = array(
                'class' => $class,
                'id' => json_encode($this->registry->getManagerForClass($class)->getClassMetadata($class)->getIdentifierValues($entity)),
                'raw' => $entity,
            );
        }

        $statisticData = $statisticOptions = array();
        if ($this->statisticsEnabled) {
            $dataPerCharacteristic = array();
            foreach ($this->registry->getManagerForClass('JMSJobQueueBundle:Job')->getConnection()->query("SELECT * FROM jms_job_statistics WHERE job_id = ".$job->getId()) as $row) {
                $dataPerCharacteristic[$row['characteristic']][] = array(
                    $row['createdAt'],
                    $row['charValue'],
                );
            }

            if ($dataPerCharacteristic) {
                $statisticData = array(array_merge(array('Time'), $chars = array_keys($dataPerCharacteristic)));
                $startTime = strtotime($dataPerCharacteristic[$chars[0]][0][0]);
                $endTime = strtotime($dataPerCharacteristic[$chars[0]][count($dataPerCharacteristic[$chars[0]])-1][0]);
                $scaleFactor = $endTime - $startTime > 300 ? 1/60 : 1;

                // This assumes that we have the same number of rows for each characteristic.
                for ($i=0,$c=count(reset($dataPerCharacteristic)); $i<$c; $i++) {
                    $row = array((strtotime($dataPerCharacteristic[$chars[0]][$i][0]) - $startTime) * $scaleFactor);
                    foreach ($chars as $name) {
                        $value = (float) $dataPerCharacteristic[$name][$i][1];

                        switch ($name) {
                            case 'memory':
                                $value /= 1024 * 1024;
                                break;
                        }

                        $row[] = $value;
                    }

                    $statisticData[] = $row;
                }
            }
        }

        return array(
            'job' => $job,
            'relatedEntities' => $relatedEntities,
            'incomingDependencies' => $this->getRepo()->getIncomingDependencies($job),
            'statisticData' => $statisticData,
            'statisticOptions' => $statisticOptions,
        );
    }

    /**
     * @Route("/{id}/retry", name = "jms_jobs_retry_job")
     */
    public function retryJobAction(Job $job)
    {
        $state = $job->getState();

        if (
            Job::STATE_FAILED !== $state &&
            Job::STATE_TERMINATED !== $state &&
            Job::STATE_INCOMPLETE !== $state &&
            Job::STATE_RUNNING !== $state //allow running jobs to be retried (sometimes failed jobs stay in running state)
        ) {
            throw new HttpException(400, 'Given job can\'t be retried');
        }

        $retryJob = clone $job;
        $retryJob->setOriginalJob($job);

        $this->getEm()->persist($retryJob);
        $this->getEm()->flush();

        $url = $this->router->generate('jms_jobs_details', array('id' => $retryJob->getId()), false);

        return new RedirectResponse($url, 201);
    }

    /** @return \Doctrine\ORM\EntityManager */
    private function getEm()
    {
        return $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }

    /** @return \JMS\JobQueueBundle\Entity\Repository\JobRepository */
    private function getRepo()
    {
        return $this->getEm()->getRepository('JMSJobQueueBundle:Job');
    }
}
