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
import type { PriceDrop, Product, Retailer } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    Package,
    ShoppingBag,
    Store,
    TrendingDown,
} from 'lucide-vue-next';
import { ref } from 'vue';
import ProductCard from './components/ProductCard.vue';
import SearchAutocomplete from './components/SearchAutocomplete.vue';

interface Props {
    featuredProducts: Product[];
    priceDrops: PriceDrop[];
    retailers: Retailer[];
    stats: {
        productCount: number;
        listingCount: number;
        retailerCount: number;
    };
}

defineProps<Props>();

const searchQuery = ref('');

function handleSearch() {
    if (searchQuery.value.trim()) {
        router.get('/products', { search: searchQuery.value });
    }
}

function formatPrice(pence: number): string {
    return `£${(pence / 100).toFixed(2)}`;
}
</script>

<template>
    <Head title="Pet Food Price Comparison" />

    <div class="min-h-screen bg-background">
        <header
            class="border-b border-border bg-card/50 backdrop-blur-sm dark:bg-card/30"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center gap-6">
                    <div class="text-center">
                        <h1
                            class="text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                        >
                            Find the Best Pet Food Deals
                        </h1>
                        <p class="mt-2 text-muted-foreground">
                            Compare prices across
                            {{ stats.retailerCount }} retailers
                        </p>
                    </div>

                    <div class="w-full max-w-2xl">
                        <SearchAutocomplete
                            v-model="searchQuery"
                            placeholder="Search for products, brands..."
                            @search="handleSearch"
                        />
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent class="flex items-center gap-4 p-6">
                        <div
                            class="flex size-12 items-center justify-center rounded-lg bg-primary/10"
                        >
                            <Package class="size-6 text-primary" />
                        </div>
                        <div>
                            <p class="text-2xl font-bold">
                                {{ stats.productCount.toLocaleString() }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Products
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex items-center gap-4 p-6">
                        <div
                            class="flex size-12 items-center justify-center rounded-lg bg-primary/10"
                        >
                            <ShoppingBag class="size-6 text-primary" />
                        </div>
                        <div>
                            <p class="text-2xl font-bold">
                                {{ stats.listingCount.toLocaleString() }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Listings
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="flex items-center gap-4 p-6">
                        <div
                            class="flex size-12 items-center justify-center rounded-lg bg-primary/10"
                        >
                            <Store class="size-6 text-primary" />
                        </div>
                        <div>
                            <p class="text-2xl font-bold">
                                {{ stats.retailerCount }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Retailers
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <section v-if="priceDrops.length > 0" class="mb-12">
                <div class="mb-6 flex items-center gap-2">
                    <TrendingDown class="size-6 text-green-600" />
                    <h2 class="text-2xl font-semibold">Recent Price Drops</h2>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card
                        v-for="drop in priceDrops"
                        :key="drop.listing?.id ?? drop.current_price_pence"
                        class="group overflow-hidden transition-shadow hover:shadow-lg"
                    >
                        <Link
                            v-if="drop.product"
                            :href="`/products/${drop.product.slug}`"
                        >
                            <div
                                class="relative aspect-square overflow-hidden bg-muted"
                            >
                                <img
                                    v-if="
                                        drop.listing?.images &&
                                        drop.listing.images.length > 0
                                    "
                                    :src="drop.listing.images[0]"
                                    :alt="drop.listing?.title ?? 'Product'"
                                    class="size-full object-cover transition-transform group-hover:scale-105"
                                />
                                <div
                                    v-else
                                    class="flex size-full items-center justify-center"
                                >
                                    <Package
                                        class="size-12 text-muted-foreground/50"
                                    />
                                </div>
                                <Badge
                                    class="absolute top-2 right-2 bg-green-600 text-white"
                                >
                                    <ArrowDown class="mr-1 size-3" />
                                    {{ drop.drop_percentage }}%
                                </Badge>
                            </div>
                            <CardContent class="p-4">
                                <p
                                    class="line-clamp-2 text-sm font-medium text-foreground"
                                >
                                    {{ drop.listing.title }}
                                </p>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="font-bold text-green-600">
                                        {{
                                            formatPrice(
                                                drop.current_price_pence,
                                            )
                                        }}
                                    </span>
                                    <span
                                        class="text-sm text-muted-foreground line-through"
                                    >
                                        {{
                                            formatPrice(
                                                drop.previous_price_pence,
                                            )
                                        }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    at {{ drop.listing.retailer?.name }}
                                </p>
                            </CardContent>
                        </Link>
                    </Card>
                </div>
            </section>

            <section v-if="featuredProducts.length > 0" class="mb-12">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-2xl font-semibold">Featured Products</h2>
                    <Button variant="outline" as-child>
                        <Link href="/products">View All</Link>
                    </Button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <ProductCard
                        v-for="product in featuredProducts"
                        :key="product.id"
                        :product="product"
                    />
                </div>
            </section>

            <section>
                <h2 class="mb-6 text-2xl font-semibold">Shop by Retailer</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Card
                        v-for="retailer in retailers"
                        :key="retailer.id"
                        class="transition-shadow hover:shadow-md"
                    >
                        <Link :href="`/products?retailer=${retailer.id}`">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <Store class="size-5" />
                                    {{ retailer.name }}
                                </CardTitle>
                                <CardDescription>
                                    {{
                                        retailer.product_listings_count?.toLocaleString()
                                    }}
                                    products
                                </CardDescription>
                            </CardHeader>
                        </Link>
                    </Card>
                </div>
            </section>
        </main>

        <footer class="mt-12 border-t border-border bg-muted/30 py-8">
            <div class="mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
                <p class="text-sm text-muted-foreground">
                    Prices updated daily. All prices shown include VAT where
                    applicable.
                </p>
            </div>
        </footer>
    </div>
</template>
