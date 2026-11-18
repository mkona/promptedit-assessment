<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { exactUsd, formatUsd } from '@/lib/money';
import type { Generation } from '@/types/generation';

const props = defineProps<{ generation: Generation }>();

const statusLabel = computed(
    () =>
        ({
            submitting: 'Waiting for provider',
            queued: 'Queued',
            processing: 'Generating',
            succeeded: 'Done',
            failed: 'Failed',
        })[props.generation.status],
);

const isPending = computed(
    () => !['succeeded', 'failed'].includes(props.generation.status),
);

const aspectClass = computed(
    () =>
        ({
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size] ?? 'aspect-square',
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            :class="aspectClass"
            class="flex items-center justify-center bg-muted/60 text-sm text-muted-foreground"
        >
            <img
                v-if="generation.image_url"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="h-full w-full object-cover"
                loading="lazy"
            />
            <div v-else-if="isPending" class="flex flex-col items-center gap-2">
                <Spinner />
                <span>{{ statusLabel }}…</span>
            </div>
            <span v-else-if="generation.image_expired" class="px-4 text-center">
                The image link has expired.
            </span>
            <span
                v-else-if="generation.status === 'failed'"
                class="px-4 text-center"
            >
                No image
            </span>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <Badge
                    :variant="
                        generation.status === 'failed'
                            ? 'destructive'
                            : generation.status === 'succeeded'
                              ? 'default'
                              : 'secondary'
                    "
                >
                    {{ statusLabel }}
                </Badge>
                <span class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }}
                    <template v-if="generation.reference_images.length">
                        · {{ generation.reference_images.length }} ref
                    </template>
                </span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p v-if="generation.error_message" class="text-sm text-destructive">
                {{ generation.error_message }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd :title="exactUsd(generation.estimated_price)">
                        {{ formatUsd(generation.estimated_price) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Charged</dt>
                    <dd :title="exactUsd(generation.price)" class="font-medium">
                        <template v-if="generation.status === 'failed'">
                            $0 (not charged)
                        </template>
                        <template v-else-if="generation.price !== null">
                            {{ formatUsd(generation.price) }}
                        </template>
                        <template v-else>
                            Up to {{ formatUsd(generation.max_price) }}
                        </template>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
