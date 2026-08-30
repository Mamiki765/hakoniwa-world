<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { EquipmentItem } from './EquipmentItemCard.vue';

const props = defineProps<{
    item: EquipmentItem;
    title: string;
    message: string;
    submitting: boolean;
}>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
const dialog = ref<HTMLElement | null>(null);
const cancelButton = ref<HTMLButtonElement | null>(null);
const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

onMounted(async () => {
    await nextTick();
    cancelButton.value?.focus();
});

onBeforeUnmount(() => previouslyFocused?.focus());

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !props.submitting) {
        event.preventDefault();
        emit('cancel');
        return;
    }
    if (event.key !== 'Tab' || dialog.value === null) return;
    const focusable = Array.from(dialog.value.querySelectorAll<HTMLElement>('button:not([disabled])'));
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
    <div class="modal-backdrop underground-confirm-backdrop" @click.self="!submitting && emit('cancel')">
        <section ref="dialog" class="underground-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="underground-confirm-title" @keydown="handleKeydown">
            <header>
                <h2 id="underground-confirm-title">{{ title }}</h2>
                <button ref="cancelButton" class="equipment-modal-close" type="button" aria-label="確認を閉じる" :disabled="submitting" @click="emit('cancel')">×</button>
            </header>
            <p>{{ message }}</p>
            <p class="underground-confirm-item"><strong>{{ item.name }}</strong><span>売却価格 {{ item.sell_price.toLocaleString('ja-JP') }}G</span></p>
            <footer>
                <button class="button secondary" type="button" :disabled="submitting" @click="emit('cancel')">キャンセル</button>
                <button class="button primary" type="button" :disabled="submitting" @click="emit('confirm')">{{ submitting ? '処理中…' : '確定する' }}</button>
            </footer>
        </section>
    </div>
</template>
