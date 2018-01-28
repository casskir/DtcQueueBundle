<?php

namespace Dtc\QueueBundle\Doctrine;

use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Dtc\QueueBundle\Model\StallableJob;
use Dtc\QueueBundle\Model\Run;
use Dtc\QueueBundle\Util\Util;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DtcQueueListener
{
    private $jobArchiveClass;
    private $runArchiveClass;

    public function __construct(ContainerInterface $container, $jobArchiveClass, $runArchiveClass)
    {
        $this->container = $container;
        $this->jobArchiveClass = $jobArchiveClass;
        $this->runArchiveClass = $runArchiveClass;
    }

    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        $object = $eventArgs->getObject();

        if ($object instanceof \Dtc\QueueBundle\Model\Job) {
            $this->processJob($object);
        } elseif ($object instanceof Run) {
            $this->processRun($object);
        }
    }

    public function processRun(Run $object)
    {
        $runArchiveClass = $this->runArchiveClass;
        if ($object instanceof $runArchiveClass) {
            return;
        }

        $runManager = $this->container->get('dtc_queue.manager.run');
        if (!$runManager instanceof DoctrineRunManager) {
            return;
        }

        $objectManager = $runManager->getObjectManager();
        $repository = $objectManager->getRepository($runArchiveClass);
        if (!$runArchive = $repository->find($object->getId())) {
            $runArchive = new $runArchiveClass();
            $newArchive = true;
        }

        Util::copy($object, $runArchive);
        if ($newArchive) {
            $metadata = $objectManager->getClassMetadata($runArchiveClass);
            $this->adjustIdGenerator($metadata, $objectManager);
        }

        $objectManager->persist($runArchive);
    }

    /**
     * @param $metadata
     */
    protected function adjustIdGenerator(\Doctrine\Common\Persistence\Mapping\ClassMetadata $metadata, ObjectManager $objectManager)
    {
        if ($objectManager instanceof EntityManager && $metadata instanceof \Doctrine\ORM\Mapping\ClassMetadata) {
            $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            $metadata->setIdGenerator(new AssignedGenerator());
        } elseif ($objectManager instanceof DocumentManager && $metadata instanceof ClassMetadata) {
            $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        }
    }

    public function processJob(\Dtc\QueueBundle\Model\Job $object)
    {
        $jobManager = $this->container->get('dtc_queue.manager.job');
        if (!$jobManager instanceof DoctrineJobManager) {
            return;
        }

        if ($object instanceof \Dtc\QueueBundle\Document\Job ||
            $object instanceof \Dtc\QueueBundle\Entity\Job) {
            /** @var JobManager $jobManager */
            $archiveObjectName = $this->jobArchiveClass;
            $objectManager = $jobManager->getObjectManager();
            $repository = $objectManager->getRepository($archiveObjectName);
            $className = $repository->getClassName();

            /** @var StallableJob $jobArchive */
            $newArchive = false;
            if (!$jobArchive = $repository->find($object->getId())) {
                $jobArchive = new $className();
                $newArchive = true;
            }

            if ($newArchive) {
                $metadata = $objectManager->getClassMetadata($className);
                $this->adjustIdGenerator($metadata, $objectManager);
            }

            Util::copy($object, $jobArchive);
            $jobArchive->setUpdatedAt(new \DateTime());
            $objectManager->persist($jobArchive);
        }
    }

    public function preUpdate(LifecycleEventArgs $eventArgs)
    {
        $object = $eventArgs->getObject();
        if ($object instanceof \Dtc\QueueBundle\Model\StallableJob) {
            $dateTime = \Dtc\QueueBundle\Util\Util::getMicrotimeDateTime();
            $object->setUpdatedAt($dateTime);
        }
    }

    public function prePersist(LifecycleEventArgs $eventArgs)
    {
        $object = $eventArgs->getObject();

        if ($object instanceof \Dtc\QueueBundle\Model\StallableJob) {
            $dateTime = \Dtc\QueueBundle\Util\Util::getMicrotimeDateTime();
            if (!$object->getCreatedAt()) {
                $object->setCreatedAt($dateTime);
            }
            $object->setUpdatedAt($dateTime);
        }
    }
}
