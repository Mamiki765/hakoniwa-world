import { computed, ref } from 'vue';

export type StatKey = 'vitality' | 'might' | 'finesse' | 'spirit' | 'agility';
export type StpDraft = ReturnType<typeof useStpDraft>;

export const statLabels = { vitality: '生命', might: '武力', finesse: '技巧', spirit: '精神', agility: '敏捷' } as const;

const emptyDraft = (): Record<StatKey, number> => ({ vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 });

// The STP the player has spread across stats but not yet confirmed. It lives with the
// panel's state, not the status tab, so switching tabs keeps the draft.
export function useStpDraft(unspentStp: () => number) {
    const values = ref<Record<StatKey, number>>(emptyDraft());
    const total = computed(() => Object.values(values.value).reduce((sum, value) => sum + value, 0));
    const remaining = computed(() => Math.max(0, unspentStp() - total.value));

    function maximum(stat: StatKey): number {
        return Math.min(2_147_483_647, values.value[stat] + remaining.value);
    }

    function set(stat: StatKey, value: number): void {
        values.value = { ...values.value, [stat]: value };
    }

    function step(stat: StatKey, delta: number): boolean {
        const next = Math.max(0, Math.min(maximum(stat), values.value[stat] + delta));
        if (next === values.value[stat]) return false;
        set(stat, next);
        return true;
    }

    function setShare(stat: StatKey, share: 0.5 | 1): void {
        set(stat, Math.floor(maximum(stat) * share));
    }

    function setFromInput(stat: StatKey, event: Event): void {
        const input = event.currentTarget;
        if (!(input instanceof HTMLInputElement)) return;
        const current = values.value[stat];
        const parsed = input.value === '' ? 0 : Number(input.value);
        if (!Number.isSafeInteger(parsed) || parsed < 0) {
            input.value = String(current);
            return;
        }
        const next = Math.min(parsed, maximum(stat));
        set(stat, next);
        if (next !== parsed) input.value = String(next);
    }

    function allocations(): Partial<Record<StatKey, number>> {
        return Object.fromEntries(Object.entries(values.value).filter(([, value]) => value > 0));
    }

    function reset(): void {
        values.value = emptyDraft();
    }

    return { values, total, remaining, maximum, step, setShare, setFromInput, allocations, reset };
}
