<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { ref, onMounted, computed } from 'vue';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';

interface LazyImageProps {
    src: string;
    cachedSrc?: string | null;
    alt?: string;
    placeholderSrc?: string;
    class?: HTMLAttributes['class'];
    imgClass?: HTMLAttributes['class'];
    width?: number | null;
    height?: number | null;
    lazy?: boolean;
}

const props = withDefaults(defineProps<LazyImageProps>(), {
    alt: '',
    placeholderSrc: '/images/placeholder.svg',
    lazy: true,
});

const isLoaded = ref(false);
const hasError = ref(false);
const imageRef = ref<HTMLImageElement | null>(null);

const imageSrc = computed(() => {
    return props.cachedSrc || props.src;
});

const aspectRatio = computed(() => {
    if (props.width && props.height) {
        return props.width / props.height;
    }
    return 1;
});

function onImageLoad() {
    isLoaded.value = true;
}

function onImageError() {
    hasError.value = true;
    isLoaded.value = true;
}

onMounted(() => {
    if (!props.lazy && imageRef.value) {
        if (imageRef.value.complete) {
            isLoaded.value = true;
        }
    }
});
</script>

<template>
    <div
        :class="cn('relative overflow-hidden bg-muted', props.class)"
        :style="{
            aspectRatio: aspectRatio,
        }"
    >
        <Skeleton
            v-if="!isLoaded"
            class="absolute inset-0 size-full"
        />
        <img
            v-show="isLoaded && !hasError"
            ref="imageRef"
            :src="imageSrc"
            :alt="alt"
            :loading="lazy ? 'lazy' : 'eager'"
            :class="
                cn(
                    'size-full object-cover transition-opacity duration-300',
                    isLoaded ? 'opacity-100' : 'opacity-0',
                    props.imgClass
                )
            "
            @load="onImageLoad"
            @error="onImageError"
        />
        <div
            v-if="hasError"
            class="flex size-full items-center justify-center text-muted-foreground"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="size-8"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                <circle cx="8.5" cy="8.5" r="1.5" />
                <polyline points="21 15 16 10 5 21" />
            </svg>
        </div>
    </div>
</template>
