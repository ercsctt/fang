<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { RetailerHealth } from '@/types/admin';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    MinusCircle,
    PauseCircle,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    retailers: RetailerHealth[];
}

const props = defineProps<Props>();

const sortedRetailers = computed(() => {
    return [...props.retailers].sort((a, b) => {
        // Sort by severity: failed/disabled first (need attention), then degraded, paused, active last
        const statusOrder: Record<string, number> = {
            failed: 0,
            disabled: 1,
            degraded: 2,
            paused: 3,
            active: 4,
        };
        return (statusOrder[a.status] ?? 5) - (statusOrder[b.status] ?? 5);
    });
});

function formatDate(date: string | null): string {
    if (!date) {
        return 'Never';
    }
    const d = new Date(date);
    return d.toLocaleString('en-GB', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatRelativeTime(date: string | null): string {
    if (!date) {
        return 'Never';
    }
    const now = new Date();
    const then = new Date(date);
    const diffMs = now.getTime() - then.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) {
        return 'Just now';
    }
    if (diffMins < 60) {
        return `${diffMins}m ago`;
    }
    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }
    return `${diffDays}d ago`;
}

function getPausedUntilText(date: string | null): string {
    if (!date) {
        return '';
    }
    const d = new Date(date);
    const now = new Date();
    if (d <= now) {
        return '';
    }
    const diffMs = d.getTime() - now.getTime();
    const diffMins = Math.ceil(diffMs / 60000);
    if (diffMins < 60) {
        return `${diffMins}m`;
    }
    const diffHours = Math.ceil(diffMins / 60);
    return `${diffHours}h`;
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr
                    class="border-b border-border text-left text-sm text-muted-foreground"
                >
                    <th class="pb-3 font-medium">Retailer</th>
                    <th class="pb-3 font-medium">Status</th>
                    <th class="pb-3 font-medium">Circuit Breaker</th>
                    <th class="pb-3 font-medium">Last Crawled</th>
                    <th class="pb-3 text-right font-medium">Listings</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="retailer in sortedRetailers"
                    :key="retailer.id"
                    class="group"
                >
                    <td class="py-3">
                        <span class="font-medium">{{ retailer.name }}</span>
                    </td>
                    <td class="py-3">
                        <Badge
                            variant="outline"
                            :class="{
                                'border-green-500 bg-green-500/10 text-green-600 dark:text-green-400':
                                    retailer.status === 'active',
                                'border-yellow-500 bg-yellow-500/10 text-yellow-600 dark:text-yellow-400':
                                    retailer.status === 'paused',
                                'border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-400':
                                    retailer.status === 'degraded',
                                'border-gray-500 bg-gray-500/10 text-gray-600 dark:text-gray-400':
                                    retailer.status === 'disabled',
                                'border-red-500 bg-red-500/10 text-red-600 dark:text-red-400':
                                    retailer.status === 'failed',
                            }"
                        >
                            <CheckCircle2
                                v-if="retailer.status === 'active'"
                                class="mr-1 size-3"
                            />
                            <PauseCircle
                                v-else-if="retailer.status === 'paused'"
                                class="mr-1 size-3"
                            />
                            <AlertTriangle
                                v-else-if="retailer.status === 'degraded'"
                                class="mr-1 size-3"
                            />
                            <MinusCircle
                                v-else-if="retailer.status === 'disabled'"
                                class="mr-1 size-3"
                            />
                            <XCircle v-else class="mr-1 size-3" />
                            {{ retailer.status_label }}
                        </Badge>
                    </td>
                    <td class="py-3">
                        <div class="flex items-center gap-2">
                            <template v-if="retailer.is_paused">
                                <Badge
                                    variant="outline"
                                    class="border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-400"
                                >
                                    <PauseCircle class="mr-1 size-3" />
                                    Paused
                                    <span
                                        v-if="
                                            getPausedUntilText(
                                                retailer.paused_until,
                                            )
                                        "
                                        class="ml-1"
                                    >
                                        ({{
                                            getPausedUntilText(
                                                retailer.paused_until,
                                            )
                                        }})
                                    </span>
                                </Badge>
                            </template>
                            <template
                                v-else-if="retailer.consecutive_failures > 0"
                            >
                                <Badge variant="secondary">
                                    {{ retailer.consecutive_failures }} failures
                                </Badge>
                            </template>
                            <template v-else>
                                <span class="text-sm text-muted-foreground">
                                    OK
                                </span>
                            </template>
                        </div>
                        <div
                            v-if="retailer.last_failure_at"
                            class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Clock class="size-3" />
                            Last failure:
                            {{ formatDate(retailer.last_failure_at) }}
                        </div>
                    </td>
                    <td class="py-3">
                        <span
                            :class="{
                                'text-green-600 dark:text-green-400':
                                    retailer.last_crawled_at &&
                                    new Date(retailer.last_crawled_at) >
                                        new Date(Date.now() - 3600000),
                                'text-yellow-600 dark:text-yellow-400':
                                    retailer.last_crawled_at &&
                                    new Date(retailer.last_crawled_at) <=
                                        new Date(Date.now() - 3600000) &&
                                    new Date(retailer.last_crawled_at) >
                                        new Date(Date.now() - 86400000),
                                'text-red-600 dark:text-red-400':
                                    !retailer.last_crawled_at ||
                                    new Date(retailer.last_crawled_at) <=
                                        new Date(Date.now() - 86400000),
                            }"
                        >
                            {{ formatRelativeTime(retailer.last_crawled_at) }}
                        </span>
                    </td>
                    <td class="py-3 text-right">
                        {{ retailer.product_listings_count.toLocaleString() }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="retailers.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            No retailers found.
        </div>
    </div>
</template>
