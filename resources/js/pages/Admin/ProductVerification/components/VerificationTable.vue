<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import admin from '@/routes/admin';
import type {
    Match,
    PaginatedMatches,
    VerificationFilters,
} from '@/types/admin';
import { router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Eye,
    XCircle,
} from 'lucide-vue-next';

interface Props {
    matches: PaginatedMatches;
    filters: VerificationFilters;
}

const props = defineProps<Props>();

function formatPrice(pence: number | null): string {
    if (pence === null) return 'N/A';
    return `£${(pence / 100).toFixed(2)}`;
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function getStatusBadgeVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'approved':
            return 'default';
        case 'rejected':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function getConfidenceColor(score: number): string {
    if (score >= 90) return 'text-green-600 dark:text-green-400';
    if (score >= 70) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

function getMatchTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        exact: 'Exact',
        fuzzy: 'Fuzzy',
        barcode: 'Barcode',
        manual: 'Manual',
    };
    return labels[type] || type;
}

function toggleSort(field: string) {
    let direction = 'asc';
    if (props.filters.sort === field && props.filters.direction === 'asc') {
        direction = 'desc';
    }
    router.get(
        admin.productVerification.index.url({
            query: { status: props.filters.status, sort: field, direction },
        }),
        {},
        { preserveState: true, preserveScroll: true },
    );
}

function goToPage(url: string | null) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function approveMatch(match: Match, event: Event) {
    event.preventDefault();
    event.stopPropagation();
    router.post(
        admin.productVerification.approve.url(match.id),
        {},
        {
            preserveScroll: true,
        },
    );
}

function rejectMatch(match: Match, event: Event) {
    event.preventDefault();
    event.stopPropagation();
    router.post(
        admin.productVerification.reject.url(match.id),
        {},
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <div>
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead class="w-[200px]">
                        <Button
                            variant="ghost"
                            class="-ml-3 h-8 text-xs"
                            @click="toggleSort('confidence_score')"
                        >
                            Confidence
                            <ArrowUpDown class="ml-2 size-4" />
                        </Button>
                    </TableHead>
                    <TableHead>Product Listing</TableHead>
                    <TableHead>Matched Product</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>
                        <Button
                            variant="ghost"
                            class="-ml-3 h-8 text-xs"
                            @click="toggleSort('matched_at')"
                        >
                            Date
                            <ArrowUpDown class="ml-2 size-4" />
                        </Button>
                    </TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="match in matches.data"
                    :key="match.id"
                    class="cursor-pointer hover:bg-muted/50"
                    @click="
                        router.get(admin.productVerification.show.url(match.id))
                    "
                >
                    <TableCell>
                        <div class="flex flex-col">
                            <span
                                class="text-lg font-bold"
                                :class="
                                    getConfidenceColor(match.confidence_score)
                                "
                            >
                                {{ match.confidence_score.toFixed(1) }}%
                            </span>
                        </div>
                    </TableCell>
                    <TableCell>
                        <div class="flex items-center gap-3">
                            <img
                                v-if="
                                    match.product_listing.images &&
                                    match.product_listing.images[0]
                                "
                                :src="match.product_listing.images[0]"
                                :alt="match.product_listing.title"
                                class="size-10 rounded object-cover"
                            />
                            <div class="size-10 rounded bg-muted" v-else />
                            <div class="max-w-[200px]">
                                <p class="truncate text-sm font-medium">
                                    {{ match.product_listing.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ match.product_listing.retailer.name }}
                                    <span
                                        v-if="match.product_listing.price_pence"
                                        class="ml-2"
                                    >
                                        {{
                                            formatPrice(
                                                match.product_listing
                                                    .price_pence,
                                            )
                                        }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </TableCell>
                    <TableCell>
                        <div class="flex items-center gap-3">
                            <img
                                v-if="match.product.primary_image"
                                :src="match.product.primary_image"
                                :alt="match.product.name"
                                class="size-10 rounded object-cover"
                            />
                            <div class="size-10 rounded bg-muted" v-else />
                            <div class="max-w-[200px]">
                                <p class="truncate text-sm font-medium">
                                    {{ match.product.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ match.product.brand || 'Unknown brand' }}
                                </p>
                            </div>
                        </div>
                    </TableCell>
                    <TableCell>
                        <Badge variant="outline">
                            {{ getMatchTypeLabel(match.match_type) }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <Badge :variant="getStatusBadgeVariant(match.status)">
                            {{ match.status }}
                        </Badge>
                    </TableCell>
                    <TableCell class="text-sm text-muted-foreground">
                        {{ formatDate(match.matched_at) }}
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            <Button
                                v-if="match.status === 'pending'"
                                variant="ghost"
                                size="icon"
                                class="size-8 text-green-600 hover:bg-green-100 hover:text-green-700 dark:text-green-400 dark:hover:bg-green-900/30"
                                title="Approve"
                                @click="(e) => approveMatch(match, e)"
                            >
                                <CheckCircle2 class="size-4" />
                            </Button>
                            <Button
                                v-if="match.status === 'pending'"
                                variant="ghost"
                                size="icon"
                                class="size-8 text-red-600 hover:bg-red-100 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-900/30"
                                title="Reject"
                                @click="(e) => rejectMatch(match, e)"
                            >
                                <XCircle class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                title="View details"
                                @click.stop="
                                    router.get(
                                        admin.productVerification.show.url(
                                            match.id,
                                        ),
                                    )
                                "
                            >
                                <Eye class="size-4" />
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
                <TableRow v-if="matches.data.length === 0">
                    <TableCell
                        colspan="7"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No matches found
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div
            v-if="matches.last_page > 1"
            class="mt-4 flex items-center justify-between border-t pt-4"
        >
            <p class="text-sm text-muted-foreground">
                Showing
                {{ (matches.current_page - 1) * matches.per_page + 1 }} to
                {{
                    Math.min(
                        matches.current_page * matches.per_page,
                        matches.total,
                    )
                }}
                of {{ matches.total }} results
            </p>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="matches.current_page === 1"
                    @click="goToPage(matches.links[0]?.url)"
                >
                    <ChevronLeft class="size-4" />
                    Previous
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="matches.current_page === matches.last_page"
                    @click="
                        goToPage(matches.links[matches.links.length - 1]?.url)
                    "
                >
                    Next
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
