<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CreditController from '@/actions/App/Http/Controllers/CreditController';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { exactUsd, formatUsd } from '@/lib/money';
import { dashboard } from '@/routes';
import type { Estimate, Generation } from '@/types/generation';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: dashboard() }],
    },
});

const props = defineProps<{
    generations: Generation[];
    hasPending: boolean;
    balance: string;
    sizes: string[];
    qualities: string[];
    creditPackages: number[];
}>();

const MAX_REFERENCES = 4;
const MAX_PROMPT = 4000;
const REFERENCE_PLACEHOLDER = 'https://example.com/style.png';

const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: 'medium',
    reference_images: [] as string[],
});

const references = ref<string[]>([]);

const promptLength = computed(() => [...form.prompt].length);

const estimate = ref<Estimate | null>(null);

const refreshEstimate = useDebounceFn(async () => {
    const query = new URLSearchParams({
        characters: String(promptLength.value),
        size: form.size,
        quality: form.quality,
        references: String(references.value.length),
    });

    const response = await fetch(
        `${GenerationController.estimate.url()}?${query}`,
        { headers: { Accept: 'application/json' } },
    );

    if (response.ok) {
        estimate.value = await response.json();
    }
}, 250);

watch(
    () => [
        promptLength.value,
        form.size,
        form.quality,
        references.value.length,
    ],
    refreshEstimate,
    { immediate: true },
);

const canAfford = computed(
    () =>
        estimate.value === null ||
        Number(props.balance) >= Number(estimate.value.maximum),
);

function addReference() {
    if (references.value.length < MAX_REFERENCES) {
        references.value.push('');
    }
}

function removeReference(index: number) {
    references.value.splice(index, 1);
}

function submit() {
    form.reference_images = [...references.value];
    form.submit(GenerationController.store(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt'),
    });
}

function buyCredits(dollars: number) {
    router.post(
        CreditController.store.url(),
        { dollars },
        { preserveScroll: true },
    );
}

const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'hasPending', 'balance'] },
    { autoStart: false },
);

watch(
    () => props.hasPending,
    (pending) => (pending ? start() : stop()),
    { immediate: true },
);

const referenceError = (index: number) =>
    (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
</script>

<template>
    <Head title="Generate" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <section
            class="grid gap-6 rounded-xl border border-sidebar-border/70 p-6 lg:grid-cols-[1fr_18rem] dark:border-sidebar-border"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs text-muted-foreground">
                            {{ promptLength }} / {{ MAX_PROMPT }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        :maxlength="MAX_PROMPT"
                        rows="4"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label>Size</Label>
                        <Select v-model="form.size">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="size in sizes"
                                    :key="size"
                                    :value="size"
                                >
                                    {{ size }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.size" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Quality</Label>
                        <Select v-model="form.quality">
                            <SelectTrigger class="w-full capitalize">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="quality in qualities"
                                    :key="quality"
                                    :value="quality"
                                    class="capitalize"
                                >
                                    {{ quality }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images (optional)</Label>
                    <div
                        v-for="(_, index) in references"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                v-model="references[index]"
                                type="url"
                                :placeholder="REFERENCE_PLACEHOLDER"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReference(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError :message="referenceError(index)" />
                    </div>
                    <Button
                        v-if="references.length < MAX_REFERENCES"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        @click="addReference"
                    >
                        <Plus /> Add reference image
                    </Button>
                    <InputError :message="form.errors.reference_images" />
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || !form.prompt.trim() || !canAfford
                        "
                    >
                        Generate
                    </Button>
                    <p v-if="!canAfford" class="text-sm text-destructive">
                        Not enough credits for this image. Buy credits to
                        continue.
                    </p>
                </div>
            </form>

            <aside class="flex flex-col gap-6 lg:border-l lg:pl-6">
                <div class="grid gap-1">
                    <p class="text-sm text-muted-foreground">Estimated cost</p>
                    <p
                        class="text-3xl font-semibold tabular-nums"
                        :title="exactUsd(estimate?.expected ?? null)"
                    >
                        {{ formatUsd(estimate?.expected ?? null) }}
                    </p>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        Between {{ formatUsd(estimate?.minimum ?? null) }} and
                        {{ formatUsd(estimate?.maximum ?? null) }}
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        The exact price depends on how long the model thinks, so
                        it is only known once the image is ready. You pay the
                        exact price, never more than the maximum, and nothing if
                        it fails.
                    </p>
                </div>

                <div class="grid gap-2 border-t pt-6">
                    <p class="text-sm text-muted-foreground">Your credits</p>
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        :title="exactUsd(balance)"
                    >
                        {{ formatUsd(balance, 2) }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="dollars in creditPackages"
                            :key="dollars"
                            variant="outline"
                            size="sm"
                            @click="buyCredits(dollars)"
                        >
                            Buy ${{ dollars }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Test purchase, no payment needed.
                    </p>
                </div>
            </aside>
        </section>

        <section class="grid gap-4">
            <h2 class="text-lg font-semibold">Your images</h2>
            <p
                v-if="generations.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing yet. Write a prompt above to generate your first image.
            </p>
            <div
                v-else
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
        </section>
    </div>
</template>
