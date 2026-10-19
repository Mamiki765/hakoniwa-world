<script setup lang="ts">
import { ref } from 'vue';
import { api, validationErrors } from '../api/client';
import type { InquirySubmission, InquirySummary } from '../types';

const emit = defineEmits<{ home: []; message: [text: string] }>();

const busy = ref(false);
const category = ref<InquirySummary['category']>('bug');
const subject = ref('');
const body = ref('');
const attachment = ref<HTMLInputElement | null>(null);
const submissionKey = ref(crypto.randomUUID());
const errors = ref<Record<string, string>>({});
const confirmation = ref<InquirySubmission | null>(null);

async function submit(): Promise<void> {
    busy.value = true;
    emit('message', '');
    errors.value = {};
    const form = new FormData();
    form.append('submission_key', submissionKey.value);
    form.append('category', category.value);
    form.append('subject', subject.value);
    form.append('body', body.value);
    const file = attachment.value?.files?.[0];
    if (file !== undefined) form.append('attachment', file);

    try {
        confirmation.value = await api<InquirySubmission>('/api/v1/inquiries', {
            method: 'POST',
            body: form,
        });
        submissionKey.value = crypto.randomUUID();
    } catch (error) {
        errors.value = validationErrors(error);
        if (Object.keys(errors.value).length === 0) {
            emit('message', error instanceof Error ? error.message : 'お問い合わせを送信できませんでした。');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="panel inquiry-page">
        <h1>お問い合わせ</h1>
        <div v-if="confirmation" class="inquiry-confirmation" role="status">
            <h2>送信しました</h2>
            <p>管理番号 <strong>{{ confirmation.management_id }}</strong></p>
            <button type="button" @click="emit('home')">TOPへ戻る</button>
        </div>
        <form v-else class="inquiry-form" enctype="multipart/form-data" @submit.prevent="submit">
            <label>種類
                <select v-model="category" required>
                    <option value="bug">バグ報告</option>
                    <option value="request">要望</option>
                    <option value="idea">アイデア</option>
                    <option value="secretary_fan_art">秘書のファンアート</option>
                    <option value="other">その他</option>
                </select>
                <span v-if="errors.category" class="field-error" role="alert">{{ errors.category }}</span>
            </label>
            <label>件名
                <input v-model="subject" maxlength="160" required>
                <span v-if="errors.subject" class="field-error" role="alert">{{ errors.subject }}</span>
            </label>
            <label>本文（プレーンテキスト）
                <textarea v-model="body" maxlength="20000" rows="10" required></textarea>
                <span v-if="errors.body" class="field-error" role="alert">{{ errors.body }}</span>
            </label>
            <label>添付画像（任意・1枚・最大10MB）
                <input ref="attachment" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                <small class="field-hint">PNG、JPEG、WebP、GIF。添付画像に個人情報などを含む画像は添付しないでください。</small>
                <span v-if="errors.attachment" class="field-error" role="alert">{{ errors.attachment }}</span>
            </label>
            <div class="inquiry-actions">
                <button class="button primary" type="submit" :disabled="busy">送信</button>
                <button type="button" :disabled="busy" @click="emit('home')">キャンセル</button>
            </div>
        </form>
    </section>
</template>
