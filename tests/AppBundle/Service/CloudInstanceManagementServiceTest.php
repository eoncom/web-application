<?php

namespace Tests\AppBundle\Service;

use AppBundle\Coordinator\CloudInstance\CloudInstanceCoordinatorFactory;
use AppBundle\Entity\CloudInstance\AwsCloudInstance;
use AppBundle\Entity\CloudInstance\CloudInstance;
use AppBundle\Entity\CloudInstanceProvider\AwsCloudInstanceProvider;
use AppBundle\Entity\CloudInstanceProvider\ProviderElement\Flavor;
use AppBundle\Entity\RemoteDesktop\RemoteDesktop;
use AppBundle\Entity\RemoteDesktop\RemoteDesktopKind;
use AppBundle\Entity\User;
use AppBundle\Service\CloudInstanceManagementService;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Tests\Fixtures\DummyOutput;

class CloudInstanceManagementServiceTest extends TestCase
{

    public function getMockCloudInstanceCoordinatorFactory()
    {
        return $this->getMockBuilder(CloudInstanceCoordinatorFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function getMockEntityManager()
    {
        return $this->getMockBuilder(EntityManager::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testScheduledForLaunchIsLaunched()
    {
        $cloudInstanceManagementService = new CloudInstanceManagementService(
            $this->getMockEntityManager(),
            $this->getMockCloudInstanceCoordinatorFactory()
        );

        $user = new User();
        $user->setUsername('userA');

        $remoteDesktop = new RemoteDesktop();
        $remoteDesktop->setId('r1');
        $remoteDesktop->setCloudInstanceProvider(new AwsCloudInstanceProvider());
        $remoteDesktop->setKind(RemoteDesktopKind::createRemoteDesktopKind(RemoteDesktopKind::GAMING_PRO));
        $remoteDesktop->setUser($user);

        $cloudInstance = new AwsCloudInstance();
        $cloudInstance->setId('c1');
        $cloudInstance->setEc2InstanceId('ec1');
        $cloudInstance->setRemoteDesktop($remoteDesktop);
        $cloudInstance->setFlavor(new Flavor(new AwsCloudInstanceProvider(), 'f1', 'flavor1', true));

        $cloudInstance->setRunstatus(CloudInstance::RUNSTATUS_SCHEDULED_FOR_LAUNCH);

        $inputDefinition = new InputDefinition([
            new InputArgument('awsApiKey', InputArgument::REQUIRED),
            new InputArgument('awsApiSecret', InputArgument::REQUIRED),
            new InputArgument('awsKeypairPrivateKeyFile', InputArgument::REQUIRED),
        ]);

        $input = new ArrayInput(
            [
                'awsApiKey' => 'foo',
                'awsApiSecret' => 'bar',
                'awsKeypairPrivateKeyFile' => 'baz'
            ],
            $inputDefinition
        );

        $output = new DummyOutput();

        $cloudInstanceManagementService->manageCloudInstance($cloudInstance, $input, $output);

        $this->assertTrue(true);
    }

}
