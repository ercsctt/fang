<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ExternalLink } from 'lucide-vue-next';

interface Props {
    title: string;
    subtitle: string;
    name: string;
    brand: string | null;
    description: string | null;
    image: string | null | undefined;
    category: string | null;
    weightGrams: number | null;
    quantity: number | null;
    extraInfo?: string;
    externalUrl?: string;
}

defineProps<Props>();

function formatWeight(grams: number | null): string {
    if (grams === null) return 'N/A';
    if (grams >= 1000) {
        return `${(grams / 1000).toFixed(1)}kg`;
    }
    return `${grams}g`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle>{{ title }}</CardTitle>
                    <CardDescription>{{ subtitle }}</CardDescription>
                </div>
                <a
                    v-if="externalUrl"
                    :href="externalUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-muted-foreground hover:text-foreground"
                >
                    <ExternalLink class="size-5" />
                </a>
            </div>
        </CardHeader>
        <CardContent>
            <div class="flex gap-4">
                <img
                    v-if="image"
                    :src="image"
                    :alt="name"
                    class="size-24 shrink-0 rounded-lg object-cover"
                />
                <div
                    v-else
                    class="flex size-24 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    No image
                </div>
                <div class="min-w-0 flex-1 space-y-2">
                    <h3 class="leading-tight font-semibold">{{ name }}</h3>
                    <p v-if="brand" class="text-sm text-muted-foreground">
                        {{ brand }}
                    </p>
                    <p v-if="extraInfo" class="text-sm font-medium">
                        {{ extraInfo }}
                    </p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-4 border-t pt-4">
                <div>
                    <p class="text-xs text-muted-foreground">Category</p>
                    <p class="text-sm font-medium">{{ category || 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Weight</p>
                    <p class="text-sm font-medium">
                        {{ formatWeight(weightGrams) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Quantity</p>
                    <p class="text-sm font-medium">{{ quantity ?? 'N/A' }}</p>
                </div>
            </div>

            <div v-if="description" class="mt-4 border-t pt-4">
                <p class="text-xs text-muted-foreground">Description</p>
                <p class="mt-1 line-clamp-3 text-sm">{{ description }}</p>
            </div>
        </CardContent>
    </Card>
</template>
