<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import type { PriceHistoryEntry, Product, ProductListingReview } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle,
    ExternalLink,
    Home,
    Package,
    Star,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PriceComparisonTable from './components/PriceComparisonTable.vue';
import PriceHistoryChart from './components/PriceHistoryChart.vue';

interface Props {
    product: Product;
    priceHistory: PriceHistoryEntry[];
    reviews: ProductListingReview[];
    averageRating: number | null;
    totalReviewCount: number;
}

const props = defineProps<Props>();

const selectedImageIndex = ref(0);

const allImages = computed(() => {
    const images: string[] = [];

    if (props.product.primary_image) {
        images.push(props.product.primary_image);
    }

    props.product.product_listings?.forEach((listing) => {
        if (listing.images) {
            listing.images.forEach((img) => {
                if (!images.includes(img)) {
                    images.push(img);
                }
            });
        }
    });

    return images.slice(0, 5);
});

function formatPrice(pence: number | null): string {
    if (pence === null) {
        return '£-.--';
    }
    return `£${(pence / 100).toFixed(2)}`;
}

function formatWeight(grams: number | null): string {
    if (grams === null) {
        return '';
    }
    if (grams >= 1000) {
        return `${(grams / 1000).toFixed(1)}kg`;
    }
    return `${grams}g`;
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
        return '';
    }
    return new Date(dateStr).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head :title="product.name" />

    <div class="min-h-screen bg-background">
        <header
            class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur-sm dark:bg-card/80"
        >
            <div class="container mx-auto px-4 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/products">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft class="size-5" />
                        </Button>
                    </Link>

                    <nav class="flex items-center gap-2 text-sm">
                        <Link href="/" class="text-muted-foreground">
                            <Home class="size-4" />
                        </Link>
                        <span class="text-muted-foreground">/</span>
                        <Link
                            href="/products"
                            class="text-muted-foreground hover:text-foreground"
                        >
                            Products
                        </Link>
                        <span class="text-muted-foreground">/</span>
                        <span class="text-foreground">{{
                            product.name.slice(0, 30)
                        }}</span>
                    </nav>
                </div>
            </div>
        </header>

        <main class="container mx-auto px-4 py-8">
            <div class="grid gap-8 lg:grid-cols-2">
                <div class="space-y-4">
                    <div
                        class="aspect-square overflow-hidden rounded-lg border"
                    >
                        <img
                            v-if="allImages[selectedImageIndex]"
                            :src="allImages[selectedImageIndex]"
                            :alt="product.name"
                            class="size-full object-contain"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center bg-muted"
                        >
                            <Package class="size-24 text-muted-foreground/50" />
                        </div>
                    </div>

                    <div
                        v-if="allImages.length > 1"
                        class="flex gap-2 overflow-x-auto pb-2"
                    >
                        <button
                            v-for="(img, index) in allImages"
                            :key="index"
                            class="flex-shrink-0 overflow-hidden rounded-md border-2 transition-colors"
                            :class="
                                selectedImageIndex === index
                                    ? 'border-primary'
                                    : 'border-transparent'
                            "
                            @click="selectedImageIndex = index"
                        >
                            <img
                                :src="img"
                                :alt="`${product.name} image ${index + 1}`"
                                class="size-16 object-cover"
                            />
                        </button>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="space-y-2">
                        <div class="flex flex-wrap gap-2">
                            <Badge v-if="product.brand" variant="secondary">
                                {{ product.brand }}
                            </Badge>
                            <Badge v-if="product.category" variant="outline">
                                {{ product.category }}
                            </Badge>
                            <Badge
                                v-if="product.is_verified"
                                class="bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400"
                            >
                                <CheckCircle class="mr-1 size-3" />
                                Verified
                            </Badge>
                        </div>

                        <h1 class="text-2xl font-bold lg:text-3xl">
                            {{ product.name }}
                        </h1>

                        <div
                            v-if="averageRating"
                            class="flex items-center gap-2"
                        >
                            <div class="flex items-center gap-1">
                                <Star
                                    v-for="i in 5"
                                    :key="i"
                                    class="size-4"
                                    :class="
                                        i <= Math.round(averageRating)
                                            ? 'fill-yellow-400 text-yellow-400'
                                            : 'fill-muted text-muted'
                                    "
                                />
                            </div>
                            <span class="font-medium">{{
                                averageRating.toFixed(1)
                            }}</span>
                            <span class="text-sm text-muted-foreground">
                                ({{ totalReviewCount }} reviews)
                            </span>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-bold text-primary">
                            {{ formatPrice(product.lowest_price_pence) }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            lowest price
                        </span>
                    </div>

                    <p v-if="product.description" class="text-muted-foreground">
                        {{ product.description }}
                    </p>

                    <div class="flex flex-wrap gap-4 text-sm">
                        <div v-if="product.weight_grams">
                            <span class="text-muted-foreground">Weight:</span>
                            <span class="ml-1 font-medium">{{
                                formatWeight(product.weight_grams)
                            }}</span>
                        </div>
                        <div v-if="product.quantity">
                            <span class="text-muted-foreground">Quantity:</span>
                            <span class="ml-1 font-medium"
                                >{{ product.quantity }} pack</span
                            >
                        </div>
                    </div>

                    <Separator />

                    <div
                        v-if="
                            product.product_listings &&
                            product.product_listings.length > 0
                        "
                    >
                        <a
                            :href="product.product_listings[0].url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Button class="w-full gap-2 sm:w-auto">
                                <ExternalLink class="size-4" />
                                Buy from
                                {{ product.product_listings[0].retailer?.name }}
                                for
                                {{
                                    formatPrice(
                                        product.product_listings[0].price_pence,
                                    )
                                }}
                            </Button>
                        </a>
                    </div>
                </div>
            </div>

            <section class="mt-12 space-y-6">
                <h2 class="text-xl font-bold">Compare Prices</h2>
                <PriceComparisonTable
                    v-if="product.product_listings"
                    :listings="product.product_listings"
                />
            </section>

            <section class="mt-12 space-y-6">
                <h2 class="text-xl font-bold">Price History</h2>
                <Card>
                    <CardContent class="p-6">
                        <PriceHistoryChart :data="priceHistory" />
                    </CardContent>
                </Card>
            </section>

            <section v-if="reviews.length > 0" class="mt-12 space-y-6">
                <h2 class="text-xl font-bold">
                    Customer Reviews ({{ totalReviewCount }})
                </h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <Card v-for="review in reviews" :key="review.id">
                        <CardHeader class="pb-2">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-2">
                                    <div class="flex items-center gap-1">
                                        <Star
                                            v-for="i in 5"
                                            :key="i"
                                            class="size-4"
                                            :class="
                                                i <= review.rating
                                                    ? 'fill-yellow-400 text-yellow-400'
                                                    : 'fill-muted text-muted'
                                            "
                                        />
                                    </div>
                                    <span
                                        v-if="review.verified_purchase"
                                        class="text-xs text-green-600"
                                    >
                                        Verified Purchase
                                    </span>
                                </div>
                                <span
                                    v-if="review.review_date"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ formatDate(review.review_date) }}
                                </span>
                            </div>
                            <CardTitle
                                v-if="review.title"
                                class="text-base font-medium"
                            >
                                {{ review.title }}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                v-if="review.body"
                                class="text-sm text-muted-foreground"
                            >
                                {{ review.body }}
                            </p>
                            <p
                                v-if="review.author"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                By {{ review.author }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </main>
    </div>
</template>
