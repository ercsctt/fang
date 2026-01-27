<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface DataFreshnessStats {
    fresh: number;
    stale_24h: number;
    stale_48h: number;
    stale_week: number;
    never_scraped: number;
    total: number;
}

interface Props {
    stats: DataFreshnessStats;
}

const props = defineProps<Props>();

const freshRate = computed(() => {
    if (props.stats.total === 0) {
        return 0;
    }
    return Math.round((props.stats.fresh / props.stats.total) * 100);
});

const hasStaleAlert = computed(
    () => props.stats.stale_week > 0 || props.stats.never_scraped > 0,
);

interface FreshnessData {
    label: string;
    count: number;
    color: string;
    description: string;
}

const freshnessData = computed<FreshnessData[]>(() => [
    {
        label: 'Fresh',
        count: props.stats.fresh,
        color: 'bg-green-500',
        description: '< 24 hours',
    },
    {
        label: 'Stale (24-48h)',
        count: props.stats.stale_24h,
        color: 'bg-yellow-500',
        description: '24-48 hours',
    },
    {
        label: 'Stale (48h-7d)',
        count: props.stats.stale_48h,
        color: 'bg-orange-500',
        description: '48h - 7 days',
    },
    {
        label: 'Very Stale',
        count: props.stats.stale_week,
        color: 'bg-red-500',
        description: '> 7 days',
    },
    {
        label: 'Never Scraped',
        count: props.stats.never_scraped,
        color: 'bg-gray-400',
        description: 'No data',
    },
]);

function getPercentage(count: number): number {
    if (props.stats.total === 0) {
        return 0;
    }
    return Math.round((count / props.stats.total) * 100);
}
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-base">Data Freshness</CardTitle>
            <CardDescription>
                {{ freshRate }}% fresh ({{ stats.fresh.toLocaleString() }} of
                {{ stats.total.toLocaleString() }} listings)
            </CardDescription>
        </CardHeader>
        <CardContent>
            <Alert v-if="hasStaleAlert" variant="destructive" class="mb-4">
                <AlertTriangle class="size-4" />
                <AlertTitle>Attention Needed</AlertTitle>
                <AlertDescription>
                    <span v-if="stats.stale_week > 0">
                        {{ stats.stale_week.toLocaleString() }} products haven't
                        been scraped in over a week.
                    </span>
                    <span v-if="stats.never_scraped > 0">
                        {{ stats.never_scraped.toLocaleString() }} products have
                        never been scraped.
                    </span>
                </AlertDescription>
            </Alert>

            <Alert
                v-else-if="freshRate >= 90"
                class="mb-4 border-green-500 bg-green-500/10"
            >
                <CheckCircle2 class="size-4 text-green-600" />
                <AlertTitle class="text-green-600"> Data is Fresh </AlertTitle>
                <AlertDescription class="text-green-600/80">
                    {{ freshRate }}% of listings have been scraped within 24
                    hours.
                </AlertDescription>
            </Alert>

            <div class="mb-3 flex h-3 overflow-hidden rounded-full bg-muted">
                <div
                    v-for="item in freshnessData"
                    :key="item.label"
                    :class="item.color"
                    :style="{ width: `${getPercentage(item.count)}%` }"
                    class="transition-all"
                />
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                <div
                    v-for="item in freshnessData"
                    :key="item.label"
                    class="text-xs"
                >
                    <div class="flex items-center gap-1">
                        <div :class="[item.color, 'size-2 rounded-full']" />
                        <span class="text-muted-foreground">
                            {{ item.label }}
                        </span>
                    </div>
                    <span class="ml-3 font-medium">
                        {{ item.count.toLocaleString() }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
