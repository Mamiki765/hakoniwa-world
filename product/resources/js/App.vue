<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from './api/client';
import CellDetails from './components/CellDetails.vue';
import CommandQueuePanel from './components/CommandQueuePanel.vue';
import HexMap from './components/HexMap.vue';
import IslandEventLog from './components/IslandEventLog.vue';
import SalePolicyPanel from './components/SalePolicyPanel.vue';
import { formatExactMoney } from './formatters/money';
import { useMapState } from './state/mapState';
import type {
    CurrentUser,
    MapSpace,
    Nation,
    PublicEventPage,
    PublicNationDetail,
    PublicRankingEntry,
    PublicWorldSummary,
    World,
} from './types';

const user = ref<CurrentUser | null>(null);
const worlds = ref<World[]>([]);
const worldSummary = ref<PublicWorldSummary | null>(null);
const rankings = ref<PublicRankingEntry[]>([]);
const publicEvents = ref<PublicEventPage | null>(null);
const nation = ref<Nation | null>(null);
const previewNation = ref<PublicNationDetail | null>(null);
const mapSpace = ref<MapSpace | null>(null);
const page = ref<'home' | 'island' | 'preview' | 'resources' | 'profile' | 'account' | 'credits'>('home');
const nationName = ref('');
const nationOwnerName = ref('');
const nationComment = ref('');
const profileOwnerName = ref('');
const profileComment = ref('');
const registrationErrors = ref<Record<string, string>>({});
const profileErrors = ref<Record<string, string>>({});
const foodDetailOpen = ref(false);
const busy = ref(true);
const message = ref('');
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const map = useMapState();
const linkedProviders = computed(() => new Set(user.value?.providers.map((identity) => identity.provider) ?? []));
const nonFoodResources = computed(() => nation.value?.resources.filter((resource) => resource.category !== 'food') ?? []);

function formatResource(amount: number, unitLabel: string | null): string {
    return `${amount.toLocaleString('ja-JP')}${unitLabel ?? ''}`;
}

onMounted(async () => {
    await loadPublicLobby();
    try {
        user.value = await api<CurrentUser>('/api/v1/me');
        nation.value = await api<Nation | null>('/api/v1/me/nation');
    } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) {
            message.value = 'ログイン状態を取得できませんでした。公開ロビーは引き続き閲覧できます。';
        }
    } finally {
        busy.value = false;
    }
});

async function loadPublicLobby(): Promise<void> {
    try {
        worlds.value = await api<World[]>('/api/v1/public/worlds');
        const world = worlds.value[0];
        if (world === undefined) return;
        const [summary, nextRankings, events] = await Promise.all([
            api<PublicWorldSummary>(`/api/v1/public/worlds/${world.id}/summary`),
            api<PublicRankingEntry[]>(`/api/v1/public/worlds/${world.id}/rankings`),
            api<PublicEventPage>(`/api/v1/public/worlds/${world.id}/events`),
        ]);
        worldSummary.value = summary;
        rankings.value = nextRankings;
        publicEvents.value = events;
    } catch {
        message.value = '公開ロビーを取得できませんでした。';
    }
}

async function loadPublicEvents(pageNumber: number): Promise<void> {
    const world = worlds.value[0];
    const anchor = publicEvents.value?.anchor_turn;
    if (world === undefined || anchor === undefined) return;

    try {
        publicEvents.value = await api<PublicEventPage>(
            `/api/v1/public/worlds/${world.id}/events?page=${pageNumber}&anchor_turn=${anchor}`,
        );
    } catch {
        message.value = '公開ニュースを取得できませんでした。';
    }
}

async function openOwnIsland(): Promise<void> {
    const currentNation = nation.value;
    if (currentNation?.capital === null || currentNation === null) return;
    busy.value = true;
    message.value = '';
    try {
        const spaces = await api<MapSpace[]>(`/api/v1/worlds/${currentNation.world_id}/map-spaces`);
        mapSpace.value = spaces.find((space) => space.key === 'surface') ?? spaces[0] ?? null;
        if (mapSpace.value !== null) {
            await map.loadAround(mapSpace.value, currentNation.capital.x, currentNation.capital.y, { kind: 'private' });
            page.value = 'island';
        }
    } catch (error) {
        message.value = error instanceof Error ? error.message : '自島を読み込めませんでした。';
    } finally {
        busy.value = false;
    }
}

async function openPreview(nationId: number): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const detail = await api<PublicNationDetail>(`/api/v1/public/nations/${nationId}`);
        if (detail.capital === null) throw new Error('首都がまだありません。');
        previewNation.value = detail;
        mapSpace.value = detail.map_space;
        await map.loadAround(detail.map_space, detail.capital.x, detail.capital.y, {
            kind: 'public',
            nationId: detail.id,
        });
        page.value = 'preview';
    } catch (error) {
        message.value = error instanceof Error ? error.message : '島previewを読み込めませんでした。';
    } finally {
        busy.value = false;
    }
}

async function createNation(): Promise<void> {
    const world = worlds.value[0];
    if (world === undefined) return;
    busy.value = true;
    message.value = '';
    registrationErrors.value = {};
    try {
        nation.value = await api<Nation>('/api/v1/nations', {
            method: 'POST',
            body: JSON.stringify({
                world_id: world.id,
                name: nationName.value,
                owner_name: nationOwnerName.value,
                comment: nationComment.value,
            }),
        });
        await loadPublicLobby();
        await openOwnIsland();
    } catch (error) {
        registrationErrors.value = validationErrors(error);
        message.value = Object.keys(registrationErrors.value).length === 0
            ? (error instanceof Error ? error.message : '島を作成できませんでした。')
            : '';
        busy.value = false;
    }
}

function validationErrors(error: unknown): Record<string, string> {
    if (!(error instanceof ApiError)) return {};

    return Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key, messages[0] ?? '入力を確認してください。']));
}

function openProfile(): void {
    if (nation.value === null) return;
    profileOwnerName.value = nation.value.owner_name;
    profileComment.value = nation.value.comment;
    profileErrors.value = {};
    message.value = '';
    page.value = 'profile';
}

async function updateProfile(): Promise<void> {
    if (nation.value === null) return;
    busy.value = true;
    message.value = '';
    profileErrors.value = {};
    try {
        nation.value = await api<Nation>(`/api/v1/nations/${nation.value.id}/profile`, {
            method: 'PATCH',
            body: JSON.stringify({
                owner_name: profileOwnerName.value,
                comment: profileComment.value,
            }),
        });
        await loadPublicLobby();
        await openOwnIsland();
    } catch (error) {
        profileErrors.value = validationErrors(error);
        message.value = Object.keys(profileErrors.value).length === 0
            ? (error instanceof Error ? error.message : 'プロフィールを更新できませんでした。')
            : '';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <header class="site-header">
        <a class="brand" href="#" @click.prevent="page = 'home'">箱庭諸島<span>２S＋</span></a>
        <nav aria-label="主要ナビゲーション">
            <button type="button" @click="page = 'home'">公開ロビー</button>
            <button v-if="nation" type="button" @click="openOwnIsland">自島へ</button>
            <button v-if="nation" type="button" @click="page = 'resources'">資源方針</button>
            <button v-if="nation" type="button" @click="openProfile">プロフィール編集</button>
            <button type="button" @click="page = 'credits'">クレジット</button>
            <a href="/manual">マニュアル</a>
            <a href="/community-guidelines">利用ルール</a>
        </nav>
        <div class="session-actions">
            <template v-if="user">
                <span>{{ user.display_name }}</span>
                <button v-if="nation" type="button" @click="openOwnIsland">{{ nation.name }}</button>
                <button v-else type="button" @click="page = 'home'">島を作る</button>
                <button type="button" @click="page = 'account'">アカウント</button>
            </template>
            <template v-else>
                <a href="/auth/discord/redirect">Discordログイン</a>
                <a href="/auth/google/redirect">Googleログイン</a>
            </template>
        </div>
    </header>

    <main :class="{ 'map-main': page === 'island' || page === 'preview' }">
        <p v-if="busy" class="status" role="status">読み込み中…</p>
        <p v-if="message" class="status error" role="alert">{{ message }}</p>

        <section v-if="page === 'home'" class="lobby">
            <div class="lobby-heading">
                <div>
                    <p class="eyebrow">HAKONIWA ISLANDS</p>
                    <h1>箱庭諸島２S＋</h1>
                    <p>島を育て、世界の出来事を見守りながら、長く続く島を作りましょう。</p>
                </div>
                <div v-if="!user" class="compact-login">
                    <p>島を運営するにはログインしてください。</p>
                    <a class="button discord" href="/auth/discord/redirect">Discord</a>
                    <a class="button google" href="/auth/google/redirect">Google</a>
                </div>
            </div>

            <dl class="world-stats">
                <div><dt>現在ターン</dt><dd>{{ worldSummary?.current_turn ?? 1 }}</dd></div>
                <div><dt>島数</dt><dd>{{ (worldSummary?.nation_count ?? 0).toLocaleString() }}</dd></div>
                <div><dt>総人口</dt><dd>{{ (worldSummary?.total_population ?? 0).toLocaleString() }}人</dd></div>
            </dl>

            <div class="lobby-grid">
                <section class="ranking-card">
                    <div class="section-heading">
                        <div><p class="eyebrow">ISLANDS</p><h2>島一覧</h2></div>
                        <span>誰でも閲覧できます</span>
                    </div>
                    <div class="ranking-scroll">
                        <table>
                            <thead><tr><th>島名</th><th>島主</th><th>人口</th><th>資金</th><th>生存ターン</th><th>活動状態</th></tr></thead>
                            <tbody>
                                <tr v-for="entry in rankings" :key="entry.id">
                                    <td>
                                        <button
                                            type="button"
                                            :class="{ 'is-finance-only': entry.finance_only_turns > 0 }"
                                            @click="openPreview(entry.id)"
                                        >
                                            {{ entry.name }}<template v-if="entry.finance_only_turns > 0"> ({{ entry.finance_only_turns }})</template>
                                        </button>
                                        <span v-if="entry.comment" class="ranking-comment">{{ entry.comment }}</span>
                                    </td>
                                    <td>{{ entry.owner_name }}</td>
                                    <td>{{ entry.total_population.toLocaleString() }}人</td>
                                    <td>{{ entry.money_display }}</td>
                                    <td>{{ entry.survival_turns.toLocaleString() }}</td>
                                    <td>{{ entry.finance_only_turns > 0 ? '資金繰りのみ' : '活動中' }}</td>
                                </tr>
                                <tr v-if="rankings.length === 0"><td colspan="6" class="empty-state">まだ島がありません。</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="events-card">
                    <div class="section-heading">
                        <div><p class="eyebrow">PUBLIC NEWS</p><h2>世界のニュース</h2></div>
                    </div>
                    <template v-if="publicEvents?.groups.length">
                        <section v-for="group in publicEvents.groups" :key="group.target_turn" class="public-event-group">
                            <h3>ターン {{ group.target_turn }}</h3>
                            <ol class="event-list">
                                <li v-for="event in group.events" :key="event.id">
                                    <span class="event-mark" aria-hidden="true"></span>
                                    <div><strong>{{ event.message }}</strong><time :datetime="event.occurred_at">{{ event.occurred_at }}</time></div>
                                </li>
                            </ol>
                        </section>
                    </template>
                    <p v-else class="empty-state">公開できる出来事はまだありません。最初の島の成立を待っています。</p>
                    <nav v-if="publicEvents" class="event-pager" aria-label="世界のニュースのページ">
                        <button type="button" :disabled="!publicEvents.has_newer_page" @click="loadPublicEvents(publicEvents.page - 1)">新しいニュース</button>
                        <span>{{ publicEvents.page }}ページ</span>
                        <button type="button" :disabled="!publicEvents.has_older_page" @click="loadPublicEvents(publicEvents.page + 1)">古いニュース</button>
                    </nav>
                    <p class="community-contact">
                        <a href="/community-guidelines">禁止行為と連絡方法</a>
                        <a v-if="worldSummary?.contact_url" :href="worldSummary.contact_url" rel="external nofollow">通報・異議申立て窓口</a>
                    </p>
                </section>
            </div>

            <form v-if="user && !nation" class="nation-form panel" @submit.prevent="createNation">
                <p class="eyebrow">CREATE YOUR NATION</p>
                <h2>最初の島を作成</h2>
                <label>
                    島名
                    <input v-model="nationName" minlength="2" maxlength="30" required aria-describedby="nation-name-help nation-name-error">
                    <small id="nation-name-help" class="field-hint">2〜30文字。登録後の変更はできません。</small>
                    <span v-if="registrationErrors.name" id="nation-name-error" class="field-error" role="alert">{{ registrationErrors.name }}</span>
                </label>
                <label>
                    島主名
                    <input v-model="nationOwnerName" minlength="1" maxlength="30" required aria-describedby="owner-name-help owner-name-error">
                    <small id="owner-name-help" class="field-hint">1〜30文字。OAuth表示名とは別の公開名です。</small>
                    <span v-if="registrationErrors.owner_name" id="owner-name-error" class="field-error" role="alert">{{ registrationErrors.owner_name }}</span>
                </label>
                <label>
                    一言コメント
                    <textarea v-model="nationComment" maxlength="100" rows="2" aria-describedby="comment-help comment-error" @keydown.enter.prevent></textarea>
                    <small id="comment-help" class="field-hint">任意・100文字以内。改行はできません。</small>
                    <span v-if="registrationErrors.comment" id="comment-error" class="field-error" role="alert">{{ registrationErrors.comment }}</span>
                </label>
                <button class="button primary" type="submit" :disabled="busy">島を作る</button>
            </form>
        </section>

        <section v-else-if="page === 'island' && nation?.capital && mapSpace" class="island-page">
            <header class="nation-hud">
                <div class="hud-identity">
                    <p class="eyebrow">MY ISLAND</p>
                    <h1>N{{ nation.nation_number }} {{ nation.name }}</h1>
                    <p class="profile-owner">島主：{{ nation.owner_name }}</p>
                    <p v-if="nation.comment" class="profile-comment">「{{ nation.comment }}」</p>
                </div>
                <dl class="hud-primary">
                    <div><dt>ターン</dt><dd>{{ nation.current_turn }}</dd></div>
                    <div class="hud-money">
                        <dt>資金</dt>
                        <dd class="hud-capacity-value">
                            <strong class="hud-current-value">{{ formatExactMoney(nation.money) }}</strong>
                            <span class="hud-capacity-limit">上限 {{ formatExactMoney(nation.money_capacity) }}</span>
                        </dd>
                    </div>
                    <div><dt>人口</dt><dd>{{ nation.total_population.toLocaleString() }}人</dd></div>
                    <div class="hud-food">
                        <dt>食料</dt>
                        <dd class="hud-capacity-value">
                            <span class="hud-value-line">
                                <strong class="hud-current-value">{{ formatResource(nation.total_food_tons, 'トン') }}</strong>
                                <button
                                    class="food-detail-toggle"
                                    type="button"
                                    :aria-expanded="foodDetailOpen"
                                    @click="foodDetailOpen = !foodDetailOpen"
                                >
                                    詳細
                                </button>
                            </span>
                            <span class="hud-capacity-limit">上限 {{ formatResource(nation.food_capacity_tons, 'トン') }}</span>
                        </dd>
                        <div v-if="foodDetailOpen" class="hud-food-detail" role="dialog" aria-label="食料の内訳">
                            <strong>食料の内訳</strong>
                            <ul>
                                <li v-for="resource in nation.food_resources" :key="resource.key">
                                    <span>{{ resource.name }}</span>
                                    <span>{{ formatResource(resource.balance, resource.unit_label) }}</span>
                                </li>
                            </ul>
                            <button type="button" @click="foodDetailOpen = false">閉じる</button>
                        </div>
                    </div>
                    <div v-for="resource in nonFoodResources" :key="resource.key">
                        <dt>{{ resource.name }}</dt>
                        <dd v-if="resource.capacity !== null" class="hud-capacity-value">
                            <strong class="hud-current-value">{{ formatResource(resource.amount, resource.unit_label) }}</strong>
                            <span class="hud-capacity-limit">上限 {{ formatResource(resource.capacity, resource.unit_label) }}</span>
                        </dd>
                        <dd v-else>{{ formatResource(resource.amount, resource.unit_label) }}</dd>
                    </div>
                </dl>
                <details class="hud-more">
                    <summary>追加統計</summary>
                    <span>保有陸地：{{ nation.owned_land_cells.toLocaleString() }}セル</span>
                    <span>出来事は24ターンごとに表示</span>
                </details>
            </header>
            <div class="island-grid">
                <CommandQueuePanel :nation-id="nation.id" :map-space-id="mapSpace.id" :selected="map.selected.value" />
                <div class="map-column">
                    <HexMap
                        :cells="map.visibleCells.value"
                        :selected="map.selected.value"
                        :capital="nation.capital"
                        :bounds="mapSpace.bounds"
                        :own-nation-id="nation.id"
                        :loading="map.loading.value"
                        :error="map.error.value"
                        :empty-chunks="map.emptyChunks.value"
                        @select="map.select"
                        @move="map.moveSelection"
                        @request-range="map.loadVisibleRange"
                        @request-all="map.loadAllChunks"
                    />
                </div>
            </div>
            <IslandEventLog :nation-id="nation.id" />
        </section>

        <section v-else-if="page === 'preview' && previewNation?.capital && mapSpace" class="preview-page">
            <header class="preview-heading">
                <div>
                    <p class="eyebrow">PUBLIC ISLAND PREVIEW</p>
                    <h1>N{{ previewNation.nation_number }} {{ previewNation.name }}</h1>
                    <p class="profile-owner">島主：{{ previewNation.owner_name }}</p>
                    <p v-if="previewNation.comment" class="profile-comment">「{{ previewNation.comment }}」</p>
                </div>
                <dl>
                    <div><dt>人口</dt><dd>{{ previewNation.total_population.toLocaleString() }}人</dd></div>
                    <div><dt>保有陸地</dt><dd>{{ previewNation.owned_land_cells.toLocaleString() }}セル</dd></div>
                    <div><dt>推定資金</dt><dd>{{ previewNation.money_display }}</dd></div>
                    <div><dt>怪獣討伐</dt><dd>{{ previewNation.monster_final_blow_count.toLocaleString() }}体</dd></div>
                </dl>
                <p v-if="previewNation.monster_kill_stats.length" class="monster-kill-marks">
                    討伐印：
                    <span v-for="stat in previewNation.monster_kill_stats" :key="stat.key">
                        {{ stat.name }} × {{ stat.kill_count.toLocaleString() }}（初T{{ stat.first_killed_turn }}／最終T{{ stat.last_killed_turn }}）
                    </span>
                </p>
            </header>
            <div class="preview-grid">
                <aside class="preview-details">
                    <CellDetails :cell="map.selected.value" />
                    <p class="queue-notice">公開情報だけを表示しています。コマンド、正確な資金・資源、非公開施設は取得していません。</p>
                </aside>
                <HexMap
                    :cells="map.visibleCells.value"
                    :selected="map.selected.value"
                    :capital="previewNation.capital"
                    :bounds="mapSpace.bounds"
                    :loading="map.loading.value"
                    :error="map.error.value"
                    :empty-chunks="map.emptyChunks.value"
                    @select="map.select"
                    @move="map.moveSelection"
                    @request-range="map.loadVisibleRange"
                    @request-all="map.loadAllChunks"
                />
            </div>
        </section>

        <SalePolicyPanel v-else-if="user && nation && page === 'resources'" :nation-id="nation.id" />

        <section v-else-if="user && nation && page === 'profile'" class="panel profile-panel">
            <p class="eyebrow">ISLAND PROFILE</p>
            <h1>プロフィール編集</h1>
            <p>N{{ nation.nation_number }} {{ nation.name }}の公開プロフィールです。島名は変更できません。</p>
            <form class="profile-form" @submit.prevent="updateProfile">
                <label>
                    島主名
                    <input v-model="profileOwnerName" minlength="1" maxlength="30" required aria-describedby="profile-owner-help profile-owner-error">
                    <small id="profile-owner-help" class="field-hint">1〜30文字。公開ロビーや島previewへ表示されます。</small>
                    <span v-if="profileErrors.owner_name" id="profile-owner-error" class="field-error" role="alert">{{ profileErrors.owner_name }}</span>
                </label>
                <label>
                    一言コメント
                    <textarea v-model="profileComment" maxlength="100" rows="3" aria-describedby="profile-comment-help profile-comment-error" @keydown.enter.prevent></textarea>
                    <small id="profile-comment-help" class="field-hint">100文字以内、改行不可。HTMLやURLはリンクとして解釈されません。</small>
                    <span v-if="profileErrors.comment" id="profile-comment-error" class="field-error" role="alert">{{ profileErrors.comment }}</span>
                </label>
                <div class="profile-actions">
                    <button class="button primary" type="submit" :disabled="busy">保存</button>
                    <button type="button" :disabled="busy" @click="openOwnIsland">キャンセル</button>
                </div>
            </form>
        </section>

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
            <p>原作GIFは本リポジトリとDocker imageに含まれません。未配置時はCSS fallbackを表示します。</p>
        </section>
    </main>
</template>
