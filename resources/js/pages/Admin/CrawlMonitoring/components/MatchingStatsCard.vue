<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { computed } from 'vue';

interface MatchingStats {
    exact: number;
    fuzzy: number;
    barcode: number;
    manual: number;
    unmatched: number;
    total_listings: number;
}

interface Props {
    stats: MatchingStats;
}

const props = defineProps<Props>();

const matchedTotal = computed(
    () =>
        props.stats.exact +
        props.stats.fuzzy +
        props.stats.barcode +
        props.stats.manual,
);

const matchRate = computed(() => {
    if (props.stats.total_listings === 0) {
        return 0;
    }
    return Math.round(
        ((props.stats.total_listings - props.stats.unmatched) /
            props.stats.total_listings) *
            100,
    );
});

interface MatchTypeData {
    label: string;
    count: number;
    color: string;
    bgColor: string;
}

const matchTypes = computed<MatchTypeData[]>(() => [
    {
        label: 'Exact',
        count: props.stats.exact,
        color: 'bg-green-500',
        bgColor: 'bg-green-500/20',
    },
    {
        label: 'Fuzzy',
        count: props.stats.fuzzy,
        color: 'bg-blue-500',
        bgColor: 'bg-blue-500/20',
    },
    {
        label: 'Barcode',
        count: props.stats.barcode,
        color: 'bg-purple-500',
        bgColor: 'bg-purple-500/20',
    },
    {
        label: 'Manual',
        count: props.stats.manual,
        color: 'bg-orange-500',
        bgColor: 'bg-orange-500/20',
    },
    {
        label: 'Unmatched',
        count: props.stats.unmatched,
        color: 'bg-gray-400',
        bgColor: 'bg-gray-400/20',
    },
]);

function getPercentage(count: number): number {
    if (props.stats.total_listings === 0) {
        return 0;
    }
    return Math.round((count / props.stats.total_listings) * 100);
}
</script>

<template>
    <Card>
        <CardHeader class="pb-2">
            <CardTitle class="text-base">Product Matching</CardTitle>
            <CardDescription>
                {{ matchRate }}% match rate ({{
                    matchedTotal.toLocaleString()
                }}
                of {{ stats.total_listings.toLocaleString() }} listings)
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div class="mb-3 flex h-3 overflow-hidden rounded-full bg-muted">
                <div
                    v-for="type in matchTypes"
                    :key="type.label"
                    :class="type.color"
                    :style="{ width: `${getPercentage(type.count)}%` }"
                    class="transition-all"
                />
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                <div
                    v-for="type in matchTypes"
                    :key="type.label"
                    class="flex items-center gap-2"
                >
                    <div :class="[type.color, 'size-2 rounded-full']" />
                    <div class="text-xs">
                        <span class="text-muted-foreground">
                            {{ type.label }}
                        </span>
                        <span class="ml-1 font-medium">
                            {{ type.count.toLocaleString() }}
                        </span>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
