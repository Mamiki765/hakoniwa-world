<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import type { Nation } from '../types';

const props = defineProps<{ nationId: number; comment: string }>();
const emit = defineEmits<{ saved: [comment: string] }>();
const editing = ref(false);
const draft = ref('');
const busy = ref(false);
const error = ref('');
const input = ref<HTMLInputElement | null>(null);
watch(() => props.nationId, () => { editing.value = false; error.value = ''; });

async function edit(): Promise<void> {
    draft.value = props.comment;
    error.value = '';
    editing.value = true;
    await nextTick();
    input.value?.focus();
}
async function save(): Promise<void> {
    if (busy.value || /[\r\n]/.test(draft.value) || [...draft.value].length > 100) return;
    const nationId = props.nationId;
    busy.value = true;
    error.value = '';
    try {
        const updated = await api<Nation>(`/api/v1/nations/${nationId}/profile`, {
            method: 'PATCH', body: JSON.stringify({ comment: draft.value }),
        });
        if (nationId !== props.nationId) return;
        emit('saved', updated.comment);
        editing.value = false;
    } catch (reason) {
        if (nationId !== props.nationId) return;
        error.value = reason instanceof ApiError && reason.errors.comment?.length
            ? reason.errors.comment.join(' ') : '一言コメントを保存できませんでした。入力は保持しています。';
    } finally { busy.value = false; }
}
</script>

<template>
    <span class="nation-inline-comment">
        <button v-if="!editing" type="button" aria-label="一言コメントを編集" @click="edit"><span class="comment-desktop">{{ comment || '一言コメントを書く' }}</span><span class="comment-mobile">一言</span></button>
        <form v-else aria-label="一言コメント" @submit.prevent="save">
            <input ref="input" v-model="draft" aria-label="一言コメント本文" maxlength="100" :disabled="busy" @keydown.esc.prevent="editing = false" @paste="error = ''">
            <button type="submit" :disabled="busy || /[\r\n]/.test(draft) || [...draft].length > 100">保存</button>
            <button type="button" :disabled="busy" @click="editing = false">取消</button>
            <small v-if="error" role="alert">{{ error }}</small>
        </form>
    </span>
</template>
