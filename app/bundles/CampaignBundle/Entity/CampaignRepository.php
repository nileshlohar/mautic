<?php

namespace Mautic\CampaignBundle\Entity;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\ORM\Query\Expr;
use Mautic\CampaignBundle\Entity\Result\CountResult;
use Mautic\CampaignBundle\Executioner\ContactFinder\Limiter\ContactLimiter;
use Mautic\CoreBundle\Entity\CommonRepository;

class CampaignRepository extends CommonRepository
{
    use ContactLimiterTrait;
    use ReplicaConnectionTrait;

    public function getEntities(array $args = [])
    {
        $q = $this->getEntityManager()->createQueryBuilder();
        $q->select($this->getTableAlias().', cat')
            ->from('MauticCampaignBundle:Campaign', $this->getTableAlias(), $this->getTableAlias().'.id')
            ->leftJoin($this->getTableAlias().'.category', 'cat');

        if (!empty($args['joinLists'])) {
            $q->leftJoin($this->getTableAlias().'.lists', 'l');
        }

        if (!empty($args['joinForms'])) {
            $q->leftJoin($this->getTableAlias().'.forms', 'f');
        }
        $q->where($q->expr()->isNull($this->getTableAlias().'.deleted'));
        $args['qb'] = $q;

        return parent::getEntities($args);
    }

    public function setCampaignAsDeleted(int $campaignId): void
    {
        $dateTime = (new \DateTime())->format('Y-m-d H:i:s');

        $this->getEntityManager()->getConnection()->update(
            MAUTIC_TABLE_PREFIX.Event::TABLE_NAME,
            ['deleted'     => $dateTime],
            ['campaign_id' => $campaignId]
        );

        $this->getEntityManager()->getConnection()->update(
            MAUTIC_TABLE_PREFIX.Campaign::TABLE_NAME,
            ['deleted'   => $dateTime, 'is_published' => 0],
            ['id'        => $campaignId]
        );
    }

    /**
     * Returns a list of all published (and active) campaigns (optionally for a specific lead).
     *
     * @param null $specificId
     * @param null $leadId
     * @param bool $forList    If true, returns ID and name only
     * @param bool $viewOther  If true, returns all the campaigns
     *
     * @return array
     */
    public function getPublishedCampaigns($specificId = null, $leadId = null, $forList = false, $viewOther = false)
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->from('MauticCampaignBundle:Campaign', 'c', 'c.id');

        if ($forList && $leadId) {
            $q->select('partial c.{id, name}, partial l.{campaign, lead, dateAdded, manuallyAdded, manuallyRemoved}, partial ll.{id}');
        } elseif ($forList) {
            $q->select('partial c.{id, name}, partial ll.{id}');
        } else {
            $q->select('c, l, partial ll.{id}')
                ->leftJoin('c.events', 'e')
                ->leftJoin('e.log', 'o');
        }

        if ($leadId || !$forList) {
            $q->leftJoin('c.leads', 'l');
        }

        $q->leftJoin('c.lists', 'll')
            ->where($this->getPublishedByDateExpression($q));

        if (!$viewOther) {
            $q->andWhere($q->expr()->eq('c.createdBy', ':id'))
                ->setParameter('id', $this->currentUser->getId());
        }

        if (!empty($specificId)) {
            $q->andWhere(
                $q->expr()->eq('c.id', (int) $specificId)
            );
        }

        if (!empty($leadId)) {
            $q->andWhere(
                $q->expr()->eq('IDENTITY(l.lead)', (int) $leadId)
            );
            $q->andWhere(
                $q->expr()->eq('l.manuallyRemoved', ':manuallyRemoved')
            )->setParameter('manuallyRemoved', false);
        }

        return $q->getQuery()->getArrayResult();
    }

    /**
     * Returns a list of all published (and active) campaigns that specific lead lists are part of.
     *
     * @param int|array $leadLists
     *
     * @return array
     */
    public function getPublishedCampaignsByLeadLists($leadLists)
    {
        if (!is_array($leadLists)) {
            $leadLists = [(int) $leadLists];
        } else {
            foreach ($leadLists as &$id) {
                $id = (int) $id;
            }
        }

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.id, c.name, ll.leadlist_id as list_id')
            ->from(MAUTIC_TABLE_PREFIX.'campaigns', 'c');

        $q->join('c', MAUTIC_TABLE_PREFIX.'campaign_leadlist_xref', 'll', 'c.id = ll.campaign_id')
            ->where($this->getPublishedByDateExpression($q));

        $q->andWhere(
            $q->expr()->in('ll.leadlist_id', $leadLists)
        );

        $results = $q->execute()->fetchAll();

        $campaigns = [];
        foreach ($results as $result) {
            if (!isset($campaigns[$result['id']])) {
                $campaigns[$result['id']] = [
                    'id'    => $result['id'],
                    'name'  => $result['name'],
                    'lists' => [],
                ];
            }

            $campaigns[$result['id']]['lists'][$result['list_id']] = [
                'id' => $result['list_id'],
            ];
        }

        return $campaigns;
    }

    /**
     * Get array of list IDs assigned to this campaign.
     *
     * @param null $id
     *
     * @return array
     */
    public function getCampaignListIds($id = null)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leadlist_xref', 'cl');

        if ($id) {
            $q->select('cl.leadlist_id')
                ->where(
                    $q->expr()->eq('cl.campaign_id', $id)
                );
        } else {
            // Retrieve a list of unique IDs that are assigned to a campaign
            $q->select('DISTINCT cl.leadlist_id');
        }

        $lists   = [];
        $results = $q->execute()->fetchAll();

        foreach ($results as $r) {
            $lists[] = $r['leadlist_id'];
        }

        return $lists;
    }

    /**
     * Get array of list IDs => name assigned to this campaign.
     *
     * @param null $id
     *
     * @return array
     */
    public function getCampaignListSources($id)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('cl.leadlist_id, l.name')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leadlist_xref', 'cl')
            ->join('cl', MAUTIC_TABLE_PREFIX.'lead_lists', 'l', 'l.id = cl.leadlist_id');
        $q->where(
            $q->expr()->eq('cl.campaign_id', $id)
        );

        $lists   = [];
        $results = $q->execute()->fetchAll();

        foreach ($results as $r) {
            $lists[$r['leadlist_id']] = $r['name'];
        }

        return $lists;
    }

    /**
     * Get array of form IDs => name assigned to this campaign.
     *
     * @return array
     */
    public function getCampaignFormSources($id)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('cf.form_id, f.name')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_form_xref', 'cf')
            ->join('cf', MAUTIC_TABLE_PREFIX.'forms', 'f', 'f.id = cf.form_id');
        $q->where(
            $q->expr()->eq('cf.campaign_id', $id)
        );

        $forms   = [];
        $results = $q->execute()->fetchAll();

        foreach ($results as $r) {
            $forms[$r['form_id']] = $r['name'];
        }

        return $forms;
    }

    /**
     * @return array
     */
    public function findByFormId($formId)
    {
        $q = $this->createQueryBuilder('c')
            ->join('c.forms', 'f');
        $q->where(
            $q->expr()->eq('f.id', $formId)
        );

        return $q->getQuery()->getResult();
    }

    public function getTableAlias(): string
    {
        return 'c';
    }

    protected function addCatchAllWhereClause($qb, $filter): array
    {
        return $this->addStandardCatchAllWhereClause($qb, $filter, [
            'c.name',
            'c.description',
        ]);
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     */
    protected function addSearchCommandWhereClause($q, $filter): array
    {
        return $this->addStandardSearchCommandWhereClause($q, $filter);
    }

    public function getSearchCommands(): array
    {
        return $this->getStandardSearchCommands();
    }

    /**
     * Get a list of popular (by logs) campaigns.
     *
     * @param int $limit
     *
     * @return array
     */
    public function getPopularCampaigns($limit = 10)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $q->select('count(cl.ip_id) as hits, c.id AS campaign_id, c.name')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'cl')
            ->leftJoin('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'cl.campaign_id = c.id')
            ->orderBy('hits', 'DESC')
            ->groupBy('c.id, c.name')
            ->setMaxResults($limit);

        $expr = $this->getPublishedByDateExpression($q, 'c');
        $q->where($expr);

        return $q->execute()->fetchAll();
    }

    /**
     * @return CountResult
     */
    public function getCountsForPendingContacts($campaignId, array $pendingEvents, ContactLimiter $limiter)
    {
        $q = $this->getSlaveConnection($limiter)->createQueryBuilder();

        $q->select('min(cl.lead_id) as min_id, max(cl.lead_id) as max_id, count(cl.lead_id) as the_count')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->eq('cl.manually_removed', ':false')
                )
            )
            ->setParameter('false', false, 'boolean');

        $this->updateQueryFromContactLimiter('cl', $q, $limiter, true);

        if (count($pendingEvents) > 0) {
            $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $sq->select('null')
                ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'e')
                ->where(
                    $sq->expr()->andX(
                        $sq->expr()->eq('cl.lead_id', 'e.lead_id'),
                        $sq->expr()->eq('e.rotation', 'cl.rotation'),
                        $sq->expr()->in('e.event_id', $pendingEvents)
                    )
                );

            $q->andWhere(
                sprintf('NOT EXISTS (%s)', $sq->getSQL())
            );
        }

        $result = $q->execute()->fetch();

        return new CountResult($result['the_count'], $result['min_id'], $result['max_id']);
    }

    /**
     * Get pending contact IDs for a campaign.
     *
     * @return array
     */
    public function getPendingContactIds($campaignId, ContactLimiter $limiter)
    {
        if ($limiter->hasCampaignLimit() && 0 === $limiter->getCampaignLimitRemaining()) {
            return [];
        }

        $q = $this->getSlaveConnection($limiter)->createQueryBuilder();

        $q->select('cl.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->eq('cl.manually_removed', ':false')
                )
            )
            ->setParameter('false', false, 'boolean')
            ->orderBy('cl.lead_id', 'ASC');

        $this->updateQueryFromContactLimiter('cl', $q, $limiter);

        // Only leads that have not started the campaign
        $sq = $this->getSlaveConnection($limiter)->createQueryBuilder();
        $sq->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'e')
            ->where(
                $sq->expr()->andX(
                    $sq->expr()->eq('e.lead_id', 'cl.lead_id'),
                    $sq->expr()->eq('e.campaign_id', ':campaignId'),
                    $sq->expr()->eq('e.rotation', 'cl.rotation')
                )
            );

        $q->andWhere(
            sprintf('NOT EXISTS (%s)', $sq->getSQL())
        )
            ->setParameter('campaignId', (int) $campaignId);

        if ($limiter->hasCampaignLimit() && $limiter->getCampaignLimitRemaining() < $limiter->getBatchLimit()) {
            $q->setMaxResults($limiter->getCampaignLimitRemaining());
        }

        $results = $q->execute()->fetchAll();
        $leads   = [];
        foreach ($results as $r) {
            $leads[] = $r['lead_id'];
        }
        unset($results);

        if ($limiter->hasCampaignLimit()) {
            $limiter->reduceCampaignLimitRemaining(count($leads));
        }

        return $leads;
    }

    /**
     * Returns true if the campaign has at least one lead.
     *
     * @throws \Doctrine\DBAL\Cache\CacheException
     */
    public function hasCampaignLeads(int $campaignId): bool
    {
        $q = $this->getSlaveConnection()->createQueryBuilder();

        $q->select('1')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', ':campaignId'),
                    $q->expr()->eq('cl.manually_removed', '0')
                )
            )
            ->setParameter('campaignId', $campaignId)
            ->setMaxResults(1);

        if ($q->getConnection()->getConfiguration()->getResultCacheImpl()) {
            $results = $q->getConnection()->executeCacheQuery(
                $q->getSQL(),
                $q->getParameters(),
                $q->getParameterTypes(),
                new QueryCacheProfile(600, __METHOD__)
            )->fetchAll();
        } else {
            $results = $q->execute()->fetchAll();
        }

        return (bool) $results;
    }

    /**
     * Get lead data of a campaign.
     *
     * @param int        $start
     * @param bool|false $limit
     * @param array      $select
     *
     * @return mixed
     */
    public function getCampaignLeads($campaignId, $start = 0, $limit = false, $select = ['cl.lead_id'])
    {
        $q = $this->getSlaveConnection()->createQueryBuilder();

        $q->select($select)
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->eq('cl.manually_removed', ':false')
                )
            )
            ->setParameter('false', false, 'boolean')
            ->orderBy('cl.lead_id', 'ASC');

        if (!empty($limit)) {
            $q->setFirstResult($start)
                ->setMaxResults($limit);
        }

        return $q->execute()->fetchAll();
    }

    /**
     * @return mixed
     */
    public function getContactSingleSegmentByCampaign($contactId, $campaignId)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        return $q->select('ll.id, ll.name')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'll')
            ->join('ll', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'lll', 'lll.leadlist_id = ll.id and lll.lead_id = :contactId and lll.manually_removed = 0')
            ->join('ll', MAUTIC_TABLE_PREFIX.'campaign_leadlist_xref', 'clx', 'clx.leadlist_id = ll.id and clx.campaign_id = :campaignId')
            ->setParameter('contactId', (int) $contactId)
            ->setParameter('campaignId', (int) $campaignId)
            ->setMaxResults(1)
            ->execute()
            ->fetch();
    }

    /**
     * Searches for emails assigned to campaign and returns associative array of email ids in format:.
     *
     *  array (size=1)
     *      0 =>
     *          array (size=2)
     *              'channelId' => int 18
     *
     * or empty array if nothing found.
     *
     * @param int $id
     *
     * @return array
     */
    public function fetchEmailIdsById($id)
    {
        $emails = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('e.channelId')
            ->from('MauticCampaignBundle:Campaign', $this->getTableAlias(), $this->getTableAlias().'.id')
            ->leftJoin(
                $this->getTableAlias().'.events',
                'e',
                Expr\Join::WITH,
                "e.channel = '".Event::CHANNEL_EMAIL."'"
            )
            ->where($this->getTableAlias().'.id = :id')
            ->setParameter('id', $id)
            ->andWhere('e.channelId IS NOT NULL')
            ->getQuery()
            ->setHydrationMode(\Doctrine\ORM\Query::HYDRATE_ARRAY)
            ->getResult();

        $return = [];
        foreach ($emails as $email) {
            // Every channelId represents e-mail ID
            $return[] = $email['channelId']; // mautic_campaign_events.channel_id
        }

        return $return;
    }
}
