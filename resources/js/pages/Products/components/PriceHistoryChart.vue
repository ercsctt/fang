<script setup lang="ts">
import type { PriceHistoryEntry } from '@/types';
import { computed } from 'vue';

interface Props {
    data: PriceHistoryEntry[];
}

const props = defineProps<Props>();

const chartData = computed(() => {
    if (!props.data || props.data.length === 0) {
        return { retailers: [], points: [], minPrice: 0, maxPrice: 0 };
    }

    const retailers = new Set<string>();
    props.data.forEach((entry) => {
        Object.keys(entry.prices).forEach((retailer) =>
            retailers.add(retailer),
        );
    });

    const retailerArray = Array.from(retailers);
    const allPrices: number[] = [];

    props.data.forEach((entry) => {
        Object.values(entry.prices).forEach((price) => allPrices.push(price));
    });

    const minPrice = Math.min(...allPrices);
    const maxPrice = Math.max(...allPrices);

    const points = retailerArray.map((retailer) => ({
        retailer,
        data: props.data.map((entry, index) => ({
            date: entry.date,
            price: entry.prices[retailer] || null,
            index,
        })),
    }));

    return { retailers: retailerArray, points, minPrice, maxPrice };
});

const colors = [
    'rgb(59, 130, 246)', // blue
    'rgb(16, 185, 129)', // green
    'rgb(251, 146, 60)', // orange
    'rgb(139, 92, 246)', // purple
    'rgb(236, 72, 153)', // pink
];

function getRetailerColor(index: number): string {
    return colors[index % colors.length];
}

function formatPrice(pence: number): string {
    return `£${(pence / 100).toFixed(2)}`;
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function getYPosition(price: number, height: number): number {
    const { minPrice, maxPrice } = chartData.value;
    const range = maxPrice - minPrice;
    if (range === 0) return height / 2;
    return height - ((price - minPrice) / range) * height;
}

function getXPosition(index: number, width: number): number {
    const count = props.data.length;
    if (count <= 1) return width / 2;
    return (index / (count - 1)) * width;
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="props.data.length === 0" class="py-12 text-center">
            <p class="text-muted-foreground">No price history data available</p>
        </div>

        <div v-else>
            <div class="mb-4 flex flex-wrap gap-4">
                <div
                    v-for="(retailer, index) in chartData.retailers"
                    :key="retailer"
                    class="flex items-center gap-2"
                >
                    <div
                        class="size-3 rounded-full"
                        :style="{ backgroundColor: getRetailerColor(index) }"
                    />
                    <span class="text-sm font-medium">{{ retailer }}</span>
                </div>
            </div>

            <div class="relative h-80 w-full">
                <svg class="size-full" viewBox="0 0 800 300">
                    <g
                        v-for="(line, lineIndex) in chartData.points"
                        :key="line.retailer"
                    >
                        <polyline
                            :points="
                                line.data
                                    .filter((point) => point.price !== null)
                                    .map(
                                        (point) =>
                                            `${getXPosition(point.index, 800)},${getYPosition(point.price!, 280)}`,
                                    )
                                    .join(' ')
                            "
                            fill="none"
                            :stroke="getRetailerColor(lineIndex)"
                            stroke-width="2"
                            class="transition-all"
                        />

                        <circle
                            v-for="point in line.data.filter(
                                (p) => p.price !== null,
                            )"
                            :key="`${line.retailer}-${point.index}`"
                            :cx="getXPosition(point.index, 800)"
                            :cy="getYPosition(point.price!, 280)"
                            r="4"
                            :fill="getRetailerColor(lineIndex)"
                            class="transition-all"
                        >
                            <title>
                                {{ line.retailer }} -
                                {{ formatDate(point.date) }}:
                                {{ formatPrice(point.price!) }}
                            </title>
                        </circle>
                    </g>

                    <g class="text-xs">
                        <text
                            v-for="(entry, index) in props.data"
                            :key="index"
                            :x="getXPosition(index, 800)"
                            y="295"
                            text-anchor="middle"
                            class="fill-muted-foreground"
                        >
                            {{ formatDate(entry.date) }}
                        </text>
                    </g>
                </svg>
            </div>

            <div class="mt-4 text-center text-sm text-muted-foreground">
                <p>
                    Price range: {{ formatPrice(chartData.minPrice) }} -
                    {{ formatPrice(chartData.maxPrice) }}
                </p>
            </div>
        </div>
    </div>
</template>
