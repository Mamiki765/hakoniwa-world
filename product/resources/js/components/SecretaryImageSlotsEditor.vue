<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '../api/client';
import type { SecretaryProfile, SecretaryImageSlot } from '../types';

type ImageSlot = SecretaryImageSlot['slot'];
type CreationMethod = Exclude<SecretaryImageSlot['creation_method'], null>;

const slots: ImageSlot[] = ['icon', 'bust', 'full_body', 'awakening_icon', 'awakening_bust', 'awakening_full_body'];
const labels: Record<ImageSlot, string> = { icon: '通常アイコン', bust: '通常バストアップ', full_body: '通常全身', awakening_icon: '覚醒アイコン', awakening_bust: '覚醒バストアップ', awakening_full_body: '覚醒全身' };
const props = defineProps<{ profile: SecretaryProfile; disabled?: boolean }>();
const emit = defineEmits<{ updated: [profile: SecretaryProfile] }>();
const files = ref<Partial<Record<ImageSlot, File | null>>>({});
const methods = ref<Record<ImageSlot, CreationMethod>>({
    icon: 'self_made',
    bust: 'self_made',
    full_body: 'self_made',
    awakening_icon: 'self_made',
    awakening_bust: 'self_made',
    awakening_full_body: 'self_made',
});
const credits = ref<Partial<Record<ImageSlot, string>>>({});
const saving = ref<string | null>(null);
const error = ref('');
const preference = ref<'full_body' | 'bust'>(props.profile.portrait_preference ?? 'full_body');

function syncMetadata(profile: SecretaryProfile): void {
    for (const slot of slots) {
        const image = profile.images?.[slot];
        const editable = image?.editable_metadata;
        methods.value[slot] = editable?.creation_method ?? image?.creation_method ?? 'self_made';
        credits.value[slot] = editable?.credit ?? image?.credit ?? '';
    }
    preference.value = profile.portrait_preference ?? 'full_body';
}

watch(() => props.profile, (profile) => syncMetadata(profile), { immediate: true });

function image(slot: ImageSlot): SecretaryImageSlot | null { return props.profile.images?.[slot] ?? null; }

function hasEditableImage(slot: ImageSlot): boolean {
    const current = image(slot);
    return current?.url != null || current?.editable_metadata != null;
}

function select(slot: ImageSlot, event: Event): void {
    files.value[slot] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function save(slot: ImageSlot): Promise<void> {
    const file = files.value[slot] ?? null;
    if ((!file && !hasEditableImage(slot)) || saving.value) return;
    saving.value = slot; error.value = '';
    try {
        let profile: SecretaryProfile;
        if (file) {
            const body = new FormData();
            body.append('image', file);
            body.append('creation_method', methods.value[slot]);
            body.append('credit', credits.value[slot] ?? '');
            profile = await api<SecretaryProfile>(`/api/v1/me/secretary/images/${slot}`, { method: 'POST', body });
        } else {
            profile = await api<SecretaryProfile>(`/api/v1/me/secretary/images/${slot}`, {
                method: 'PATCH',
                body: JSON.stringify({ creation_method: methods.value[slot], credit: credits.value[slot] ?? '' }),
            });
        }
        files.value[slot] = null;
        emit('updated', profile);
    }
    catch (caught) { error.value = caught instanceof Error ? caught.message : '画像を保存できませんでした。'; }
    finally { saving.value = null; }
}

async function remove(slot: ImageSlot): Promise<void> {
    if (saving.value || !hasEditableImage(slot) || !window.confirm(`${labels[slot]}を削除しますか？`)) return;
    saving.value = slot; error.value = '';
    try {
        emit('updated', await api<SecretaryProfile>(`/api/v1/me/secretary/images/${slot}`, { method: 'DELETE' }));
        files.value[slot] = null;
    }
    catch (caught) { error.value = caught instanceof Error ? caught.message : '画像を削除できませんでした。'; }
    finally { saving.value = null; }
}

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
        <h3 id="secretary-image-slots-title">秘書画像</h3>
        <p class="field-hint">アイコンは1:1、バストアップと全身は3:4です。覚醒画像がない場合は通常画像を表示します。全身画像がない場合も、登録済みのバストアップを大きな表示に使用します。</p>
        <div class="secretary-portrait-preference">
            <label>大きな画像で優先するもの <select v-model="preference" :disabled="disabled || saving !== null"><option value="full_body">全身</option><option value="bust">バストアップ</option></select></label>
            <button type="button" :disabled="disabled || saving !== null" @click="savePreference">表示優先を保存</button>
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <div class="secretary-image-slots-grid">
            <article v-for="slot in slots" :key="slot" class="secretary-image-slot" :data-slot="slot">
                <header><strong>{{ labels[slot] }}</strong><span class="secretary-image-slot-state"><small>{{ hasEditableImage(slot) ? '登録済み' : '未登録' }}</small><button v-if="hasEditableImage(slot)" type="button" class="secretary-image-delete" :aria-label="`${labels[slot]}を削除`" title="画像を削除" :disabled="disabled || saving !== null" @click="remove(slot)">×</button></span></header>
                <div class="secretary-image-slot-preview">
                    <img v-if="image(slot)?.url" :src="image(slot)!.url!" :alt="`${labels[slot]}プレビュー`">
                    <span v-else>{{ image(slot)?.editable_metadata ? '表示設定により非表示' : '未登録' }}</span>
                </div>
                <dl v-if="hasEditableImage(slot)" class="secretary-image-slot-metadata">
                    <div><dt>制作方法</dt><dd>{{ image(slot)?.editable_metadata?.creation_method_label ?? image(slot)?.creation_method_label }}</dd></div>
                    <div><dt>作者・権利表記</dt><dd>{{ image(slot)?.editable_metadata?.credit ?? image(slot)?.credit }}</dd></div>
                </dl>
                <label><span>画像</span><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" :disabled="disabled || saving !== null" @change="select(slot, $event)"></label>
                <label><span>制作方法</span><select v-model="methods[slot]" :disabled="disabled || saving !== null"><option value="self_made">自作</option><option value="ai_generated">AI生成</option><option value="commissioned_or_permitted">依頼・使用許諾済み</option><option value="other">その他</option></select></label>
                <label><span>作者・権利表記</span><input v-model="credits[slot]" maxlength="160" placeholder="作者名・権利表記" :disabled="disabled || saving !== null"></label>
                <button type="button" :disabled="disabled || saving !== null || (!files[slot] && !hasEditableImage(slot))" @click="save(slot)">{{ saving === slot ? '保存中…' : (files[slot] ? '画像を保存' : '表記を保存') }}</button>
            </article>
        </div>
    </section>
</template>
