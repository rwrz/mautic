<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Entity;

use Doctrine\DBAL\Types\Type;
use Mautic\CampaignBundle\Entity\Result\CountResult;
use Mautic\CampaignBundle\Executioner\ContactFinder\Limiter\ContactLimiter;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * Class CampaignRepository.
 */
class CampaignRepository extends CommonRepository
{
    use ContactLimiterTrait;
    use SlaveConnectionTrait;

    /**
     * {@inheritdoc}
     */
    public function getEntities(array $args = [])
    {
        $q = $this->getEntityManager()
            ->createQueryBuilder()
            ->select($this->getTableAlias().', cat')
            ->from('MauticCampaignBundle:Campaign', $this->getTableAlias(), $this->getTableAlias().'.id')
            ->leftJoin($this->getTableAlias().'.category', 'cat');

        if (!empty($args['joinLists'])) {
            $q->leftJoin($this->getTableAlias().'.lists', 'l');
        }

        if (!empty($args['joinForms'])) {
            $q->leftJoin($this->getTableAlias().'.forms', 'f');
        }

        $args['qb'] = $q;

        return parent::getEntities($args);
    }

    /**
     * {@inheritdoc}
     *
     * @param object $entity
     * @param bool   $flush
     */
    public function deleteEntity($entity, $flush = true)
    {
        // Null parents of associated events first
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->update(MAUTIC_TABLE_PREFIX.'campaign_events')
            ->set('parent_id', ':null')
            ->setParameter('null', null)
            ->where('campaign_id = '.$entity->getId())
            ->execute();

        // Delete events
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->delete(MAUTIC_TABLE_PREFIX.'campaign_events')
            ->where('campaign_id = '.$entity->getId())
            ->execute();

        parent::deleteEntity($entity, $flush);
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

        $results = $q->getQuery()->getArrayResult();

        return $results;
    }

    /**
     * Returns a list of all published (and active) campaigns that specific lead lists are part of.
     *
     * @param int|array $leadLists
     *
     * @return array
     */
    public function getPublishedCampaignsByLeadLists($leadLists, $viewOther = false)
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

        if (!$viewOther) {
            $q->andWhere($q->expr()->eq('c.created_by', ':id'))
                ->setParameter('id', $this->currentUser->getId());
        }

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
     * @param $id
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
     * @param $formId
     *
     * @return array
     */
    public function findByFormId($formId)
    {
        $q = $this->createQueryBuilder('c')
            ->join('c.forms', 'f');
        $q->where(
            $q->expr()->eq('f.id', $formId)
        );

        $campaigns = $q->getQuery()->getResult();

        return $campaigns;
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return 'c';
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addCatchAllWhereClause($q, $filter)
    {
        return $this->addStandardCatchAllWhereClause($q, $filter, [
            'c.name',
            'c.description',
        ]);
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addSearchCommandWhereClause($q, $filter)
    {
        return $this->addStandardSearchCommandWhereClause($q, $filter);
    }

    /**
     * @return array
     */
    public function getSearchCommands()
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
     * @param                $campaignId
     * @param array          $pendingEvents
     * @param ContactLimiter $limiter
     *
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
     * @param                $campaignId
     * @param ContactLimiter $limiter
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
     * Get a count of leads that belong to the campaign.
     *
     * @param int   $campaignId
     * @param int   $leadId        Optional lead ID to check if lead is part of campaign
     * @param array $pendingEvents List of specific events to rule out
     *
     * @return int
     */
    public function getCampaignLeadCount($campaignId, $leadId = null, $pendingEvents = [])
    {
        $q = $this->getSlaveConnection()->createQueryBuilder();

        $q->select('count(cl.lead_id) as lead_count')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('cl.campaign_id', (int) $campaignId),
                    $q->expr()->eq('cl.manually_removed', ':false')
                )
            )
            ->setParameter('false', false, Type::BOOLEAN);

        if ($leadId) {
            $q->andWhere(
                $q->expr()->eq('cl.lead_id', (int) $leadId)
            );
        }

        if (count($pendingEvents) > 0) {
            $sq = $this->getSlaveConnection()->createQueryBuilder();
            $sq->select('null')
                ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'e')
                ->where(
                    $sq->expr()->andX(
                        $sq->expr()->eq('cl.lead_id', 'e.lead_id'),
                        $sq->expr()->in('e.event_id', $pendingEvents)
                    )
                );

            $q->andWhere(
                sprintf('NOT EXISTS (%s)', $sq->getSQL())
            );
        }

        $results = $q->execute()->fetchAll();

        return (int) $results[0]['lead_count'];
    }

    /**
     * Get lead data of a campaign.
     *
     * @param            $campaignId
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

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * @param $contactId
     * @param $campaignId
     *
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
     * Get lead IDs of a campaign.
     *
     * @deprecated 2.13.0 to be removed in 3.0
     *
     * @param            $campaignId
     * @param int        $start
     * @param bool|false $limit
     * @param bool|false  getCampaignLeadIds
     *
     * @return array
     */
    public function getCampaignLeadIds($campaignId, $start = 0, $limit = false, $pendingOnly = false)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

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

        if ($pendingOnly) {
            // Only leads that have not started the campaign
            $sq = $this->getEntityManager()->getConnection()->createQueryBuilder();

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
        }

        if (!empty($limit)) {
            $q->setMaxResults($limit);
        }

        if (!$pendingOnly && $start) {
            $q->setFirstResult($start);
        }

        $results = $q->execute()->fetchAll();

        $leads = [];
        foreach ($results as $r) {
            $leads[] = $r['lead_id'];
        }

        unset($results);

        return $leads;
    }

    /**
     * Returns leads that are part of a lead list that belongs to a campaign.
     *
     * @deprecated 2.14 to be removed in 3.0; use LeadRepository::getCampaignContacts
     *
     * @param       $id
     * @param array $lists
     * @param array $args
     *
     * @return array|int
     */
    public function getCampaignLeadsFromLists($id, array $lists, $args = [])
    {
        $batchLimiters = (!array_key_exists('batchLimiters', $args)) ? false : $args['batchLimiters'];
        $withMinId     = (!array_key_exists('withMinId', $args)) ? false : $args['withMinId'];
        $countOnly     = (!array_key_exists('countOnly', $args)) ? false : $args['countOnly'];
        $start         = (!array_key_exists('start', $args)) ? false : $args['start'];
        $limit         = (!array_key_exists('limit', $args)) ? false : $args['limit'];

        $leads = ($countOnly) ? 0 : [];

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        if ($countOnly) {
            $q->select('max(list_leads.lead_id) as max_id, count(distinct(list_leads.lead_id)) as lead_count');
            if ($withMinId) {
                $q->addSelect('min(list_leads.lead_id) as min_id');
            }
        } else {
            $q->select('distinct(list_leads.lead_id) as id');
        }

        $q->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'list_leads');

        $expr = $q->expr()->andX(
            $q->expr()->eq('list_leads.manually_removed', ':false'),
            $q->expr()->in('list_leads.leadlist_id', $lists)
        );

        if ($batchLimiters) {
            $expr->add(
            // Only leads in the list at the time of count
                $q->expr()->lte('list_leads.date_added', $q->expr()->literal($batchLimiters['dateTime']))
            );

            if (!empty($batchLimiters['minId']) && !empty($batchLimiters['maxId'])) {
                $expr->add(
                    $q->expr()->comparison('list_leads.lead_id', 'BETWEEN', "{$batchLimiters['minId']} and {$batchLimiters['maxId']}")
                );
            } elseif (!empty($batchLimiters['maxId'])) {
                // Only leads that existed at the time of count
                $expr->add(
                    $q->expr()->lte('list_leads.lead_id', $batchLimiters['maxId'])
                );
            }
        }

        // Exclude leads already part of or manually removed from the campaign
        $subq = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'campaign_leads')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('campaign_leads.lead_id', 'list_leads.lead_id'),
                    $q->expr()->eq('campaign_leads.campaign_id', (int) $id)
                )
            );

        $expr->add(
            sprintf('NOT EXISTS (%s)', $subq->getSQL())
        );

        $q->where($expr)
            ->setParameter('false', false, 'boolean');

        // Set limits if applied
        if (!empty($limit)) {
            $q->setMaxResults($limit);
        }

        if ($start) {
            $q->setFirstResult($start);
        }

        $results = $q->execute()->fetchAll();

        foreach ($results as $r) {
            if ($countOnly) {
                $leads = [
                    'count' => $r['lead_count'],
                    'maxId' => $r['max_id'],
                ];
                if ($withMinId) {
                    $leads['minId'] = $r['min_id'];
                }
            } else {
                $leads[] = $r['id'];
            }
        }

        unset($parameters, $q, $expr, $results);

        return $leads;
    }

    /**
     * @param int   $segmentId
     * @param array $campaignIds
     *
     * @return array
     */
    public function getCampaignsSegmentShare($segmentId, $campaignIds = [])
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->select('c.id, c.name, ROUND(IFNULL(COUNT(DISTINCT cl2.lead_id)/COUNT(DISTINCT cl.lead_id)*100, 0),1) data');
        $q->from(MAUTIC_TABLE_PREFIX.'campaigns', 'c')
        ->leftJoin('c', MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl', 'cl.campaign_id = c.id AND cl.manually_removed = 0')
        ->leftJoin('c', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'lll', 'lll.leadlist_id = '.$segmentId.' AND lll.manually_removed = 0')
        ->leftJoin('c', MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl2', 'cl2.campaign_id = c.id AND cl2.manually_removed = 0 AND cl2.lead_id = lll.lead_id');
        $q->groupBy('c.id');
        $q->orderBy('data', 'desc');

        if (!empty($campaignIds)) {
            $q->where($q->expr()->in('c.id', $campaignIds));
        }

        return $q->execute()->fetchAll();
    }

    /**
     * Get leads that do not belong based on lead lists.
     *
     * @deprecated 2.14 to be removed in 3.0; use LeadRepository::getOprhanedContacts instead
     *
     * @param       $id
     * @param array $lists
     * @param array $args
     *
     * @return array|int
     */
    public function getCampaignOrphanLeads($id, array $lists, $args = [])
    {
        $batchLimiters = (!array_key_exists('batchLimiters', $args)) ? false : $args['batchLimiters'];
        $countOnly     = (!array_key_exists('countOnly', $args)) ? false : $args['countOnly'];
        $start         = (!array_key_exists('start', $args)) ? false : $args['start'];
        $limit         = (!array_key_exists('limit', $args)) ? false : $args['limit'];

        $leads = ($countOnly) ? 0 : [];

        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        if ($countOnly) {
            $q->select('max(campaign_leads.lead_id) as max_id, count(campaign_leads.lead_id) as lead_count');
        } else {
            $q->select('campaign_leads.lead_id as id');
        }

        $q->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'campaign_leads')
            ->setParameter('false', false, 'boolean');

        $expr = $q->expr()->andX(
            $q->expr()->eq('campaign_leads.campaign_id', (int) $id),
            $q->expr()->eq('campaign_leads.manually_added', ':false')
        );

        if ($batchLimiters) {
            $expr->add(
            // Only leads part of the campaign at the time of count
                $q->expr()->lte('campaign_leads.date_added', $q->expr()->literal($batchLimiters['dateTime']))
            );

            if (!empty($batchLimiters['maxId'])) {
                // Only leads that existed at the time of count
                $expr->add(
                    $q->expr()->lte('campaign_leads.lead_id', $batchLimiters['maxId'])
                );
            }
        }

        if (!empty($lists)) {
            $subq = $this->getEntityManager()->getConnection()->createQueryBuilder()
                ->select('null')
                ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'list_leads')
                ->where(
                    $q->expr()->andX(
                        $q->expr()->eq('campaign_leads.lead_id', 'list_leads.lead_id'),
                        $q->expr()->eq('list_leads.manually_removed', ':false'),
                        $q->expr()->in('list_leads.leadlist_id', $lists)
                    )
                );

            $expr->add(
                sprintf('NOT EXISTS (%s)', $subq->getSQL())
            );
        }

        $q->where($expr);

        // Set limits if applied
        if (!empty($limit)) {
            $q->setFirstResult($start)
                ->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        foreach ($results as $r) {
            if ($countOnly) {
                $leads = [
                    'count' => $r['lead_count'],
                    'maxId' => $r['max_id'],
                ];
            } else {
                $leads[] = $r['id'];
            }
        }

        unset($parameters, $q, $expr, $results);

        return $leads;
    }
}
