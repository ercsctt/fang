<script setup lang="ts">
import RetailerController from '@/actions/App/Http/Controllers/Admin/RetailerController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface CrawlerClass {
    value: string;
    label: string;
}

interface Status {
    value: string;
    label: string;
    color: string;
}

interface Props {
    crawlerClasses: CrawlerClass[];
    statuses: Status[];
    defaultStatus: string;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Retailers', href: '/admin/retailers' },
    { title: 'Create Retailer', href: '/admin/retailers/create' },
];

const name = ref('');
const slug = ref('');
const baseUrl = ref('');
const crawlerClass = ref('');
const rateLimitMs = ref(1000);
const status = ref(props.defaultStatus);
const autoGenerateSlug = ref(true);

const slugify = (text: string): string => {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
};

watch(name, (newName) => {
    if (autoGenerateSlug.value) {
        slug.value = slugify(newName);
    }
});

watch(slug, () => {
    if (slug.value !== slugify(name.value)) {
        autoGenerateSlug.value = false;
    }
});

const selectedCrawlerLabel = computed(() => {
    const selected = props.crawlerClasses.find(
        (c) => c.value === crawlerClass.value,
    );
    return selected?.label ?? 'Select a crawler...';
});

const selectedStatusLabel = computed(() => {
    const selected = props.statuses.find((s) => s.value === status.value);
    return selected?.label ?? 'Select a status...';
});
</script>

<template>
    <Head title="Create Retailer" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
            <div class="flex items-center gap-4">
                <Link
                    href="/admin/retailers"
                    class="flex items-center gap-2 text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" />
                    Back to Retailers
                </Link>
            </div>

            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    Create Retailer
                </h1>
                <p class="text-muted-foreground">
                    Add a new retailer to the crawling system
                </p>
            </div>

            <Card class="max-w-2xl">
                <CardHeader>
                    <CardTitle>Retailer Details</CardTitle>
                    <CardDescription>
                        Enter the retailer information and crawler configuration
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="RetailerController.store.form()"
                        class="space-y-6"
                        v-slot="{ errors, processing }"
                    >
                        <div class="space-y-4">
                            <HeadingSmall
                                title="Basic Information"
                                description="Retailer name and identification"
                            />

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="name">Name *</Label>
                                    <Input
                                        id="name"
                                        v-model="name"
                                        name="name"
                                        placeholder="e.g. Tesco"
                                        required
                                    />
                                    <InputError :message="errors.name" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="slug">Slug</Label>
                                    <Input
                                        id="slug"
                                        v-model="slug"
                                        name="slug"
                                        placeholder="e.g. tesco"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Auto-generated from name. Edit to
                                        customize.
                                    </p>
                                    <InputError :message="errors.slug" />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="base_url">Base URL *</Label>
                                <Input
                                    id="base_url"
                                    v-model="baseUrl"
                                    name="base_url"
                                    type="url"
                                    placeholder="https://www.example.com"
                                    required
                                />
                                <InputError :message="errors.base_url" />
                            </div>
                        </div>

                        <div class="space-y-4">
                            <HeadingSmall
                                title="Crawler Configuration"
                                description="Configure how this retailer will be crawled"
                            />

                            <div class="grid gap-2">
                                <Label for="crawler_class"
                                    >Crawler Class *</Label
                                >
                                <Select v-model="crawlerClass">
                                    <SelectTrigger>
                                        <SelectValue
                                            :placeholder="selectedCrawlerLabel"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="crawler in crawlerClasses"
                                            :key="crawler.value"
                                            :value="crawler.value"
                                        >
                                            {{ crawler.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="crawler_class"
                                    :value="crawlerClass"
                                />
                                <InputError :message="errors.crawler_class" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="rate_limit_ms"
                                    >Rate Limit (ms) *</Label
                                >
                                <Input
                                    id="rate_limit_ms"
                                    v-model.number="rateLimitMs"
                                    name="rate_limit_ms"
                                    type="number"
                                    min="100"
                                    max="60000"
                                    step="100"
                                    required
                                />
                                <p class="text-xs text-muted-foreground">
                                    Delay between requests in milliseconds
                                    (100-60000)
                                </p>
                                <InputError :message="errors.rate_limit_ms" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="status">Initial Status *</Label>
                                <Select v-model="status">
                                    <SelectTrigger>
                                        <SelectValue
                                            :placeholder="selectedStatusLabel"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="s in statuses"
                                            :key="s.value"
                                            :value="s.value"
                                        >
                                            {{ s.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <input
                                    type="hidden"
                                    name="status"
                                    :value="status"
                                />
                                <InputError :message="errors.status" />
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-4">
                            <Button type="submit" :disabled="processing">
                                {{
                                    processing
                                        ? 'Creating...'
                                        : 'Create Retailer'
                                }}
                            </Button>
                            <Link
                                href="/admin/retailers"
                                class="text-sm text-muted-foreground hover:text-foreground"
                            >
                                Cancel
                            </Link>
                        </div>
                    </Form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
