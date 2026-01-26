<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Scraper Tester',
        href: '/scraper-tester',
    },
];

const url = ref('');
const useProxy = ref(false);
const rotateUserAgent = ref(true);
const loading = ref(false);
const response = ref<any>(null);
const error = ref<string | null>(null);

const fetchHtml = async () => {
    if (!url.value) return;

    loading.value = true;
    error.value = null;
    response.value = null;

    try {
        const result = await axios.post('/scraper-tester/fetch', {
            url: url.value,
            use_proxy: useProxy.value,
            rotate_user_agent: rotateUserAgent.value,
        });

        response.value = result.data;
    } catch (err: any) {
        error.value =
            err.response?.data?.error ||
            'An error occurred while fetching the URL';
    } finally {
        loading.value = false;
    }
};

const formattedLength = computed(() => {
    if (!response.value?.length) return '0 bytes';
    const bytes = response.value.length;
    if (bytes < 1024) return `${bytes} bytes`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(2)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
});

const copyToClipboard = async () => {
    if (response.value?.html) {
        await navigator.clipboard.writeText(response.value.html);
    }
};
</script>

<template>
    <Head title="Scraper Tester" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Scraper Tester</CardTitle>
                    <CardDescription>
                        Test the scraper by fetching HTML from any URL
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="url">URL</Label>
                        <Input
                            id="url"
                            v-model="url"
                            type="url"
                            placeholder="https://example.com"
                            @keyup.enter="fetchHtml"
                        />
                    </div>

                    <div class="flex flex-col gap-4">
                        <div class="flex items-center space-x-2">
                            <Checkbox
                                id="rotate-user-agent"
                                v-model:checked="rotateUserAgent"
                            />
                            <Label
                                for="rotate-user-agent"
                                class="cursor-pointer text-sm font-normal"
                            >
                                Rotate user agent
                            </Label>
                        </div>

                        <div class="flex items-center space-x-2">
                            <Checkbox
                                id="use-proxy"
                                v-model:checked="useProxy"
                            />
                            <Label
                                for="use-proxy"
                                class="cursor-pointer text-sm font-normal"
                            >
                                Use proxy (BrightData)
                            </Label>
                        </div>
                    </div>

                    <Button
                        @click="fetchHtml"
                        :disabled="loading || !url"
                        class="w-full"
                    >
                        <Spinner v-if="loading" class="mr-2 h-4 w-4" />
                        {{ loading ? 'Fetching...' : 'Fetch HTML' }}
                    </Button>
                </CardContent>
            </Card>

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <Card v-if="response && response.success">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle>Response</CardTitle>
                        <div class="flex gap-2">
                            <Badge variant="secondary">
                                Status: {{ response.status_code }}
                            </Badge>
                            <Badge variant="secondary">
                                Size: {{ formattedLength }}
                            </Badge>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div>
                        <Label class="mb-2 block">HTML Response</Label>
                        <div class="relative">
                            <pre
                                class="overflow-x-auto rounded-lg border bg-muted p-4 text-xs"
                                style="max-height: 500px"
                                >{{ response.html }}</pre
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                class="absolute top-2 right-2"
                                @click="copyToClipboard"
                            >
                                Copy
                            </Button>
                        </div>
                    </div>

                    <div v-if="response.headers">
                        <Label class="mb-2 block">Response Headers</Label>
                        <div class="rounded-lg border bg-muted p-4 text-xs">
                            <div
                                v-for="(value, key) in response.headers"
                                :key="key"
                                class="font-mono"
                            >
                                <span class="text-muted-foreground"
                                    >{{ key }}:</span
                                >
                                {{ value }}
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
