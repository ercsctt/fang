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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    ExternalLink,
    RefreshCw,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ProductComparisonCard from './components/ProductComparisonCard.vue';

interface Product {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    description: string | null;
    primary_image: string | null;
    weight_grams: number | null;
    quantity: number | null;
    category: string | null;
    subcategory: string | null;
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
    description: string | null;
    url: string;
    price_pence: number | null;
    images: string[] | null;
    weight_grams: number | null;
    quantity: number | null;
    category: string | null;
    ingredients: string | null;
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

interface OtherMatch {
    id: number;
    product_listing: {
        id: number;
        retailer_id: number;
        title: string;
        url: string;
        price_pence: number | null;
        retailer: {
            id: number;
            name: string;
        };
    };
}

interface SuggestedProduct {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    primary_image: string | null;
}

interface Props {
    match: Match;
    otherMatches: OtherMatch[];
    suggestedProducts: SuggestedProduct[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Product Verification',
        href: '/admin/product-verification',
    },
    {
        title: `Match #${props.match.id}`,
        href: `/admin/product-verification/${props.match.id}`,
    },
];

const isProcessing = ref(false);
const showRematchForm = ref(false);
const rejectionReason = ref('');
const selectedProductId = ref<number | null>(null);

function approve() {
    isProcessing.value = true;
    router.post(
        `/admin/product-verification/${props.match.id}/approve`,
        {},
        {
            onFinish: () => {
                isProcessing.value = false;
            },
        },
    );
}

function reject() {
    isProcessing.value = true;
    router.post(
        `/admin/product-verification/${props.match.id}/reject`,
        { reason: rejectionReason.value },
        {
            onFinish: () => {
                isProcessing.value = false;
            },
        },
    );
}

function rematch() {
    if (!selectedProductId.value) return;

    isProcessing.value = true;
    router.post(
        `/admin/product-verification/${props.match.id}/rematch`,
        { product_id: selectedProductId.value },
        {
            onFinish: () => {
                isProcessing.value = false;
                showRematchForm.value = false;
            },
        },
    );
}

function formatPrice(pence: number | null): string {
    if (pence === null) return 'N/A';
    return `£${(pence / 100).toFixed(2)}`;
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getConfidenceColor(score: number): string {
    if (score >= 90) return 'text-green-600 dark:text-green-400';
    if (score >= 70) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

function getMatchTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        exact: 'Exact Match',
        fuzzy: 'Fuzzy Match',
        barcode: 'Barcode Match',
        manual: 'Manual Match',
    };
    return labels[type] || type;
}

const isPending = computed(() => props.match.status === 'pending');
</script>

<template>
    <Head :title="`Verify Match #${match.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button
                        variant="ghost"
                        size="icon"
                        @click="router.get('/admin/product-verification')"
                    >
                        <ArrowLeft class="size-4" />
                    </Button>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight">
                            Verify Match #{{ match.id }}
                        </h1>
                        <p class="text-muted-foreground">
                            Review the product match and take action
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="
                            match.status === 'approved'
                                ? 'default'
                                : match.status === 'rejected'
                                  ? 'destructive'
                                  : 'secondary'
                        "
                        class="text-sm"
                    >
                        {{ match.status }}
                    </Badge>
                    <span
                        class="text-2xl font-bold"
                        :class="getConfidenceColor(match.confidence_score)"
                    >
                        {{ match.confidence_score.toFixed(1) }}%
                    </span>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <ProductComparisonCard
                    title="Product Listing"
                    subtitle="From retailer"
                    :name="match.product_listing.title"
                    :brand="match.product_listing.brand"
                    :description="match.product_listing.description"
                    :image="match.product_listing.images?.[0]"
                    :category="match.product_listing.category"
                    :weight-grams="match.product_listing.weight_grams"
                    :quantity="match.product_listing.quantity"
                    :extra-info="`${match.product_listing.retailer.name} - ${formatPrice(match.product_listing.price_pence)}`"
                    :external-url="match.product_listing.url"
                />

                <ProductComparisonCard
                    title="Canonical Product"
                    subtitle="In database"
                    :name="match.product.name"
                    :brand="match.product.brand"
                    :description="match.product.description"
                    :image="match.product.primary_image"
                    :category="match.product.category"
                    :weight-grams="match.product.weight_grams"
                    :quantity="match.product.quantity"
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Match Details</CardTitle>
                    <CardDescription>
                        Information about this match
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <Label class="text-muted-foreground"
                                >Match Type</Label
                            >
                            <p class="font-medium">
                                {{ getMatchTypeLabel(match.match_type) }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-muted-foreground"
                                >Matched At</Label
                            >
                            <p class="font-medium">
                                {{ formatDate(match.matched_at) }}
                            </p>
                        </div>
                        <div v-if="match.verified_at">
                            <Label class="text-muted-foreground"
                                >Verified At</Label
                            >
                            <p class="font-medium">
                                {{ formatDate(match.verified_at) }}
                            </p>
                        </div>
                        <div v-if="match.verifier">
                            <Label class="text-muted-foreground"
                                >Verified By</Label
                            >
                            <p class="font-medium">{{ match.verifier.name }}</p>
                        </div>
                        <div
                            v-if="match.rejection_reason"
                            class="sm:col-span-2 lg:col-span-4"
                        >
                            <Label class="text-muted-foreground"
                                >Rejection Reason</Label
                            >
                            <p
                                class="font-medium text-red-600 dark:text-red-400"
                            >
                                {{ match.rejection_reason }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="isPending">
                <CardHeader>
                    <CardTitle>Actions</CardTitle>
                    <CardDescription>
                        Approve, reject, or rematch this product listing
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-wrap gap-4">
                            <Button
                                :disabled="isProcessing"
                                class="bg-green-600 hover:bg-green-700"
                                @click="approve"
                            >
                                <CheckCircle2 class="mr-2 size-4" />
                                Approve Match
                            </Button>
                            <Button
                                variant="destructive"
                                :disabled="isProcessing"
                                @click="reject"
                            >
                                <XCircle class="mr-2 size-4" />
                                Reject Match
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="isProcessing"
                                @click="showRematchForm = !showRematchForm"
                            >
                                <RefreshCw class="mr-2 size-4" />
                                Rematch to Different Product
                            </Button>
                        </div>

                        <div v-if="!showRematchForm">
                            <Label for="rejection-reason"
                                >Rejection Reason (optional)</Label
                            >
                            <Textarea
                                id="rejection-reason"
                                v-model="rejectionReason"
                                placeholder="Enter a reason for rejection..."
                                class="mt-2"
                                rows="2"
                            />
                        </div>

                        <div
                            v-if="showRematchForm"
                            class="space-y-4 rounded-lg border p-4"
                        >
                            <h4 class="font-medium">
                                Select a different product to match
                            </h4>

                            <div v-if="suggestedProducts.length > 0">
                                <Label class="text-muted-foreground"
                                    >Suggested Products</Label
                                >
                                <div
                                    class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                >
                                    <button
                                        v-for="product in suggestedProducts"
                                        :key="product.id"
                                        type="button"
                                        class="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-muted"
                                        :class="{
                                            'ring-2 ring-primary':
                                                selectedProductId ===
                                                product.id,
                                        }"
                                        @click="selectedProductId = product.id"
                                    >
                                        <img
                                            v-if="product.primary_image"
                                            :src="product.primary_image"
                                            :alt="product.name"
                                            class="size-10 rounded object-cover"
                                        />
                                        <div
                                            class="size-10 rounded bg-muted"
                                            v-else
                                        />
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-sm font-medium"
                                            >
                                                {{ product.name }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    product.brand ||
                                                    'Unknown brand'
                                                }}
                                            </p>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <div v-else>
                                <p class="text-sm text-muted-foreground">
                                    No suggested products found. Enter a product
                                    ID manually.
                                </p>
                            </div>

                            <div>
                                <Label for="product-id"
                                    >Or enter Product ID manually</Label
                                >
                                <Input
                                    id="product-id"
                                    type="number"
                                    :model-value="selectedProductId ?? ''"
                                    @update:model-value="
                                        selectedProductId = $event
                                            ? Number($event)
                                            : null
                                    "
                                    placeholder="Enter product ID..."
                                    class="mt-2 max-w-xs"
                                />
                            </div>

                            <div class="flex gap-2">
                                <Button
                                    :disabled="
                                        !selectedProductId || isProcessing
                                    "
                                    @click="rematch"
                                >
                                    Confirm Rematch
                                </Button>
                                <Button
                                    variant="outline"
                                    @click="
                                        showRematchForm = false;
                                        selectedProductId = null;
                                    "
                                >
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="otherMatches.length > 0">
                <CardHeader>
                    <CardTitle>Other Matches for this Product</CardTitle>
                    <CardDescription>
                        Other product listings matched to the same canonical
                        product
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="otherMatch in otherMatches"
                            :key="otherMatch.id"
                            class="flex items-center justify-between rounded-lg border p-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ otherMatch.product_listing.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        otherMatch.product_listing.retailer.name
                                    }}
                                    <span
                                        v-if="
                                            otherMatch.product_listing
                                                .price_pence
                                        "
                                    >
                                        -
                                        {{
                                            formatPrice(
                                                otherMatch.product_listing
                                                    .price_pence,
                                            )
                                        }}
                                    </span>
                                </p>
                            </div>
                            <a
                                :href="otherMatch.product_listing.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-2 text-muted-foreground hover:text-foreground"
                            >
                                <ExternalLink class="size-4" />
                            </a>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
