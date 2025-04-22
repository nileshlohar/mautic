<?php

namespace Mautic\CampaignBundle\Controller;

use Doctrine\DBAL\Cache\CacheException;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\Entity\Summary;
use Mautic\CampaignBundle\Entity\SummaryRepository;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\EventListener\CampaignActionJumpToEventSubscriber;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use Mautic\CoreBundle\Form\Type\DateRangeType;
use Mautic\LeadBundle\Controller\EntityContactsTrait;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

class CampaignController extends AbstractStandardFormController
{
    use EntityContactsTrait;

    /**
     * @var array
     */
    protected $addedSources = [];

    /**
     * @var array
     */
    protected $campaignEvents = [];

    /**
     * @var array
     */
    protected $campaignSources = [];

    /**
     * @var array
     */
    protected $connections = [];

    /**
     * @var array
     */
    protected $deletedEvents = [];

    /**
     * @var array
     */
    protected $deletedSources = [];

    /**
     * @var array
     */
    protected $listFilters = [];

    /**
     * @var array
     */
    protected $modifiedEvents = [];

    protected $sessionId;

    /**
     * @return array
     */
    protected function getPermissions()
    {
        // set some permissions
        return (array) $this->get('mautic.security')->isGranted(
            [
                'campaign:campaigns:viewown',
                'campaign:campaigns:viewother',
                'campaign:campaigns:create',
                'campaign:campaigns:editown',
                'campaign:campaigns:editother',
                'campaign:campaigns:cloneown',
                'campaign:campaigns:cloneother',
                'campaign:campaigns:deleteown',
                'campaign:campaigns:deleteother',
                'campaign:campaigns:publishown',
                'campaign:campaigns:publishother',
            ],
            'RETURN_ARRAY'
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function batchDeleteAction()
    {
        return $this->batchDeleteStandard();
    }

    /**
     * Clone an entity.
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function cloneAction($objectId)
    {
        return $this->cloneStandard($objectId);
    }

    /**
     * @param int $page
     * @param int $count
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function contactsAction($objectId, $page = 1, $count = null, \DateTimeInterface $dateFrom = null, \DateTimeInterface $dateTo = null)
    {
        /** @var Session */
        $session= $this->get('session');
        $session->set('mautic.campaign.contact.page', $page);

        return $this->generateContactsGrid(
            $objectId,
            $page,
            'campaign:campaigns:view',
            'campaign',
            'campaign_leads',
            null,
            'campaign_id',
            ['manually_removed' => 0],
            null,
            null,
            [],
            null,
            'entity.lead_id',
            'DESC',
            $count,
            $dateFrom,
            $dateTo
        );
    }

    public function EventStatsAction(int $objectId, string $dateFromValue, string $dateToValue): JsonResponse
    {
        $response        = [];
        $events          = $this->getCampaignModel()->getEventRepository()->getCampaignEvents($objectId);
        $dateFrom        = null;
        $dateTo          = null;
        $this->setCoreParametersHelper($this->get('mautic.config'));
        if ($this->coreParametersHelper->get('campaign_by_range')) {
            $dateFrom = new \DateTimeImmutable($dateFromValue);
            $dateTo   = new \DateTimeImmutable($dateToValue);
            $dateTo   = $dateTo->modify('+1 day');
        }

        $hasCampaignLeads = $this->getCampaignModel()->getRepository()->hasCampaignLeads($objectId);
        $logCounts        = $this->processCampaignLogCounts($objectId, $dateFrom, $dateTo);

        $campaignLogCounts          = $logCounts['campaignLogCounts'] ?? [];
        $campaignLogCountsProcessed = $logCounts['campaignLogCountsProcessed'] ?? [];

        $this->processCampaignEvents($events, $hasCampaignLeads, $campaignLogCounts, $campaignLogCountsProcessed);
        $sortedEvents           = $this->processCampaignEventsFromParentCondition($events);
        $response['decisions']  = trim($this->renderView('MauticCampaignBundle:Campaign:events.html.php', ['events' => $sortedEvents['decision']]));
        $response['actions']    = trim($this->renderView('MauticCampaignBundle:Campaign:events.html.php', ['events' => $sortedEvents['action']]));
        $response['conditions'] = trim($this->renderView('MauticCampaignBundle:Campaign:events.html.php', ['events' => $sortedEvents['condition']]));

        return new JsonResponse(array_filter($response));
    }

    public function GraphAction(int $objectId, string $dateFrom, string $dateTo): JsonResponse
    {
        $dateRangeValues = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $action          = $this->generateUrl('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $objectId]);
        $dateRangeForm   = $this->get('form.factory')->create(DateRangeType::class, $dateRangeValues, ['action' => $action]);
        $stats           = $this->getCampaignModel()->getCampaignMetricsLineChartData(
            null,
            new \DateTime($dateRangeForm->get('date_from')->getData()),
            new \DateTime($dateRangeForm->get('date_to')->getData()),
            null,
            ['campaign_id' => $objectId]
        );

        return $this->ajaxAction(
            [
                'contentTemplate' => 'MauticCampaignBundle:Campaign:graph.html.php',
                'viewParameters'  => [
                    'stats'         => $stats,
                    'dateRangeForm' => $dateRangeForm->createView(),
                ],
            ]
        );
    }

    /**
     * Deletes the entity.
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction($objectId)
    {
        return $this->deleteStandard($objectId);
    }

    /**
     * @param bool $ignorePost
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function editAction($objectId, $ignorePost = false)
    {
        return $this->editStandard($objectId, $ignorePost);
    }

    /**
     * @param int $page
     *
     * @return JsonResponse|Response
     */
    public function indexAction($page = null)
    {
        return $this->indexStandard($page);
    }

    /**
     * Generates new form and processes post data.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function newAction()
    {
        return $this->newStandard();
    }

    /**
     * View a specific campaign.
     *
     * @return JsonResponse|Response
     */
    public function viewAction($objectId)
    {
        return $this->viewStandard($objectId, $this->getModelName(), null, null, 'campaign');
    }

    /**
     * @param Campaign $campaign
     * @param Campaign $oldCampaign
     */
    protected function afterEntityClone($campaign, $oldCampaign)
    {
        $tempId   = 'mautic_'.sha1(uniqid(mt_rand(), true));
        $objectId = $oldCampaign->getId();

        // Get the events that need to be duplicated as well
        $events = $oldCampaign->getEvents()->toArray();

        $campaign->setIsPublished(false);

        // Clone the campaign's events
        /** @var Event $event */
        foreach ($events as $event) {
            $tempEventId = 'new'.$event->getId();

            $clone = clone $event;
            $clone->nullId();
            $clone->setCampaign($campaign);
            $clone->setTempId($tempEventId);

            // Just wipe out the parent as it'll be generated when the cloned entity is saved
            $clone->setParent(null);

            if (CampaignActionJumpToEventSubscriber::EVENT_NAME === $clone->getType()) {
                // Update properties to point to the new temp ID
                $properties                = $clone->getProperties();
                $properties['jumpToEvent'] = 'new'.$properties['jumpToEvent'];

                $clone->setProperties($properties);
            }

            $campaign->addEvent($tempEventId, $clone);
        }

        // Update canvas settings with new event ids
        $canvasSettings = $campaign->getCanvasSettings();
        if (isset($canvasSettings['nodes'])) {
            foreach ($canvasSettings['nodes'] as &$node) {
                // Only events and not lead sources
                if (is_numeric($node['id'])) {
                    $node['id'] = 'new'.$node['id'];
                }
            }
        }

        if (isset($canvasSettings['connections'])) {
            foreach ($canvasSettings['connections'] as &$c) {
                // Only events and not lead sources
                if (is_numeric($c['sourceId'])) {
                    $c['sourceId'] = 'new'.$c['sourceId'];
                }

                // Only events and not lead sources
                if (is_numeric($c['targetId'])) {
                    $c['targetId'] = 'new'.$c['targetId'];
                }
            }
        }

        // Simulate edit
        $campaign->setCanvasSettings($canvasSettings);
        $this->setSessionCanvasSettings($tempId, $canvasSettings);
        $tempId = $this->getCampaignSessionId($campaign, 'clone', $tempId);

        $campaignSources = $this->getCampaignModel()->getLeadSources($objectId);
        $this->prepareCampaignSourcesForEdit($tempId, $campaignSources);
    }

    /**
     * @param null $persistConnections
     */
    protected function afterEntitySave($entity, Form $form, $action, $persistConnections = null)
    {
        if ($persistConnections) {
            // Update canvas settings with new event IDs then save
            $this->connections = $this->getCampaignModel()->setCanvasSettings($entity, $this->connections);
        } else {
            // Just update and add to entity
            $this->connections = $this->getCampaignModel()->setCanvasSettings($entity, $this->connections, false, $this->modifiedEvents);
        }
    }

    /**
     * @param bool $isClone
     */
    protected function afterFormProcessed($isValid, $entity, Form $form, $action, $isClone = false)
    {
        if (!$isValid) {
            // Add the canvas settings to the entity to be able to rebuild it
            $this->afterEntitySave($entity, $form, $action, false);
        } else {
            $this->clearSessionComponents($this->sessionId);
            $this->sessionId = $entity->getId();
        }
    }

    /**
     * @param null $objectId
     * @param bool $isClone
     */
    protected function beforeFormProcessed($entity, Form $form, $action, $isPost, $objectId = null, $isClone = false)
    {
        $sessionId = $this->getCampaignSessionId($entity, $action, $objectId);
        // set added/updated events
        [$this->modifiedEvents, $this->deletedEvents, $this->campaignEvents] = $this->getSessionEvents($sessionId);

        // set added/updated sources
        [$this->addedSources, $this->deletedSources, $campaignSources] = $this->getSessionSources($sessionId, $isClone);
        $this->connections                                             = $this->getSessionCanvasSettings($sessionId);

        if ($isPost) {
            $this->getCampaignModel()->setCanvasSettings($entity, $this->connections, false, $this->modifiedEvents);
            $this->prepareCampaignSourcesForEdit($sessionId, $campaignSources, true);
        } else {
            if (!$isClone) {
                // clear out existing fields in case the form was refreshed, browser closed, etc
                $this->clearSessionComponents($sessionId);
                $this->modifiedEvents = $this->campaignSources = [];

                if ($entity->getId()) {
                    $campaignSources = $this->getCampaignModel()->getLeadSources($entity->getId());
                    $this->prepareCampaignSourcesForEdit($sessionId, $campaignSources);

                    $this->setSessionCanvasSettings($sessionId, $entity->getCanvasSettings());
                }
            }

            $this->deletedEvents = [];

            $form->get('sessionId')->setData($sessionId);

            $this->prepareCampaignEventsForEdit($entity, $sessionId, $isClone);
        }
    }

    /**
     * @param Campaign $entity
     * @param null     $objectId
     * @param bool     $isClone
     *
     * @return bool
     */
    protected function beforeEntitySave($entity, Form $form, $action, $objectId = null, $isClone = false)
    {
        if (empty($this->campaignEvents)) {
            // set the error
            $form->addError(
                new FormError(
                    $this->get('translator')->trans('mautic.campaign.form.events.notempty', [], 'validators')
                )
            );

            return false;
        }

        if (empty($this->campaignSources['lists']) && empty($this->campaignSources['forms'])) {
            // set the error
            $form->addError(
                new FormError(
                    $this->get('translator')->trans('mautic.campaign.form.sources.notempty', [], 'validators')
                )
            );

            return false;
        }

        if ($isClone) {
            [$this->addedSources, $this->deletedSources, $campaignSources] = $this->getSessionSources($objectId, $isClone);
            $this->getCampaignModel()->setLeadSources($entity, $campaignSources, []);
            // If this is a clone, we need to save the entity first to properly build the events, sources and canvas settings
            $this->getCampaignModel()->getRepository()->saveEntity($entity);
            // Set as new so that timestamps are still hydrated
            $entity->setNew();
            $this->sessionId = $entity->getId();
        }

        // Set lead sources
        $this->getCampaignModel()->setLeadSources($entity, $this->addedSources, $this->deletedSources);

        // Build and set Event entities
        $this->getCampaignModel()->setEvents($entity, $this->campaignEvents, $this->connections, $this->deletedEvents);

        if ('edit' === $action && null !== $this->connections) {
            if (!empty($this->deletedEvents)) {
                /** @var EventModel $eventModel */
                $eventModel = $this->getModel('campaign.event');
                $eventModel->deleteEvents($entity->getEvents()->toArray(), $this->deletedEvents);
            }
        }

        return true;
    }

    /**
     * Clear field and events from the session.
     */
    protected function clearSessionComponents($id)
    {
        $session = $this->get('session');
        $session->remove('mautic.campaign.'.$id.'.events.modified');
        $session->remove('mautic.campaign.'.$id.'.events.deleted');
        $session->remove('mautic.campaign.'.$id.'.events.canvassettings');
        $session->remove('mautic.campaign.'.$id.'.leadsources.current');
        $session->remove('mautic.campaign.'.$id.'.leadsources.modified');
        $session->remove('mautic.campaign.'.$id.'.leadsources.deleted');
    }

    /**
     * @return CampaignModel
     */
    protected function getCampaignModel()
    {
        /** @var CampaignModel $model */
        $model = $this->getModel($this->getModelName());

        return $model;
    }

    /**
     * @param null $objectId
     *
     * @return int|string|null
     */
    protected function getCampaignSessionId(Campaign $campaign, $action, $objectId = null)
    {
        if (isset($this->sessionId)) {
            return $this->sessionId;
        }

        if ($objectId) {
            $sessionId = $objectId;
        } elseif ('new' === $action && empty($sessionId)) {
            $sessionId = 'mautic_'.sha1(uniqid(mt_rand(), true));
            if ($this->request->request->has('campaign')) {
                $campaign  = $this->request->request->get('campaign', []);
                $sessionId = $campaign['sessionId'] ?? $sessionId;
            }
        } elseif ('edit' === $action) {
            $sessionId = $campaign->getId();
        }

        $this->sessionId = $sessionId;

        return $sessionId;
    }

    /**
     * @return string
     */
    protected function getControllerBase()
    {
        return 'MauticCampaignBundle:Campaign';
    }

    protected function getIndexItems($start, $limit, $filter, $orderBy, $orderByDir, array $args = [])
    {
        $session        = $this->get('session');
        $currentFilters = $session->get('mautic.campaign.list_filters', []);
        $updatedFilters = $this->request->get('filters', false);

        $sourceLists = $this->getCampaignModel()->getSourceLists();
        $listFilters = [
            'filters' => [
                'placeholder' => $this->get('translator')->trans('mautic.campaign.filter.placeholder'),
                'multiple'    => true,
                'groups'      => [
                    'mautic.campaign.leadsource.form' => [
                        'options' => $sourceLists['forms'],
                        'prefix'  => 'form',
                    ],
                    'mautic.campaign.leadsource.list' => [
                        'options' => $sourceLists['lists'],
                        'prefix'  => 'list',
                    ],
                ],
            ],
        ];

        if ($updatedFilters) {
            // Filters have been updated

            // Parse the selected values
            $newFilters     = [];
            $updatedFilters = json_decode($updatedFilters, true);

            if ($updatedFilters) {
                foreach ($updatedFilters as $updatedFilter) {
                    [$clmn, $fltr] = explode(':', $updatedFilter);

                    $newFilters[$clmn][] = $fltr;
                }

                $currentFilters = $newFilters;
            } else {
                $currentFilters = [];
            }
        }
        $session->set('mautic.campaign.list_filters', $currentFilters);

        $joinLists = $joinForms = false;
        if (!empty($currentFilters)) {
            $listIds = [];
            foreach ($currentFilters as $type => $typeFilters) {
                $listFilters['filters']['groups']['mautic.campaign.leadsource.'.$type]['values'] = $typeFilters;

                foreach ($typeFilters as $fltr) {
                    if ('list' == $type) {
                        $listIds[] = (int) $fltr;
                    } else {
                        $formIds[] = (int) $fltr;
                    }
                }
            }

            if (!empty($listIds)) {
                $joinLists         = true;
                $filter['force'][] = ['column' => 'l.id', 'expr' => 'in', 'value' => $listIds];
            }

            if (!empty($formIds)) {
                $joinForms         = true;
                $filter['force'][] = ['column' => 'f.id', 'expr' => 'in', 'value' => $formIds];
            }
        }

        // Store for customizeViewArguments
        $this->listFilters = $listFilters;

        return parent::getIndexItems(
            $start,
            $limit,
            $filter,
            $orderBy,
            $orderByDir,
            [
                'joinLists' => $joinLists,
                'joinForms' => $joinForms,
            ]
        );
    }

    /**
     * @return string
     */
    protected function getModelName()
    {
        return 'campaign';
    }

    /**
     * @return array
     */
    protected function getPostActionRedirectArguments(array $args, $action)
    {
        switch ($action) {
            case 'new':
            case 'edit':
                if (!empty($args['entity'])) {
                    $sessionId = $this->getCampaignSessionId($args['entity'], $action);
                    $this->clearSessionComponents($sessionId);
                }
                break;
        }

        return $args;
    }

    /**
     * Get events from session.
     *
     * @return array
     */
    protected function getSessionEvents($id)
    {
        $session = $this->get('session');

        $modifiedEvents = $session->get('mautic.campaign.'.$id.'.events.modified', []);
        $deletedEvents  = $session->get('mautic.campaign.'.$id.'.events.deleted', []);

        $events = array_diff_key($modifiedEvents, array_flip($deletedEvents));

        return [$modifiedEvents, $deletedEvents, $events];
    }

    /**
     * Get events from session.
     *
     * @return array
     */
    protected function getSessionSources($id, $isClone = false)
    {
        $session = $this->get('session');

        $campaignSources = $session->get('mautic.campaign.'.$id.'.leadsources.current', []);
        $modifiedSources = $session->get('mautic.campaign.'.$id.'.leadsources.modified', []);

        if ($campaignSources === $modifiedSources) {
            if ($isClone) {
                // Clone hasn't saved the sources yet so return the current list as added
                return [$campaignSources, [], $campaignSources];
            } else {
                return [[], [], $campaignSources];
            }
        }

        // Deleted sources
        $deletedSources = [];
        foreach ($campaignSources as $type => $sources) {
            if (isset($modifiedSources[$type])) {
                $deletedSources[$type] = array_diff_key($sources, $modifiedSources[$type]);
            } else {
                $deletedSources[$type] = $sources;
            }
        }

        // Added sources
        $addedSources = [];
        foreach ($modifiedSources as $type => $sources) {
            if (isset($campaignSources[$type])) {
                $addedSources[$type] = array_diff_key($sources, $campaignSources[$type]);
            } else {
                $addedSources[$type] = $sources;
            }
        }

        return [$addedSources, $deletedSources, $modifiedSources];
    }

    /**
     * @param string $action
     *
     * @throws CacheException
     */
    protected function getViewArguments(array $args, $action): array
    {
        /** @var EventCollector $eventCollector */
        $eventCollector = $this->get('mautic.campaign.event_collector');

        switch ($action) {
            case 'index':
                $args['viewParameters']['filters'] = $this->listFilters;
                break;
            case 'view':
                /** @var Campaign $entity */
                $entity   = $args['entity'];
                $objectId = $args['objectId'];
                // Init the date range filter form
                $dateRangeValues = $this->request->get('daterange', []);
                $action          = $this->generateUrl('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $objectId]);
                $dateRangeForm   = $this->get('form.factory')->create(DateRangeType::class, $dateRangeValues, ['action' => $action]);
                $events          = $this->getCampaignModel()->getEventRepository()->getCampaignEvents($objectId);
                $sourcesList     = $this->getCampaignModel()->getSourceLists();

                $this->prepareCampaignSourcesForEdit($objectId, $sourcesList, true);
                $this->prepareCampaignEventsForEdit($entity, $objectId, true);

                $args['viewParameters'] = array_merge(
                    $args['viewParameters'],
                    [
                        'campaign'        => $entity,
                        'eventSettings'   => $eventCollector->getEventsArray(),
                        'sources'         => $this->getCampaignModel()->getLeadSources($entity),
                        'campaignSources' => $this->campaignSources,
                        'campaignEvents'  => $events,
                        'dateRangeForm'   => $dateRangeForm->createView(),
                    ]
                );
                break;
            case 'new':
            case 'edit':
                $args['viewParameters'] = array_merge(
                    $args['viewParameters'],
                    [
                        'eventSettings'   => $eventCollector->getEventsArray(),
                        'campaignEvents'  => $this->campaignEvents,
                        'campaignSources' => $this->campaignSources,
                        'deletedEvents'   => $this->deletedEvents,
                    ]
                );
                break;
        }

        return $args;
    }

    /**
     * @param bool $isClone
     *
     * @return array
     */
    protected function prepareCampaignEventsForEdit($entity, $objectId, $isClone = false)
    {
        // load existing events into session
        $campaignEvents = [];

        $existingEvents = $entity->getEvents()->toArray();
        $translator     = $this->get('translator');
        $dateHelper     = $this->get('mautic.helper.template.date');
        foreach ($existingEvents as $e) {
            $event = $e->convertToArray();

            if ($isClone) {
                $id          = $e->getTempId();
                $event['id'] = $id;
            } else {
                $id = $e->getId();
            }

            unset($event['campaign']);
            unset($event['children']);
            unset($event['parent']);
            unset($event['log']);

            $label = false;
            switch ($event['triggerMode']) {
                case 'interval':
                    $label = $translator->trans(
                        'mautic.campaign.connection.trigger.interval.label'.('no' == $event['decisionPath'] ? '_inaction' : ''),
                        [
                            '%number%' => $event['triggerInterval'],
                            '%unit%'   => $translator->transChoice(
                                'mautic.campaign.event.intervalunit.'.$event['triggerIntervalUnit'],
                                $event['triggerInterval']
                            ),
                        ]
                    );
                    break;
                case 'date':
                    $label = $translator->trans(
                        'mautic.campaign.connection.trigger.date.label'.('no' == $event['decisionPath'] ? '_inaction' : ''),
                        [
                            '%full%' => $dateHelper->toFull($event['triggerDate']),
                            '%time%' => $dateHelper->toTime($event['triggerDate']),
                            '%date%' => $dateHelper->toShort($event['triggerDate']),
                        ]
                    );
                    break;
            }
            if ($label) {
                $event['label'] = $label;
            }

            $campaignEvents[$id] = $event;
        }

        $this->modifiedEvents = $this->campaignEvents = $campaignEvents;
        $this->get('session')->set('mautic.campaign.'.$objectId.'.events.modified', $campaignEvents);
    }

    protected function prepareCampaignSourcesForEdit($objectId, $campaignSources, $isPost = false)
    {
        $this->campaignSources = [];
        if (is_array($campaignSources)) {
            foreach ($campaignSources as $type => $sources) {
                if (!empty($sources)) {
                    $sourceList                   = $this->getModel('campaign')->getSourceLists($type);
                    $this->campaignSources[$type] = [
                        'sourceType' => $type,
                        'campaignId' => $objectId,
                        'names'      => implode(', ', array_intersect_key($sourceList, $sources)),
                    ];
                }
            }
        }

        if (!$isPost) {
            $session = $this->get('session');
            $session->set('mautic.campaign.'.$objectId.'.leadsources.current', $campaignSources);
            $session->set('mautic.campaign.'.$objectId.'.leadsources.modified', $campaignSources);
        }
    }

    protected function setSessionCanvasSettings($sessionId, $canvasSettings)
    {
        $this->get('session')->set('mautic.campaign.'.$sessionId.'.events.canvassettings', $canvasSettings);
    }

    /**
     * @return mixed
     */
    protected function getSessionCanvasSettings($sessionId)
    {
        return $this->get('session')->get('mautic.campaign.'.$sessionId.'.events.canvassettings');
    }

    /**
     * @throws CacheException
     */
    private function processCampaignLogCounts(int $id, ?\DateTimeInterface $dateFrom, ?\DateTimeInterface $dateTo): array
    {
        if ($this->coreParametersHelper->get('campaign_use_summary')) {
            /** @var SummaryRepository $summaryRepo */
            $summaryRepo                = $this->getDoctrine()->getManager()->getRepository(Summary::class);
            $campaignLogCounts          = $summaryRepo->getCampaignLogCounts($id, $dateFrom, $dateTo);
            $campaignLogCountsProcessed = $this->getCampaignLogCountsProcessed($campaignLogCounts);
        } else {
            /** @var LeadEventLogRepository $eventLogRepo */
            $eventLogRepo               = $this->getDoctrine()->getManager()->getRepository(LeadEventLog::class);
            $campaignLogCounts          = $eventLogRepo->getCampaignLogCounts($id, false, false, true, $dateFrom, $dateTo);
            $campaignLogCountsProcessed = $eventLogRepo->getCampaignLogCounts($id, false, false, false, $dateFrom, $dateTo);
        }

        return [
            'campaignLogCounts'          => $campaignLogCounts,
            'campaignLogCountsProcessed' => $campaignLogCountsProcessed,
        ];
    }

    private function processCampaignEvents(
        array &$events,
        bool $hasCampaignLeads,
        array $campaignLogCounts,
        array $campaignLogCountsProcessed,
    ): void {
        foreach ($events as &$event) {
            $event['logCountForPending'] =
            $event['logCountProcessed']  =
            $event['percent']            =
            $event['yesPercent']         =
            $event['noPercent']          = 0;

            if (isset($campaignLogCounts[$event['id']])) {
                $loggedCount                 = array_sum($campaignLogCounts[$event['id']]);
                $logCountsProcessed          = isset($campaignLogCountsProcessed[$event['id']]) ? array_sum($campaignLogCountsProcessed[$event['id']]) : 0;
                $pending                     = $loggedCount - $logCountsProcessed;
                $event['logCountForPending'] = $pending;
                $event['logCountProcessed']  = $logCountsProcessed;
                [$totalNo, $totalYes]        = $campaignLogCounts[$event['id']];
                $total                       = $totalYes + $totalNo;

                if ($hasCampaignLeads) {
                    $event['percent']    = min(100, max(0, round(($loggedCount / $total) * 100, 1)));
                    $event['yesPercent'] = min(100, max(0, round(($totalYes / $total) * 100, 1)));
                    $event['noPercent']  = min(100, max(0, round(($totalNo / $total) * 100, 1)));
                }
            }
        }
    }

    private function processCampaignEventsFromParentCondition(array &$events): array
    {
        $sortedEvents = [
            'decision'  => [],
            'action'    => [],
            'condition' => [],
        ];

        // rewrite stats data from parent condition if exist
        foreach ($events as &$event) {
            if (!empty($event['decisionPath'])
                && !empty($event['parent_id'])
                && isset($events[$event['parent_id']])
                && 'condition' !== $event['eventType']) {
                $parentEvent                 = $events[$event['parent_id']];
                $event['percent']            = $parentEvent['percent'];
                $event['yesPercent']         = $parentEvent['yesPercent'];
                $event['noPercent']          = $parentEvent['noPercent'];
                if ('yes' === $event['decisionPath']) {
                    $event['noPercent'] = 0;
                } else {
                    $event['yesPercent'] = 0;
                }
            }
            $sortedEvents[$event['eventType']][] = $event;
        }

        return $sortedEvents;
    }

    private function getCampaignLogCountsProcessed(array &$campaignLogCounts): array
    {
        $campaignLogCountsProcessed = [];

        foreach ($campaignLogCounts as $eventId => $campaignLogCount) {
            $campaignLogCountsProcessed[$eventId][] = $campaignLogCount[2];
            unset($campaignLogCounts[$eventId][2]);
        }

        return $campaignLogCountsProcessed;
    }
}
