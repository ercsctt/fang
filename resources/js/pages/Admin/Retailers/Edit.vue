<script setup lang="ts">
import RetailerController from '@/actions/App/Http/Controllers/Admin/RetailerController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import admin from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';
import type {
    CrawlerClass,
    FailureHistory,
    RetailerEditData,
    RetailerStatistics,
    StatusOption,
} from '@/types/admin';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    Loader2,
    Package,
    RefreshCw,
    XCircle,
    Zap,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    retailer: RetailerEditData;
    crawlerClasses: CrawlerClass[];
    statuses: StatusOption[];
    statistics: RetailerStatistics;
    failureHistory: FailureHistory;
}

const props = defineProps<Props>();
const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Retailers', href: admin.retailers.index.url() },
    {
        title: props.retailer.name,
        href: admin.retailers.edit.url(props.retailer.id),
    },
];

const name = ref(props.retailer.name);
const slug = ref(props.retailer.slug);
const baseUrl = ref(props.retailer.base_url);
const crawlerClass = ref(props.retailer.crawler_class ?? '');
const rateLimitMs = ref(props.retailer.rate_limit_ms);
const status = ref(props.retailer.status);

const isTestingConnection = ref(false);
const testResult = ref<{
    success: boolean;
    message: string;
    details?: Record<string, unknown>;
} | null>(null);

const selectedCrawlerLabel = computed(() => {
    const selected = props.crawlerClasses.find(
        (c) => c.value === crawlerClass.value,
    );
    return selected?.label ?? 'Select a crawler...';
});

const selectedStatusLabel = computed(() => {
    const selected = props.statuses.find((s) => s.value === status.value);
    return selected?.label ?? 'Select a status...';
});

const formatDate = (dateString: string | null): string => {
    if (!dateString) {
        return 'Never';
    }
    return new Date(dateString).toLocaleString();
};

const formatRelativeTime = (dateString: string | null): string => {
    if (!dateString) {
        return 'Never';
    }
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) {
        return 'Just now';
    }
    if (diffMins < 60) {
        return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    }
    if (diffHours < 24) {
        return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    }
    return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
};

const testConnection = async () => {
    isTestingConnection.value = true;
    testResult.value = null;

    try {
        const response = await fetch(
            admin.retailers.testConnection.url(props.retailer.id),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
            },
        );

        testResult.value = await response.json();
    } catch {
        testResult.value = {
            success: false,
            message: 'Failed to test connection. Please try again.',
        };
    } finally {
        isTestingConnection.value = false;
    }
};

const flash = computed(
    () => page.props.flash as { success?: string } | undefined,
);
</script>

<template>
    <Head :title="`Edit ${retailer.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center gap-4">
                <Link
                    :href="admin.retailers.index.url()"
                    class="flex items-center gap-2 text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" />
                    Back to Retailers
                </Link>
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold tracking-tight">
                            {{ retailer.name }}
                        </h1>
                        <Badge :class="retailer.status_badge_classes">
                            {{ retailer.status_label }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground">
                        {{ retailer.status_description }}
                    </p>
                </div>
            </div>

            <div
                v-if="flash?.success"
                class="rounded-md bg-green-50 p-4 dark:bg-green-900/20"
            >
                <div class="flex">
                    <CheckCircle2 class="size-5 text-green-400" />
                    <p
                        class="ml-3 text-sm font-medium text-green-800 dark:text-green-200"
                    >
                        {{ flash.success }}
                    </p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Retailer Details</CardTitle>
                            <CardDescription>
                                Update retailer information and crawler
                                configuration
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                v-bind="
                                    RetailerController.update.form(retailer.id)
                                "
                                class="space-y-6"
                                v-slot="{ errors, processing }"
                            >
                                <div class="space-y-4">
                                    <HeadingSmall
                                        title="Basic Information"
                                        description="Retailer name and identification"
                                    />

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div class="grid gap-2">
                                            <Label for="name">Name *</Label>
                                            <Input
                                                id="name"
                                                v-model="name"
                                                name="name"
                                                placeholder="e.g. Tesco"
                                                required
                                            />
                                            <InputError
                                                :message="errors.name"
                                            />
                                        </div>

                                        <div class="grid gap-2">
                                            <Label for="slug">Slug</Label>
                                            <Input
                                                id="slug"
                                                v-model="slug"
                                                name="slug"
                                                placeholder="e.g. tesco"
                                            />
                                            <InputError
                                                :message="errors.slug"
                                            />
                                        </div>
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="base_url">Base URL *</Label>
                                        <Input
                                            id="base_url"
                                            v-model="baseUrl"
                                            name="base_url"
                                            type="url"
                                            placeholder="https://www.example.com"
                                            required
                                        />
                                        <InputError
                                            :message="errors.base_url"
                                        />
                                    </div>
                                </div>

                                <Separator />

                                <div class="space-y-4">
                                    <HeadingSmall
                                        title="Crawler Configuration"
                                        description="Configure how this retailer will be crawled"
                                    />

                                    <div class="grid gap-2">
                                        <Label for="crawler_class"
                                            >Crawler Class *</Label
                                        >
                                        <Select v-model="crawlerClass">
                                            <SelectTrigger>
                                                <SelectValue
                                                    :placeholder="
                                                        selectedCrawlerLabel
                                                    "
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="crawler in crawlerClasses"
                                                    :key="crawler.value"
                                                    :value="crawler.value"
                                                >
                                                    {{ crawler.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <input
                                            type="hidden"
                                            name="crawler_class"
                                            :value="crawlerClass"
                                        />
                                        <InputError
                                            :message="errors.crawler_class"
                                        />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="rate_limit_ms"
                                            >Rate Limit (ms) *</Label
                                        >
                                        <Input
                                            id="rate_limit_ms"
                                            v-model.number="rateLimitMs"
                                            name="rate_limit_ms"
                                            type="number"
                                            min="100"
                                            max="60000"
                                            step="100"
                                            required
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Delay between requests in
                                            milliseconds (100-60000)
                                        </p>
                                        <InputError
                                            :message="errors.rate_limit_ms"
                                        />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="status">Status *</Label>
                                        <Select v-model="status">
                                            <SelectTrigger>
                                                <SelectValue
                                                    :placeholder="
                                                        selectedStatusLabel
                                                    "
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="s in statuses"
                                                    :key="s.value"
                                                    :value="s.value"
                                                >
                                                    {{ s.label }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <input
                                            type="hidden"
                                            name="status"
                                            :value="status"
                                        />
                                        <InputError :message="errors.status" />
                                    </div>
                                </div>

                                <div class="flex items-center gap-4 pt-4">
                                    <Button
                                        type="submit"
                                        :disabled="processing"
                                    >
                                        {{
                                            processing
                                                ? 'Saving...'
                                                : 'Save Changes'
                                        }}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="
                                            isTestingConnection || !crawlerClass
                                        "
                                        @click="testConnection"
                                    >
                                        <Loader2
                                            v-if="isTestingConnection"
                                            class="mr-2 size-4 animate-spin"
                                        />
                                        <Zap v-else class="mr-2 size-4" />
                                        Test Connection
                                    </Button>
                                </div>

                                <div
                                    v-if="testResult"
                                    class="rounded-md p-4"
                                    :class="
                                        testResult.success
                                            ? 'bg-green-50 dark:bg-green-900/20'
                                            : 'bg-red-50 dark:bg-red-900/20'
                                    "
                                >
                                    <div class="flex">
                                        <CheckCircle2
                                            v-if="testResult.success"
                                            class="size-5 text-green-400"
                                        />
                                        <XCircle
                                            v-else
                                            class="size-5 text-red-400"
                                        />
                                        <div class="ml-3">
                                            <p
                                                class="text-sm font-medium"
                                                :class="
                                                    testResult.success
                                                        ? 'text-green-800 dark:text-green-200'
                                                        : 'text-red-800 dark:text-red-200'
                                                "
                                            >
                                                {{ testResult.message }}
                                            </p>
                                            <div
                                                v-if="testResult.details"
                                                class="mt-2 text-xs text-muted-foreground"
                                            >
                                                <p
                                                    v-if="
                                                        testResult.details
                                                            .test_url
                                                    "
                                                >
                                                    Test URL:
                                                    {{
                                                        testResult.details
                                                            .test_url
                                                    }}
                                                </p>
                                                <p
                                                    v-if="
                                                        testResult.details
                                                            .status_code
                                                    "
                                                >
                                                    Status Code:
                                                    {{
                                                        testResult.details
                                                            .status_code
                                                    }}
                                                </p>
                                                <p
                                                    v-if="
                                                        testResult.details
                                                            .html_length
                                                    "
                                                >
                                                    Response Size:
                                                    {{
                                                        testResult.details
                                                            .html_length
                                                    }}
                                                    bytes
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </Form>
                        </CardContent>
                    </Card>
                </div>

                <div class="space-y-6">
                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Products</CardTitle
                            >
                            <Package class="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-2xl font-bold">
                                {{ statistics.product_count.toLocaleString() }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Total product listings
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Last Crawled</CardTitle
                            >
                            <Clock class="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div class="text-lg font-semibold">
                                {{
                                    formatRelativeTime(
                                        statistics.last_crawled_at,
                                    )
                                }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ formatDate(statistics.last_crawled_at) }}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle class="text-sm font-medium"
                                >Success Rate (7d)</CardTitle
                            >
                            <RefreshCw class="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div
                                class="text-2xl font-bold"
                                :class="{
                                    'text-green-600 dark:text-green-400':
                                        statistics.success_rate !== null &&
                                        statistics.success_rate >= 90,
                                    'text-yellow-600 dark:text-yellow-400':
                                        statistics.success_rate !== null &&
                                        statistics.success_rate >= 70 &&
                                        statistics.success_rate < 90,
                                    'text-red-600 dark:text-red-400':
                                        statistics.success_rate !== null &&
                                        statistics.success_rate < 70,
                                }"
                            >
                                {{
                                    statistics.success_rate !== null
                                        ? `${statistics.success_rate}%`
                                        : 'N/A'
                                }}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    statistics.last_seven_days.crawls_completed
                                }}
                                completed /
                                {{ statistics.last_seven_days.crawls_failed }}
                                failed
                            </p>
                        </CardContent>
                    </Card>

                    <Card
                        v-if="
                            failureHistory.consecutive_failures > 0 ||
                            failureHistory.total_failures_last_30_days > 0
                        "
                    >
                        <CardHeader
                            class="flex flex-row items-center justify-between pb-2"
                        >
                            <CardTitle
                                class="text-sm font-medium text-red-600 dark:text-red-400"
                            >
                                Failure History
                            </CardTitle>
                            <AlertTriangle class="size-4 text-red-500" />
                        </CardHeader>
                        <CardContent class="space-y-2">
                            <div>
                                <span class="text-sm text-muted-foreground"
                                    >Consecutive failures:</span
                                >
                                <span class="ml-2 font-semibold">{{
                                    failureHistory.consecutive_failures
                                }}</span>
                            </div>
                            <div>
                                <span class="text-sm text-muted-foreground"
                                    >Failures (30d):</span
                                >
                                <span class="ml-2 font-semibold">{{
                                    failureHistory.total_failures_last_30_days
                                }}</span>
                            </div>
                            <div v-if="failureHistory.last_failure_at">
                                <span class="text-sm text-muted-foreground"
                                    >Last failure:</span
                                >
                                <span class="ml-2 text-sm">{{
                                    formatRelativeTime(
                                        failureHistory.last_failure_at,
                                    )
                                }}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm font-medium"
                                >7-Day Statistics</CardTitle
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Crawls started</span
                                    >
                                    <span class="font-medium">{{
                                        statistics.last_seven_days
                                            .crawls_started
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Crawls completed</span
                                    >
                                    <span
                                        class="font-medium text-green-600 dark:text-green-400"
                                    >
                                        {{
                                            statistics.last_seven_days
                                                .crawls_completed
                                        }}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Crawls failed</span
                                    >
                                    <span
                                        class="font-medium text-red-600 dark:text-red-400"
                                    >
                                        {{
                                            statistics.last_seven_days
                                                .crawls_failed
                                        }}
                                    </span>
                                </div>
                                <Separator />
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Listings discovered</span
                                    >
                                    <span class="font-medium">{{
                                        statistics.last_seven_days
                                            .listings_discovered
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Details extracted</span
                                    >
                                    <span class="font-medium">{{
                                        statistics.last_seven_days
                                            .details_extracted
                                    }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm font-medium"
                                >Metadata</CardTitle
                            >
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >ID</span
                                    >
                                    <span class="font-mono">{{
                                        retailer.id
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Created</span
                                    >
                                    <span>{{
                                        formatDate(retailer.created_at)
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Updated</span
                                    >
                                    <span>{{
                                        formatDate(retailer.updated_at)
                                    }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
