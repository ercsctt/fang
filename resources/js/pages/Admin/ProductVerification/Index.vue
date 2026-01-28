<script setup lang="ts">
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
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    RefreshCw,
    XCircle,
    Zap,
} from 'lucide-vue-next';
import { ref } from 'vue';
import VerificationTable from './components/VerificationTable.vue';

interface Product {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    primary_image: string | null;
    weight_grams: number | null;
    quantity: number | null;
}

interface Retailer {
    id: number;
    name: string;
    slug: string;
}

interface ProductListing {
    id: number;
    retailer_id: number;
    title: string;
    brand: string | null;
    url: string;
    price_pence: number | null;
    images: string[] | null;
    weight_grams: number | null;
    quantity: number | null;
    retailer: Retailer;
}

interface Verifier {
    id: number;
    name: string;
}

interface Match {
    id: number;
    product_id: number;
    product_listing_id: number;
    confidence_score: number;
    match_type: string;
    matched_at: string;
    verified_at: string | null;
    status: string;
    rejection_reason: string | null;
    product: Product;
    product_listing: ProductListing;
    verifier: Verifier | null;
}

interface PaginatedMatches {
    data: Match[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Stats {
    pending: number;
    approved: number;
    rejected: number;
    total: number;
    high_confidence_pending: number;
}

interface Props {
    matches: PaginatedMatches;
    stats: Stats;
    filters: {
        status: string;
        sort: string;
        direction: string;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Product Verification',
        href: '/admin/product-verification',
    },
];

const selectedStatus = ref(props.filters.status);
const isRefreshing = ref(false);
const isBulkApproving = ref(false);

function changeStatus(value: unknown) {
    if (!value || typeof value !== 'string') return;
    selectedStatus.value = value;
    router.get(
        '/admin/product-verification',
        {
            status: value,
            sort: props.filters.sort,
            direction: props.filters.direction,
        },
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

async function bulkApprove() {
    if (isBulkApproving.value) return;

    isBulkApproving.value = true;

    try {
        const response = await fetch(
            '/admin/product-verification/bulk-approve',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    min_confidence: 95,
                    limit: 100,
                }),
            },
        );

        if (response.ok) {
            router.reload();
        }
    } finally {
        isBulkApproving.value = false;
    }
}

const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'all', label: 'All' },
];
</script>

<template>
    <Head title="Product Verification" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">
                        Product Verification
                    </h1>
                    <p class="text-muted-foreground">
                        Review and verify product listing matches
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <Select
                        :model-value="selectedStatus"
                        @update:model-value="changeStatus"
                    >
                        <SelectTrigger class="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        v-if="stats.high_confidence_pending > 0"
                        variant="outline"
                        :disabled="isBulkApproving"
                        @click="bulkApprove"
                    >
                        <Zap class="mr-2 size-4" />
                        Bulk Approve ({{ stats.high_confidence_pending }})
                    </Button>
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
                            Pending Review
                        </CardTitle>
                        <Clock class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ stats.pending.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Awaiting verification
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Approved
                        </CardTitle>
                        <CheckCircle2 class="size-4 text-green-500" />
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-2xl font-bold text-green-600 dark:text-green-400"
                        >
                            {{ stats.approved.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Verified matches
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            Rejected
                        </CardTitle>
                        <XCircle class="size-4 text-red-500" />
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-2xl font-bold text-red-600 dark:text-red-400"
                        >
                            {{ stats.rejected.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Invalid matches
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between pb-2"
                    >
                        <CardTitle class="text-sm font-medium">
                            High Confidence
                        </CardTitle>
                        <AlertTriangle class="size-4 text-yellow-500" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">
                            {{ stats.high_confidence_pending.toLocaleString() }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Ready for bulk approval (&ge;95%)
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Verification Queue</CardTitle>
                    <CardDescription>
                        Review matches sorted by confidence score (lowest first)
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <VerificationTable :matches="matches" :filters="filters" />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
