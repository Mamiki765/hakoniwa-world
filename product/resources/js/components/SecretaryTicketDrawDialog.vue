<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{ name: string; level: number; maxDrawCount: number; busy: boolean }>();
const emit = defineEmits<{ cancel: []; draw: [count: number] }>();
const count = ref(Math.max(1, Math.min(props.level, props.maxDrawCount)));
const valid = computed(() => Number.isInteger(count.value) && count.value >= 1 && count.value <= props.maxDrawCount);
</script>

<template>
    <div class="modal-backdrop" @click.self="!busy && emit('cancel')">
        <form class="secretary-synthesis-dialog" role="dialog" aria-modal="true" aria-labelledby="ticket-draw-title" @submit.prevent="valid && emit('draw', count)">
            <h2 id="ticket-draw-title">{{ name }}を使いますか？</h2>
            <template v-if="maxDrawCount > 0">
                <label class="secretary-ticket-count">引く回数 <input v-model.number="count" type="number" min="1" :max="maxDrawCount" inputmode="numeric" required> 回</label>
                <p>倉庫の空きの都合で、今は{{ maxDrawCount.toLocaleString('ja-JP') }}回まで引けます。引いた分だけチケットのLvが減ります（残り Lv{{ Math.max(0, level - (count || 0)).toLocaleString('ja-JP') }}）。</p>
                <div class="secretary-ticket-shortcuts">
                    <button type="button" @click="count = 1">1回</button>
                    <button v-if="maxDrawCount >= 10" type="button" @click="count = 10">10回</button>
                    <button type="button" @click="count = maxDrawCount">最大</button>
                </div>
            </template>
            <p v-else class="status error">倉庫に空きがないので引けません。アイテムを売るか、まとめるか、倉庫を広げてください。</p>
            <footer>
                <button class="button secondary" type="button" :disabled="busy" @click="emit('cancel')">やめる</button>
                <button v-if="maxDrawCount > 0" class="button primary" type="submit" :disabled="busy || !valid">{{ busy ? '処理中…' : '引く' }}</button>
            </footer>
        </form>
    </div>
</template>
