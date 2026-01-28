<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import type { PaginatedData, Product, ProductFilters, Retailer } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Filter,
    Grid2x2,
    Home,
    List,
    Package,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ProductCard from './components/ProductCard.vue';
import SearchAutocomplete from './components/SearchAutocomplete.vue';

interface Props {
    products: PaginatedData<Product>;
    filters: ProductFilters;
    brands: string[];
    categories: string[];
    retailers: Retailer[];
}

const props = defineProps<Props>();

const localFilters = ref({ ...props.filters });
const isGridView = ref(true);

function applyFilters() {
    const query: Record<string, string> = {};

    if (localFilters.value.search) {
        query.search = localFilters.value.search;
    }
    if (localFilters.value.brand) {
        query.brand = localFilters.value.brand;
    }
    if (localFilters.value.category) {
        query.category = localFilters.value.category;
    }
    if (localFilters.value.retailer) {
        query.retailer = localFilters.value.retailer;
    }
    if (localFilters.value.min_price) {
        query.min_price = localFilters.value.min_price;
    }
    if (localFilters.value.max_price) {
        query.max_price = localFilters.value.max_price;
    }
    if (localFilters.value.sort !== 'name') {
        query.sort = localFilters.value.sort;
    }
    if (localFilters.value.dir !== 'asc') {
        query.dir = localFilters.value.dir;
    }

    router.get('/products', query, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    localFilters.value = {
        search: '',
        brand: '',
        category: '',
        retailer: '',
        min_price: '',
        max_price: '',
        sort: 'name',
        dir: 'asc',
    };
    router.get('/products');
}

function handleSort(field: string) {
    if (localFilters.value.sort === field) {
        localFilters.value.dir =
            localFilters.value.dir === 'asc' ? 'desc' : 'asc';
    } else {
        localFilters.value.sort = field;
        localFilters.value.dir = 'asc';
    }
    applyFilters();
}

const hasActiveFilters = computed(() => {
    return (
        localFilters.value.search ||
        localFilters.value.brand ||
        localFilters.value.category ||
        localFilters.value.retailer ||
        localFilters.value.min_price ||
        localFilters.value.max_price
    );
});

const pageNumbers = computed(() => {
    const current = props.products.current_page;
    const last = props.products.last_page;
    const pages: (number | string)[] = [];

    if (last <= 7) {
        for (let i = 1; i <= last; i++) {
            pages.push(i);
        }
    } else {
        pages.push(1);

        if (current > 3) {
            pages.push('...');
        }

        const start = Math.max(2, current - 1);
        const end = Math.min(last - 1, current + 1);

        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        if (current < last - 2) {
            pages.push('...');
        }

        pages.push(last);
    }

    return pages;
});
</script>

<template>
    <Head title="Products" />

    <div class="min-h-screen bg-background">
        <header
            class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur-sm dark:bg-card/80"
        >
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link href="/">
                            <Home class="size-5" />
                        </Link>
                    </Button>

                    <div class="flex-1">
                        <SearchAutocomplete
                            v-model="localFilters.search"
                            placeholder="Search products..."
                            @search="applyFilters"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            :variant="isGridView ? 'default' : 'ghost'"
                            size="icon"
                            @click="isGridView = true"
                        >
                            <Grid2x2 class="size-5" />
                        </Button>
                        <Button
                            :variant="!isGridView ? 'default' : 'ghost'"
                            size="icon"
                            @click="isGridView = false"
                        >
                            <List class="size-5" />
                        </Button>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-6 lg:flex-row">
                <aside class="w-full shrink-0 lg:w-64">
                    <Card>
                        <CardContent class="p-4">
                            <div class="mb-4 flex items-center justify-between">
                                <h3
                                    class="flex items-center gap-2 font-semibold"
                                >
                                    <Filter class="size-4" />
                                    Filters
                                </h3>
                                <Button
                                    v-if="hasActiveFilters"
                                    variant="ghost"
                                    size="sm"
                                    @click="clearFilters"
                                >
                                    <X class="mr-1 size-4" />
                                    Clear
                                </Button>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <Label class="mb-2 block">Brand</Label>
                                    <Select
                                        v-model="localFilters.brand"
                                        @update:model-value="applyFilters"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="All brands"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">
                                                All brands
                                            </SelectItem>
                                            <SelectItem
                                                v-for="brand in brands"
                                                :key="brand"
                                                :value="brand"
                                            >
                                                {{ brand }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label class="mb-2 block">Category</Label>
                                    <Select
                                        v-model="localFilters.category"
                                        @update:model-value="applyFilters"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="All categories"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">
                                                All categories
                                            </SelectItem>
                                            <SelectItem
                                                v-for="category in categories"
                                                :key="category"
                                                :value="category"
                                            >
                                                {{ category }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label class="mb-2 block">Retailer</Label>
                                    <Select
                                        v-model="localFilters.retailer"
                                        @update:model-value="applyFilters"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="All retailers"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">
                                                All retailers
                                            </SelectItem>
                                            <SelectItem
                                                v-for="retailer in retailers"
                                                :key="retailer.id"
                                                :value="String(retailer.id)"
                                            >
                                                {{ retailer.name }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <Separator />

                                <div>
                                    <Label class="mb-2 block"
                                        >Price Range</Label
                                    >
                                    <div class="flex items-center gap-2">
                                        <Input
                                            v-model="localFilters.min_price"
                                            type="number"
                                            placeholder="Min"
                                            class="w-full"
                                            @blur="applyFilters"
                                            @keyup.enter="applyFilters"
                                        />
                                        <span class="text-muted-foreground">
                                            -
                                        </span>
                                        <Input
                                            v-model="localFilters.max_price"
                                            type="number"
                                            placeholder="Max"
                                            class="w-full"
                                            @blur="applyFilters"
                                            @keyup.enter="applyFilters"
                                        />
                                    </div>
                                </div>

                                <Separator />

                                <div>
                                    <Label class="mb-2 block">Sort By</Label>
                                    <div class="flex flex-col gap-2">
                                        <Button
                                            :variant="
                                                localFilters.sort === 'name'
                                                    ? 'secondary'
                                                    : 'ghost'
                                            "
                                            size="sm"
                                            class="justify-start"
                                            @click="handleSort('name')"
                                        >
                                            <ArrowUpDown class="mr-2 size-4" />
                                            Name
                                            <span
                                                v-if="
                                                    localFilters.sort === 'name'
                                                "
                                                class="ml-auto text-xs"
                                            >
                                                {{
                                                    localFilters.dir === 'asc'
                                                        ? 'A-Z'
                                                        : 'Z-A'
                                                }}
                                            </span>
                                        </Button>
                                        <Button
                                            :variant="
                                                localFilters.sort === 'price'
                                                    ? 'secondary'
                                                    : 'ghost'
                                            "
                                            size="sm"
                                            class="justify-start"
                                            @click="handleSort('price')"
                                        >
                                            <ArrowUpDown class="mr-2 size-4" />
                                            Price
                                            <span
                                                v-if="
                                                    localFilters.sort ===
                                                    'price'
                                                "
                                                class="ml-auto text-xs"
                                            >
                                                {{
                                                    localFilters.dir === 'asc'
                                                        ? 'Low-High'
                                                        : 'High-Low'
                                                }}
                                            </span>
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </aside>

                <main class="flex-1">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            {{ products.total.toLocaleString() }} products found
                        </p>
                    </div>

                    <div
                        v-if="products.data.length > 0"
                        :class="
                            isGridView
                                ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3'
                                : 'flex flex-col gap-4'
                        "
                    >
                        <template v-if="isGridView">
                            <ProductCard
                                v-for="product in products.data"
                                :key="product.id"
                                :product="product"
                            />
                        </template>
                        <template v-else>
                            <Card
                                v-for="product in products.data"
                                :key="product.id"
                                class="transition-shadow hover:shadow-md"
                            >
                                <Link :href="`/products/${product.slug}`">
                                    <CardContent
                                        class="flex items-center gap-4 p-4"
                                    >
                                        <div
                                            class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                                        >
                                            <img
                                                v-if="product.primary_image"
                                                :src="product.primary_image"
                                                :alt="product.name"
                                                class="size-full object-cover"
                                            />
                                            <Package
                                                v-else
                                                class="size-8 text-muted-foreground/50"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3
                                                class="truncate font-medium text-foreground"
                                            >
                                                {{ product.name }}
                                            </h3>
                                            <div
                                                class="mt-1 flex items-center gap-2"
                                            >
                                                <span
                                                    v-if="product.brand"
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    {{ product.brand }}
                                                </span>
                                                <span
                                                    v-if="product.category"
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    {{ product.category }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p
                                                v-if="
                                                    product.lowest_price_pence
                                                "
                                                class="text-lg font-bold text-primary"
                                            >
                                                {{
                                                    `£${(product.lowest_price_pence / 100).toFixed(2)}`
                                                }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                from
                                            </p>
                                        </div>
                                    </CardContent>
                                </Link>
                            </Card>
                        </template>
                    </div>

                    <div
                        v-else
                        class="flex flex-col items-center justify-center py-16"
                    >
                        <Package
                            class="mb-4 size-16 text-muted-foreground/50"
                        />
                        <h3 class="text-lg font-semibold">No products found</h3>
                        <p class="mt-1 text-muted-foreground">
                            Try adjusting your filters or search terms.
                        </p>
                        <Button
                            v-if="hasActiveFilters"
                            class="mt-4"
                            variant="outline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </div>

                    <nav
                        v-if="products.last_page > 1"
                        class="mt-8 flex items-center justify-center gap-1"
                    >
                        <Button
                            variant="outline"
                            size="icon"
                            :disabled="!products.prev_page_url"
                            as-child
                        >
                            <Link
                                v-if="products.prev_page_url"
                                :href="products.prev_page_url"
                                preserve-scroll
                            >
                                <ChevronLeft class="size-4" />
                            </Link>
                            <span v-else>
                                <ChevronLeft class="size-4" />
                            </span>
                        </Button>

                        <template v-for="page in pageNumbers" :key="page">
                            <span
                                v-if="page === '...'"
                                class="px-2 text-muted-foreground"
                            >
                                ...
                            </span>
                            <Button
                                v-else
                                :variant="
                                    page === products.current_page
                                        ? 'default'
                                        : 'outline'
                                "
                                size="icon"
                                as-child
                            >
                                <Link
                                    :href="`${products.path}?page=${page}`"
                                    preserve-scroll
                                >
                                    {{ page }}
                                </Link>
                            </Button>
                        </template>

                        <Button
                            variant="outline"
                            size="icon"
                            :disabled="!products.next_page_url"
                            as-child
                        >
                            <Link
                                v-if="products.next_page_url"
                                :href="products.next_page_url"
                                preserve-scroll
                            >
                                <ChevronRight class="size-4" />
                            </Link>
                            <span v-else>
                                <ChevronRight class="size-4" />
                            </span>
                        </Button>
                    </nav>
                </main>
            </div>
        </div>
    </div>
</template>
