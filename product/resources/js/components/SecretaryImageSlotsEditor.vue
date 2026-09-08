<script setup lang="ts">
import { ref } from 'vue';
import { api } from '../api/client';
import type { SecretaryProfile, SecretaryImageSlot } from '../types';

const slots: SecretaryImageSlot['slot'][] = ['icon', 'bust', 'full_body', 'awakening_icon', 'awakening_bust', 'awakening_full_body'];
const labels: Record<SecretaryImageSlot['slot'], string> = { icon: '通常 icon', bust: '通常 bust', full_body: '通常 full body', awakening_icon: '覚醒 icon', awakening_bust: '覚醒 bust', awakening_full_body: '覚醒 full body' };
const props = defineProps<{ profile: SecretaryProfile; disabled?: boolean }>();
const emit = defineEmits<{ updated: [profile: SecretaryProfile] }>();
const files = ref<Partial<Record<SecretaryImageSlot['slot'], File | null>>>({});
const methods = ref<Record<SecretaryImageSlot['slot'], 'self_made' | 'ai_generated' | 'commissioned_or_permitted' | 'other'>>({ icon: 'self_made', bust: 'self_made', full_body: 'self_made', awakening_icon: 'self_made', awakening_bust: 'self_made', awakening_full_body: 'self_made' });
const credits = ref<Partial<Record<SecretaryImageSlot['slot'], string>>>({});
const saving = ref<string | null>(null);
const error = ref('');
const preference = ref<'full_body' | 'bust'>(props.profile.portrait_preference ?? 'full_body');
function select(slot: SecretaryImageSlot['slot'], event: Event): void { files.value[slot] = (event.target as HTMLInputElement).files?.[0] ?? null; }
async function save(slot: SecretaryImageSlot['slot']): Promise<void> {
    const file = files.value[slot]; if (!file || saving.value) return;
    saving.value = slot; error.value = '';
    try { const body = new FormData(); body.append('image', file); body.append('creation_method', methods.value[slot]); body.append('credit', credits.value[slot] ?? ''); const profile = await api<SecretaryProfile>(`/api/v1/me/secretary/images/${slot}`, { method: 'POST', body }); files.value[slot] = null; emit('updated', profile); }
    catch (caught) { error.value = caught instanceof Error ? caught.message : '画像を保存できませんでした。'; }
    finally { saving.value = null; }
}
function image(slot: SecretaryImageSlot['slot']): SecretaryImageSlot | null { return props.profile.images?.[slot] ?? null; }
async function savePreference(): Promise<void> {
    if (saving.value) return;
    saving.value = 'preference'; error.value = '';
    try { emit('updated', await api<SecretaryProfile>('/api/v1/me/secretary/portrait-preference', { method: 'PATCH', body: JSON.stringify({ portrait_preference: preference.value }) })); }
    catch (caught) { error.value = caught instanceof Error ? caught.message : '表示優先設定を保存できませんでした。'; }
    finally { saving.value = null; }
}
</script>
<template>
    <section class="secretary-image-slots" aria-labelledby="secretary-image-slots-title">
        <h3 id="secretary-image-slots-title">画像6スロット</h3>
        <p class="field-hint">iconは1:1、bust/full bodyはどちらも3:4。覚醒画像が未登録なら同じ種類の通常画像を表示します。制作方法と作者・権利表記は画像ごとに保存します。</p>
        <div class="secretary-portrait-preference">
            <label>戦闘portraitの優先 <select v-model="preference" :disabled="disabled || saving !== null"><option value="full_body">full body</option><option value="bust">bust</option></select></label>
            <button type="button" :disabled="disabled || saving !== null" @click="savePreference">表示優先を保存</button>
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <div class="secretary-image-slots-grid">
            <article v-for="slot in slots" :key="slot" class="secretary-image-slot" :data-slot="slot">
                <header><strong>{{ labels[slot] }}</strong><small>{{ image(slot)?.url ? '登録済み' : '未登録' }}</small></header>
                <div class="secretary-image-slot-preview">
                    <img v-if="image(slot)?.url" :src="image(slot)!.url!" :alt="`${labels[slot]}プレビュー`">
                    <span v-else>未登録</span>
                </div>
                <dl v-if="image(slot)?.url" class="secretary-image-slot-metadata">
                    <div><dt>制作方法</dt><dd>{{ image(slot)?.creation_method_label }}</dd></div>
                    <div><dt>作者・権利表記</dt><dd>{{ image(slot)?.credit }}</dd></div>
                </dl>
                <label><span>画像</span><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" :disabled="disabled || saving !== null" @change="select(slot, $event)"></label>
                <label><span>制作方法</span><select v-model="methods[slot]" :disabled="disabled || saving !== null"><option value="self_made">自作</option><option value="ai_generated">AI生成</option><option value="commissioned_or_permitted">依頼・使用許諾済み</option><option value="other">その他</option></select></label>
                <label><span>作者・権利表記</span><input v-model="credits[slot]" maxlength="160" placeholder="画像ごとのcredit" :disabled="disabled || saving !== null"></label>
                <button type="button" :disabled="disabled || saving !== null || !files[slot]" @click="save(slot)">{{ saving === slot ? '保存中…' : 'このslotを保存' }}</button>
            </article>
        </div>
    </section>
</template>
