<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';

const props = defineProps<{ itemName: string; effectText: string }>();
const wrapper = ref<HTMLElement | null>(null);
const button = ref<HTMLButtonElement | null>(null);
const popup = ref<HTMLElement | null>(null);
const open = ref(false);
const openReason = ref<'hover' | 'focus' | 'click' | null>(null);
const popupStyle = ref<Record<string, string>>({});
const popupId = useId();

async function positionPopup(): Promise<void> {
    await nextTick();
    if (!open.value || button.value === null || popup.value === null) return;

    const buttonRect = button.value.getBoundingClientRect();
    const popupRect = popup.value.getBoundingClientRect();
    const gutter = 8;
    const left = Math.min(
        Math.max(gutter, buttonRect.left),
        Math.max(gutter, window.innerWidth - popupRect.width - gutter),
    );
    const below = buttonRect.bottom + 4;
    const top = below + popupRect.height <= window.innerHeight - gutter
        ? below
        : Math.max(gutter, buttonRect.top - popupRect.height - 4);

    popupStyle.value = { left: `${left}px`, top: `${top}px` };
}

function show(reason: 'hover' | 'focus' | 'click'): void {
    open.value = true;
    openReason.value = reason;
    void positionPopup();
}

function hide(): void {
    open.value = false;
    openReason.value = null;
    popupStyle.value = {};
}

function toggle(): void {
    if (open.value && openReason.value === 'click') {
        hide();

        return;
    }

    show('click');
}

function onMouseleave(): void {
    if (wrapper.value?.contains(document.activeElement)) return;
    if (openReason.value !== 'click') hide();
}

function onFocusout(event: FocusEvent): void {
    const nextTarget = event.relatedTarget;
    if (nextTarget instanceof Node && wrapper.value?.contains(nextTarget)) return;
    if (wrapper.value?.matches(':hover')) return;
    hide();
}

function onOutsidePointerdown(event: PointerEvent): void {
    if (event.target instanceof Node && !wrapper.value?.contains(event.target)) hide();
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (!open.value || event.key !== 'Escape') return;

    event.preventDefault();
    const shouldRestoreFocus = openReason.value === 'focus' || openReason.value === 'click';
    hide();
    if (shouldRestoreFocus) button.value?.focus();
}

function updatePosition(): void {
    if (open.value) void positionPopup();
}

onMounted(() => {
    document.addEventListener('pointerdown', onOutsidePointerdown);
    document.addEventListener('keydown', onDocumentKeydown);
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onOutsidePointerdown);
    document.removeEventListener('keydown', onDocumentKeydown);
    window.removeEventListener('resize', updatePosition);
    window.removeEventListener('scroll', updatePosition, true);
});
</script>

<template>
    <span
        ref="wrapper"
        class="item-effect-info"
        @mouseenter="show('hover')"
        @mouseleave="onMouseleave"
        @focusout="onFocusout"
    >
        <button
            ref="button"
            class="item-effect-info-button"
            type="button"
            :aria-label="`${props.itemName}の効果を表示`"
            :aria-expanded="open"
            :aria-controls="popupId"
            @focus="show('focus')"
            @click.stop="toggle"
        >
            i
        </button>
        <span v-if="open" :id="popupId" ref="popup" class="item-effect-info-popup" role="tooltip" :style="popupStyle">
            {{ props.effectText }}
        </span>
    </span>
</template>
