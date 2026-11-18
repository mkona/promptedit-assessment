<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { exactUsd, formatUsd } from '@/lib/money';
import { report } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

type Totals = {
    generations: number;
    spend: string;
    cost: string;
    profit: string;
};

type UserRow = Totals & { id: number; name: string; email: string };

type DayRow = Totals & {
    day: string;
    provider_reported_cost: string | null;
    matches_provider: boolean | null;
    users: UserRow[];
};

const props = defineProps<{
    from: string;
    to: string;
    days: DayRow[];
    totals: Totals & { provider_reported_cost: string | null };
    providerUnavailable: boolean;
}>();

const from = ref(props.from);
const to = ref(props.to);

function apply() {
    router.get(ReportController.url(), { from: from.value, to: to.value });
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Credits, costs and profit</h1>
                <p class="text-sm text-muted-foreground">
                    Per UTC day the generation completed, matching how
                    Pixelforge bills. Hover an amount for the exact value.
                </p>
            </div>
            <form class="flex items-end gap-2" @submit.prevent="apply">
                <div class="grid gap-1">
                    <Label for="from">From</Label>
                    <Input id="from" v-model="from" type="date" />
                </div>
                <div class="grid gap-1">
                    <Label for="to">To</Label>
                    <Input id="to" v-model="to" type="date" />
                </div>
                <Button type="submit" variant="outline">Show</Button>
            </form>
        </div>

        <dl class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Generations</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ totals.generations }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Credit spend</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="exactUsd(totals.spend)"
                >
                    {{ formatUsd(totals.spend) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="exactUsd(totals.cost)"
                >
                    {{ formatUsd(totals.cost) }}
                </dd>
                <dd
                    v-if="totals.provider_reported_cost !== null"
                    class="text-xs text-muted-foreground"
                    :title="exactUsd(totals.provider_reported_cost)"
                >
                    Pixelforge reports
                    {{ formatUsd(totals.provider_reported_cost) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd
                    class="text-2xl font-semibold tabular-nums"
                    :title="exactUsd(totals.profit)"
                >
                    {{ formatUsd(totals.profit) }}
                </dd>
            </div>
        </dl>

        <p v-if="providerUnavailable" class="text-sm text-destructive">
            Pixelforge's usage report couldn't be loaded, so provider costs
            aren't compared right now.
        </p>

        <p v-if="days.length === 0" class="text-sm text-muted-foreground">
            No completed generations in this period.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm tabular-nums">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Day / user</th>
                        <th class="px-4 py-2 text-right font-medium">Images</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Credit spend
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Pixelforge check
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="day in days" :key="day.day">
                        <tr class="border-t bg-muted/20 font-medium">
                            <td class="px-4 py-2">{{ day.day }}</td>
                            <td class="px-4 py-2 text-right">
                                {{ day.generations }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(day.spend)"
                            >
                                {{ formatUsd(day.spend) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(day.cost)"
                            >
                                {{ formatUsd(day.cost) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(day.profit)"
                            >
                                {{ formatUsd(day.profit) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(day.provider_reported_cost)"
                            >
                                <span
                                    v-if="day.matches_provider === true"
                                    class="text-green-700 dark:text-green-400"
                                >
                                    Matches
                                </span>
                                <span
                                    v-else-if="day.matches_provider === false"
                                    class="text-destructive"
                                >
                                    Pixelforge:
                                    {{ formatUsd(day.provider_reported_cost) }}
                                </span>
                                <span v-else class="text-muted-foreground">
                                    –
                                </span>
                            </td>
                        </tr>
                        <tr
                            v-for="user in day.users"
                            :key="`${day.day}-${user.id}`"
                            class="border-t"
                        >
                            <td class="px-4 py-2 pl-8">
                                {{ user.name }}
                                <span class="text-muted-foreground">
                                    {{ user.email }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(user.spend)"
                            >
                                {{ formatUsd(user.spend) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(user.cost)"
                            >
                                {{ formatUsd(user.cost) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :title="exactUsd(user.profit)"
                            >
                                {{ formatUsd(user.profit) }}
                            </td>
                            <td />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
