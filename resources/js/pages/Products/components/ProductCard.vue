<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { Product } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Package } from 'lucide-vue-next';

interface Props {
    product: Product;
}

defineProps<Props>();

function formatPrice(pence: number | null): string {
    if (pence === null) {
        return 'N/A';
    }
    return `£${(pence / 100).toFixed(2)}`;
}
</script>

<template>
    <Card
        class="group overflow-hidden transition-shadow hover:shadow-lg dark:hover:shadow-lg dark:hover:shadow-primary/5"
    >
        <Link :href="`/products/${product.slug}`">
            <div class="relative aspect-square overflow-hidden bg-muted">
                <img
                    v-if="product.primary_image"
                    :src="product.primary_image"
                    :alt="product.name"
                    class="size-full object-cover transition-transform group-hover:scale-105"
                />
                <div v-else class="flex size-full items-center justify-center">
                    <Package class="size-12 text-muted-foreground/50" />
                </div>
                <Badge
                    v-if="product.brand"
                    variant="secondary"
                    class="absolute top-2 left-2"
                >
                    {{ product.brand }}
                </Badge>
            </div>
            <CardContent class="p-4">
                <h3
                    class="line-clamp-2 text-sm font-medium text-foreground group-hover:text-primary"
                >
                    {{ product.name }}
                </h3>
                <div v-if="product.category" class="mt-1">
                    <span class="text-xs text-muted-foreground">
                        {{ product.category }}
                    </span>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <div>
                        <span
                            v-if="product.lowest_price_pence"
                            class="text-lg font-bold text-primary"
                        >
                            {{ formatPrice(product.lowest_price_pence) }}
                        </span>
                        <span v-else class="text-sm text-muted-foreground">
                            Price unavailable
                        </span>
                        <span
                            v-if="product.lowest_price_pence"
                            class="ml-1 text-xs text-muted-foreground"
                        >
                            from
                        </span>
                    </div>
                    <div
                        v-if="product.weight_grams"
                        class="text-xs text-muted-foreground"
                    >
                        {{ (product.weight_grams / 1000).toFixed(1) }}kg
                    </div>
                </div>
            </CardContent>
        </Link>
    </Card>
</template>
