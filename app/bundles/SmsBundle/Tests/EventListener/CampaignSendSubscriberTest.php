<?php

namespace Mautic\SmsBundle\Tests\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Entity\Sms;
use Mautic\SmsBundle\EventListener\CampaignSendSubscriber;
use Mautic\SmsBundle\Model\SmsModel;
use Mautic\SmsBundle\Sms\TransportChain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

class CampaignSendSubscriberTest extends TestCase
{
    public function testOnCampaignTriggerBatchAction(): void
    {
        $sms = $this->createMock(Sms::class);
        $sms->expects($this->any())
            ->method('getId')
            ->willReturn(1);

        // Partial mock, mocks just getRepository
        $smsModel = $this->getMockBuilder(SmsModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendSms', 'getEntity'])
            ->getMock();

        $smsModel->method('sendSms')
            ->willReturn(true);
        $smsModel->method('getEntity')
            ->willReturn($sms);

        $transportChain = $this->createMock(TransportChain::class);

        $event    = new Event();
        $campaign = new class extends Campaign {
            public function getId()
            {
                return 111;
            }
        };
        $leadLog = new class extends LeadEventLog {
            public function getId()
            {
                return 456;
            }
        };
        $contact = new class extends Lead {
            public function getId()
            {
                return 789;
            }
        };

        $leadLog->setLead($contact);

        $translator = new class extends Translator {
            public function __construct()
            {
            }
        };

        $subscriber = new CampaignSendSubscriber(
            $smsModel,
            $transportChain,
            $translator
        );

        $event->setProperties(['sms' => 1]);
        $event->setCampaign($campaign);

        $pendingEvent = new PendingEvent(new ActionAccessor([]), $event, new ArrayCollection([$leadLog->getId() => $leadLog]));

        $this->assertCount(1, $pendingEvent->getContacts());
        $subscriber->onCampaignTriggerBatchAction($pendingEvent);
    }
}
