<?php

namespace MauticPlugin\MauticFocusBundle\EventListener;

use Mautic\CoreBundle\EventListener\ChannelTrait;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\PageBundle\Model\PageModel;
use Mautic\PageBundle\Model\VideoModel;
use MauticPlugin\MauticFocusBundle\Entity\Stat;
use MauticPlugin\MauticFocusBundle\Model\FocusModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorInterface;

class LeadSubscriber implements EventSubscriberInterface
{
    use ChannelTrait;

    /**
     * @var PageModel
     */
    private $pageModel;

    /**
     * @var VideoModel
     */
    private $pageVideoModel;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RouterInterface
     */
    private $router;

    private FocusModel $focusModel;

    public function __construct(
        PageModel $pageModel,
        VideoModel $pageVideoModel,
        TranslatorInterface $translator,
        RouterInterface $router,
        FocusModel $focusModel
    ) {
        $this->pageModel      = $pageModel;
        $this->pageVideoModel = $pageVideoModel;
        $this->translator     = $translator;
        $this->router         = $router;
        $this->focusModel     = $focusModel;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            LeadEvents::TIMELINE_ON_GENERATE      => ['onTimelineGenerate', 0],
        ];
    }

    /**
     * Compile events for the lead timeline.
     */
    public function onTimelineGenerate(LeadTimelineEvent $event)
    {
        $eventViewTypeKey  = 'focus.view';
        $eventViewTypeName = $this->translator->trans('mautic.focus.event.view');
        $event->addEventType($eventViewTypeKey, $eventViewTypeName);
        $eventViewApplicable = $event->isApplicable($eventViewTypeKey);

        $eventClickTypeKey  = 'focus.click';
        $eventClickTypeName = $this->translator->trans('mautic.focus.event.click');
        $event->addEventType($eventClickTypeKey, $eventClickTypeName);
        $eventClickApplicable = $event->isApplicable($eventClickTypeKey);

        $event->addSerializerGroup('focusList');

        $contactId        = $event->getLead()->getId();
        $statsViewsByLead = $this->focusModel->getStatRepository()->getStatsViewByLead($contactId, $event->getQueryOptions());
        //dump($statsViewsByLead);

        if (!$event->isEngagementCount()) {
            $template = 'MauticFocusBundle:SubscribedEvents\Timeline:index.html.php';
            $icon     = 'fa-search';

            $counter = [Stat::TYPE_NOTIFICATION=>0, Stat::TYPE_CLICK=>0];
            // Add the view to the event array
            foreach ($statsViewsByLead['result'] as $statsView) {
                if (((Stat::TYPE_CLICK == $statsView['type']) && !$eventClickApplicable)
                    ||
                    ((Stat::TYPE_NOTIFICATION == $statsView['type']) && !$eventViewApplicable)) {
                    continue;
                } else {
                    ++$counter[$statsView['type']];

                    $eventLabel = [
                        'label' => $statsView['focus']['name'],
                        'href'  => $this->router->generate('mautic_focus_action', ['objectAction' => 'view', 'objectId' => $statsView['focus']['id']]),
                    ];

                    $event->addEvent(
                        [
                            'event'           => (Stat::TYPE_NOTIFICATION == $statsView['type']) ? $eventViewTypeKey : $eventClickTypeKey,
                            'eventId'         => $statsView['id'],
                            'eventLabel'      => $eventLabel,
                            'eventType'       => (Stat::TYPE_NOTIFICATION == $statsView['type']) ? $eventViewTypeName : $eventClickTypeName,
                            'timestamp'       => $statsView['dateAdded'],
                            'contentTemplate' => $template,
                            'icon'            => $icon,
                            'contactId'       => $contactId,
                        ]
                    );
                }
            }

            // Add to counter view
            $event->addToCounter($eventViewTypeKey, $counter[Stat::TYPE_NOTIFICATION]);
            // Add to counter click
            $event->addToCounter($eventClickTypeKey, $counter[Stat::TYPE_CLICK]);
        }
    }
}
