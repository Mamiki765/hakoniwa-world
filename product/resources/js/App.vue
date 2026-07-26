<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from './api/client';
import HexMap from './components/HexMap.vue';
import SalePolicyPanel from './components/SalePolicyPanel.vue';
import { useMapState } from './state/mapState';
import type { CurrentUser, MapSpace, Nation, World } from './types';

const user = ref<CurrentUser | null>(null);
const worlds = ref<World[]>([]);
const nation = ref<Nation | null>(null);
const mapSpace = ref<MapSpace | null>(null);
const page = ref<'home' | 'map' | 'resources' | 'account' | 'credits'>('home');
const nationName = ref('');
const busy = ref(true);
const message = ref('');
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const map = useMapState();
const linkedProviders = computed(() => new Set(user.value?.providers.map((identity) => identity.provider) ?? []));
const wheatAmount = computed(() => nation.value?.resources.find((resource) => resource.key === 'wheat')?.amount ?? 0);

onMounted(async () => {
    try {
        user.value = await api<CurrentUser>('/api/v1/me');
        worlds.value = await api<World[]>('/api/v1/worlds');
        nation.value = await api<Nation | null>('/api/v1/me/nation');
        if (nation.value !== null) await prepareMap();
    } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) message.value = '初期データを取得できませんでした。';
    } finally {
        busy.value = false;
    }
});

async function prepareMap(): Promise<void> {
    const currentNation = nation.value;
    if (currentNation?.capital === null || currentNation === null || worlds.value.length === 0) return;
    const spaces = await api<MapSpace[]>(`/api/v1/worlds/${currentNation.world_id}/map-spaces`);
    mapSpace.value = spaces.find((space) => space.key === 'surface') ?? spaces[0] ?? null;
    if (mapSpace.value !== null) {
        await map.loadAround(mapSpace.value.id, currentNation.capital.q, currentNation.capital.r);
        page.value = 'map';
    }
}

async function createNation(): Promise<void> {
    const world = worlds.value[0];
    if (world === undefined) return;
    busy.value = true;
    message.value = '';
    try {
        nation.value = await api<Nation>('/api/v1/nations', {
            method: 'POST',
            body: JSON.stringify({ world_id: world.id, name: nationName.value }),
        });
        await prepareMap();
    } catch (error) {
        message.value = error instanceof Error ? error.message : '国家を作成できませんでした。';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <header class="site-header">
        <a class="brand" href="#" @click.prevent="page = 'home'">HAKONIWA <span>WORLD</span></a>
        <nav v-if="user">
            <button type="button" @click="page = 'home'">ホーム</button>
            <button v-if="nation" type="button" @click="page = 'map'">世界地図</button>
            <button v-if="nation" type="button" @click="page = 'resources'">資源方針</button>
            <button type="button" @click="page = 'account'">アカウント</button>
            <button type="button" @click="page = 'credits'">クレジット</button>
        </nav>
    </header>

    <main>
        <p v-if="busy" class="status" role="status">読み込み中…</p>
        <p v-if="message" class="status error" role="alert">{{ message }}</p>

        <section v-if="!busy && !user" class="hero">
            <p class="eyebrow">ONE OCEAN. MANY NATIONS.</p>
            <h1>海から始まる、<br><em>共有世界の箱庭。</em></h1>
            <p>ログインして国家を作ると、空いている海域へ初期島と首都が生成されます。</p>
            <div class="login-actions">
                <a class="button discord" href="/auth/discord/redirect">Discordでログイン</a>
                <a class="button google" href="/auth/google/redirect">Googleでログイン</a>
            </div>
        </section>

        <section v-else-if="user && page === 'home'" class="panel welcome-panel">
            <p class="eyebrow">WELCOME, {{ user.display_name }}</p>
            <h1 v-if="nation">{{ nation.name }}</h1>
            <template v-if="nation">
                <p>資金 {{ nation.money }} ／ 小麦 {{ wheatAmount }} ／ 首都 q={{ nation.capital?.q }}, r={{ nation.capital?.r }}</p>
                <button class="button primary" type="button" @click="page = 'map'">首都周辺を見る</button>
            </template>
            <form v-else class="nation-form" @submit.prevent="createNation">
                <h2>最初の国家を作成</h2>
                <p>登録時に、全面海の世界から空き海域を選び、旧作仕様を基礎とする初期島を生成します。</p>
                <label>国家名 <input v-model="nationName" minlength="2" maxlength="30" required></label>
                <button class="button primary" type="submit" :disabled="busy">国家を作成</button>
            </form>
        </section>

        <HexMap
            v-else-if="user && page === 'map' && nation?.capital && mapSpace"
            :cells="map.visibleCells.value"
            :selected="map.selected.value"
            :capital="nation.capital"
            :nation-id="nation.id"
            :map-space-id="mapSpace.id"
            :loading="map.loading.value"
            :error="map.error.value"
            :empty-chunks="map.emptyChunks.value"
            @select="map.select"
            @move="map.moveSelection"
        />

        <SalePolicyPanel v-else-if="user && nation && page === 'resources'" :nation-id="nation.id" />

        <section v-else-if="user && page === 'account'" class="panel account-panel">
            <p class="eyebrow">ACCOUNT SETTINGS</p>
            <h1>{{ user.display_name }}</h1>
            <ul>
                <li v-for="provider in ['discord', 'google'] as const" :key="provider">
                    <strong>{{ provider === 'discord' ? 'Discord' : 'Google' }}</strong>
                    <span v-if="linkedProviders.has(provider)">連携済み</span>
                    <a v-else :href="`/account/link/${provider}/redirect`">連携する</a>
                </li>
            </ul>
            <form method="post" action="/logout">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit">ログアウト</button>
            </form>
        </section>

        <section v-else-if="page === 'credits'" class="panel credits-panel">
            <p class="eyebrow">CREDITS</p>
            <h1>参考作品と画像</h1>
            <p>箱庭諸島2＋：字・原作 徳岡宏樹、画像 小川克人、題字 稲葉修吾。</p>
            <p><a href="http://www.bekkoame.ne.jp/~tokuoka/hakoniwa.html" rel="external">原配布元</a></p>
            <p>原作GIFは本リポジトリとDocker imageに含まれません。未配置時はCSS fallbackを表示します。首都は新作placeholderです。</p>
        </section>
    </main>
</template>
