<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { api, validationErrors } from '../api/client';
import type { Announcement } from '../types';
import AnnouncementBody from './AnnouncementBody.vue';

// The announcement to edit, or null to write a new one.
const props = defineProps<{ announcement: Announcement | null }>();
const emit = defineEmits<{
    saved: [announcement: Announcement];
    cancel: [];
    message: [text: string];
}>();
// Shared with the page so the global loading status and other buttons follow saves.
const busy = defineModel<boolean>('busy', { required: true });
let requestPending = false;
let disposed = false;
onBeforeUnmount(() => {
    disposed = true;
    // Model updates no longer reach the page after this editor is unmounted.
    if (requestPending) busy.value = false;
});

function finishRequest(): void {
    requestPending = false;
    // A late response must not unlock a replacement editor's request.
    if (!disposed) busy.value = false;
}

const title = ref(props.announcement?.title ?? '');
const body = ref(props.announcement?.body ?? '');
const bodyFormat = ref<Announcement['body_format']>(props.announcement === null ? 'markdown' : (props.announcement.body_format ?? 'plain_text'));
const previewHtml = ref<string | null>(null);
const previewVisible = ref(false);
const errors = ref<Record<string, string>>({});
watch([body, bodyFormat], () => {
    previewVisible.value = false;
    previewHtml.value = null;
});

async function save(): Promise<void> {
    if (busy.value || requestPending) return;
    requestPending = true;
    busy.value = true;
    emit('message', '');
    errors.value = {};
    const id = props.announcement?.id ?? null;
    try {
        const saved = await api<Announcement>(id === null
            ? '/api/v1/admin/announcements'
            : `/api/v1/admin/announcements/${id}`, {
            method: id === null ? 'POST' : 'PATCH',
            body: JSON.stringify({ title: title.value, body: body.value, body_format: bodyFormat.value }),
        });
        emit('saved', saved);
    } catch (error) {
        errors.value = validationErrors(error);
        if (Object.keys(errors.value).length === 0) {
            emit('message', error instanceof Error ? error.message : 'お知らせを保存できませんでした。');
        }
    } finally {
        finishRequest();
    }
}

async function preview(): Promise<void> {
    if (busy.value || requestPending) return;
    requestPending = true;
    const requestedBody = body.value;
    const requestedFormat = bodyFormat.value;
    busy.value = true;
    emit('message', '');
    errors.value = {};
    try {
        const result = await api<{ body_html: string | null }>('/api/v1/admin/announcements/preview', {
            method: 'POST',
            body: JSON.stringify({ body: requestedBody, body_format: requestedFormat }),
        });
        if (body.value === requestedBody && bodyFormat.value === requestedFormat) {
            previewHtml.value = result.body_html;
            previewVisible.value = true;
        }
    } catch (error) {
        errors.value = validationErrors(error);
        if (Object.keys(errors.value).length === 0) {
            emit('message', error instanceof Error ? error.message : 'プレビューを表示できませんでした。');
        }
    } finally {
        finishRequest();
    }
}
</script>

<template>
    <form class="announcement-form" @submit.prevent="save">
        <h2>{{ announcement === null ? 'お知らせを作成' : 'お知らせを編集' }}</h2>
        <label>題名
            <input v-model="title" maxlength="160" required>
            <span v-if="errors.title" class="field-error" role="alert">{{ errors.title }}</span>
        </label>
        <label>本文の形式
            <select v-model="bodyFormat">
                <option value="markdown">Markdown（文字の装飾・箇条書き）</option>
                <option value="plain_text">プレーンテキスト（入力したまま）</option>
            </select>
            <span v-if="errors.body_format" class="field-error" role="alert">{{ errors.body_format }}</span>
        </label>
        <label>本文
            <textarea v-model="body" aria-describedby="announcement-format-help" maxlength="20000" rows="12" required></textarea>
            <span v-if="errors.body" class="field-error" role="alert">{{ errors.body }}</span>
        </label>
        <details v-if="bodyFormat === 'markdown'" id="announcement-format-help" class="announcement-format-help">
            <summary>書き方の例（Discordでも使える書式）</summary>
            <ul>
                <li><code>**太字**</code> → <strong>太字</strong></li>
                <li><code>## 見出し</code> → 見出し</li>
                <li><code>- 項目</code> → 箇条書き</li>
                <li><code>~~取り消し~~</code> → <s>取り消し</s></li>
                <li><code>[表示名](https://example.com)</code> → リンク</li>
            </ul>
            <p>改行はそのまま表示されます。保存前にプレビューで確認できます。</p>
        </details>
        <p v-else id="announcement-format-help" class="announcement-format-help">文字の装飾はせず、入力した内容と改行をそのまま表示します。</p>
        <div class="announcement-actions">
            <button type="button" :disabled="busy || !body.trim()" @click="preview">プレビュー</button>
            <button class="button primary" type="submit" :disabled="busy">保存</button>
            <button type="button" @click="emit('cancel')">キャンセル</button>
        </div>
        <section v-if="previewVisible" class="announcement-preview" aria-label="お知らせのプレビュー">
            <p class="eyebrow">プレビュー（まだ保存されていません）</p>
            <h2>{{ title }}</h2>
            <AnnouncementBody :body="body" :format="bodyFormat" :html="previewHtml" />
        </section>
    </form>
</template>
