<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ProductListing } from '@/types';
import { ArrowDown, Check, ExternalLink, Store, X } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    listings: ProductListing[];
}

const props = defineProps<Props>();

const sortedListings = computed(() => {
    return [...props.listings].sort((a, b) => {
        if (a.price_pence === null) {
            return 1;
        }
        if (b.price_pence === null) {
            return -1;
        }
        return a.price_pence - b.price_pence;
    });
});

const lowestPrice = computed(() => {
    const prices = props.listings
        .map((l) => l.price_pence)
        .filter((p): p is number => p !== null);

    return prices.length > 0 ? Math.min(...prices) : null;
});

function formatPrice(pence: number | null): string {
    if (pence === null) {
        return 'N/A';
    }
    return `£${(pence / 100).toFixed(2)}`;
}

function getSavings(price: number | null): string | null {
    if (
        price === null ||
        lowestPrice.value === null ||
        price === lowestPrice.value
    ) {
        return null;
    }

    const diff = price - lowestPrice.value;
    return `+£${(diff / 100).toFixed(2)}`;
}

function getDiscountPercentage(listing: ProductListing): number | null {
    if (
        listing.price_pence === null ||
        listing.original_price_pence === null ||
        listing.price_pence >= listing.original_price_pence
    ) {
        return null;
    }

    return Math.round(
        ((listing.original_price_pence - listing.price_pence) /
            listing.original_price_pence) *
            100,
    );
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr
                    class="border-b border-border text-left text-sm text-muted-foreground"
                >
                    <th class="pr-4 pb-3 font-medium">Retailer</th>
                    <th class="pr-4 pb-3 font-medium">Price</th>
                    <th class="hidden pr-4 pb-3 font-medium sm:table-cell">
                        Stock
                    </th>
                    <th class="hidden pr-4 pb-3 font-medium md:table-cell">
                        Last Updated
                    </th>
                    <th class="pb-3 font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="(listing, index) in sortedListings"
                    :key="listing.id"
                    class="transition-colors hover:bg-muted/50"
                >
                    <td class="py-4 pr-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-10 items-center justify-center rounded-lg bg-muted"
                            >
                                <Store class="size-5 text-muted-foreground" />
                            </div>
                            <div>
                                <p class="font-medium text-foreground">
                                    {{ listing.retailer?.name || 'Unknown' }}
                                </p>
                                <p
                                    class="max-w-[200px] truncate text-xs text-muted-foreground"
                                >
                                    {{ listing.title }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="py-4 pr-4">
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-lg font-bold"
                                    :class="
                                        index === 0 && lowestPrice !== null
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-foreground'
                                    "
                                >
                                    {{ formatPrice(listing.price_pence) }}
                                </span>
                                <Badge
                                    v-if="index === 0 && lowestPrice !== null"
                                    class="bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400"
                                >
                                    Lowest
                                </Badge>
                                <Badge
                                    v-if="getDiscountPercentage(listing)"
                                    variant="destructive"
                                    class="bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400"
                                >
                                    <ArrowDown class="mr-1 size-3" />
                                    {{ getDiscountPercentage(listing) }}%
                                </Badge>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    v-if="
                                        listing.original_price_pence &&
                                        listing.price_pence &&
                                        listing.original_price_pence >
                                            listing.price_pence
                                    "
                                    class="text-sm text-muted-foreground line-through"
                                >
                                    {{
                                        formatPrice(
                                            listing.original_price_pence,
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="getSavings(listing.price_pence)"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ getSavings(listing.price_pence) }} more
                                </span>
                            </div>
                        </div>
                    </td>
                    <td class="hidden py-4 pr-4 sm:table-cell">
                        <div class="flex items-center gap-1">
                            <Check
                                v-if="listing.in_stock"
                                class="size-4 text-green-600 dark:text-green-400"
                            />
                            <X v-else class="size-4 text-red-500" />
                            <span
                                :class="
                                    listing.in_stock
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-red-500'
                                "
                            >
                                {{
                                    listing.in_stock
                                        ? 'In Stock'
                                        : 'Out of Stock'
                                }}
                            </span>
                        </div>
                    </td>
                    <td
                        class="hidden py-4 pr-4 text-sm text-muted-foreground md:table-cell"
                    >
                        {{
                            listing.last_scraped_at
                                ? new Date(
                                      listing.last_scraped_at,
                                  ).toLocaleDateString('en-GB')
                                : 'Unknown'
                        }}
                    </td>
                    <td class="py-4">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!listing.in_stock"
                            as-child
                        >
                            <a
                                :href="listing.url"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                View
                                <ExternalLink class="ml-1 size-4" />
                            </a>
                        </Button>
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="sortedListings.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            No price listings available for this product.
        </div>
    </div>
</template>
