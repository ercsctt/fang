<script setup lang="ts">
import { Input } from '@/components/ui/input';
import type { Product } from '@/types';
import { router } from '@inertiajs/vue3';
import { Package, Search } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface Props {
    modelValue: string;
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Search products...',
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    search: [];
}>();

const inputValue = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});

const suggestions = ref<Product[]>([]);
const isOpen = ref(false);
const isLoading = ref(false);
const selectedIndex = ref(-1);
const containerRef = ref<HTMLElement | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(inputValue, (newValue) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    if (newValue.length < 2) {
        suggestions.value = [];
        isOpen.value = false;
        return;
    }

    isLoading.value = true;

    debounceTimer = setTimeout(async () => {
        try {
            const response = await fetch(
                `/products/search?q=${encodeURIComponent(newValue)}`,
            );
            const data = await response.json();
            suggestions.value = data;
            isOpen.value = data.length > 0;
            selectedIndex.value = -1;
        } catch (error) {
            console.error('Search error:', error);
            suggestions.value = [];
        } finally {
            isLoading.value = false;
        }
    }, 300);
});

function handleKeydown(event: KeyboardEvent) {
    if (!isOpen.value) {
        if (event.key === 'Enter') {
            emit('search');
        }
        return;
    }

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            selectedIndex.value = Math.min(
                selectedIndex.value + 1,
                suggestions.value.length - 1,
            );
            break;
        case 'ArrowUp':
            event.preventDefault();
            selectedIndex.value = Math.max(selectedIndex.value - 1, -1);
            break;
        case 'Enter':
            event.preventDefault();
            if (
                selectedIndex.value >= 0 &&
                suggestions.value[selectedIndex.value]
            ) {
                navigateToProduct(suggestions.value[selectedIndex.value]);
            } else {
                emit('search');
            }
            break;
        case 'Escape':
            isOpen.value = false;
            break;
    }
}

function navigateToProduct(product: Product) {
    isOpen.value = false;
    router.get(`/products/${product.slug}`);
}

function formatPrice(pence: number | null): string {
    if (pence === null) {
        return '';
    }
    return `£${(pence / 100).toFixed(2)}`;
}

function handleClickOutside(event: MouseEvent) {
    if (
        containerRef.value &&
        !containerRef.value.contains(event.target as Node)
    ) {
        isOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <div class="relative">
            <Search
                class="absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="inputValue"
                type="text"
                :placeholder="placeholder"
                class="h-12 pr-4 pl-10 text-base"
                @keydown="handleKeydown"
                @focus="isOpen = suggestions.length > 0"
            />
        </div>

        <div
            v-if="isOpen"
            class="absolute top-full right-0 left-0 z-50 mt-1 max-h-80 overflow-auto rounded-lg border border-border bg-popover shadow-lg"
        >
            <div v-if="isLoading" class="p-4 text-center text-muted-foreground">
                Searching...
            </div>
            <div v-else>
                <button
                    v-for="(product, index) in suggestions"
                    :key="product.id"
                    class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent"
                    :class="{
                        'bg-accent': index === selectedIndex,
                    }"
                    @click="navigateToProduct(product)"
                    @mouseenter="selectedIndex = index"
                >
                    <div
                        class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted"
                    >
                        <img
                            v-if="product.primary_image"
                            :src="product.primary_image"
                            :alt="product.name"
                            class="size-full object-cover"
                        />
                        <Package
                            v-else
                            class="size-6 text-muted-foreground/50"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">
                            {{ product.name }}
                        </p>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="product.brand"
                                class="text-sm text-muted-foreground"
                            >
                                {{ product.brand }}
                            </span>
                            <span
                                v-if="product.lowest_price_pence"
                                class="text-sm font-medium text-primary"
                            >
                                {{ formatPrice(product.lowest_price_pence) }}
                            </span>
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</template>
