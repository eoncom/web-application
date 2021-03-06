<?php

namespace Tests\Functional;

use AppBundle\Entity\Billing\AccountMovement;
use AppBundle\Entity\Billing\AccountMovementRepository;
use AppBundle\Entity\CloudInstance\CloudInstance;
use AppBundle\Entity\RemoteDesktop\Event\RemoteDesktopRelevantForBillingEvent;
use AppBundle\Entity\RemoteDesktop\RemoteDesktop;
use AppBundle\Utility\DateTimeUtility;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Helpers\Helpers;

class LaunchRemoteDesktopFunctionalTest extends WebTestCase
{
    use Helpers;

    protected function verifyDektopStatusBooting(Client $client, Crawler $crawler)
    {
        $link = $crawler->selectLink('Refresh status')->first()->link();
        $crawler = $client->click($link);

        $this->assertContains('My first cloud gaming rig', $crawler->filter('h2')->first()->text());

        $this->assertContains('Usage costs per hour', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());
        $this->assertContains('(only in status Ready to use and Rebooting): $1.95', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());

        $this->assertContains('Storage costs per hour', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());
        $this->assertContains('(until rig is removed): $0.04', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());

        $this->assertContains('Current status:', $crawler->filter('h3')->first()->text());

        $this->assertContains('Booting...', $crawler->filter('.remotedesktopstatus')->first()->text());

        $this->assertContains('Refresh status', $crawler->filter('.panel-footer a.btn')->first()->text());
        $this->assertEquals(
            1,
            $crawler->filter('.panel-footer a.btn')->count()
        );
    }

    public function testLaunchRemoteDesktop()
    {
        $client = (new CreateRemoteDesktopFunctionalTest())->testCreateRemoteDesktop();

        $crawler = $client->request('GET', '/en/remoteDesktops/');

        $link = $crawler->selectLink('Launch this cloud gaming rig')->first()->link();

        $crawler = $client->click($link);

        $container = $client->getContainer();
        /** @var EntityManager $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $remoteDesktopRepo = $em->getRepository(RemoteDesktop::class);
        /** @var RemoteDesktop $remoteDesktop */
        $remoteDesktop = $remoteDesktopRepo->findOneBy(['title' => 'My first cloud gaming rig']);
        $this->assertEquals(
            '/en/remoteDesktops/' . $remoteDesktop->getId() . '/cloudInstances/new',
            $client->getRequest()->getRequestUri()
        );

        $this->assertContains('Launch your cloud gaming rig', $crawler->filter('h1')->first()->text());

        $buttonNode = $crawler->selectButton('Launch now');
        $form = $buttonNode->form();

        $client->submit($form, [
            'form[region]' => 'eu-central-1',
        ]);

        $crawler = $client->followRedirect();

        // We want to be back in the overview
        $this->assertEquals(
            '/en/remoteDesktops/',
            $client->getRequest()->getRequestUri()
        );


        // At this point, the instance is in "Scheduled for launch" state

        $this->verifyDektopStatusBooting($client, $crawler);


        // Switching instance to "Launching" status, which must not change the desktop status

        $remoteDesktop = $remoteDesktopRepo->findOneBy(['title' => 'My first cloud gaming rig']);
        /** @var CloudInstance $cloudInstance */
        $cloudInstance = $remoteDesktop->getCloudInstances()->get(0);
        $cloudInstance->setRunstatus(CloudInstance::RUNSTATUS_LAUNCHING);
        $em->persist($cloudInstance);
        $em->flush();

        $this->verifyDektopStatusBooting($client, $crawler);


        // Switching instance to "Running" status, which must put the desktop into "Ready to use" status

        $remoteDesktop = $remoteDesktopRepo->findOneBy(['title' => 'My first cloud gaming rig']);
        /** @var CloudInstance $cloudInstance */
        $cloudInstance = $remoteDesktop->getCloudInstances()->get(0);
        $cloudInstance->setPublicAddress('121.122.123.124');
        $cloudInstance->setAdminPassword('foo');
        $cloudInstance->setRunstatus(CloudInstance::RUNSTATUS_RUNNING);
        $em->persist($cloudInstance);
        $em->flush();

        $remoteDesktopRelevantForBillingEventRepo = $em->getRepository(RemoteDesktopRelevantForBillingEvent::class);

        /** @var RemoteDesktopRelevantForBillingEvent[] $remoteDesktopRelevantForBillingEvents */
        $remoteDesktopRelevantForBillingEvents = $remoteDesktopRelevantForBillingEventRepo->findAll();
        $this->assertEquals(
            2,
            sizeof($remoteDesktopRelevantForBillingEvents)
        );

        $this->assertEquals(
            $remoteDesktopRelevantForBillingEvents[0]->getEventType(),
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_WAS_PROVISIONED_FOR_USER
        );

        $this->assertEquals(
            $remoteDesktopRelevantForBillingEvents[1]->getEventType(),
            RemoteDesktopRelevantForBillingEvent::EVENT_TYPE_DESKTOP_BECAME_AVAILABLE_TO_USER
        );

        $link = $crawler->selectLink('Refresh status')->first()->link();
        $crawler = $client->click($link);

        $this->assertContains('My first cloud gaming rig', $crawler->filter('h2')->first()->text());

        $this->assertContains('Current usage costs per hour', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());
        $this->assertContains('(while in status Ready to use and Rebooting): $1.95', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());

        $this->assertContains('Storage costs per hour', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());
        $this->assertContains('(until rig is removed): $0.04', $crawler->filter('div.usagecostsforoneintervalbox')->first()->text());

        $this->assertContains('Current status:', $crawler->filter('h3')->first()->text());
        $this->assertContains('Ready to use', $crawler->filter('.remotedesktopstatus')->first()->text());
        $this->assertContains('Auto stop cost protection feature', $crawler->filter('.costprotectionblock')->first()->text());
        $this->assertContains('current', $crawler->filter('.costprotectionblock a.btn')->first()->text());
        $this->assertContains('8th', $crawler->filter('.costprotectionblock a.btn')->eq(7)->text());
        $this->assertContains('Your data is safe - everything is kept in place when your cloud gaming rig is stopped.', $crawler->filter('.dataissafeinfo')->first()->text());

        $this->assertContains('121.122.123.124 | foo', $crawler->filter('.clientinfolabel')->first()->attr('title'));

        $this->assertContains('Connect now', $crawler->filter('li.list-group-item')->eq(0)->filter('a.btn')->text());
        $this->assertContains('You need to have the client program installed.', $crawler->filter('.clientinfolabel')->first()->text());
        $this->assertContains('121.122.123.124 | foo', $crawler->filter('.clientinfolabel')->first()->attr('title'));

        $this->assertContains('Download the Windows client', $crawler->filter('li.list-group-item')->eq(1)->filter('a.btn')->eq(0)->text());
        $this->assertContains('Download the Mac client', $crawler->filter('li.list-group-item')->eq(1)->filter('a.btn')->eq(1)->text());


        // Check that the CGX launcher URI is correct and its target SGX file URI works

        $remoteDesktop = $remoteDesktopRepo->findOneBy(['title' => 'My first cloud gaming rig']);

        $launcherUri = $crawler->filter('li.list-group-item')->eq(0)->filter('a.btn')->attr('href');
        $this->assertEquals(
            'sgxportal://'
                . $client->getRequest()->getHttpHost()
                . '/en/remoteDesktops/'
                . $remoteDesktop->getId()
                . '/'
                . $remoteDesktop->getIdHash()
                . '/1280/800?protocol='
                . $client->getRequest()->getScheme()
                . '&version=1_10_0#'
                . $remoteDesktop->getId(),
            $launcherUri
        );

        $resolutionsAndBitrates = [
            ['width' => '1024', 'height' => '768',  'bitrate' => '10'],
            ['width' => '1280', 'height' => '720',  'bitrate' => '12'],
            ['width' => '1280', 'height' => '800',  'bitrate' => '15'],
            ['width' => '1536', 'height' => '1152', 'bitrate' => '17'],
            ['width' => '1920', 'height' => '1080', 'bitrate' => '23'],
            ['width' => '1920', 'height' => '1200', 'bitrate' => '25'],
            ['width' => '2048', 'height' => '1536', 'bitrate' => '28'],
            ['width' => '2560', 'height' => '1440', 'bitrate' => '30']
        ];

        foreach ($resolutionsAndBitrates as $resolutionsAndBitrate) {
            $client->request('GET', '/en/remoteDesktops/' . $remoteDesktop->getId() . '/' . $remoteDesktop->getIdHash() . '/' . $resolutionsAndBitrate['width'] . '/' . $resolutionsAndBitrate['height'] . '/sgx_files/' . $remoteDesktop->getId() . '.sgx');

            $content = $client->getResponse()->getContent();

            $this->assertContains('ip: 121.122.123.124', $content);
            $this->assertContains('key: ' . $remoteDesktop->getId(), $content);
            $this->assertContains('password: foo', $content);
            $this->assertContains('mouse-relative: true', $content);
            $this->assertContains('bitrate: ' . $resolutionsAndBitrate['bitrate'], $content);
            $this->assertContains('width: ' . $resolutionsAndBitrate['width'], $content);
            $this->assertContains('height: ' . $resolutionsAndBitrate['height'], $content);
        }

        // Check that billing worked
        $kernel = static::createClient()->getKernel();
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArgvInput(['', 'app:generatebillableitems', '--no-interaction', '-q']);
        $application->run($input);

        /** @var AccountMovementRepository $accountMovementRepo */
        $accountMovementRepo = $em->getRepository(AccountMovement::class);

        $this->assertSame(
            98.01, // 0.95 for usage, 0.04 for provisioning
            $accountMovementRepo->getAccountBalanceForUser($remoteDesktop->getUser())
        );

        $crawler = $client->click($crawler->filter('.accountbalancehistorylink')->link());
        $this->assertContains(DateTimeUtility::createDateTime()->format('F j, Y'), $crawler->filter('tr td')->eq(0)->text());
        $this->assertContains(DateTimeUtility::createDateTime()->format('H:i'), $crawler->filter('tr td')->eq(0)->text());

        $this->assertContains(
            'An amount of $100.00 was deposited onto your account.',
            $crawler->filter('tr td')->eq(1)->text()
        );
        $this->assertContains(
            'An amount of $1.95 was debited from your account for 1 usage hour of rig \'My first cloud gaming rig\'.',
            $crawler->filter('tr td')->eq(1)->text()
        );
        $this->assertContains(
            'An amount of $0.04 was debited from your account for 1 storage space hour of rig \'My first cloud gaming rig\'.',
            $crawler->filter('tr td')->eq(1)->text()
        );
        $this->assertContains(
            'Storage for the cloud gaming rig \'My first cloud gaming rig\' was provisioned.',
            $crawler->filter('tr td')->eq(1)->text()
        );
        $this->assertContains(
            "The cloud gaming rig 'My first cloud gaming rig' became available for remote sessions.",
            $crawler->filter('tr td')->eq(1)->text()
        );

        /* Sadly, this is currently too flaky
        $this->assertContains(
            "98.47",
            $crawler->filter('tr td')->eq(2)->text()
        );
        */

        // We want to build on this in other tests
        return $client;
    }

    public function testCannotLaunchRemoteDesktopIfBalanceTooLow()
    {
        $client = (new CreateRemoteDesktopFunctionalTest())->testCreateRemoteDesktop();

        $this->resetAccountBalanceForTestuser($client);

        $crawler = $client->request('GET', '/en/remoteDesktops/');

        $link = $crawler->selectLink('Launch this cloud gaming rig')->first()->link();

        $crawler = $client->click($link);

        $this->assertContains('Launch your cloud gaming rig', $crawler->filter('h1')->first()->text());

        $buttonNode = $crawler->selectButton('Launch now');
        $form = $buttonNode->form();

        $crawler = $client->submit($form, [
            'form[region]' => 'eu-central-1',
        ]);

        $this->assertContains(
            'Your account balance is too low to use this cloud gaming rig.',
            $crawler->filter('div.alert')->first()->text()
        );

        $this->assertContains(
            'Running this cloud gaming rig costs $1.95 per hour, but your current balance is',
            $crawler->filter('div.alert')->first()->text()
        );

        $this->assertContains(
            '$0.00.',
            $crawler->filter('div.alert')->first()->text()
        );

        $this->assertContains(
            'Click here to increase your balance now',
            $crawler->filter('a.btn')->first()->text()
        );
    }

}
