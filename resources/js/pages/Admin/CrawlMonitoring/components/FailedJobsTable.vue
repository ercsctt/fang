<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { RefreshCw, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface FailedJob {
    id: number;
    uuid: string;
    queue: string;
    payload_summary: string;
    exception_summary: string;
    failed_at: string;
}

interface Props {
    jobs: FailedJob[];
}

const props = defineProps<Props>();

const retryingJobIds = ref<Set<number>>(new Set());
const deletingJobIds = ref<Set<number>>(new Set());
const retryingAll = ref(false);

async function retryJob(jobId: number) {
    retryingJobIds.value.add(jobId);

    try {
        const response = await fetch(
            `/admin/crawl-monitoring/jobs/${jobId}/retry`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
            },
        );

        if (response.ok) {
            router.reload({ only: ['failedJobs'] });
        }
    } finally {
        retryingJobIds.value.delete(jobId);
    }
}

async function deleteJob(jobId: number) {
    if (!confirm('Are you sure you want to delete this failed job?')) {
        return;
    }

    deletingJobIds.value.add(jobId);

    try {
        const response = await fetch(`/admin/crawl-monitoring/jobs/${jobId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
            },
        });

        if (response.ok) {
            router.reload({ only: ['failedJobs'] });
        }
    } finally {
        deletingJobIds.value.delete(jobId);
    }
}

async function retryAllJobs() {
    if (
        !confirm(
            `Are you sure you want to retry all ${props.jobs.length} failed jobs?`,
        )
    ) {
        return;
    }

    retryingAll.value = true;

    try {
        const response = await fetch('/admin/crawl-monitoring/jobs/retry-all', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
            },
        });

        if (response.ok) {
            router.reload({ only: ['failedJobs'] });
        }
    } finally {
        retryingAll.value = false;
    }
}

function formatDate(date: string): string {
    return new Date(date).toLocaleString('en-GB', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function truncateText(text: string, maxLength: number): string {
    if (text.length <= maxLength) {
        return text;
    }
    return text.substring(0, maxLength) + '...';
}
</script>

<template>
    <div>
        <div v-if="jobs.length > 0" class="mb-4 flex items-center justify-end">
            <Button
                variant="outline"
                size="sm"
                :disabled="retryingAll"
                @click="retryAllJobs"
            >
                <RefreshCw
                    class="mr-2 size-4"
                    :class="{ 'animate-spin': retryingAll }"
                />
                Retry All ({{ jobs.length }})
            </Button>
        </div>

        <div class="overflow-x-auto">
            <table v-if="jobs.length > 0" class="w-full">
                <thead>
                    <tr
                        class="border-b border-border text-left text-sm text-muted-foreground"
                    >
                        <th class="pb-3 font-medium">Job</th>
                        <th class="pb-3 font-medium">Queue</th>
                        <th class="pb-3 font-medium">Error</th>
                        <th class="pb-3 font-medium">Failed At</th>
                        <th class="pb-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="job in jobs" :key="job.id">
                        <td class="py-3">
                            <div class="font-medium">
                                {{ job.payload_summary }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ job.uuid }}
                            </div>
                        </td>
                        <td class="py-3">
                            <span
                                class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium"
                            >
                                {{ job.queue }}
                            </span>
                        </td>
                        <td class="max-w-xs py-3">
                            <span
                                class="text-sm text-destructive"
                                :title="job.exception_summary"
                            >
                                {{ truncateText(job.exception_summary, 80) }}
                            </span>
                        </td>
                        <td class="py-3 text-sm text-muted-foreground">
                            {{ formatDate(job.failed_at) }}
                        </td>
                        <td class="py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="size-8"
                                    :disabled="retryingJobIds.has(job.id)"
                                    title="Retry job"
                                    @click="retryJob(job.id)"
                                >
                                    <RefreshCw
                                        class="size-4"
                                        :class="{
                                            'animate-spin': retryingJobIds.has(
                                                job.id,
                                            ),
                                        }"
                                    />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="size-8 text-destructive hover:bg-destructive hover:text-destructive-foreground"
                                    :disabled="deletingJobIds.has(job.id)"
                                    title="Delete job"
                                    @click="deleteJob(job.id)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-else class="py-8 text-center text-muted-foreground">
                No failed jobs. All systems running smoothly.
            </div>
        </div>
    </div>
</template>
