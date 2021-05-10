<?php

declare(strict_types=1);

namespace Mautic\CampaignBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Doctrine\GeneratedColumn\GeneratedColumn;
use Mautic\CoreBundle\Event\GeneratedColumnsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class GeneratedColumnSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::ON_GENERATED_COLUMNS_BUILD => ['onGeneratedColumnsBuild', 0],
        ];
    }

    public function onGeneratedColumnsBuild(GeneratedColumnsEvent $event): void
    {
        $event->addGeneratedColumn($this->buildGeneratedColumn('hour', 'DATETIME', '%Y-%m-%d %H:00:00'));
        $event->addGeneratedColumn($this->buildGeneratedColumn('day', 'DATE', '%Y-%m-%d'));
        $event->addGeneratedColumn($this->buildGeneratedColumn('month', 'DATE', '%Y-%m-01'));
        $event->addGeneratedColumn($this->buildGeneratedColumn('year', 'DATE', '%Y-01-01'));
    }

    private function buildGeneratedColumn(string $unit, string $type, string $format): GeneratedColumn
    {
        $generatedColumn = new GeneratedColumn('campaign_lead_event_log', "generated_date_$unit", $type, "DATE_FORMAT(date_triggered, '$format')");
        $generatedColumn->setOriginalDateColumn('date_triggered', $unit);
        $generatedColumn->addIndexColumn('campaign_id');
        $generatedColumn->addIndexColumn('is_scheduled');
        $generatedColumn->addIndexColumn('non_action_path_taken');
        $generatedColumn->setFilterDateColumn("generated_date_$unit");

        return $generatedColumn;
    }
}
