<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\Billing\BillableItem;
use AppBundle\Entity\CloudInstanceProvider\AwsCloudInstanceProvider;
use AppBundle\Entity\CloudInstanceProvider\PaperspaceCloudInstanceProvider;
use AppBundle\Entity\RemoteDesktop\Event\RemoteDesktopRelevantForBillingEvent;
use AppBundle\Entity\RemoteDesktop\RemoteDesktop;
use AppBundle\Entity\RemoteDesktop\RemoteDesktopGamingProKind;
use AppBundle\Entity\RemoteDesktop\RemoteDesktopGamingProPaperspaceKind;
use AppBundle\Service\BillingService;
use AppBundle\Utility\DateTimeUtility;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class BillingServiceProvisioningBillingTest extends TestCase
{

    protected function getAwsBasedRemoteDesktop() : RemoteDesktop
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

    protected function getPaperspaceBasedRemoteDesktop() : RemoteDesktop
    {
        $remoteDesktop = new RemoteDesktop();
        $remoteDesktop->setCloudInstanceProvider(new PaperspaceCloudInstanceProvider());
        $remoteDesktop->setId('r1');
        $remoteDesktop->setKind(new RemoteDesktopGamingProPaperspaceKind());
        $cloudInstanceProvider = $remoteDesktop->getKind()->getCloudInstanceProvider();
        $remoteDesktop->addCloudInstance(
            $cloudInstanceProvider->createInstanceForRemoteDesktopAndRegion(
                $remoteDesktop,
                $cloudInstanceProvider->getRegionByInternalName('East Coast (NY2)')
            )
        );
        return $remoteDesktop;
    }

    public function testNoProvisioningBillableItemsForRemoteDesktopWithoutProvisioningEvents()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        // For provisioning billing, this event type must be ignored
        $usageEvent = new RemoteDesktopRelevantForBillingEvent(
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
            ->willReturn([$usageEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('now'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertEmpty($billableItems);
    }

    public function testNoProvisioningBillableItemsForRemoteDesktopWithoutOnlyAnProvisioningEndEvent()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        // For provisioning billing, this event type must be ignored
        $usageEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_UNPROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$usageEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('now'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertEmpty($billableItems);
    }

    public function testOneProvisioningBillableItemForLaunchedRemoteDesktop()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        $events = [
            new RemoteDesktopRelevantForBillingEvent(
                $remoteDesktop,
                RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
                DateTimeUtility::createDateTime('2017-03-26 18:37:01')
            ),
            new RemoteDesktopRelevantForBillingEvent(
                $remoteDesktop,
                RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
                DateTimeUtility::createDateTime('2017-03-26 18:37:01')
            ),
        ];

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn($events);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_PROVISIONING], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 18:40:00'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertCount(1, $billableItems);

        /** @var \AppBundle\Entity\Billing\BillableItem $actualBillableItem */
        $actualBillableItem = $billableItems[0];

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $actualBillableItem->getTimewindowBegin());
        $this->assertEquals(0.04, $actualBillableItem->getPrice());
        $this->assertEquals(BillableItem::TYPE_PROVISIONING, $actualBillableItem->getType());
    }


    public function testTwoProvisioningBillableItemsForRemoteDesktopLaunchedMoreThanOneHourAgo()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        $events = [
            new RemoteDesktopRelevantForBillingEvent(
                $remoteDesktop,
                RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER,
                DateTimeUtility::createDateTime('2017-03-26 18:37:01')
            ),
            new RemoteDesktopRelevantForBillingEvent(
                $remoteDesktop,
                RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
                DateTimeUtility::createDateTime('2017-03-26 18:37:01')
            ),

            // The fact that a desktop is stopped must not be relevant for provisioning billing
            new RemoteDesktopRelevantForBillingEvent(
                $remoteDesktop,
                RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_UNAVAILABLE_TO_USER,
                DateTimeUtility::createDateTime('2017-03-26 18:38:01')
            ),
        ];

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn($events);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_PROVISIONING], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-26 19:40:00'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertCount(2, $billableItems);

        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 19:37:01'), $billableItems[1]->getTimewindowBegin());
    }


    public function testNoProvisioningBillableItemForProvisionedAndUnprovisionedRemoteDesktopIfAllBillingItemsAlreadyExist()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        $finishedLaunchingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $beganStoppingEvent1 = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_UNPROVISIONED_FOR_USER,
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
            BillableItem::TYPE_PROVISIONING
        );

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_PROVISIONING], ['timewindowBegin' => 'DESC'])
            ->willReturn($latestExistingBillableItem);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertCount(0, $billableItems);
    }


    public function testSevenProvisioningBillableItemsForProvisionedAndUnprovisionedRemoteDesktop()
    {
        $remoteDesktop = $this->getAwsBasedRemoteDesktop();

        $provisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $unprovisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_UNPROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-27 00:37:01')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$provisioningEvent, $unprovisioningEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_PROVISIONING], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-03-27 15:40:00'),
            BillableItem::TYPE_PROVISIONING
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

    public function testTwoProvisioningBillableItemForProvisionedAndUnprovisionedPaperspaceRemoteDesktop()
    {
        // With hourly provisioning costs, this would result in many billable items, but with monthly provisioning costs, in only 2

        $remoteDesktop = $this->getPaperspaceBasedRemoteDesktop();

        $provisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-03-26 18:37:01')
        );

        $unprovisioningEvent = new RemoteDesktopRelevantForBillingEvent(
            $remoteDesktop,
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_UNPROVISIONED_FOR_USER,
            DateTimeUtility::createDateTime('2017-04-26 18:37:02')
        );

        $remoteDesktopRelevantForBillingEventRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $remoteDesktopRelevantForBillingEventRepo->expects($this->once())
            ->method('findBy')
            ->with(['remoteDesktop' => $remoteDesktop], ['datetimeOccured' => 'ASC'])
            ->willReturn([$provisioningEvent, $unprovisioningEvent]);

        $billableItemRepo = $this
            ->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $billableItemRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['remoteDesktop' => $remoteDesktop, 'type' => BillableItem::TYPE_PROVISIONING], ['timewindowBegin' => 'DESC'])
            ->willReturn(null);

        $bs = new BillingService($remoteDesktopRelevantForBillingEventRepo, $billableItemRepo);

        /** @var BillableItem[] $billableItems */
        $billableItems = $bs->generateMissingBillableItems(
            $remoteDesktop,
            DateTimeUtility::createDateTime('2017-04-27 00:00:00'),
            BillableItem::TYPE_PROVISIONING
        );

        $this->assertCount(2, $billableItems);

        // a billing month is exactly 30 days long on this platform
        $this->assertEquals(DateTimeUtility::createDateTime('2017-03-26 18:37:01'), $billableItems[0]->getTimewindowBegin());
        $this->assertEquals(DateTimeUtility::createDateTime('2017-04-25 18:37:01'), $billableItems[1]->getTimewindowBegin());
    }
}
