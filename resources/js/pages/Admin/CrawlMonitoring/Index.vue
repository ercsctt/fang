<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import admin from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';
import type {
    ChartData,
    CrawlStatistic,
    DataFreshnessStats,
    FailedJob,
    MatchingStats,
    RetailerHealth,
    TodayStats,
} from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock,
    Database,
    PauseCircle,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CrawlStatsChart from './components/CrawlStatsChart.vue';
import DataFreshnessCard from './components/DataFreshnessCard.vue';
import FailedJobsTable from './components/FailedJobsTable.vue';
import MatchingStatsCard from './components/MatchingStatsCard.vue';
import RetailerHealthTable from './components/RetailerHealthTable.vue';

interface Props {
    retailers: RetailerHealth[];
    statistics: CrawlStatistic[];
    todayStats: TodayStats;
    matchingStats: MatchingStats;
    dataFreshnessStats: DataFreshnessStats;
    failedJobs: FailedJob[];
    chartData: ChartData;
    filters: {
        range: string;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Crawl Monitoring',
        href: admin.crawlMonitoring.url(),
    },
];

const selectedRange = ref(props.filters.range);
const isRefreshing = ref(false);

function changeRange(value: unknown) {
    if (!value || typeof value !== 'string') return;
    selectedRange.value = value;
    router.get(
        admin.crawlMonitoring.url({ query: { range: value } }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function refresh() {
    isRefreshing.value = true;
    router.reload({
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
}

// Map new status enum to health categories for KPI display
// 'active' -> healthy, 'paused' -> paused, 'degraded' -> degraded, 'disabled'/'failed' -> unhealthy
const healthyCount = computed(
    () => props.retailers.filter((r) => r.status === 'active').length,
);
const pausedCount = computed(
    () => props.retailers.filter((r) => r.status === 'paused').length,
);
const degradedCount = computed(
    () => props.retailers.filter((r) => r.status === 'degraded').length,
);
const unhealthyCount = computed(
    () =>
        props.retailers.filter(
            (r) => r.status === 'disabled' || r.status === 'failed',
        ).length,
);
</script>

<template>
    <Head title="Crawl Monitoring" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Crawl Monitoring
                    </h1>
                    <p class="text-muted-foreground">
                        Monitor crawl operations, retailer health, and data
                        quality
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Select
                        :model-value="selectedRange"
                        @update:model-value="changeRange"
                    >
                        <SelectTrigger class="w-36">
                            <SelectValue placeholder="Time range" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="7">Last 7 days</SelectItem>
                            <SelectItem value="14">Last 14 days</SelectItem>
                            <SelectItem value="30">Last 30 days</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        variant="outline"
                        size="icon"
                        :disabled="isRefreshing"
                        @click="refresh"
                    >
                        <RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': isRefreshing }"
                        />
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Today's Crawls
                        </CardTitle>
                        <Activity class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ todayStats.crawls_completed.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <span
                                v-if="todayStats.crawls_failed > 0"
                                class="text-destructive"
                            >
                                {{ todayStats.crawls_failed }} failed
                            </span>
                            <span
                                v-else
                                class="text-green-600 dark:text-green-400"
                            >
                                No failures
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Success Rate
                        </CardTitle>
                        <CheckCircle2 class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{
                                todayStats.success_rate !== null
                                    ? `${todayStats.success_rate}%`
                                    : 'N/A'
                            }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ todayStats.crawls_started }} started today
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Listings Discovered
                        </CardTitle>
                        <Database class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{
                                todayStats.listings_discovered.toLocaleString()
                            }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ todayStats.details_extracted.toLocaleString() }}
                            details extracted
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Retailer Health
                        </CardTitle>
                        <Clock class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                v-if="healthyCount > 0"
                                variant="outline"
                                class="border-green-500 bg-green-500/10 text-green-600 dark:text-green-400"
                            >
                                <CheckCircle2 class="mr-1 size-3" />
                                {{ healthyCount }}
                            </Badge>
                            <Badge
                                v-if="pausedCount > 0"
                                variant="outline"
                                class="border-blue-500 bg-blue-500/10 text-blue-600 dark:text-blue-400"
                            >
                                <PauseCircle class="mr-1 size-3" />
                                {{ pausedCount }}
                            </Badge>
                            <Badge
                                v-if="degradedCount > 0"
                                variant="outline"
                                class="border-yellow-500 bg-yellow-500/10 text-yellow-600 dark:text-yellow-400"
                            >
                                <AlertTriangle class="mr-1 size-3" />
                                {{ degradedCount }}
                            </Badge>
                            <Badge
                                v-if="unhealthyCount > 0"
                                variant="outline"
                                class="border-red-500 bg-red-500/10 text-red-600 dark:text-red-400"
                            >
                                <XCircle class="mr-1 size-3" />
                                {{ unhealthyCount }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ retailers.length }} retailers total
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Crawl Activity</CardTitle>
                        <CardDescription>
                            Completed crawls, listings discovered, and failures
                            over time
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CrawlStatsChart :chart-data="chartData" />
                    </CardContent>
                </Card>

                <div class="grid gap-6">
                    <MatchingStatsCard :stats="matchingStats" />
                    <DataFreshnessCard :stats="dataFreshnessStats" />
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Retailer Health Status</CardTitle>
                    <CardDescription>
                        Current health status and circuit breaker state for each
                        retailer
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <RetailerHealthTable :retailers="retailers" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Failed Jobs</CardTitle>
                    <CardDescription>
                        Recent failed crawl jobs with retry controls
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <FailedJobsTable :jobs="failedJobs" />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
