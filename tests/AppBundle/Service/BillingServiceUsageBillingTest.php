<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\Billing\BillableItem;
use AppBundle\Entity\CloudInstanceProvider\AwsCloudInstanceProvider;
use AppBundle\Entity\RemoteDesktop\Event\RemoteDesktopRelevantForBillingEvent;
use AppBundle\Entity\RemoteDesktop\RemoteDesktop;
use AppBundle\Entity\RemoteDesktop\RemoteDesktopGamingProKind;
use AppBundle\Service\BillingService;
use AppBundle\Utility\DateTimeUtility;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class BillingServiceUsageBillingTest extends TestCase
{

    protected function getRemoteDesktop() : RemoteDesktop
    {
        $remoteDesktop = new RemoteDesktop();
        $remoteDesktop->setCloudInstanceProvider(new AwsCloudInstanceProvider());
        $remoteDesktop->setId('r1');
        $remoteDesktop->setKind(new RemoteDesktopGamingProKind());
        $cloudInstanceProvider = $remoteDesktop->getKind()->getCloudInstanceProvider();
        $remoteDesktop->addCloudInstance(
            $cloudInstanceProvider->createInstanceForRemoteDesktopAndRegion(
                $remoteDesktop,
                $cloudInstanceProvider->getRegionByInternalName('eu-central-1')
            )
        );
        return $remoteDesktop;
    }

    public function testNoUsageBillableItemsForRemoteDesktopWithoutUsageEvents()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        // For usage billing, this event type must be ignored
        $provisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$provisioningEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('now'),
            BillableItem::TYPE_USAGE
        );

        $this->assertEmpty($billableItems);
    }


    public function testOneUsageBillableItemForLaunchedRemoteDesktop()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $event = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$event]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 18:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(1, $billableItems);

        $actualBillableItem = $billableItems[0];

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $actualBillableItem->getTimewindowBegin());
    }


    public function testOneUsageBillableItemForMultipleTimesLaunchedAndStoppedRemoteDesktop()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:39:01')
        );

        $finishedLaunchingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:20:00')
        );

        $beganStoppingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:30:00')
        );

        // For usage billing, this event type must be ignored
        $provisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:31:00')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([
                $finishedLaunchingEvent1,
                $beganStoppingEvent1,
                $finishedLaunchingEvent2,
                $beganStoppingEvent2,
                $provisioningEvent
            ]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 22:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(1, $billableItems);

        $actualBillableItem = $billableItems[0];

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $actualBillableItem->getTimewindowBegin());
        $this->assertEquals(BillableItem::TYPE_USAGE, $actualBillableItem->getType());
        $this->assertEquals(1.95, $actualBillableItem->getPrice());
    }


    public function testThreeUsageBillableItemsForMultipleTimesLaunchedAndStoppedRemoteDesktop()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:39:01')
        );

        $finishedLaunchingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:20:00')
        );

        $beganStoppingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 20:37:01') // Within next-next usage hour
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1, $finishedLaunchingEvent2, $beganStoppingEvent2]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 22:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(3, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 20:37:01'), $billableItems[2]->getTimewindowBegin());
    }


    public function testSevenUsageBillableItemsForLaunchedAndStoppedRemoteDesktop()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(7, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 20:37:01'), $billableItems[2]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 21:37:01'), $billableItems[3]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 22:37:01'), $billableItems[4]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 23:37:01'), $billableItems[5]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-27 00:37:01'), $billableItems[6]->getTimewindowBegin());
    }


    public function testOnlySixUsageBillableItemsForLaunchedAndStoppedRemoteDesktopIfOneBillingItemAlreadyExists()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1]);

        $latestExistingBillableItem = new BillableItem(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01'),
            BillableItem::TYPE_USAGE
        );

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn($latestExistingBillableItem);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(6, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 20:37:01'), $billableItems[1]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 21:37:01'), $billableItems[2]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 22:37:01'), $billableItems[3]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 23:37:01'), $billableItems[4]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-27 00:37:01'), $billableItems[5]->getTimewindowBegin());
    }


    public function testOnlyOneUsageBillableItemForLaunchedAndStoppedRemoteDesktopIfSixBillingItemsAlreadyExist()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1]);

        $latestExistingBillableItem = new BillableItem(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 23:37:01'),
            BillableItem::TYPE_USAGE
        );

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn($latestExistingBillableItem);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(1, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-27 00:37:01'), $billableItems[0]->getTimewindowBegin());
    }


    public function testNoUsageBillableItemForLaunchedAndStoppedRemoteDesktopIfAllBillingItemsAlreadyExist()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1]);

        $latestExistingBillableItem = new BillableItem(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01'),
            BillableItem::TYPE_USAGE
        );

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn($latestExistingBillableItem);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(0, $billableItems);
    }


    public function testTwoUsageBillableItemsForRemoteDesktopLaunchedMoreThanOneHourAgo()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $event = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$event]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 19:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(2, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
    }


    public function testOneUsageBillableItemForRemoteDesktopLaunchedMoreThanOneHourAgoAndStoppedWithinOneHour()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:37:00') // This is still considered as within the first usage hour
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent, $beganStoppingEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 19:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(1, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
    }


    public function testTwoUsageBillableItemsForRemoteDesktopLaunchedMoreThanOneHourAgoAndStoppedMoreThanOneHourLater()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:37:01') // This counts as the next usage hour, because the end date is exclusive
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent, $beganStoppingEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 23:40:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(2, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
    }


    public function testOneUsageBillableItemsForRemoteDesktopLaunchedWithinTheUptoHourAndStoppedMoreThanOneHourLater()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:50:01') // This counts as the next usage hour, because the end date is exclusive
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->exactly(2))
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent, $beganStoppingEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        // We ask to only work up to a point in time that is not more than one hour away from the start event - thus
        // we expect to not learn about the prolongation
        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 19:37:01'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(1, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());


        // However, if we set up to to only one seconds into the hour that follows the hour from the beginning of the item
        // created by the start event, we expect to get the prolongation
        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 19:37:02'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(2, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
    }


    public function testTwoUsageBillableItemsForTwoUsagesWithALargeGapBetweenThem()
    {
        $remoteDesktop = $this->getRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-26 19:20:01')
        );

        $finishedLaunchingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-29 21:15:00')
        );

        $beganStoppingEvent2 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
            DateTimeUtility::createDateTime('2017-03-29 22:10:05')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$finishedLaunchingEvent1, $beganStoppingEvent1, $finishedLaunchingEvent2, $beganStoppingEvent2]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_USAGE], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        // We ask to only work up to a point in time that is not more than one hour away from the start event - thus
        // we expect to not learn about the prolongation
        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-29 22:30:00'),
            BillableItem::TYPE_USAGE
        );

        $this->assertCount(2, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-29 21:15:00'), $billableItems[1]->getTimewindowBegin());
    }

}
