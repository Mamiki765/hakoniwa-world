<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '../api/client';
import type { SecretaryProfile, SecretaryImageSlot } from '../types';

type ImageSlot = SecretaryImageSlot['slot'];
type CreationMethod = Exclude<SecretaryImageSlot['creation_method'], null>;

const slots: ImageSlot[] = ['icon', 'bust', 'full_body', 'awakening_icon', 'awakening_bust', 'awakening_full_body'];
const labels: Record<ImageSlot, string> = { icon: '通常 icon', bust: '通常 bust', full_body: '通常 full body', awakening_icon: '覚醒 icon', awakening_bust: '覚醒 bust', awakening_full_body: '覚醒 full body' };
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

function usesLegacyMainFallback(slot: ImageSlot): boolean {
    const current = image(slot);
    return slot === 'full_body'
        && current?.source === 'legacy_main';
}

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
            const endpoint = usesLegacyMainFallback(slot)
                ? '/api/v1/me/secretary/main-image'
                : `/api/v1/me/secretary/images/${slot}`;
            profile = await api<SecretaryProfile>(endpoint, {
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
        <p class="field-hint">iconは1:1、bust/full bodyはどちらも3:4。覚醒画像が未登録なら同じ種類の通常画像を表示します。制作方法と作者・権利表記は画像ごとに保存します。旧メイン画像はfull body未登録時のfallbackです。</p>
        <div class="secretary-portrait-preference">
            <label>戦闘portraitの優先 <select v-model="preference" :disabled="disabled || saving !== null"><option value="full_body">full body</option><option value="bust">bust</option></select></label>
            <button type="button" :disabled="disabled || saving !== null" @click="savePreference">表示優先を保存</button>
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <div class="secretary-image-slots-grid">
            <article v-for="slot in slots" :key="slot" class="secretary-image-slot" :data-slot="slot">
                <header><strong>{{ labels[slot] }}</strong><small>{{ image(slot)?.url ? (usesLegacyMainFallback(slot) ? '旧main fallback' : '登録済み') : (image(slot)?.editable_metadata ? '画像非表示・編集可' : '未登録') }}</small></header>
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
                <label><span>作者・権利表記</span><input v-model="credits[slot]" maxlength="160" placeholder="画像ごとのcredit" :disabled="disabled || saving !== null"></label>
                <button type="button" :disabled="disabled || saving !== null || (!files[slot] && !hasEditableImage(slot))" @click="save(slot)">{{ saving === slot ? '保存中…' : (files[slot] ? 'このslotを保存' : 'metadataを保存') }}</button>
            </article>
        </div>
    </section>
</template>
