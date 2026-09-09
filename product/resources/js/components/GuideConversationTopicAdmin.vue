<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import type { GuideConversationTopic, GuideConversationTopicIndex } from '../types';

const emit = defineEmits<{ close: [] }>();
const topics = ref<GuideConversationTopic[]>([]);
const unlockOptions = ref<GuideConversationTopicIndex['unlock_options']>([]);
const loading = ref(false);
const saving = ref(false);
const message = ref('');
const errors = ref<Record<string, string>>({});
const editingId = ref<number | null>(null);
const initialLine = ref('');
const choice1 = ref('');
const reply1 = ref('');
const choice2 = ref('');
const reply2 = ref('');
const choice3 = ref('');
const reply3 = ref('');
const unlockKey = ref('always');
const enabled = ref(true);

onMounted(load);

async function load(): Promise<void> {
    loading.value = true;
    message.value = '';
    try {
        const result = await api<GuideConversationTopicIndex>('/api/v1/admin/guide-conversation-topics');
        topics.value = result.topics;
        unlockOptions.value = result.unlock_options;
    } catch (error) {
        message.value = error instanceof Error ? error.message : '会話トピックを読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function clearForm(): void {
    editingId.value = null;
    initialLine.value = '';
    choice1.value = '';
    reply1.value = '';
    choice2.value = '';
    reply2.value = '';
    choice3.value = '';
    reply3.value = '';
    unlockKey.value = 'always';
    enabled.value = true;
    errors.value = {};
}

function edit(topic: GuideConversationTopic): void {
    editingId.value = topic.id;
    initialLine.value = topic.initial_line;
    choice1.value = topic.choice_1;
    reply1.value = topic.reply_1;
    choice2.value = topic.choice_2 ?? '';
    reply2.value = topic.reply_2 ?? '';
    choice3.value = topic.choice_3 ?? '';
    reply3.value = topic.reply_3 ?? '';
    unlockKey.value = topic.unlock_key;
    enabled.value = topic.enabled;
    errors.value = {};
    message.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function save(): Promise<void> {
    if (saving.value) return;
    saving.value = true;
    message.value = '';
    errors.value = {};
    const id = editingId.value;
    try {
        await api<GuideConversationTopic>(id === null
            ? '/api/v1/admin/guide-conversation-topics'
            : `/api/v1/admin/guide-conversation-topics/${id}`, {
            method: id === null ? 'POST' : 'PATCH',
            body: JSON.stringify({
                initial_line: initialLine.value,
                choice_1: choice1.value,
                reply_1: reply1.value,
                choice_2: choice2.value === '' ? null : choice2.value,
                reply_2: reply2.value === '' ? null : reply2.value,
                choice_3: choice3.value === '' ? null : choice3.value,
                reply_3: reply3.value === '' ? null : reply3.value,
                unlock_key: unlockKey.value,
                enabled: enabled.value,
            }),
        });
        clearForm();
        await load();
        message.value = id === null ? '会話トピックを登録しました。' : '会話トピックを更新しました。';
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = Object.fromEntries(
                Object.entries(error.errors).map(([key, values]) => [key, values[0] ?? '入力を確認してください。']),
            );
        }
        if (Object.keys(errors.value).length === 0) {
            message.value = error instanceof Error ? error.message : '会話トピックを保存できませんでした。';
        }
    } finally {
        saving.value = false;
    }
}

async function remove(topic: GuideConversationTopic): Promise<void> {
    if (!window.confirm('この会話トピックを削除しますか？')) return;
    loading.value = true;
    message.value = '';
    try {
        await api<null>(`/api/v1/admin/guide-conversation-topics/${topic.id}`, { method: 'DELETE' });
        if (editingId.value === topic.id) clearForm();
        await load();
        message.value = '会話トピックを削除しました。';
    } catch (error) {
        message.value = error instanceof Error ? error.message : '会話トピックを削除できませんでした。';
    } finally {
        loading.value = false;
    }
}

function unlockLabel(key: string): string {
    return unlockOptions.value.find((option) => option.key === key)?.label ?? key;
}
</script>

<template>
    <section class="guide-topic-admin panel" aria-labelledby="guide-topic-admin-title">
        <header class="guide-topic-admin-heading">
            <div><p class="eyebrow">GUIDE CONVERSATIONS</p><h1 id="guide-topic-admin-title">案内人の会話管理</h1></div>
            <button type="button" @click="emit('close')">TOPへ戻る</button>
        </header>

        <p v-if="message" :class="{ 'field-error': Object.keys(errors).length === 0 && !message.endsWith('ました。') }" role="status">{{ message }}</p>

        <form class="guide-topic-form" @submit.prevent="save">
            <h2>{{ editingId === null ? '新しい話題' : `話題 #${editingId} を編集` }}</h2>
            <label>最初の台詞
                <textarea v-model="initialLine" maxlength="4000" rows="4" required></textarea>
                <span v-if="errors.initial_line" class="field-error" role="alert">{{ errors.initial_line }}</span>
            </label>
            <fieldset>
                <legend>選択肢1（必須）</legend>
                <label>選択肢<textarea v-model="choice1" maxlength="1000" rows="2" required></textarea><span v-if="errors.choice_1" class="field-error" role="alert">{{ errors.choice_1 }}</span></label>
                <label>返答<textarea v-model="reply1" maxlength="4000" rows="3" required></textarea><span v-if="errors.reply_1" class="field-error" role="alert">{{ errors.reply_1 }}</span></label>
            </fieldset>
            <fieldset>
                <legend>選択肢2（任意・両方入力）</legend>
                <label>選択肢<textarea v-model="choice2" maxlength="1000" rows="2"></textarea><span v-if="errors.choice_2" class="field-error" role="alert">{{ errors.choice_2 }}</span></label>
                <label>返答<textarea v-model="reply2" maxlength="4000" rows="3"></textarea><span v-if="errors.reply_2" class="field-error" role="alert">{{ errors.reply_2 }}</span></label>
            </fieldset>
            <fieldset>
                <legend>選択肢3（任意・両方入力）</legend>
                <label>選択肢<textarea v-model="choice3" maxlength="1000" rows="2"></textarea><span v-if="errors.choice_3" class="field-error" role="alert">{{ errors.choice_3 }}</span></label>
                <label>返答<textarea v-model="reply3" maxlength="4000" rows="3"></textarea><span v-if="errors.reply_3" class="field-error" role="alert">{{ errors.reply_3 }}</span></label>
            </fieldset>
            <div class="guide-topic-settings">
                <label>解放条件
                    <select v-model="unlockKey" required>
                        <option v-for="option in unlockOptions" :key="option.key" :value="option.key">{{ option.label }}</option>
                    </select>
                    <span v-if="errors.unlock_key" class="field-error" role="alert">{{ errors.unlock_key }}</span>
                </label>
                <label class="guide-topic-enabled"><input v-model="enabled" type="checkbox"> プレイヤーへ表示する</label>
            </div>
            <div class="guide-topic-actions">
                <button class="button primary" type="submit" :disabled="saving || loading">{{ editingId === null ? '登録' : '更新' }}</button>
                <button v-if="editingId !== null" type="button" :disabled="saving" @click="clearForm">新規入力へ戻る</button>
            </div>
        </form>

        <section class="guide-topic-list" aria-labelledby="guide-topic-list-title">
            <h2 id="guide-topic-list-title">登録済み話題</h2>
            <p v-if="loading">読み込み中…</p>
            <p v-else-if="topics.length === 0" class="empty-state">会話トピックはまだありません。</p>
            <article v-for="topic in topics" v-else :key="topic.id" :data-enabled="topic.enabled">
                <header><strong>#{{ topic.id }} {{ unlockLabel(topic.unlock_key) }}</strong><span>{{ topic.enabled ? '表示中' : '停止中' }}</span></header>
                <p>{{ topic.initial_line }}</p>
                <div class="guide-topic-actions">
                    <button type="button" @click="edit(topic)">編集</button>
                    <button class="danger" type="button" @click="remove(topic)">削除</button>
                </div>
            </article>
        </section>
    </section>
</template>

<style scoped>
.guide-topic-admin { display: grid; gap: 1.25rem; color: var(--ink); }
.guide-topic-admin-heading, .guide-topic-list article header, .guide-topic-actions { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.guide-topic-form { display: grid; gap: 1rem; padding: 1rem; border: 1px solid var(--line); background: var(--paper); }
.guide-topic-form label, .guide-topic-form fieldset { display: grid; gap: .45rem; }
.guide-topic-form fieldset { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; padding: .8rem; border: 1px solid var(--line); }
.guide-topic-form legend { padding: 0 .35rem; font-weight: 800; }
.guide-topic-form textarea, .guide-topic-form select { width: 100%; box-sizing: border-box; padding: .7rem; border: 1px solid var(--input-border); background: var(--input-surface); color: var(--ink); font: inherit; resize: vertical; }
.guide-topic-settings { display: grid; grid-template-columns: minmax(12rem, 1fr) auto; align-items: end; gap: 1rem; }
.guide-topic-enabled { display: flex !important; grid-auto-flow: column; align-items: center; justify-content: start; }
.guide-topic-list { display: grid; gap: .75rem; }
.guide-topic-list article { display: grid; gap: .65rem; padding: 1rem; border: 1px solid var(--line); background: var(--paper); }
.guide-topic-list article[data-enabled="false"] { opacity: .7; }
.guide-topic-list p { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 720px) {
    .guide-topic-admin-heading, .guide-topic-list article header { align-items: stretch; flex-direction: column; }
    .guide-topic-form fieldset, .guide-topic-settings { grid-template-columns: 1fr; }
    .guide-topic-actions { flex-wrap: wrap; justify-content: flex-start; }
}
</style>
