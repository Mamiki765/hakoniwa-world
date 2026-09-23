<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../api/client';

interface MonumentDesign {
    name: string;
    image_url: string | null;
    template_url: string | null;
}

const design = ref<MonumentDesign | null>(null);
const name = ref('オリジナル記念碑');
const image = ref<File | null>(null);
const busy = ref(false);
const error = ref('');
const saved = ref(false);

onMounted(async () => {
    try {
        design.value = await api<MonumentDesign>('/api/v1/me/monument-design');
        name.value = design.value.name;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '記念碑の設定を読み込めませんでした。';
    }
});

function selectImage(event: Event): void {
    image.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    saved.value = false;
}

async function save(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    saved.value = false;
    try {
        const body = new FormData();
        body.append('name', name.value);
        if (image.value !== null) body.append('image', image.value);
        design.value = await api<MonumentDesign>('/api/v1/me/monument-design', { method: 'POST', body });
        name.value = design.value.name;
        image.value = null;
        saved.value = true;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '記念碑の設定を保存できませんでした。';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="options-section monument-design-settings" aria-labelledby="monument-design-title">
        <h2 id="monument-design-title">オリジナル記念碑</h2>
        <p>建設したオリジナル記念碑には、ここで登録した名前と画像を表示します。画像を差し替えると、すでに建っている記念碑にも反映されます。</p>
        <form @submit.prevent="save">
            <label>名前
                <input v-model="name" maxlength="40" required :disabled="busy">
            </label>
            <label>画像（32×32 GIF、10MBまで。アニメーション可）
                <input type="file" accept="image/gif,.gif" :disabled="busy" @change="selectImage">
            </label>
            <p v-if="design?.template_url"><a :href="design.template_url" download="land2.gif">制作素材の平地タイル</a></p>
            <p v-if="design?.image_url">現在の画像 <img :src="design.image_url" alt="登録中の記念碑画像" width="32" height="32"></p>
            <p v-else>画像未設定の記念碑は「?」で表示されます。</p>
            <p v-if="error" class="field-error" role="alert">{{ error }}</p>
            <p v-if="saved" role="status">設定を保存しました。</p>
            <button class="button primary" type="submit" :disabled="busy">記念碑の設定を保存</button>
        </form>
    </section>
</template>
