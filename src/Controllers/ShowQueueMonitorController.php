<?php

namespace romanzipp\QueueMonitor\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MongoDB\BSON\UTCDateTime;
use romanzipp\QueueMonitor\Controllers\Payloads\Metric;
use romanzipp\QueueMonitor\Controllers\Payloads\Metrics;
use romanzipp\QueueMonitor\Models\Contracts\MonitorContract;
use romanzipp\QueueMonitor\Services\QueueMonitor;

class ShowQueueMonitorController
{
    /**
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['all', 'running', 'failed', 'succeeded'])],
            'queue' => ['nullable', 'string'],
        ]);

        $filters = [
            'type' => $data['type'] ?? 'all',
            'queue' => $data['queue'] ?? 'all',
        ];

        $jobs = QueueMonitor::getModel()
            ->newQuery()
            ->when(($type = $filters['type']) && 'all' !== $type, static function (Builder $builder) use ($type) {
                switch ($type) {
                    case 'running':
                        $builder->whereNull('finished_at');
                        break;

                    case 'failed':
                        $builder->where('failed', 1)->whereNotNull('finished_at');
                        break;

                    case 'succeeded':
                        $builder->where('failed', 0)->whereNotNull('finished_at');
                        break;
                }
            })
            ->when(($queue = $filters['queue']) && 'all' !== $queue, static function (Builder $builder) use ($queue) {
                $builder->where('queue', $queue);
            })
            ->ordered()
            ->paginate(
                config('queue-monitor.ui.per_page')
            )
            ->appends(
                $request->all()
            );

        $queues = QueueMonitor::getModel()
            ->newQuery()
            ->select('queue')
            ->groupBy('queue')
            ->get()
            ->map(function (MonitorContract $monitor) {
                return $monitor->queue;
            })
            ->toArray();

        $metrics = null;

        if (config('queue-monitor.ui.show_metrics')) {
            $metrics = $this->collectMetrics();
        }

        return view('queue-monitor::jobs', [
            'jobs' => $jobs,
            'filters' => $filters,
            'queues' => $queues,
            'metrics' => $metrics,
        ]);
    }

    public function collectMetrics(): Metrics
    {
        $timeFrame = config('queue-monitor.ui.metrics_time_frame') ?? 2;

        $metrics = new Metrics();

        $aggregatedInfo = collect(DB::table(
                QueueMonitor::getModel()->getTable()
            )->raw(function ($collection) use ($timeFrame) {
                return $collection->aggregate(
                    [
                        [
                            '$match' => [
                                'started_at' => [
                                    '$gte' => new UTCDateTime(Carbon::now()->subDays($timeFrame)->format('Uv')),
                                ],
                            ],
                        ],
                        [
                            '$group' => [
                                '_id' => 1,
                                'count' => ['$sum' => 1],
                                'total_time_elapsed' => ['$sum' => '$time_elapsed'],
                                'average_time_elapsed' => ['$avg' => '$time_elapsed'],
                            ],
                        ],
                    ],
                    ['allowDiskUse' => true]
                );
            }))->first();
        $aggregatedComparisonInfo = collect(DB::table(
                QueueMonitor::getModel()->getTable()
            )->raw(function ($collection) use ($timeFrame) {
                return $collection->aggregate(
                    [
                        [
                            '$match' => [
                                'started_at' => [
                                    '$gte' => new UTCDateTime(Carbon::now()->subDays($timeFrame * 2)->format('Uv')),
                                    '$lte' => new UTCDateTime(Carbon::now()->subDays($timeFrame)->format('Uv')),
                                ],
                            ],
                        ],
                        [
                            '$group' => [
                                '_id' => 1,
                                'count' => ['$sum' => 1],
                                'total_time_elapsed' => ['$sum' => '$time_elapsed'],
                                'average_time_elapsed' => ['$avg' => '$time_elapsed'],
                            ],
                        ],
                    ],
                    ['allowDiskUse' => true]
                );
            }))->first();

        if (null === $aggregatedInfo || null === $aggregatedComparisonInfo) {
            return $metrics;
        }

        return $metrics
            ->push(
                new Metric('Total Jobs Executed', $aggregatedInfo->count ?? 0, $aggregatedComparisonInfo->count, '%d')
            )
            ->push(
                new Metric('Total Execution Time', $aggregatedInfo->total_time_elapsed ?? 0, $aggregatedComparisonInfo->total_time_elapsed, '%ds')
            )
            ->push(
                new Metric('Average Execution Time', $aggregatedInfo->average_time_elapsed ?? 0, $aggregatedComparisonInfo->average_time_elapsed, '%0.2fs')
            );
    }
}
