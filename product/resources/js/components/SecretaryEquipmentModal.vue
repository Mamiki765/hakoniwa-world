<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { SecretaryEquipmentOptions } from '../types';

const props = defineProps<{
    targetSlot: number;
    options: SecretaryEquipmentOptions | null;
    loading: boolean;
    submitting: boolean;
    error: string;
    requireFreshChoice: boolean;
}>();

const emit = defineEmits<{
    close: [];
    submit: [itemId: number | null];
    selectionChange: [];
}>();

const dialog = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const selectedItemId = ref<number | null>(null);
const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

watch(
    () => props.options,
    (options) => {
        selectedItemId.value = options?.current_item?.id ?? null;
    },
    { immediate: true },
);

onMounted(async () => {
    await nextTick();
    closeButton.value?.focus();
});

onBeforeUnmount(() => previouslyFocused?.focus());

function requestClose(): void {
    if (!props.submitting) emit('close');
}

function select(itemId: number | null): void {
    selectedItemId.value = itemId;
    emit('selectionChange');
}

function submit(): void {
    if (props.loading || props.submitting || props.options === null || props.requireFreshChoice) return;
    emit('submit', selectedItemId.value);
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        requestClose();
        return;
    }
    if (event.key !== 'Tab' || dialog.value === null) return;

    const focusable = Array.from(dialog.value.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ));
    if (focusable.length === 0) return;
    const first = focusable[0]!;
    const last = focusable.at(-1)!;
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <div class="modal-backdrop equipment-modal-backdrop" @click.self="requestClose">
        <section
            ref="dialog"
            class="equipment-modal"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="`equipment-modal-title-${targetSlot}`"
            @keydown="handleKeydown"
        >
            <header class="equipment-modal-header">
                <h2 :id="`equipment-modal-title-${targetSlot}`">装備 slot {{ targetSlot }}</h2>
                <button ref="closeButton" class="equipment-modal-close" type="button" aria-label="装備選択を閉じる" :disabled="submitting" @click="requestClose">
                    ×
                </button>
            </header>

            <div v-if="loading" class="equipment-modal-status" role="status">装備候補を読み込んでいます…</div>
            <template v-else-if="options">
                <div class="equipment-options-scroll" data-native-scroll="true">
                    <label class="equipment-option-row">
                        <input
                            type="radio"
                            name="secretary-equipment-item"
                            :checked="selectedItemId === null"
                            :disabled="submitting"
                            @change="select(null)"
                        >
                        <span><strong>外す</strong></span>
                    </label>
                    <label v-for="item in options.items" :key="item.id" class="equipment-option-row">
                        <input
                            type="radio"
                            name="secretary-equipment-item"
                            :value="item.id"
                            :checked="selectedItemId === item.id"
                            :disabled="submitting"
                            @change="select(item.id)"
                        >
                        <span>
                            <strong>{{ item.name }}</strong>
                            <small>Lv{{ item.level }}・{{ item.category_label }}</small>
                        </span>
                    </label>
                </div>
                <p v-if="requireFreshChoice" class="equipment-modal-notice" role="status">装備状態が更新されました。最新の候補から選び直してください。</p>
            </template>
            <p v-if="error" class="field-error equipment-modal-error" role="alert">{{ error }}</p>

            <footer class="equipment-modal-footer">
                <button
                    class="button primary"
                    type="button"
                    :disabled="loading || submitting || options === null || requireFreshChoice"
                    @click="submit"
                >
                    {{ submitting ? '変更中…' : '変更する' }}
                </button>
            </footer>
        </section>
    </div>
</template>
