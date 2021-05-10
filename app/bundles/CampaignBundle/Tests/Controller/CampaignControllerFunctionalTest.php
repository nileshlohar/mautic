<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\Tests\Controller;

use function GuzzleHttp\json_decode;

use Mautic\CampaignBundle\Command\SummarizeCommand;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Tests\Campaign\AbstractCampaignTest;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

class CampaignControllerFunctionalTest extends AbstractCampaignTest
{
    private const CAMPAIGN_SUMMARY_PARAM = 'campaign_use_summary';
    private const CAMPAIGN_RANGE_PARAM   = 'campaign_by_range';

    /**
     * @var CampaignModel
     */
    private $campaignModel;

    /**
     * @var string
     */
    private $campaignLeadsLabel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campaignModel      = $this->container->get('mautic.model.factory')->getModel('campaign');
        $this->campaignLeadsLabel = $this->container->get('translator')->trans('mautic.campaign.campaign.leads');
    }

    public function testCampaignContactCountThroughStats(): void
    {
        $campaign   = $this->saveSomeCampaignLeadEventLogs();
        $campaignId = $campaign->getId();

        // Campaign Summary OFF
        $coreParam = $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false]);
        $this->campaignModel->setCoreParametersHelper($coreParam);
        $totalContacts = $this->getStatTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);

        // Campaign Summary ON
        $coreParam = $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true]);
        $this->campaignModel->setCoreParametersHelper($coreParam);
        $totalContacts = $this->getStatTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);
    }

    public function testCampaignContactCountOnCanvas(): void
    {
        $campaign   = $this->saveSomeCampaignLeadEventLogs();
        $campaignId = $campaign->getId();

        // Campaign Summary OFF, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => false]);
        $totalContacts = $this->getCanvasTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);

        // Campaign Summary ON, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => false]);
        $totalContacts = $this->getCanvasTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);

        // Campaign Summary OFF, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => true]);
        $totalContacts = $this->getCanvasTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);

        // Campaign Summary ON, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => true]);
        $totalContacts = $this->getCanvasTotalContacts($campaignId);
        Assert::assertSame(2, $totalContacts);
    }

    public function testCampaignCountsBeforeSummarizeCommand(): void
    {
        $campaign   = $this->saveSomeCampaignLeadEventLogs();
        $campaignId = $campaign->getId();

        // Campaign Summary OFF, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('0%', $actionCounts['successPercent']);
        Assert::assertSame('0', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary OFF, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('0%', $actionCounts['successPercent']);
        Assert::assertSame('0', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);
    }

    public function testCampaignCountsAfterSummarizeCommand(): void
    {
        $campaign   = $this->saveSomeCampaignLeadEventLogs();
        $campaignId = $campaign->getId();

        $this->runCommand(
            SummarizeCommand::NAME,
            [
                '--env'       => 'test',
                '--max-hours' => 9999999,
            ]
        );

        // Campaign Summary OFF, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary OFF, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('0', $actionCounts['pending']);
    }

    public function testCampaignPendingCounts(): void
    {
        // emulate pending count
        $campaign   = $this->saveSomeCampaignLeadEventLogs(true);
        $campaignId = $campaign->getId();

        $this->runCommand(
            SummarizeCommand::NAME,
            [
                '--env'       => 'test',
                '--max-hours' => 9999999,
            ]
        );

        // Campaign Summary OFF, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);

        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('1', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range OFF
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => false]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('1', $actionCounts['pending']);

        // Campaign Summary OFF, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => false, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('1', $actionCounts['pending']);

        // Campaign Summary ON, Campaign Range ON
        $this->setSummaryCoreParameter([self::CAMPAIGN_SUMMARY_PARAM => true, self::CAMPAIGN_RANGE_PARAM => true]);
        $actionCounts = $this->getActionCounts($campaignId);
        Assert::assertSame('100%', $actionCounts['successPercent']);
        Assert::assertSame('2', $actionCounts['completed']);
        Assert::assertSame('1', $actionCounts['pending']);
    }

    private function getStatTotalContacts(int $campaignId): int
    {
        $stats = $this->campaignModel->getCampaignMetricsLineChartData(
            null,
            new \DateTime('2020-10-21'),
            new \DateTime('2020-11-22'),
            null,
            ['campaign_id' => $campaignId]
        );
        $datasets      = $stats['datasets'] ?? [];

        return $this->processTotalContactStats($datasets);
    }

    private function getCanvasTotalContacts(int $campaignId): int
    {
        $this->client->request('GET', sprintf('s/campaigns/graph/%d/%s/%s', $campaignId, '2020-11-1', '2020-11-30'));
        $response      = $this->client->getResponse();
        $body          = json_decode($response->getContent(), true);
        $crawler       = new Crawler($body['newContent']);
        $canvasJson    = trim($crawler->filter('canvas')->html());
        $canvasData    = json_decode($canvasJson, true);
        $datasets      = $canvasData['datasets'] ?? [];
        $this->client->restart();

        return $this->processTotalContactStats($datasets);
    }

    private function setSummaryCoreParameter(array $parameters): CoreParametersHelper
    {
        $coreParam = new class($this->container, $parameters) extends CoreParametersHelper {
            private $parameters;

            public function __construct(ContainerInterface $container, array $parameters)
            {
                $this->parameters = $parameters;
                parent::__construct($container);
            }

            public function get($name, $default = null)
            {
                return $this->parameters[$name] ?? parent::get($name, $default);
            }
        };
        $this->container->set('mautic.helper.core_parameters', $coreParam);

        return $coreParam;
    }

    private function processTotalContactStats(array $datasets): int
    {
        $totalContacts = 0;

        foreach ($datasets as $dataset) {
            if ($dataset['label'] === $this->campaignLeadsLabel) {
                $data          = $dataset['data'] ?? [];
                $totalContacts = array_sum($data);
                break;
            }
        }

        return $totalContacts;
    }

    private function getCrawler(int $campaignId): Crawler
    {
        $url = sprintf('s/campaigns/event/stats/%d/%s/%s', $campaignId, '2020-11-1', '2020-11-30');
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        $body     = json_decode($response->getContent(), true);
        $this->client->restart();

        return new Crawler($body['actions']);
    }

    private function getActionCounts(int $campaignId): array
    {
        $crawler        = $this->getCrawler($campaignId);
        $successPercent = trim($crawler->filter('.campaign-event-list')->filter('span')->eq(0)->html());
        $completed      = trim($crawler->filter('.campaign-event-list')->filter('span')->eq(1)->html());
        $pending        = trim($crawler->filter('.campaign-event-list')->filter('span')->eq(2)->html());

        return [
            'successPercent' => $successPercent,
            'completed'      => $completed,
            'pending'        => $pending,
        ];
    }

    public function testCampaignView(): void
    {
        $campaign = $this->saveSomeCampaignLeadEventLogs();
        $crawler  = $this->client->request('GET', sprintf('/s/campaigns/view/%d', $campaign->getId()));
        $response = $this->client->getResponse();
        self::assertTrue($response->isOk());
        self::assertStringContainsString('Campaign ABC', $response->getContent());
        self::assertSame('', trim($crawler->filter('#decisions-container')->text()));
        self::assertSame('', trim($crawler->filter('#actions-container')->text()));
        self::assertSame('', trim($crawler->filter('#conditions-container')->text()));
        self::assertSame('', trim($crawler->filter('#campaign-graph-div')->text()));
    }

    public function testCampaignViewEvents(): void
    {
        $campaign = $this->saveSomeCampaignLeadEventLogs();
        $this->client->request('GET', sprintf('s/campaigns/event/stats/%d/%s/%s', $campaign->getId(), '2020-11-20 16:34:00', '2020-11-22 16:34:00'));
        $response = $this->client->getResponse();
        self::assertTrue($response->isOk());
        $body     = json_decode($response->getContent(), true);
        self::assertCount(1, $body);
        self::arrayHasKey('actions');
        self::assertStringContainsString('100% 2 0 Event A mautic.campaign.type.a 100% 2 0 Event B mautic.campaign.type.b', preg_replace('/\s+/', ' ', strip_tags($body['actions'])));
    }

    public function testCampaignViewGraph(): void
    {
        $campaign = $this->saveSomeCampaignLeadEventLogs();
        $this->client->request('GET', sprintf('s/campaigns/graph/%d/%s/%s', $campaign->getId(), '2020-11-20 16:34:00', '2020-11-22 16:34:00'));
        $response = $this->client->getResponse();
        self::assertTrue($response->isOk());
        self::assertStringContainsString('Campaign statistics', $response->getContent());
    }
}
