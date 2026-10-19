<script setup lang="ts">
import { ref, watch } from 'vue';
import { api, apiEnvelope } from '../api/client';
import { formatAnnouncementDate } from '../formatters/date';
import type { InquiryDetail, InquirySummary } from '../types';

export type AdminInquiriesRequest = { page: number } | { managementId: string };

const props = defineProps<{ request: AdminInquiriesRequest }>();
const emit = defineEmits<{ home: []; message: [text: string] }>();

const busy = ref(false);
const items = ref<InquirySummary[]>([]);
const detail = ref<InquiryDetail | null>(null);
const pageNumber = ref(1);
const lastPage = ref(1);
const loaded = ref(false);

async function openList(requested = 1): Promise<void> {
    busy.value = true;
    emit('message', '');
    try {
        const envelope = await apiEnvelope<InquirySummary[]>(`/api/v1/admin/inquiries?page=${requested}`);
        items.value = envelope.data;
        const currentPage = Number(envelope.meta?.current_page ?? requested);
        const last = Number(envelope.meta?.last_page ?? currentPage);
        pageNumber.value = Number.isInteger(currentPage) && currentPage > 0 ? currentPage : requested;
        lastPage.value = Number.isInteger(last) && last > 0 ? last : pageNumber.value;
        detail.value = null;
        loaded.value = true;
    } catch (error) {
        emit('message', error instanceof Error ? error.message : 'お問い合わせ一覧を取得できませんでした。');
    } finally {
        busy.value = false;
    }
}

async function openDetail(managementId: string): Promise<void> {
    busy.value = true;
    emit('message', '');
    try {
        const id = Number(managementId.replace('INQ-', ''));
        detail.value = await api<InquiryDetail>(`/api/v1/admin/inquiries/${id}`);
    } catch (error) {
        emit('message', error instanceof Error ? error.message : 'お問い合わせ詳細を取得できませんでした。');
    } finally {
        busy.value = false;
    }
}

watch(() => props.request, (request) => {
    void ('managementId' in request ? openDetail(request.managementId) : openList(request.page));
}, { immediate: true });
</script>

<template>
    <section class="panel inquiry-admin-page">
        <div class="section-heading">
            <div><p class="eyebrow">INQUIRIES</p><h1>お問い合わせ管理</h1></div>
            <button type="button" @click="emit('home')">TOPへ戻る</button>
        </div>
        <article v-if="detail" class="inquiry-detail">
            <p><strong>{{ detail.management_id }}</strong> [{{ detail.category_label }}]</p>
            <h2>{{ detail.subject }}</h2>
            <dl>
                <div><dt>User</dt><dd>#{{ detail.user.id }} {{ detail.user.display_name }}</dd></div>
                <div><dt>Nation</dt><dd>{{ detail.nation ? `N${detail.nation.nation_number} ${detail.nation.name}` : 'なし' }}</dd></div>
                <div><dt>投稿ターン</dt><dd>{{ detail.world.submitted_turn }}</dd></div>
                <div><dt>アプリ版</dt><dd>{{ detail.application_version }}</dd></div>
                <div><dt>投稿日時</dt><dd>{{ formatAnnouncementDate(detail.created_at) }}</dd></div>
            </dl>
            <p class="inquiry-body">{{ detail.body }}</p>
            <a v-if="detail.attachment_url" :href="detail.attachment_url" target="_blank" rel="noopener">
                <img class="inquiry-attachment" :src="detail.attachment_url" alt="お問い合わせ添付画像">
            </a>
            <button type="button" :disabled="busy" @click="openList(pageNumber)">一覧へ戻る</button>
        </article>
        <template v-else-if="loaded">
            <ol v-if="items.length" class="inquiry-list full">
                <li v-for="inquiry in items" :key="inquiry.management_id">
                    <button type="button" @click="openDetail(inquiry.management_id)">
                        {{ inquiry.management_id }} [{{ inquiry.category_label }}] {{ inquiry.subject }}
                    </button>
                    <time :datetime="inquiry.created_at">{{ formatAnnouncementDate(inquiry.created_at) }}</time>
                </li>
            </ol>
            <p v-else class="empty-state">お問い合わせはまだありません。</p>
            <nav class="inquiry-pager" aria-label="お問い合わせのページ">
                <button type="button" :disabled="pageNumber <= 1" @click="openList(pageNumber - 1)">前へ</button>
                <span>{{ pageNumber }}ページ</span>
                <button type="button" :disabled="pageNumber >= lastPage" @click="openList(pageNumber + 1)">次へ</button>
            </nav>
        </template>
    </section>
</template>
