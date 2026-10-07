<script setup lang="ts">
import { ref, watch } from 'vue';
import type { SecretaryProfile } from '../types';
import SecretaryEquippedItems from './SecretaryEquippedItems.vue';

const props = defineProps<{ profile: SecretaryProfile; busy: boolean; biographyError?: string }>();
const emit = defineEmits<{ saveBiography: [value: string]; equipment: []; underground: [] }>();
const editing = ref(false);
const draft = ref(props.profile.biography);
watch(() => props.profile, (profile) => {
    draft.value = profile.biography;
    editing.value = false;
});
const stats = { vitality: '生命', might: '武力', finesse: '技巧', spirit: '精神', agility: '敏捷' } as const;
</script>

<template>
    <div class="secretary-profile-hero" :class="{ 'secretary-profile-no-combat': !profile.underground_status }">
        <div class="secretary-portrait-column">
            <div class="secretary-portrait-frame">
                <img v-if="profile.main_image.url" :src="profile.main_image.url" :alt="`${profile.name}のメイン画像`">
                <span v-else class="secretary-no-image">画像なし</span>
                <details v-if="profile.main_image.display === 'uploaded'" class="secretary-image-info">
                    <summary aria-label="画像について">ⓘ</summary>
                    <div>
                        <strong>画像について</strong>
                        <p>制作方法：{{ profile.main_image.creation_method_label }}</p>
                        <p v-if="profile.main_image.credit">作者・権利表記：{{ profile.main_image.credit }}</p>
                    </div>
                </details>
            </div>
        </div>
        <section class="secretary-profile-summary" aria-label="秘書基本情報">
            <h3>ステータス</h3>
            <dl>
                <div><dt>内政Lv</dt><dd>{{ profile.domestic_level }}</dd></div>
                <div v-if="profile.combat_level !== null"><dt>戦闘Lv</dt><dd>{{ profile.combat_level }}</dd></div>
                <div><dt>資金・食糧最大</dt><dd>+{{ profile.capacity_bonus_percent }}%</dd></div>
                <div><dt>討伐経験値</dt><dd>{{ profile.monster_experience.toLocaleString('ja-JP') }}</dd></div>
            </dl>
            <div v-if="profile.is_owner && profile.name && !profile.underground_status" class="secretary-underground-entry">
                <button class="button primary" type="button" @click="emit('underground')">地底へ</button>
            </div>
        </section>
        <section v-if="profile.underground_status" class="secretary-combat-stats" aria-label="地底の能力値">
                <p>{{ profile.underground_status.growth_path_label }}</p>
                <dl>
                    <div class="secretary-max-hp"><dt>最大HP</dt><dd>{{ profile.underground_status.max_hp.toLocaleString('ja-JP') }}</dd></div>
                    <div v-for="(label, key) in stats" :key="key"><dt>{{ label }}</dt><dd>{{ profile.underground_status.stats[key].toLocaleString('ja-JP') }}</dd></div>
                </dl>
                <small>装備による加算を含む能力値</small>
            <div v-if="profile.is_owner && profile.name" class="secretary-underground-entry">
                <button class="button primary" type="button" @click="emit('underground')">地底へ</button>
            </div>
        </section>
        <section class="secretary-biography" aria-labelledby="secretary-biography-title">
            <div class="secretary-profile-section-heading">
                <h3 id="secretary-biography-title">経歴</h3>
                <button v-if="profile.is_owner && !editing" type="button" @click="editing = true">経歴を編集</button>
            </div>
            <form v-if="profile.is_owner && editing" @submit.prevent="emit('saveBiography', draft)">
                <textarea v-model="draft" maxlength="1000" rows="6" aria-label="経歴" aria-describedby="secretary-biography-count secretary-biography-error" :disabled="busy"></textarea>
                <small id="secretary-biography-count">{{ draft.length }} / 1000文字。改行のみ表示へ反映します。</small>
                <span v-if="biographyError" id="secretary-biography-error" class="field-error" role="alert">{{ biographyError }}</span>
                <div class="secretary-biography-actions">
                    <button type="button" :disabled="busy" @click="draft = profile.biography; editing = false">キャンセル</button>
                    <button class="button primary" type="submit" :disabled="busy">経歴を保存</button>
                </div>
            </form>
            <p v-else-if="profile.biography" class="secretary-biography-text">{{ profile.biography }}</p>
            <p v-else class="secretary-biography-empty">経歴はまだ公開されていません。</p>
        </section>
    </div>
    <div class="secretary-profile-section-heading secretary-equipment-heading">
        <h3>装備</h3>
        <button type="button" @click="emit('equipment')">{{ profile.is_owner ? '地上装備を変更' : '装備を見る' }}</button>
    </div>
    <SecretaryEquippedItems :profile="profile" />
</template>
