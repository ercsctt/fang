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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Database,
    RefreshCw,
    Search,
    Store,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import RetailerTable from './components/RetailerTable.vue';

interface RetailerData {
    id: number;
    name: string;
    slug: string;
    base_url: string;
    status: string;
    status_label: string;
    status_color: string;
    status_description: string;
    status_badge_classes: string;
    status_icon: string;
    consecutive_failures: number;
    last_failure_at: string | null;
    paused_until: string | null;
    last_crawled_at: string | null;
    is_paused: boolean;
    is_available_for_crawling: boolean;
    product_listings_count: number;
    can_pause: boolean;
    can_resume: boolean;
    can_disable: boolean;
    can_enable: boolean;
}

interface StatusOption {
    value: string;
    label: string;
    color: string;
}

interface StatusCounts {
    all: number;
    active: number;
    paused: number;
    disabled: number;
    degraded: number;
    failed: number;
    [key: string]: number;
}

interface SummaryStats {
    total: number;
    crawlable: number;
    with_problems: number;
    recently_crawled: number;
    total_products: number;
}

interface Filters {
    status: string;
    search: string;
    sort: string;
    dir: string;
}

interface Props {
    retailers: RetailerData[];
    statusCounts: StatusCounts;
    summaryStats: SummaryStats;
    filters: Filters;
    statuses: StatusOption[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Retailers',
        href: '/admin/retailers',
    },
];

const searchQuery = ref(props.filters.search);
const selectedStatus = ref(props.filters.status || 'all');
const sortField = ref(props.filters.sort);
const sortDirection = ref(props.filters.dir);
const isRefreshing = ref(false);

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

watch(searchQuery, (value) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        applyFilters({ search: value });
    }, 300);
});

function changeStatus(value: unknown) {
    if (!value || typeof value !== 'string') return;
    selectedStatus.value = value;
    applyFilters({ status: value });
}

function changeSort(field: string) {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    applyFilters({ sort: sortField.value, dir: sortDirection.value });
}

function applyFilters(overrides: Partial<Filters> = {}) {
    const params: Record<string, string> = {
        status: selectedStatus.value,
        search: searchQuery.value,
        sort: sortField.value,
        dir: sortDirection.value,
        ...overrides,
    };

    if (params.status === 'all') {
        delete params.status;
    }
    if (!params.search) {
        delete params.search;
    }

    router.get('/admin/retailers', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function refresh() {
    isRefreshing.value = true;
    router.reload({
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
}

function handleRetailerUpdated() {
    refresh();
}

const activeCount = computed(() => props.statusCounts.active || 0);
const problemCount = computed(
    () =>
        (props.statusCounts.degraded || 0) +
        (props.statusCounts.failed || 0) +
        (props.statusCounts.paused || 0),
);
const disabledCount = computed(() => props.statusCounts.disabled || 0);
</script>

<template>
    <Head title="Retailers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Retailers</h1>
                    <p class="text-muted-foreground">
                        Manage retailer status and monitor crawl health
                    </p>
                </div>
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

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Total Retailers
                        </CardTitle>
                        <Store class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ summaryStats.total }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ summaryStats.crawlable }} available for crawling
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Status Overview
                        </CardTitle>
                        <CheckCircle2 class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-2">
                            <Badge
                                v-if="activeCount > 0"
                                variant="outline"
                                class="border-green-500 bg-green-500/10 text-green-600 dark:text-green-400"
                            >
                                <CheckCircle2 class="mr-1 size-3" />
                                {{ activeCount }}
                            </Badge>
                            <Badge
                                v-if="problemCount > 0"
                                variant="outline"
                                class="border-yellow-500 bg-yellow-500/10 text-yellow-600 dark:text-yellow-400"
                            >
                                <AlertTriangle class="mr-1 size-3" />
                                {{ problemCount }}
                            </Badge>
                            <Badge
                                v-if="disabledCount > 0"
                                variant="outline"
                                class="border-gray-500 bg-gray-500/10 text-gray-600 dark:text-gray-400"
                            >
                                <XCircle class="mr-1 size-3" />
                                {{ disabledCount }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Active / Issues / Disabled
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Recently Crawled
                        </CardTitle>
                        <RefreshCw class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ summaryStats.recently_crawled }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Crawled in last 24 hours
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Total Products
                        </CardTitle>
                        <Database class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ summaryStats.total_products.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Across all retailers
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Retailer Management</CardTitle>
                    <CardDescription>
                        View and manage retailer statuses, pause crawling, or
                        disable retailers
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="mb-4 flex flex-col gap-4 sm:flex-row">
                        <div class="relative flex-1">
                            <Search
                                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Search retailers..."
                                class="pl-9"
                            />
                        </div>
                        <Select
                            :model-value="selectedStatus"
                            @update:model-value="changeStatus"
                        >
                            <SelectTrigger class="w-full sm:w-44">
                                <SelectValue placeholder="Filter by status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All ({{ statusCounts.all }})
                                </SelectItem>
                                <SelectItem
                                    v-for="status in statuses"
                                    :key="status.value"
                                    :value="status.value"
                                >
                                    {{ status.label }} ({{
                                        statusCounts[status.value] || 0
                                    }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <RetailerTable
                        :retailers="retailers"
                        :sort-field="sortField"
                        :sort-direction="sortDirection"
                        @sort="changeSort"
                        @retailer-updated="handleRetailerUpdated"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
