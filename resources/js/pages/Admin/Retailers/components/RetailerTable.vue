<script setup lang="ts">
import {
    disable,
    enable,
    pause,
    resume,
} from '@/actions/App/Http/Controllers/Admin/RetailerStatusController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CheckCircle2,
    Clock,
    ExternalLink,
    MinusCircle,
    MoreHorizontal,
    PauseCircle,
    Play,
    Power,
    PowerOff,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface RetailerData {
    id: number;
    name: string;
    slug: string;
    base_url: string;
    status: string;
    status_label: string;
    status_color: string;
    status_description: string;
    status_badge_classes: string;
    status_icon: string;
    consecutive_failures: number;
    last_failure_at: string | null;
    paused_until: string | null;
    last_crawled_at: string | null;
    is_paused: boolean;
    is_available_for_crawling: boolean;
    product_listings_count: number;
    can_pause: boolean;
    can_resume: boolean;
    can_disable: boolean;
    can_enable: boolean;
}

interface Props {
    retailers: RetailerData[];
    sortField: string;
    sortDirection: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    sort: [field: string];
    retailerUpdated: [];
}>();

const isPauseDialogOpen = ref(false);
const isDisableDialogOpen = ref(false);
const selectedRetailer = ref<RetailerData | null>(null);
const pauseDuration = ref('60');
const pauseReason = ref('');
const disableReason = ref('');
const isSubmitting = ref(false);

function getSortIcon(field: string) {
    if (props.sortField !== field) {
        return ArrowUpDown;
    }
    return props.sortDirection === 'asc' ? ArrowUp : ArrowDown;
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'active':
            return CheckCircle2;
        case 'paused':
            return PauseCircle;
        case 'disabled':
            return MinusCircle;
        case 'degraded':
            return AlertTriangle;
        case 'failed':
            return XCircle;
        default:
            return CheckCircle2;
    }
}

function formatDate(date: string | null): string {
    if (!date) {
        return 'Never';
    }
    const d = new Date(date);
    return d.toLocaleString('en-GB', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatRelativeTime(date: string | null): string {
    if (!date) {
        return 'Never';
    }
    const now = new Date();
    const then = new Date(date);
    const diffMs = now.getTime() - then.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) {
        return 'Just now';
    }
    if (diffMins < 60) {
        return `${diffMins}m ago`;
    }
    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }
    return `${diffDays}d ago`;
}

function getPausedUntilText(date: string | null): string {
    if (!date) {
        return '';
    }
    const d = new Date(date);
    const now = new Date();
    if (d <= now) {
        return '';
    }
    const diffMs = d.getTime() - now.getTime();
    const diffMins = Math.ceil(diffMs / 60000);
    if (diffMins < 60) {
        return `${diffMins}m`;
    }
    const diffHours = Math.ceil(diffMins / 60);
    if (diffHours < 24) {
        return `${diffHours}h`;
    }
    const diffDays = Math.ceil(diffHours / 24);
    return `${diffDays}d`;
}

function openPauseDialog(retailer: RetailerData) {
    selectedRetailer.value = retailer;
    pauseDuration.value = '60';
    pauseReason.value = '';
    isPauseDialogOpen.value = true;
}

function openDisableDialog(retailer: RetailerData) {
    selectedRetailer.value = retailer;
    disableReason.value = '';
    isDisableDialogOpen.value = true;
}

async function handlePause() {
    if (!selectedRetailer.value) return;

    isSubmitting.value = true;

    try {
        const response = await fetch(pause.url(selectedRetailer.value.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN':
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1]
                        ?.replace(/%3D/g, '=') || '',
                Accept: 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                duration_minutes: parseInt(pauseDuration.value, 10),
                reason: pauseReason.value || null,
            }),
        });

        if (response.ok) {
            isPauseDialogOpen.value = false;
            emit('retailerUpdated');
        }
    } catch {
        // Handle error silently
    } finally {
        isSubmitting.value = false;
    }
}

async function handleResume(retailer: RetailerData) {
    isSubmitting.value = true;

    try {
        const response = await fetch(resume.url(retailer.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN':
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1]
                        ?.replace(/%3D/g, '=') || '',
                Accept: 'application/json',
            },
            credentials: 'include',
        });

        if (response.ok) {
            emit('retailerUpdated');
        }
    } catch {
        // Handle error silently
    } finally {
        isSubmitting.value = false;
    }
}

async function handleDisable() {
    if (!selectedRetailer.value) return;

    isSubmitting.value = true;

    try {
        const response = await fetch(disable.url(selectedRetailer.value.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN':
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1]
                        ?.replace(/%3D/g, '=') || '',
                Accept: 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                reason: disableReason.value || null,
            }),
        });

        if (response.ok) {
            isDisableDialogOpen.value = false;
            emit('retailerUpdated');
        }
    } catch {
        // Handle error silently
    } finally {
        isSubmitting.value = false;
    }
}

async function handleEnable(retailer: RetailerData) {
    isSubmitting.value = true;

    try {
        const response = await fetch(enable.url(retailer.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN':
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1]
                        ?.replace(/%3D/g, '=') || '',
                Accept: 'application/json',
            },
            credentials: 'include',
        });

        if (response.ok) {
            emit('retailerUpdated');
        }
    } catch {
        // Handle error silently
    } finally {
        isSubmitting.value = false;
    }
}

const sortedRetailers = computed(() => [...props.retailers]);
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr
                    class="border-b border-border text-left text-sm text-muted-foreground"
                >
                    <th class="pb-3 font-medium">
                        <button
                            class="flex items-center gap-1 hover:text-foreground"
                            @click="emit('sort', 'name')"
                        >
                            Retailer
                            <component
                                :is="getSortIcon('name')"
                                class="size-4"
                            />
                        </button>
                    </th>
                    <th class="pb-3 font-medium">
                        <button
                            class="flex items-center gap-1 hover:text-foreground"
                            @click="emit('sort', 'status')"
                        >
                            Status
                            <component
                                :is="getSortIcon('status')"
                                class="size-4"
                            />
                        </button>
                    </th>
                    <th class="pb-3 font-medium">
                        <button
                            class="flex items-center gap-1 hover:text-foreground"
                            @click="emit('sort', 'last_crawled_at')"
                        >
                            Last Crawled
                            <component
                                :is="getSortIcon('last_crawled_at')"
                                class="size-4"
                            />
                        </button>
                    </th>
                    <th class="pb-3 font-medium">
                        <button
                            class="flex items-center gap-1 hover:text-foreground"
                            @click="emit('sort', 'consecutive_failures')"
                        >
                            Failures
                            <component
                                :is="getSortIcon('consecutive_failures')"
                                class="size-4"
                            />
                        </button>
                    </th>
                    <th class="pb-3 text-right font-medium">
                        <button
                            class="ml-auto flex items-center gap-1 hover:text-foreground"
                            @click="emit('sort', 'product_listings_count')"
                        >
                            Products
                            <component
                                :is="getSortIcon('product_listings_count')"
                                class="size-4"
                            />
                        </button>
                    </th>
                    <th class="pb-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="retailer in sortedRetailers"
                    :key="retailer.id"
                    class="group"
                >
                    <td class="py-3">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{
                                    retailer.name
                                }}</span>
                                <a
                                    :href="retailer.base_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-muted-foreground hover:text-foreground"
                                >
                                    <ExternalLink class="size-3" />
                                </a>
                            </div>
                            <span class="text-xs text-muted-foreground">
                                {{ retailer.slug }}
                            </span>
                        </div>
                    </td>
                    <td class="py-3">
                        <div class="flex flex-col gap-1">
                            <Badge
                                variant="outline"
                                :class="retailer.status_badge_classes"
                            >
                                <component
                                    :is="getStatusIcon(retailer.status)"
                                    class="mr-1 size-3"
                                />
                                {{ retailer.status_label }}
                                <span
                                    v-if="
                                        retailer.is_paused &&
                                        getPausedUntilText(
                                            retailer.paused_until,
                                        )
                                    "
                                    class="ml-1"
                                >
                                    ({{
                                        getPausedUntilText(
                                            retailer.paused_until,
                                        )
                                    }})
                                </span>
                            </Badge>
                            <span
                                class="text-xs text-muted-foreground"
                                :title="retailer.status_description"
                            >
                                {{ retailer.status_description }}
                            </span>
                        </div>
                    </td>
                    <td class="py-3">
                        <span
                            :class="{
                                'text-green-600 dark:text-green-400':
                                    retailer.last_crawled_at &&
                                    new Date(retailer.last_crawled_at) >
                                        new Date(Date.now() - 3600000),
                                'text-yellow-600 dark:text-yellow-400':
                                    retailer.last_crawled_at &&
                                    new Date(retailer.last_crawled_at) <=
                                        new Date(Date.now() - 3600000) &&
                                    new Date(retailer.last_crawled_at) >
                                        new Date(Date.now() - 86400000),
                                'text-red-600 dark:text-red-400':
                                    !retailer.last_crawled_at ||
                                    new Date(retailer.last_crawled_at) <=
                                        new Date(Date.now() - 86400000),
                            }"
                            :title="formatDate(retailer.last_crawled_at)"
                        >
                            {{ formatRelativeTime(retailer.last_crawled_at) }}
                        </span>
                    </td>
                    <td class="py-3">
                        <div class="flex flex-col gap-1">
                            <template v-if="retailer.consecutive_failures > 0">
                                <Badge
                                    variant="secondary"
                                    class="w-fit"
                                    :class="{
                                        'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300':
                                            retailer.consecutive_failures >= 10,
                                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300':
                                            retailer.consecutive_failures >=
                                                5 &&
                                            retailer.consecutive_failures < 10,
                                    }"
                                >
                                    {{ retailer.consecutive_failures }}
                                </Badge>
                                <div
                                    v-if="retailer.last_failure_at"
                                    class="flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <Clock class="size-3" />
                                    {{ formatDate(retailer.last_failure_at) }}
                                </div>
                            </template>
                            <span v-else class="text-sm text-muted-foreground">
                                0
                            </span>
                        </div>
                    </td>
                    <td class="py-3 text-right">
                        {{ retailer.product_listings_count.toLocaleString() }}
                    </td>
                    <td class="py-3 text-right">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="icon">
                                    <MoreHorizontal class="size-4" />
                                    <span class="sr-only">Open menu</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="retailer.can_pause"
                                    @click="openPauseDialog(retailer)"
                                >
                                    <PauseCircle class="mr-2 size-4" />
                                    Pause
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="retailer.can_resume"
                                    @click="handleResume(retailer)"
                                >
                                    <Play class="mr-2 size-4" />
                                    Resume
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="
                                        (retailer.can_pause ||
                                            retailer.can_resume) &&
                                        (retailer.can_disable ||
                                            retailer.can_enable)
                                    "
                                />
                                <DropdownMenuItem
                                    v-if="retailer.can_disable"
                                    class="text-destructive focus:text-destructive"
                                    @click="openDisableDialog(retailer)"
                                >
                                    <PowerOff class="mr-2 size-4" />
                                    Disable
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="retailer.can_enable"
                                    class="text-green-600 focus:text-green-600"
                                    @click="handleEnable(retailer)"
                                >
                                    <Power class="mr-2 size-4" />
                                    Enable
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="retailers.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            No retailers found.
        </div>
    </div>

    <Dialog v-model:open="isPauseDialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Pause Retailer</DialogTitle>
                <DialogDescription>
                    Temporarily pause crawling for
                    {{ selectedRetailer?.name }}. The retailer will
                    automatically resume after the duration expires.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4 py-4">
                <div class="grid gap-2">
                    <Label for="pause-duration">Duration</Label>
                    <Select v-model="pauseDuration">
                        <SelectTrigger id="pause-duration">
                            <SelectValue placeholder="Select duration" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="15">15 minutes</SelectItem>
                            <SelectItem value="30">30 minutes</SelectItem>
                            <SelectItem value="60">1 hour</SelectItem>
                            <SelectItem value="120">2 hours</SelectItem>
                            <SelectItem value="360">6 hours</SelectItem>
                            <SelectItem value="720">12 hours</SelectItem>
                            <SelectItem value="1440">24 hours</SelectItem>
                            <SelectItem value="4320">3 days</SelectItem>
                            <SelectItem value="10080">7 days</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="pause-reason">Reason (optional)</Label>
                    <Input
                        id="pause-reason"
                        v-model="pauseReason"
                        placeholder="e.g., Rate limiting, maintenance..."
                    />
                </div>
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    @click="isPauseDialogOpen = false"
                    :disabled="isSubmitting"
                >
                    Cancel
                </Button>
                <Button @click="handlePause" :disabled="isSubmitting">
                    <PauseCircle class="mr-2 size-4" />
                    Pause Retailer
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isDisableDialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Disable Retailer</DialogTitle>
                <DialogDescription>
                    Disable crawling for {{ selectedRetailer?.name }}. This will
                    stop all crawling until manually re-enabled.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4 py-4">
                <div class="grid gap-2">
                    <Label for="disable-reason">Reason (optional)</Label>
                    <Input
                        id="disable-reason"
                        v-model="disableReason"
                        placeholder="e.g., Site changes, terms violation..."
                    />
                </div>
            </div>
            <DialogFooter>
                <Button
                    variant="outline"
                    @click="isDisableDialogOpen = false"
                    :disabled="isSubmitting"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    @click="handleDisable"
                    :disabled="isSubmitting"
                >
                    <PowerOff class="mr-2 size-4" />
                    Disable Retailer
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
