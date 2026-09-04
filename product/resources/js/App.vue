<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ApiError, api, apiEnvelope } from './api/client';
import CellDetails from './components/CellDetails.vue';
import CommandQueuePanel from './components/CommandQueuePanel.vue';
import HexMap from './components/HexMap.vue';
import IslandEventLog from './components/IslandEventLog.vue';
import MessageBoard from './components/MessageBoard.vue';
import RankingAchievements from './components/RankingAchievements.vue';
import SalePolicyPanel from './components/SalePolicyPanel.vue';
import SecretaryEquipmentModal from './components/SecretaryEquipmentModal.vue';
import TradingPostPanel from './components/TradingPostPanel.vue';
import UndergroundPanel from './components/UndergroundPanel.vue';
import UndergroundSurfaceMapView from './components/UndergroundSurfaceMap.vue';
import { formatExactMoney } from './formatters/money';
import { useMapState } from './state/mapState';
import type {
    Announcement,
    CommandQueue,
    CurrentUser,
    InquiryDetail,
    InquirySubmission,
    InquirySummary,
    MajorNewsFeed,
    MapSpace,
    Nation,
    PublicEventPage,
    PublicNationDetail,
    PublicRankingEntry,
    PublicWorldSummary,
    Secretary,
    SecretaryEquipmentOptions,
    SecretaryProfile,
    UndergroundFacilityTarget,
    UndergroundSurfaceMap,
    World,
} from './types';

const applicationVersion = document.querySelector<HTMLMetaElement>(
    'meta[name="hakoniwa-application-version"]',
)?.content ?? '';
const themeModes = ['system', 'light', 'dark'] as const;
type ThemeMode = typeof themeModes[number];

function normaliseThemeMode(value: string | undefined): ThemeMode {
    return themeModes.includes(value as ThemeMode) ? value as ThemeMode : 'system';
}

const themeMode = ref<ThemeMode>(normaliseThemeMode(document.documentElement.dataset.theme));
document.documentElement.dataset.theme = themeMode.value;
const user = ref<CurrentUser | null>(null);
const worlds = ref<World[]>([]);
const worldSummary = ref<PublicWorldSummary | null>(null);
const rankings = ref<PublicRankingEntry[]>([]);
const majorNews = ref<MajorNewsFeed | null>(null);
const publicEvents = ref<PublicEventPage | null>(null);
const latestAnnouncements = ref<Announcement[]>([]);
const announcementItems = ref<Announcement[]>([]);
const announcementDetail = ref<Announcement | null>(null);
const announcementPageNumber = ref(1);
const announcementLastPage = ref(1);
const announcementView = ref<'list' | 'detail' | 'form'>('list');
const announcementFormId = ref<number | null>(null);
const announcementTitle = ref('');
const announcementBody = ref('');
const announcementErrors = ref<Record<string, string>>({});
const nation = ref<Nation | null>(null);
const secretary = ref<Secretary | null>(null);
type SecretarySection = 'main' | 'skills' | 'equipment' | 'warehouse';
const secretarySection = ref<SecretarySection>('main');
const viewedSecretaryProfile = ref<SecretaryProfile | null>(null);
const viewedSecretaryWorldId = ref<number | null>(null);
const secretaryBiography = ref('');
const secretaryProfileErrors = ref<Record<string, string>>({});
const secretaryImageModalOpen = ref(false);
const secretaryImageFile = ref<File | null>(null);
const secretaryImageInput = ref<HTMLInputElement | null>(null);
const secretaryImageCreationMethod = ref<'self_made' | 'ai_generated' | 'commissioned_or_permitted' | 'other'>('self_made');
const secretaryImageCredit = ref('');
const secretaryImageErrors = ref<Record<string, string>>({});
const secretaryPreferencesModalOpen = ref(false);
const secretaryShowAiImages = ref(true);
const secretaryImageFallback = ref<'silhouette' | 'peridot'>('silhouette');
const secretaryPreferenceErrors = ref<Record<string, string>>({});
const equipmentModalSlot = ref<number | null>(null);
const equipmentOptions = ref<SecretaryEquipmentOptions | null>(null);
const equipmentOptionsLoading = ref(false);
const equipmentSubmitting = ref(false);
const equipmentError = ref('');
const equipmentRequireFreshChoice = ref(false);
let equipmentRequestGeneration = 0;
const latestInquiries = ref<InquirySummary[]>([]);
const inquiryItems = ref<InquirySummary[]>([]);
const inquiryDetail = ref<InquiryDetail | null>(null);
const inquiryPageNumber = ref(1);
const inquiryLastPage = ref(1);
const inquiryCategory = ref<InquirySummary['category']>('bug');
const inquirySubject = ref('');
const inquiryBody = ref('');
const inquiryAttachment = ref<HTMLInputElement | null>(null);
const inquirySubmissionKey = ref(crypto.randomUUID());
const inquiryErrors = ref<Record<string, string>>({});
const inquiryConfirmation = ref<InquirySubmission | null>(null);
const previewNation = ref<PublicNationDetail | null>(null);
const mapSpace = ref<MapSpace | null>(null);
const authoritativeCommandQueue = ref<CommandQueue | null>(null);
const undergroundSurfaceMap = ref<UndergroundSurfaceMap | null>(null);
const selectedUndergroundSlot = ref<UndergroundFacilityTarget | null>(null);
const page = ref<'home' | 'announcements' | 'inquiry' | 'admin-inquiries' | 'island' | 'preview' | 'resources' | 'trading-post' | 'secretary' | 'underground' | 'options' | 'account' | 'credits'>(
    window.location.pathname === '/credits'
        ? 'credits'
        : (window.location.pathname === '/underground' ? 'underground' : 'home'),
);

watch(page, (nextPage) => {
    if (nextPage !== 'underground' && window.location.pathname === '/underground') {
        window.history.replaceState({ page: nextPage }, '', '/');
    }
});

function canOpenUnderground(): boolean {
    return user.value !== null && secretary.value !== null && secretary.value.name !== null;
}

function redirectFromUnavailableUnderground(): void {
    if (user.value !== null && secretary.value !== null) {
        secretarySection.value = 'main';
        page.value = 'secretary';
        return;
    }

    page.value = 'home';
}

const secretaryTabOrder = computed<SecretarySection[]>(() => viewedSecretaryProfile.value?.is_owner
    ? ['main', 'skills', 'equipment', 'warehouse']
    : ['main']);
const secretaryTabIds = {
    main: 'secretary-tab-main',
    skills: 'secretary-tab-skills',
    equipment: 'secretary-tab-equipment',
    warehouse: 'secretary-tab-warehouse',
} as const;

async function handleSecretaryTabKeydown(event: KeyboardEvent): Promise<void> {
    const tabs = secretaryTabOrder.value;
    const currentIndex = tabs.indexOf(secretarySection.value);
    let nextIndex: number | null = null;
    if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabs.length;
    if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') nextIndex = 0;
    if (event.key === 'End') nextIndex = tabs.length - 1;
    if (nextIndex === null) return;

    event.preventDefault();
    secretarySection.value = tabs[nextIndex]!;
    await nextTick();
    document.getElementById(secretaryTabIds[secretarySection.value])?.focus();
}
const nationName = ref('');
const nationOwnerName = ref('');
const nationComment = ref('');
const nationRegistrationRequestKey = ref(crypto.randomUUID());
const profileOwnerName = ref('');
const profileComment = ref('');
const abandonmentModalOpen = ref(false);
const abandonmentConfirmationName = ref('');
const abandonmentError = ref('');
const dormancyDays = ref(1);
const dormancyError = ref('');
const registrationErrors = ref<Record<string, string>>({});
const profileErrors = ref<Record<string, string>>({});
const profileSecretaryName = ref('');
const profileSecretaryErrors = ref<Record<string, string>>({});
const secretaryName = ref('ペリドット');
const secretaryErrors = ref<Record<string, string>>({});
const busy = ref(true);
const message = ref('');
const clockNow = ref(Date.now());
let clockTimer: ReturnType<typeof setInterval> | null = null;
let summaryDeadlineTimer: ReturnType<typeof setTimeout> | null = null;
let summaryRetryTimer: ReturnType<typeof setTimeout> | null = null;
let summaryFallbackTimer: ReturnType<typeof setInterval> | null = null;
let turnViewCurrentTurn: number | null = null;
let nationStateGeneration = 0;
const summaryRetryDelays = [2_000, 3_000, 5_000, 10_000, 15_000, 30_000] as const;
const maximumTimeoutDelay = 2_147_000_000;
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const map = useMapState();

async function selectUndergroundSlot(target: UndergroundFacilityTarget): Promise<void> {
    map.clearSelection();
    selectedUndergroundSlot.value = target;
    if (page.value !== 'island') return;
    await nextTick();
    scrollIslandWorkspaceTo('.command-panel');
}

function selectSurfaceCell(cell: Parameters<typeof map.select>[0]): void {
    selectedUndergroundSlot.value = null;
    map.select(cell);
}
const islandWorkspaceScroll = ref<HTMLElement | null>(null);
const linkedProviders = computed(() => new Set(user.value?.providers.map((identity) => identity.provider) ?? []));
const abandonmentConfirmed = computed(() => nation.value !== null
    && abandonmentConfirmationName.value === nation.value.name);
const nonFoodResources = computed(() => nation.value?.resources.filter((resource) => resource.category !== 'food') ?? []);
const nextTurnCountdown = computed(() => {
    if (worldSummary.value?.turn_status !== 'normal') return null;
    const remaining = Math.max(0, new Date(worldSummary.value.next_scheduled_turn_at).getTime() - clockNow.value);
    const totalSeconds = Math.floor(remaining / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return [hours, minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');
});
const turnStatusMessage = computed(() => matchTurnStatus(worldSummary.value?.turn_status));

function scrollIslandWorkspaceTo(selector: string): void {
    const scroller = islandWorkspaceScroll.value;
    const section = scroller?.querySelector<HTMLElement>(selector);
    if (!scroller || !section || typeof scroller.scrollTo !== 'function') return;

    scroller.scrollTo({
        left: section.offsetLeft,
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    });
}

function formatForecastDelta(value: number): string {
    if (value === 0) return '0';

    return `${value > 0 ? '+' : '−'}${Math.abs(value).toLocaleString('ja-JP')}`;
}

function formatPercentageTenths(value: number): string {
    return (value / 10).toFixed(1);
}

function formatResource(amount: number, unitLabel: string | null): string {
    return `${amount.toLocaleString('ja-JP')}${unitLabel ?? ''}`;
}

function formatFacilityScale(population: number): string {
    return population === 0 ? '保有せず' : `${population.toLocaleString('ja-JP')}人`;
}

function formatAnnouncementDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Tokyo',
    }).format(new Date(value));
}

function formatTurnTimestamp(value: string | null): string {
    if (value === null) return '未実施';

    return new Intl.DateTimeFormat('ja-JP', {
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: 'Asia/Tokyo',
    }).format(new Date(value));
}

function matchTurnStatus(status: PublicWorldSummary['turn_status'] | undefined): string {
    if (status === 'failed' || status === 'blocked') return 'ターン更新が停止しています。';
    if (status === 'delayed') return 'ターン更新が遅延しています。';

    return '';
}

onMounted(async () => {
    window.addEventListener('popstate', syncPageFromHistory);
    clockTimer = setInterval(() => { clockNow.value = Date.now(); }, 1000);
    await loadPublicLobby();
    try {
        user.value = await api<CurrentUser>('/api/v1/me');
        nation.value = await api<Nation | null>('/api/v1/me/nation');
        if (page.value === 'options' && nation.value !== null) {
            profileOwnerName.value = nation.value.owner_name;
            profileComment.value = nation.value.comment;
        }
        try {
            await loadSecretary();
        } catch (error) {
            message.value = error instanceof Error
                ? error.message
                : 'Secretaryの状態を取得できませんでした。';
        }
        if (user.value.can_manage_inquiries) await loadLatestInquiries();
    } catch (error) {
        if (!(error instanceof ApiError && error.status === 401)) {
            message.value = 'ログイン状態を取得できませんでした。公開ロビーは引き続き閲覧できます。';
        }
    } finally {
        if (page.value === 'underground' && !canOpenUnderground()) {
            redirectFromUnavailableUnderground();
        }
        busy.value = false;
    }
});

onUnmounted(() => {
    window.removeEventListener('popstate', syncPageFromHistory);
    if (clockTimer !== null) clearInterval(clockTimer);
    if (summaryDeadlineTimer !== null) clearTimeout(summaryDeadlineTimer);
    if (summaryRetryTimer !== null) clearTimeout(summaryRetryTimer);
    stopSummaryFallbackPolling();
});

function syncPageFromHistory(event: PopStateEvent): void {
    const historicPage = event.state && typeof event.state === 'object'
        ? (event.state as { page?: unknown }).page
        : null;
    if (historicPage === 'secretary' && secretary.value?.name) {
        secretarySection.value = 'main';
        page.value = 'secretary';
        return;
    }
    if (window.location.pathname === '/underground' && !canOpenUnderground()) {
        redirectFromUnavailableUnderground();
        return;
    }
    page.value = window.location.pathname === '/credits'
        ? 'credits'
        : (window.location.pathname === '/underground' ? 'underground' : 'home');
}

function stopSummaryFallbackPolling(): void {
    if (summaryFallbackTimer !== null) clearInterval(summaryFallbackTimer);
    summaryFallbackTimer = null;
}

function startSummaryFallbackPolling(): void {
    stopSummaryFallbackPolling();
    summaryFallbackTimer = setInterval(() => { void refreshFallbackSummary(); }, 60_000);
}

async function loadPublicLobby(): Promise<void> {
    try {
        const [nextWorlds, announcements] = await Promise.all([
            api<World[]>('/api/v1/public/worlds'),
            api<Announcement[]>('/api/v1/public/announcements/latest'),
        ]);
        worlds.value = nextWorlds;
        latestAnnouncements.value = announcements;
        const world = worlds.value[0];
        if (world === undefined) return;
        const [summary, nextRankings, news, events] = await Promise.all([
            api<PublicWorldSummary>(`/api/v1/public/worlds/${world.id}/summary`),
            api<PublicRankingEntry[]>(`/api/v1/public/worlds/${world.id}/rankings`),
            api<MajorNewsFeed>(`/api/v1/public/worlds/${world.id}/major-news`),
            api<PublicEventPage>(`/api/v1/public/worlds/${world.id}/events`),
        ]);
        worldSummary.value = summary;
        scheduleDeadlineRefresh();
        rankings.value = nextRankings;
        majorNews.value = news;
        publicEvents.value = events;
        turnViewCurrentTurn = summary.current_turn;
        startSummaryFallbackPolling();
    } catch {
        message.value = '公開ロビーを取得できませんでした。';
    }
}

async function refreshWorldSummary(reschedule = true): Promise<PublicWorldSummary | null> {
    const world = worlds.value[0];
    if (world === undefined) return null;

    try {
        const summary = await api<PublicWorldSummary>(`/api/v1/public/worlds/${world.id}/summary`);
        worldSummary.value = summary;
        if (reschedule) scheduleDeadlineRefresh();

        return summary;
    } catch {
        return null;
    }
}

async function refreshFallbackSummary(): Promise<void> {
    const summary = await refreshWorldSummary(false);
    if (summary !== null) await refreshTurnDependentViewsIfNeeded(summary);
    if (summary !== null
        && summary.turn_status === 'normal'
        && new Date(summary.next_scheduled_turn_at).getTime() > Date.now()) {
        scheduleDeadlineRefresh();
    }
}

async function refreshTurnDependentViewsIfNeeded(summary: PublicWorldSummary): Promise<boolean> {
    if (turnViewCurrentTurn === summary.current_turn) {
        const currentPreview = page.value === 'preview' ? previewNation.value : null;
        if (currentPreview !== null) {
            try {
                const refreshedPreview = await api<PublicNationDetail>(`/api/v1/public/nations/${currentPreview.id}`);
                const nextMapSpace = refreshedPreview.map_space;
                const mapNeedsReload = mapSpace.value?.id !== nextMapSpace.id
                    || mapSpace.value?.bounds_revision !== nextMapSpace.bounds_revision
                    || map.error.value !== null;
                previewNation.value = refreshedPreview;
                map.synchronizeMapSpace(nextMapSpace);
                mapSpace.value = nextMapSpace;
                if (! mapNeedsReload || refreshedPreview.capital === null) return true;

                await map.loadAround(nextMapSpace, refreshedPreview.capital.x, refreshedPreview.capital.y, {
                    kind: 'public',
                    nationId: refreshedPreview.id,
                });

                return map.error.value === null;
            } catch {
                return false;
            }
        }

        const currentNation = nation.value;
        if (page.value !== 'island' || currentNation === null || currentNation.capital === null) return true;

        try {
            const spaces = await api<MapSpace[]>(`/api/v1/worlds/${currentNation.world_id}/map-spaces`);
            const nextMapSpace = spaces.find((space) => space.key === 'surface') ?? spaces[0] ?? null;
            if (nextMapSpace === null) return false;

            const mapNeedsReload = mapSpace.value?.id !== nextMapSpace.id
                || mapSpace.value?.bounds_revision !== nextMapSpace.bounds_revision
                || map.error.value !== null;
            map.synchronizeMapSpace(nextMapSpace);
            mapSpace.value = nextMapSpace;
            if (! mapNeedsReload) return true;

            await map.loadAround(nextMapSpace, currentNation.capital.x, currentNation.capital.y, { kind: 'private' });

            return map.error.value === null;
        } catch {
            return false;
        }
    }

    const world = worlds.value[0];
    if (world === undefined) return false;
    const currentNation = nation.value;
    const nationRequestGeneration = nationStateGeneration;
    const currentPreview = page.value === 'preview' ? previewNation.value : null;
    const ownerMapSpaceRequest = page.value === 'island' && currentNation !== null
        ? api<MapSpace[]>(`/api/v1/worlds/${currentNation.world_id}/map-spaces`)
        : Promise.resolve(null);
    const ownerUndergroundMapRequest = page.value === 'island' && currentNation !== null
        ? api<UndergroundSurfaceMap | null>('/api/v1/me/underground/surface-map')
        : Promise.resolve(null);
    const [
        rankingResult,
        newsResult,
        eventResult,
        nationResult,
        previewResult,
        ownerMapSpacesResult,
        ownerUndergroundMapResult,
    ] = await Promise.allSettled([
        api<PublicRankingEntry[]>(`/api/v1/public/worlds/${world.id}/rankings`),
        api<MajorNewsFeed>(`/api/v1/public/worlds/${world.id}/major-news`),
        api<PublicEventPage>(`/api/v1/public/worlds/${world.id}/events`),
        currentNation === null ? Promise.resolve(null) : api<Nation | null>('/api/v1/me/nation'),
        currentPreview === null
            ? Promise.resolve(null)
            : api<PublicNationDetail>(`/api/v1/public/nations/${currentPreview.id}`),
        ownerMapSpaceRequest,
        ownerUndergroundMapRequest,
    ] as const);

    let refreshed = true;
    if (rankingResult.status === 'fulfilled') rankings.value = rankingResult.value;
    else refreshed = false;
    if (newsResult.status === 'fulfilled') majorNews.value = newsResult.value;
    else refreshed = false;
    if (eventResult.status === 'fulfilled') publicEvents.value = eventResult.value;
    else refreshed = false;

    let refreshedNation = currentNation;
    if (nationResult.status === 'fulfilled') {
        if (nationRequestGeneration === nationStateGeneration) {
            refreshedNation = nationResult.value;
            nation.value = refreshedNation;
        } else {
            refreshedNation = nation.value;
        }
    } else {
        refreshed = false;
    }

    let refreshedPreview = currentPreview;
    if (previewResult.status === 'fulfilled') {
        refreshedPreview = previewResult.value;
        if (refreshedPreview !== null) {
            previewNation.value = refreshedPreview;
            mapSpace.value = refreshedPreview.map_space;
        }
    } else {
        refreshed = false;
    }

    let refreshedMapSpace = mapSpace.value;
    if (ownerMapSpacesResult.status === 'fulfilled') {
        if (ownerMapSpacesResult.value !== null) {
            refreshedMapSpace = ownerMapSpacesResult.value.find((space) => space.key === 'surface')
                ?? ownerMapSpacesResult.value[0]
                ?? null;
            if (refreshedMapSpace === null) {
                refreshed = false;
            } else {
                map.synchronizeMapSpace(refreshedMapSpace);
                mapSpace.value = refreshedMapSpace;
            }
        }
    } else {
        refreshed = false;
    }

    if (page.value === 'island') {
        if (ownerUndergroundMapResult.status === 'fulfilled') {
            const nextUndergroundMap = ownerUndergroundMapResult.value;
            const selected = selectedUndergroundSlot.value;
            const selectedSlot = selected === null
                ? undefined
                : nextUndergroundMap?.layers
                    .find((layer) => layer.layer === selected.layer)
                    ?.slots.find((slot) => slot.slot_index === selected.slot_index);
            undergroundSurfaceMap.value = nextUndergroundMap;
            selectedUndergroundSlot.value = selected === null || selectedSlot === undefined
                ? null
                : {
                    layer: selected.layer,
                    slot_index: selectedSlot.slot_index,
                    coordinate_label: selectedSlot.coordinate_label,
                    facility_key: selectedSlot.facility_key,
                };
        } else {
            refreshed = false;
        }
    }

    if (page.value === 'island' && refreshedNation !== null && refreshedNation.capital !== null && refreshedMapSpace !== null) {
        await map.loadAround(refreshedMapSpace, refreshedNation.capital.x, refreshedNation.capital.y, { kind: 'private' });
        if (map.error.value !== null) refreshed = false;
    } else if (page.value === 'preview' && refreshedPreview !== null && refreshedPreview.capital !== null) {
        await map.loadAround(refreshedPreview.map_space, refreshedPreview.capital.x, refreshedPreview.capital.y, {
            kind: 'public',
            nationId: refreshedPreview.id,
        });
        if (map.error.value !== null) refreshed = false;
    }

    if (refreshed) turnViewCurrentTurn = summary.current_turn;

    return refreshed;
}

function scheduleDeadlineRefresh(): void {
    if (summaryDeadlineTimer !== null) clearTimeout(summaryDeadlineTimer);
    summaryDeadlineTimer = null;
    if (summaryRetryTimer !== null) clearTimeout(summaryRetryTimer);
    summaryRetryTimer = null;
    if (worldSummary.value?.turn_status !== 'normal') return;

    const remaining = new Date(worldSummary.value.next_scheduled_turn_at).getTime() - Date.now();
    const delay = Math.min(maximumTimeoutDelay, Math.max(0, remaining));
    summaryDeadlineTimer = setTimeout(() => {
        if (remaining > maximumTimeoutDelay) {
            scheduleDeadlineRefresh();
            return;
        }
        void refreshAfterDeadline(0);
    }, delay);
}

async function refreshAfterDeadline(attempt: number): Promise<void> {
    const before = worldSummary.value;
    if (before === null || before.turn_status !== 'normal') return;
    const summary = await refreshWorldSummary(false);
    if (summary === null) {
        scheduleSummaryRetry(attempt);
        return;
    }

    const viewsWereStale = turnViewCurrentTurn !== summary.current_turn;
    if (viewsWereStale && ! await refreshTurnDependentViewsIfNeeded(summary)) {
        scheduleSummaryRetry(attempt);
        return;
    }

    const unchanged = summary.turn_status === 'normal'
        && summary.current_turn === before.current_turn
        && summary.last_successful_turn_at === before.last_successful_turn_at
        && summary.next_scheduled_turn_at === before.next_scheduled_turn_at;
    if (unchanged && ! viewsWereStale) {
        scheduleSummaryRetry(attempt);
    } else {
        if (summaryRetryTimer !== null) clearTimeout(summaryRetryTimer);
        summaryRetryTimer = null;
        scheduleDeadlineRefresh();
    }
}

function scheduleSummaryRetry(attempt: number): void {
    const delay = summaryRetryDelays[attempt];
    if (delay === undefined) return;
    if (summaryRetryTimer !== null) clearTimeout(summaryRetryTimer);
    summaryRetryTimer = setTimeout(() => { void refreshAfterDeadline(attempt + 1); }, delay);
}

async function openAnnouncements(pageNumber = 1): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const envelope = await apiEnvelope<Announcement[]>(`/api/v1/public/announcements?page=${pageNumber}`);
        announcementItems.value = envelope.data;
        const currentPage = Number(envelope.meta?.current_page ?? pageNumber);
        const lastPage = Number(envelope.meta?.last_page ?? currentPage);
        announcementPageNumber.value = Number.isInteger(currentPage) && currentPage > 0 ? currentPage : pageNumber;
        announcementLastPage.value = Number.isInteger(lastPage) && lastPage > 0 ? lastPage : announcementPageNumber.value;
        announcementView.value = 'list';
        page.value = 'announcements';
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'お知らせ一覧を取得できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function openAnnouncement(id: number): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        announcementDetail.value = await api<Announcement>(`/api/v1/public/announcements/${id}`);
        announcementView.value = 'detail';
        page.value = 'announcements';
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'お知らせを取得できませんでした。';
    } finally {
        busy.value = false;
    }
}

function editAnnouncement(announcement: Announcement | null = null): void {
    announcementFormId.value = announcement?.id ?? null;
    announcementTitle.value = announcement?.title ?? '';
    announcementBody.value = announcement?.body ?? '';
    announcementErrors.value = {};
    announcementView.value = 'form';
    page.value = 'announcements';
}

async function saveAnnouncement(): Promise<void> {
    busy.value = true;
    message.value = '';
    announcementErrors.value = {};
    const id = announcementFormId.value;
    try {
        const saved = await api<Announcement>(id === null
            ? '/api/v1/admin/announcements'
            : `/api/v1/admin/announcements/${id}`, {
            method: id === null ? 'POST' : 'PATCH',
            body: JSON.stringify({ title: announcementTitle.value, body: announcementBody.value }),
        });
        announcementDetail.value = saved;
        announcementView.value = 'detail';
        latestAnnouncements.value = await api<Announcement[]>('/api/v1/public/announcements/latest');
    } catch (error) {
        announcementErrors.value = validationErrors(error);
        if (Object.keys(announcementErrors.value).length === 0) {
            message.value = error instanceof Error ? error.message : 'お知らせを保存できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

async function deleteAnnouncement(announcement: Announcement): Promise<void> {
    if (!window.confirm(`「${announcement.title}」を削除しますか？`)) return;
    busy.value = true;
    message.value = '';
    try {
        await api<null>(`/api/v1/admin/announcements/${announcement.id}`, { method: 'DELETE' });
        latestAnnouncements.value = await api<Announcement[]>('/api/v1/public/announcements/latest');
        await openAnnouncements(1);
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'お知らせを削除できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function loadLatestInquiries(): Promise<void> {
    try {
        latestInquiries.value = await api<InquirySummary[]>('/api/v1/admin/inquiries/latest');
    } catch {
        message.value = '管理者向けの最新お問い合わせを取得できませんでした。';
    }
}

function openInquiry(): void {
    inquiryCategory.value = 'bug';
    inquirySubject.value = '';
    inquiryBody.value = '';
    inquirySubmissionKey.value = crypto.randomUUID();
    inquiryErrors.value = {};
    inquiryConfirmation.value = null;
    if (inquiryAttachment.value !== null) inquiryAttachment.value.value = '';
    page.value = 'inquiry';
}

async function submitInquiry(): Promise<void> {
    busy.value = true;
    message.value = '';
    inquiryErrors.value = {};
    const form = new FormData();
    form.append('submission_key', inquirySubmissionKey.value);
    form.append('category', inquiryCategory.value);
    form.append('subject', inquirySubject.value);
    form.append('body', inquiryBody.value);
    const attachment = inquiryAttachment.value?.files?.[0];
    if (attachment !== undefined) form.append('attachment', attachment);

    try {
        inquiryConfirmation.value = await api<InquirySubmission>('/api/v1/inquiries', {
            method: 'POST',
            body: form,
        });
        inquirySubmissionKey.value = crypto.randomUUID();
    } catch (error) {
        inquiryErrors.value = validationErrors(error);
        if (Object.keys(inquiryErrors.value).length === 0) {
            message.value = error instanceof Error ? error.message : 'お問い合わせを送信できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

async function openAdminInquiries(pageNumber = 1): Promise<void> {
    if (!user.value?.can_manage_inquiries) return;
    busy.value = true;
    message.value = '';
    try {
        const envelope = await apiEnvelope<InquirySummary[]>(`/api/v1/admin/inquiries?page=${pageNumber}`);
        inquiryItems.value = envelope.data;
        const currentPage = Number(envelope.meta?.current_page ?? pageNumber);
        const lastPage = Number(envelope.meta?.last_page ?? currentPage);
        inquiryPageNumber.value = Number.isInteger(currentPage) && currentPage > 0 ? currentPage : pageNumber;
        inquiryLastPage.value = Number.isInteger(lastPage) && lastPage > 0 ? lastPage : inquiryPageNumber.value;
        inquiryDetail.value = null;
        page.value = 'admin-inquiries';
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'お問い合わせ一覧を取得できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function openAdminInquiry(managementId: string): Promise<void> {
    if (!user.value?.can_manage_inquiries) return;
    busy.value = true;
    message.value = '';
    try {
        const id = Number(managementId.replace('INQ-', ''));
        inquiryDetail.value = await api<InquiryDetail>(`/api/v1/admin/inquiries/${id}`);
        page.value = 'admin-inquiries';
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'お問い合わせ詳細を取得できませんでした。';
    } finally {
        busy.value = false;
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
        message.value = '公開島ログを取得できませんでした。';
    }
}

async function openOwnIsland(): Promise<void> {
    const currentNation = nation.value;
    if (currentNation?.capital === null || currentNation === null) return;
    busy.value = true;
    message.value = '';
    try {
        const [spacesResult, undergroundMapResult] = await Promise.allSettled([
            api<MapSpace[]>(`/api/v1/worlds/${currentNation.world_id}/map-spaces`),
            api<UndergroundSurfaceMap | null>('/api/v1/me/underground/surface-map'),
        ]);
        if (spacesResult.status === 'rejected') throw spacesResult.reason;
        const spaces = spacesResult.value;
        undergroundSurfaceMap.value = undergroundMapResult.status === 'fulfilled' ? undergroundMapResult.value : null;
        selectedUndergroundSlot.value = null;
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
    if (nation.value?.id === nationId) {
        await openOwnIsland();
        return;
    }
    busy.value = true;
    message.value = '';
    try {
        const detail = await api<PublicNationDetail>(`/api/v1/public/nations/${nationId}`);
        if (detail.capital === null) throw new Error('首都がまだありません。');
        previewNation.value = detail;
        selectedUndergroundSlot.value = null;
        mapSpace.value = detail.map_space;
        await map.loadAround(
            detail.map_space,
            detail.capital.x,
            detail.capital.y,
            nation.value === null ? { kind: 'public', nationId: detail.id } : { kind: 'private' },
        );
        page.value = 'preview';
    } catch (error) {
        message.value = error instanceof Error ? error.message : '島previewを読み込めませんでした。';
    } finally {
        busy.value = false;
    }
}

async function refreshMyNation(): Promise<void> {
    if (user.value === null) return;
    const requestGeneration = nationStateGeneration;
    try {
        const refreshedNation = await api<Nation | null>('/api/v1/me/nation');
        if (requestGeneration === nationStateGeneration) nation.value = refreshedNation;
    } catch {
        // The authoritative message response is already rendered; account data can refresh later.
    }
}

async function loadSecretary(): Promise<void> {
    if (user.value === null) return;
    const worldQuery = nation.value === null ? '' : `?world_id=${nation.value.world_id}`;
    secretary.value = await api<Secretary | null>(`/api/v1/me/secretary${worldQuery}`);
    if (secretary.value !== null) setOwnedSecretaryProfile(secretary.value);
    if (page.value === 'options') profileSecretaryName.value = secretary.value?.name ?? '';
}

function setViewedSecretaryProfile(profile: SecretaryProfile, worldId: number | null): void {
    viewedSecretaryProfile.value = profile;
    viewedSecretaryWorldId.value = worldId;
    secretaryBiography.value = profile.biography;
}

function setOwnedSecretaryProfile(value: Secretary): void {
    setViewedSecretaryProfile({
        ...value.profile,
        name: value.name,
        equipment: value.equipment,
        is_owner: true,
    }, nation.value?.world_id ?? null);
}

async function openPublicSecretary(secretaryId: number, worldId: number): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const profile = await api<SecretaryProfile>(`/api/v1/secretaries/${secretaryId}?world_id=${worldId}`);
        setViewedSecretaryProfile(profile, worldId);
        secretarySection.value = 'main';
        page.value = 'secretary';
    } catch (error) {
        message.value = error instanceof Error ? error.message : '秘書プロフィールを読み込めませんでした。';
    } finally {
        busy.value = false;
    }
}

async function reloadViewedSecretaryProfile(): Promise<void> {
    const current = viewedSecretaryProfile.value;
    if (current === null) return;
    if (current.is_owner && viewedSecretaryWorldId.value === null) {
        await loadSecretary();
        return;
    }
    const worldQuery = viewedSecretaryWorldId.value === null ? '' : `?world_id=${viewedSecretaryWorldId.value}`;
    const profile = await api<SecretaryProfile>(`/api/v1/secretaries/${current.id}${worldQuery}`);
    setViewedSecretaryProfile(profile, viewedSecretaryWorldId.value);
}

async function updateSecretaryBiography(): Promise<void> {
    if (viewedSecretaryProfile.value?.is_owner !== true) return;
    busy.value = true;
    secretaryProfileErrors.value = {};
    try {
        const profile = await api<SecretaryProfile>('/api/v1/me/secretary/profile', {
            method: 'PATCH',
            body: JSON.stringify({ biography: secretaryBiography.value }),
        });
        setViewedSecretaryProfile(profile, viewedSecretaryWorldId.value);
        await reloadViewedSecretaryProfile();
    } catch (error) {
        secretaryProfileErrors.value = validationErrors(error);
        if (Object.keys(secretaryProfileErrors.value).length === 0) {
            message.value = error instanceof Error ? error.message : '経歴を保存できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

function openSecretaryImageModal(): void {
    const metadata = viewedSecretaryProfile.value?.editable_image_metadata;
    secretaryImageFile.value = null;
    if (secretaryImageInput.value !== null) secretaryImageInput.value.value = '';
    secretaryImageCreationMethod.value = metadata?.creation_method ?? 'self_made';
    secretaryImageCredit.value = metadata?.credit ?? '';
    secretaryImageErrors.value = {};
    secretaryImageModalOpen.value = true;
}

function closeSecretaryImageModal(): void {
    if (busy.value) return;
    secretaryImageModalOpen.value = false;
    secretaryImageFile.value = null;
}

function selectSecretaryImage(event: Event): void {
    secretaryImageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function submitSecretaryImage(): Promise<void> {
    const profile = viewedSecretaryProfile.value;
    if (profile?.is_owner !== true) return;
    if (secretaryImageFile.value === null && profile.editable_image_metadata === null) {
        secretaryImageErrors.value = { image: '画像を選択してください。' };
        return;
    }
    busy.value = true;
    secretaryImageErrors.value = {};
    try {
        let committed: SecretaryProfile;
        if (secretaryImageFile.value !== null) {
            const formData = new FormData();
            formData.append('image', secretaryImageFile.value);
            formData.append('creation_method', secretaryImageCreationMethod.value);
            formData.append('credit', secretaryImageCredit.value);
            committed = await api<SecretaryProfile>('/api/v1/me/secretary/main-image', {
                method: 'POST',
                body: formData,
            });
        } else {
            committed = await api<SecretaryProfile>('/api/v1/me/secretary/main-image', {
                method: 'PATCH',
                body: JSON.stringify({
                    creation_method: secretaryImageCreationMethod.value,
                    credit: secretaryImageCredit.value,
                }),
            });
        }
        setViewedSecretaryProfile(committed, viewedSecretaryWorldId.value);
        await reloadViewedSecretaryProfile();
        busy.value = false;
        closeSecretaryImageModal();
    } catch (error) {
        secretaryImageErrors.value = validationErrors(error);
        if (Object.keys(secretaryImageErrors.value).length === 0) {
            message.value = error instanceof Error ? error.message : 'メイン画像を保存できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

function openSecretaryPreferencesModal(): void {
    const preferences = viewedSecretaryProfile.value?.viewer_preferences;
    secretaryShowAiImages.value = preferences?.show_ai_generated_images ?? true;
    secretaryImageFallback.value = preferences?.own_secretary_fallback ?? preferences?.fallback ?? 'silhouette';
    secretaryPreferenceErrors.value = {};
    secretaryPreferencesModalOpen.value = true;
}

function closeSecretaryPreferencesModal(): void {
    if (!busy.value) secretaryPreferencesModalOpen.value = false;
}

async function saveSecretaryImagePreferences(): Promise<void> {
    if (user.value === null) return;
    busy.value = true;
    secretaryPreferenceErrors.value = {};
    try {
        await api('/api/v1/me/secretary/image-preferences', {
            method: 'PATCH',
            body: JSON.stringify({
                show_ai_generated_images: secretaryShowAiImages.value,
                own_secretary_fallback: secretaryImageFallback.value,
            }),
        });
        await reloadViewedSecretaryProfile();
        busy.value = false;
        closeSecretaryPreferencesModal();
    } catch (error) {
        secretaryPreferenceErrors.value = validationErrors(error);
        if (Object.keys(secretaryPreferenceErrors.value).length === 0) {
            message.value = error instanceof Error ? error.message : '画像表示設定を保存できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

async function loadEquipmentOptions(slot: number, preserveFreshChoice = false): Promise<void> {
    const requestGeneration = ++equipmentRequestGeneration;
    equipmentOptionsLoading.value = true;
    equipmentError.value = '';
    if (!preserveFreshChoice) equipmentRequireFreshChoice.value = false;
    try {
        const worldQuery = nation.value === null ? '' : `?world_id=${nation.value.world_id}`;
        const options = await api<SecretaryEquipmentOptions>(
            `/api/v1/me/secretary/equipment/${slot}/options${worldQuery}`,
        );
        if (requestGeneration !== equipmentRequestGeneration || equipmentModalSlot.value !== slot) return;
        equipmentOptions.value = options;
    } catch (error) {
        if (requestGeneration !== equipmentRequestGeneration || equipmentModalSlot.value !== slot) return;
        equipmentOptions.value = null;
        equipmentError.value = error instanceof Error ? error.message : '装備候補を読み込めませんでした。';
    } finally {
        if (requestGeneration === equipmentRequestGeneration) equipmentOptionsLoading.value = false;
    }
}

async function openEquipmentModal(slot: number): Promise<void> {
    if (secretary.value === null) return;
    equipmentModalSlot.value = slot;
    equipmentOptions.value = null;
    equipmentError.value = '';
    equipmentRequireFreshChoice.value = false;
    await loadEquipmentOptions(slot);
}

function closeEquipmentModal(): void {
    if (equipmentSubmitting.value) return;
    equipmentRequestGeneration++;
    equipmentModalSlot.value = null;
    equipmentOptions.value = null;
    equipmentOptionsLoading.value = false;
    equipmentError.value = '';
    equipmentRequireFreshChoice.value = false;
}

async function submitEquipment(itemId: number | null): Promise<void> {
    const slot = equipmentModalSlot.value;
    const options = equipmentOptions.value;
    if (slot === null || options === null || equipmentSubmitting.value || equipmentRequireFreshChoice.value) return;

    equipmentSubmitting.value = true;
    equipmentError.value = '';
    message.value = '';
    try {
        const committedSecretary = await api<Secretary>(`/api/v1/me/secretary/equipment/${slot}`, {
            method: 'PUT',
            body: JSON.stringify({ item_id: itemId, expected_version: options.equipment_version }),
        });
        secretary.value = committedSecretary;
        setOwnedSecretaryProfile(committedSecretary);
        try {
            await loadSecretary();
        } catch {
            message.value = '装備は変更されましたが、最新の効果表示を読み込めませんでした。画面を開き直してください。';
        }
        equipmentSubmitting.value = false;
        closeEquipmentModal();
    } catch (error) {
        if (error instanceof ApiError && error.status === 409 && error.code === 'secretary_equipment_version_conflict') {
            equipmentRequireFreshChoice.value = true;
            try {
                await loadSecretary();
                await loadEquipmentOptions(slot, true);
            } catch {
                equipmentError.value = '最新の装備状態を読み込めませんでした。画面を開き直してください。';
            }
        } else {
            equipmentError.value = error instanceof Error ? error.message : '装備を変更できませんでした。';
        }
    } finally {
        equipmentSubmitting.value = false;
    }
}

async function sellSecretaryItem(item: Secretary['inventory']['items'][number]): Promise<void> {
    if (secretary.value === null || nation.value === null || busy.value) return;
    if (!window.confirm(`${item.name} Lv${item.level}を${item.fixed_sale_price_money.toLocaleString()}億円で売却しますか？`)) return;
    busy.value = true;
    message.value = '';
    try {
        const committed = await api<{ secretary: Secretary; nation: Nation }>(
            `/api/v1/me/secretary/items/${item.id}/sell`,
            { method: 'POST', body: JSON.stringify({ world_id: nation.value.world_id }) },
        );
        secretary.value = committed.secretary;
        nation.value = committed.nation;
        setOwnedSecretaryProfile(committed.secretary);
        message.value = `${item.name} Lv${item.level}を${item.fixed_sale_price_money.toLocaleString()}億円で売却しました。`;
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'アイテムを売却できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function openSecretary(): Promise<void> {
    if (user.value === null) return;
    busy.value = true;
    message.value = '';
    secretaryErrors.value = {};
    try {
        await loadSecretary();
        if (secretary.value === null) throw new Error('Secretaryの状態を取得できませんでした。');
        secretaryName.value = 'ペリドット';
        secretarySection.value = 'main';
        page.value = 'secretary';
    } catch (error) {
        message.value = error instanceof Error ? error.message : 'Secretaryを読み込めませんでした。';
    } finally {
        busy.value = false;
    }
}

function openUnderground(): void {
    if (user.value === null || secretary.value?.name === null) return;
    message.value = '';
    window.history.replaceState({ page: page.value }, '', window.location.href);
    page.value = 'underground';
    if (window.location.pathname !== '/underground') window.history.pushState({ page: 'underground' }, '', '/underground');
}

async function returnFromUnderground(): Promise<void> {
    secretarySection.value = 'main';
    page.value = 'secretary';
    window.history.replaceState({ page: 'secretary' }, '', '/');
    try {
        await loadSecretary();
    } catch {
        message.value = '戦闘Lvを読み込めませんでした。画面を開き直してください。';
    }
}

async function nameSecretary(): Promise<void> {
    if (secretary.value === null || secretary.value.name !== null) return;
    busy.value = true;
    message.value = '';
    secretaryErrors.value = {};
    try {
        const committedSecretary = await api<Secretary>('/api/v1/me/secretary/name', {
            method: 'POST',
            body: JSON.stringify({ name: secretaryName.value }),
        });
        secretary.value = committedSecretary;
        setOwnedSecretaryProfile(committedSecretary);
        try {
            await loadSecretary();
        } catch {
            message.value = `秘書は「${committedSecretary.name ?? secretaryName.value}」と命名されましたが、最新の効果表示を読み込めませんでした。画面を開き直してください。`;
        }
    } catch (error) {
        secretaryErrors.value = validationErrors(error);
        message.value = Object.keys(secretaryErrors.value).length === 0
            ? (error instanceof Error ? error.message : 'Secretaryを命名できませんでした。')
            : '';
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
        const createdNation = await api<Nation>('/api/v1/nations', {
            method: 'POST',
            body: JSON.stringify({
                request_key: nationRegistrationRequestKey.value,
                world_id: world.id,
                name: nationName.value,
                owner_name: nationOwnerName.value,
                comment: nationComment.value,
            }),
        });
        nationStateGeneration++;
        nation.value = createdNation;
        await loadSecretary();
        nationRegistrationRequestKey.value = crypto.randomUUID();
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

function selectTheme(selectedTheme: ThemeMode): void {
    themeMode.value = selectedTheme;
    document.documentElement.dataset.theme = selectedTheme;
    const secureAttribute = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `hakoniwa_theme=${selectedTheme}; Path=/; Max-Age=31536000; SameSite=Lax${secureAttribute}`;
}

function openOptions(): void {
    if (nation.value !== null) {
        profileOwnerName.value = nation.value.owner_name;
        profileComment.value = nation.value.comment;
        profileSecretaryName.value = secretary.value?.name ?? '';
    }
    profileErrors.value = {};
    profileSecretaryErrors.value = {};
    message.value = '';
    page.value = 'options';
}

async function renameProfileSecretary(): Promise<void> {
    if (secretary.value === null || secretary.value.name === null) return;
    busy.value = true;
    message.value = '';
    profileSecretaryErrors.value = {};
    try {
        const committedSecretary = await api<Secretary>('/api/v1/me/secretary/name', {
            method: 'PATCH',
            body: JSON.stringify({ name: profileSecretaryName.value }),
        });
        secretary.value = committedSecretary;
        setOwnedSecretaryProfile(committedSecretary);
        profileSecretaryName.value = committedSecretary.name ?? profileSecretaryName.value;
        try {
            await loadSecretary();
            profileSecretaryName.value = secretary.value.name ?? '';
            message.value = `秘書の名前を「${profileSecretaryName.value}」に変更しました。`;
        } catch {
            message.value = `秘書の名前は「${profileSecretaryName.value}」に変更されましたが、最新の効果表示を読み込めませんでした。画面を開き直してください。`;
        }
    } catch (error) {
        profileSecretaryErrors.value = validationErrors(error);
        message.value = Object.keys(profileSecretaryErrors.value).length === 0
            ? (error instanceof Error ? error.message : '秘書名を変更できませんでした。')
            : '';
    } finally {
        busy.value = false;
    }
}

async function updateProfile(): Promise<void> {
    if (nation.value === null) return;
    const requestGeneration = nationStateGeneration;
    const targetNationId = nation.value.id;
    busy.value = true;
    message.value = '';
    profileErrors.value = {};
    try {
        const updatedNation = await api<Nation>(`/api/v1/nations/${targetNationId}/profile`, {
            method: 'PATCH',
            body: JSON.stringify({
                owner_name: profileOwnerName.value,
                comment: profileComment.value,
            }),
        });
        if (requestGeneration !== nationStateGeneration) return;
        nationStateGeneration++;
        nation.value = updatedNation;
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

async function requestDormancy(): Promise<void> {
    const target = nation.value;
    if (target === null || !target.can_request_dormancy) return;
    busy.value = true;
    message.value = '';
    dormancyError.value = '';
    try {
        nation.value = await api<Nation>(`/api/v1/nations/${target.id}/dormancy`, {
            method: 'POST',
            body: JSON.stringify({ days: dormancyDays.value }),
        });
        nationStateGeneration++;
        message.value = `${dormancyDays.value}日間の休止を開始しました。期限前には解除できません。`;
        await loadPublicLobby();
    } catch (error) {
        const errors = validationErrors(error);
        dormancyError.value = errors.days
            ?? (error instanceof Error ? error.message : '島を休止できませんでした。');
    } finally {
        busy.value = false;
    }
}

function openAbandonmentModal(): void {
    abandonmentConfirmationName.value = '';
    abandonmentError.value = '';
    abandonmentModalOpen.value = true;
}

function closeAbandonmentModal(): void {
    if (busy.value) return;
    abandonmentModalOpen.value = false;
    abandonmentConfirmationName.value = '';
    abandonmentError.value = '';
}

function clearAbandonedNationState(): void {
    map.clear();
    nation.value = null;
    mapSpace.value = null;
    page.value = 'home';
    abandonmentModalOpen.value = false;
    abandonmentConfirmationName.value = '';
    nationRegistrationRequestKey.value = crypto.randomUUID();
}

function shouldReconcileAbandonment(error: unknown): boolean {
    if (!(error instanceof ApiError)) return true;

    return error.status >= 500 || error.code === 'nation_not_active';
}

async function reconcileAmbiguousAbandonment(error: unknown): Promise<void> {
    if (! shouldReconcileAbandonment(error)) return;

    const reconciliationGeneration = ++nationStateGeneration;
    try {
        const currentNation = await api<Nation | null>('/api/v1/me/nation');
        if (reconciliationGeneration !== nationStateGeneration) return;
        if (currentNation === null) {
            clearAbandonedNationState();
            await loadPublicLobby();
            abandonmentError.value = '';
            message.value = '島の破棄を確認しました。新しい島を登録できます。';

            return;
        }

        nation.value = currentNation;
    } catch {
        abandonmentError.value = '島の破棄結果を確認できませんでした。通信状態を確認して、しばらくしてから再度お試しください。';
    }
}

async function abandonNation(): Promise<void> {
    const target = nation.value;
    if (target === null || !abandonmentConfirmed.value) return;

    busy.value = true;
    message.value = '';
    abandonmentError.value = '';
    let committed = false;
    try {
        await api(`/api/v1/nations/${target.id}/abandon`, {
            method: 'POST',
            body: JSON.stringify({ confirmation_name: abandonmentConfirmationName.value }),
        });
        committed = true;
        const reconciliationGeneration = ++nationStateGeneration;
        clearAbandonedNationState();
        const currentNation = await api<Nation | null>('/api/v1/me/nation');
        if (reconciliationGeneration === nationStateGeneration) nation.value = currentNation;
        await loadPublicLobby();
        message.value = '島を破棄しました。新しい島を登録できます。';
    } catch (error) {
        const errors = validationErrors(error);
        abandonmentError.value = errors.confirmation_name
            ?? (error instanceof Error ? error.message : '島を破棄できませんでした。');
        if (committed) {
            clearAbandonedNationState();
            message.value = '島は破棄されました。最新状態を再取得できなかったため、しばらくしてから再度お試しください。';
        } else {
            await reconcileAmbiguousAbandonment(error);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <header class="site-header">
        <a class="brand" href="#" @click.prevent="page = 'home'">
            箱庭諸島<span>２S＋</span><small class="app-version">ver {{ applicationVersion }}</small>
        </a>
        <nav aria-label="主要ナビゲーション">
            <button type="button" @click="page = 'home'">TOP</button>
            <button v-if="nation" type="button" @click="openOwnIsland">自島へ</button>
            <button v-if="secretary" type="button" @click="openSecretary">{{ secretary.header_label }}</button>
            <button v-if="nation" type="button" @click="page = 'resources'">資源売却</button>
            <button v-if="nation" type="button" @click="page = 'trading-post'">交易場</button>
            <button type="button" @click="openOptions">オプション</button>
            <a href="/manual">マニュアル</a>
        </nav>
        <div class="session-actions">
            <template v-if="user">
                <div class="session-user-actions">
                    <div class="session-account-actions">
                        <span>{{ user.display_name }}</span>
                        <button v-if="!nation" type="button" @click="page = 'home'">島を作る</button>
                        <button type="button" @click="page = 'account'">アカウント</button>
                    </div>
                    <button
                        v-if="!user.can_manage_inquiries"
                        class="inquiry-shortcut"
                        type="button"
                        @click="openInquiry"
                    >
                        お問い合わせ
                    </button>
                </div>
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
                <div>
                    <dt>ターン更新（2時間ごと）</dt>
                    <dd>{{ worldSummary?.current_turn ?? 1 }}</dd>
                    <small class="hakoniwa-calendar">{{ worldSummary?.hakoniwa_calendar?.label ?? '箱庭歴 1年1月' }}</small>
                </div>
                <div><dt>島数</dt><dd>{{ (worldSummary?.nation_count ?? 0).toLocaleString() }}</dd></div>
                <div><dt>総人口</dt><dd>{{ (worldSummary?.total_population ?? 0).toLocaleString() }}人</dd></div>
            </dl>

            <section v-if="worldSummary" class="turn-status-card" :data-status="worldSummary.turn_status" aria-label="ターン更新状況">
                <div>
                    <span>最終ターン更新</span>
                    <strong>{{ formatTurnTimestamp(worldSummary.last_successful_turn_at) }}</strong>
                </div>
                <div v-if="worldSummary.turn_status === 'normal'">
                    <span>次回更新まで</span>
                    <strong class="turn-countdown">{{ nextTurnCountdown }}</strong>
                    <time :datetime="worldSummary.next_scheduled_turn_at">予定 {{ formatTurnTimestamp(worldSummary.next_scheduled_turn_at) }}</time>
                </div>
                <p v-else role="status">{{ turnStatusMessage }}</p>
            </section>

            <section class="announcement-window" aria-labelledby="latest-announcements-heading">
                <div class="section-heading">
                    <div><p class="eyebrow">ANNOUNCEMENTS</p><h2 id="latest-announcements-heading">お知らせ</h2></div>
                    <button type="button" @click="openAnnouncements(1)">すべて表示</button>
                </div>
                <ol v-if="latestAnnouncements.length" class="announcement-list compact">
                    <li v-for="announcement in latestAnnouncements" :key="announcement.id">
                        <button type="button" @click="openAnnouncement(announcement.id)">{{ announcement.title }}</button>
                        <time :datetime="announcement.created_at">{{ formatAnnouncementDate(announcement.created_at) }}</time>
                    </li>
                </ol>
                <p v-else class="empty-state">お知らせはまだありません。</p>
            </section>

            <section v-if="user?.can_manage_inquiries" class="inquiry-window" aria-labelledby="inquiry-heading">
                <div class="section-heading">
                    <div><p class="eyebrow">CONTACT</p><h2 id="inquiry-heading">お問い合わせ</h2></div>
                    <button type="button" @click="openInquiry">お問い合わせを送る</button>
                </div>
                <ol v-if="latestInquiries.length" class="inquiry-list compact">
                    <li v-for="inquiry in latestInquiries" :key="inquiry.management_id">
                        <button type="button" @click="openAdminInquiry(inquiry.management_id)">
                            {{ inquiry.management_id }} [{{ inquiry.category_label }}] {{ inquiry.subject }}
                        </button>
                    </li>
                </ol>
                <p v-else class="empty-state">お問い合わせはまだありません。</p>
                <button type="button" @click="openAdminInquiries(1)">すべて見る</button>
            </section>

            <div class="lobby-grid">
                <section class="ranking-card">
                    <div class="section-heading">
                        <div><p class="eyebrow">ISLANDS</p><h2>島一覧</h2></div>
                        <span>誰でも閲覧できます</span>
                    </div>
                    <div class="ranking-scroll">
                        <table>
                            <thead><tr><th>順位</th><th>島名＋賞/討伐</th><th>人口</th><th>面積</th><th>資金</th><th>食料</th><th>農場規模</th><th>工場規模</th><th>採掘場規模</th><th>生存ターン</th></tr></thead>
                            <tbody v-for="entry in rankings" :key="entry.id" class="ranking-entry">
                                <tr class="ranking-primary-row">
                                    <td rowspan="2" class="ranking-rank">{{ entry.rank }}</td>
                                    <td class="ranking-island">
                                        <button
                                            type="button"
                                            :class="{
                                                'is-finance-only': entry.finance_only_turns > 0,
                                                'is-dormant': entry.state === 'dormant',
                                                'is-karma-positive': entry.karma > 0,
                                            }"
                                            @click="openPreview(entry.id)"
                                        >
                                            {{ entry.name }}<template v-if="entry.state === 'active' && entry.finance_only_turns > 0"> ({{ entry.finance_only_turns }})</template>
                                        </button>
                                        <RankingAchievements v-if="entry.achievements" :achievements="entry.achievements" />
                                        <span v-if="entry.state_label" class="state-badge">{{ entry.state_label }}</span>
                                        <span v-if="entry.karma_badge" class="karma-badge">{{ entry.karma_badge }}</span>
                                    </td>
                                    <td>{{ entry.total_population.toLocaleString() }}人</td>
                                    <td>{{ entry.owned_land_cells.toLocaleString() }}セル</td>
                                    <td>{{ entry.money_display }}</td>
                                    <td>{{ entry.food_total_tons.toLocaleString() }}トン</td>
                                    <td>{{ formatFacilityScale(entry.farm_capacity_people) }}</td>
                                    <td>{{ formatFacilityScale(entry.factory_capacity_people) }}</td>
                                    <td>{{ formatFacilityScale(entry.mine_capacity_people) }}</td>
                                    <td>{{ entry.survival_turns.toLocaleString() }}</td>
                                </tr>
                                <tr class="ranking-owner-row">
                                    <td colspan="9">{{ entry.owner_name }}<template v-if="entry.comment">：{{ entry.comment }}</template></td>
                                </tr>
                            </tbody>
                            <tbody v-if="rankings.length === 0"><tr><td colspan="10" class="empty-state">まだ島がありません。</td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="events-card">
                    <div class="section-heading">
                        <div><p class="eyebrow">MAJOR NEWS</p><h2>重大ニュース</h2></div>
                    </div>
                    <template v-if="majorNews?.groups.length">
                        <section v-for="group in majorNews.groups" :key="group.target_turn" class="public-event-group">
                            <h3>第{{ group.target_turn }}ターン</h3>
                            <ol class="event-list">
                                <li v-for="event in group.events" :key="event.id">
                                    <span class="event-mark" aria-hidden="true"></span>
                                    <strong>{{ event.message }}</strong>
                                </li>
                            </ol>
                        </section>
                    </template>
                    <p v-else class="empty-state">重大ニュースはまだありません。</p>
                </section>

                <section class="events-card">
                    <div class="section-heading">
                        <div><p class="eyebrow">PUBLIC ISLAND LOG</p><h2>公開島ログ</h2></div>
                    </div>
                    <template v-if="publicEvents?.groups.length">
                        <section v-for="group in publicEvents.groups" :key="group.target_turn" class="public-event-group">
                            <h3>第{{ group.target_turn }}ターン</h3>
                            <ol class="event-list">
                                <li v-for="event in group.events" :key="event.id">
                                    <span class="event-mark" aria-hidden="true"></span>
                                    <strong>{{ event.message }}</strong>
                                </li>
                            </ol>
                        </section>
                    </template>
                    <p v-else class="empty-state">このターン範囲には公開島ログがありません。</p>
                    <nav v-if="publicEvents" class="event-pager" aria-label="公開島ログのページ">
                        <button type="button" :disabled="!publicEvents.has_newer_page" @click="loadPublicEvents(publicEvents.page - 1)">新しい2ターン</button>
                        <span>{{ publicEvents.page }}ページ</span>
                        <button type="button" :disabled="!publicEvents.has_older_page" @click="loadPublicEvents(publicEvents.page + 1)">過去2ターン</button>
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

        <section v-else-if="user && page === 'inquiry'" class="panel inquiry-page">
            <p class="eyebrow">CONTACT</p>
            <h1>お問い合わせ</h1>
            <div v-if="inquiryConfirmation" class="inquiry-confirmation" role="status">
                <h2>送信しました</h2>
                <p>管理番号 <strong>{{ inquiryConfirmation.management_id }}</strong></p>
                <button type="button" @click="page = 'home'">TOPへ戻る</button>
            </div>
            <form v-else class="inquiry-form" enctype="multipart/form-data" @submit.prevent="submitInquiry">
                <label>種類
                    <select v-model="inquiryCategory" required>
                        <option value="bug">バグ報告</option>
                        <option value="request">要望</option>
                        <option value="idea">アイデア</option>
                        <option value="secretary_fan_art">秘書のファンアート</option>
                        <option value="other">その他</option>
                    </select>
                    <span v-if="inquiryErrors.category" class="field-error" role="alert">{{ inquiryErrors.category }}</span>
                </label>
                <label>件名
                    <input v-model="inquirySubject" maxlength="160" required>
                    <span v-if="inquiryErrors.subject" class="field-error" role="alert">{{ inquiryErrors.subject }}</span>
                </label>
                <label>本文（プレーンテキスト）
                    <textarea v-model="inquiryBody" maxlength="20000" rows="10" required></textarea>
                    <span v-if="inquiryErrors.body" class="field-error" role="alert">{{ inquiryErrors.body }}</span>
                </label>
                <label>添付画像（任意・1枚・最大10MB）
                    <input ref="inquiryAttachment" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
                    <small class="field-hint">PNG、JPEG、WebP、GIF。添付画像に個人情報などを含む画像は添付しないでください。</small>
                    <span v-if="inquiryErrors.attachment" class="field-error" role="alert">{{ inquiryErrors.attachment }}</span>
                </label>
                <div class="inquiry-actions">
                    <button class="button primary" type="submit" :disabled="busy">送信</button>
                    <button type="button" :disabled="busy" @click="page = 'home'">キャンセル</button>
                </div>
            </form>
        </section>

        <section v-else-if="user?.can_manage_inquiries && page === 'admin-inquiries'" class="panel inquiry-admin-page">
            <div class="section-heading">
                <div><p class="eyebrow">INQUIRIES</p><h1>お問い合わせ管理</h1></div>
                <button type="button" @click="page = 'home'">TOPへ戻る</button>
            </div>
            <article v-if="inquiryDetail" class="inquiry-detail">
                <p><strong>{{ inquiryDetail.management_id }}</strong> [{{ inquiryDetail.category_label }}]</p>
                <h2>{{ inquiryDetail.subject }}</h2>
                <dl>
                    <div><dt>User</dt><dd>#{{ inquiryDetail.user.id }} {{ inquiryDetail.user.display_name }}</dd></div>
                    <div><dt>Nation</dt><dd>{{ inquiryDetail.nation ? `N${inquiryDetail.nation.nation_number} ${inquiryDetail.nation.name}` : 'なし' }}</dd></div>
                    <div><dt>投稿ターン</dt><dd>{{ inquiryDetail.world.submitted_turn }}</dd></div>
                    <div><dt>アプリ版</dt><dd>{{ inquiryDetail.application_version }}</dd></div>
                    <div><dt>投稿日時</dt><dd>{{ formatAnnouncementDate(inquiryDetail.created_at) }}</dd></div>
                </dl>
                <p class="inquiry-body">{{ inquiryDetail.body }}</p>
                <a v-if="inquiryDetail.attachment_url" :href="inquiryDetail.attachment_url" target="_blank" rel="noopener">
                    <img class="inquiry-attachment" :src="inquiryDetail.attachment_url" alt="お問い合わせ添付画像">
                </a>
                <button type="button" :disabled="busy" @click="openAdminInquiries(inquiryPageNumber)">一覧へ戻る</button>
            </article>
            <template v-else>
                <ol v-if="inquiryItems.length" class="inquiry-list full">
                    <li v-for="inquiry in inquiryItems" :key="inquiry.management_id">
                        <button type="button" @click="openAdminInquiry(inquiry.management_id)">
                            {{ inquiry.management_id }} [{{ inquiry.category_label }}] {{ inquiry.subject }}
                        </button>
                        <time :datetime="inquiry.created_at">{{ formatAnnouncementDate(inquiry.created_at) }}</time>
                    </li>
                </ol>
                <p v-else class="empty-state">お問い合わせはまだありません。</p>
                <nav class="inquiry-pager" aria-label="お問い合わせのページ">
                    <button type="button" :disabled="inquiryPageNumber <= 1" @click="openAdminInquiries(inquiryPageNumber - 1)">前へ</button>
                    <span>{{ inquiryPageNumber }}ページ</span>
                    <button type="button" :disabled="inquiryPageNumber >= inquiryLastPage" @click="openAdminInquiries(inquiryPageNumber + 1)">次へ</button>
                </nav>
            </template>
        </section>

        <section v-else-if="page === 'announcements'" class="announcement-page panel">
            <div class="section-heading">
                <div><p class="eyebrow">ANNOUNCEMENTS</p><h1>お知らせ</h1></div>
                <div class="announcement-actions">
                    <button type="button" @click="page = 'home'">TOPへ戻る</button>
                    <button v-if="user?.can_manage_announcements" type="button" @click="editAnnouncement()">新規作成</button>
                </div>
            </div>

            <template v-if="announcementView === 'list'">
                <ol v-if="announcementItems.length" class="announcement-list full">
                    <li v-for="announcement in announcementItems" :key="announcement.id">
                        <button type="button" @click="openAnnouncement(announcement.id)">{{ announcement.title }}</button>
                        <time :datetime="announcement.created_at">{{ formatAnnouncementDate(announcement.created_at) }}</time>
                    </li>
                </ol>
                <p v-else class="empty-state">お知らせはまだありません。</p>
                <nav class="announcement-pager" aria-label="お知らせのページ">
                    <button type="button" :disabled="announcementPageNumber <= 1" @click="openAnnouncements(announcementPageNumber - 1)">前へ</button>
                    <span>{{ announcementPageNumber }}ページ</span>
                    <button type="button" :disabled="announcementPageNumber >= announcementLastPage" @click="openAnnouncements(announcementPageNumber + 1)">次へ</button>
                </nav>
            </template>

            <article v-else-if="announcementView === 'detail' && announcementDetail" class="announcement-article">
                <h2>{{ announcementDetail.title }}</h2>
                <p class="announcement-timestamps">
                    投稿日時 <time :datetime="announcementDetail.created_at">{{ formatAnnouncementDate(announcementDetail.created_at) }}</time>
                    <template v-if="announcementDetail.updated_at !== announcementDetail.created_at">
                        ／ 更新日時 <time :datetime="announcementDetail.updated_at">{{ formatAnnouncementDate(announcementDetail.updated_at) }}</time>
                    </template>
                </p>
                <p class="announcement-body">{{ announcementDetail.body }}</p>
                <div class="announcement-actions">
                    <button type="button" @click="openAnnouncements(announcementPageNumber)">一覧へ戻る</button>
                    <button v-if="user?.can_manage_announcements" type="button" @click="editAnnouncement(announcementDetail)">編集</button>
                    <button v-if="user?.can_manage_announcements" class="danger" type="button" @click="deleteAnnouncement(announcementDetail)">削除</button>
                </div>
            </article>

            <form v-else-if="announcementView === 'form' && user?.can_manage_announcements" class="announcement-form" @submit.prevent="saveAnnouncement">
                <h2>{{ announcementFormId === null ? 'お知らせを作成' : 'お知らせを編集' }}</h2>
                <label>題名
                    <input v-model="announcementTitle" maxlength="160" required>
                    <span v-if="announcementErrors.title" class="field-error" role="alert">{{ announcementErrors.title }}</span>
                </label>
                <label>本文（プレーンテキスト）
                    <textarea v-model="announcementBody" maxlength="20000" rows="12" required></textarea>
                    <span v-if="announcementErrors.body" class="field-error" role="alert">{{ announcementErrors.body }}</span>
                </label>
                <div class="announcement-actions">
                    <button class="button primary" type="submit" :disabled="busy">保存</button>
                    <button type="button" @click="announcementView = announcementDetail ? 'detail' : 'list'">キャンセル</button>
                </div>
            </form>
        </section>

        <section v-else-if="page === 'island' && nation?.capital && mapSpace" class="island-page">
            <header class="nation-hud">
                <div class="hud-identity">
                    <p class="eyebrow">MY ISLAND</p>
                    <h1 :class="{ 'karma-name': nation.karma_positive }">N{{ nation.nation_number }} {{ nation.name }}</h1>
                    <p v-if="nation.state_label"><span class="state-badge">{{ nation.state_label }}</span><template v-if="nation.winter_theme_active"> 冬theme適用中</template></p>
                    <p v-if="nation.karma_positive" class="karma-emphasis">KARMA:{{ nation.karma }}</p>
                    <p class="turn-indicator">現在ターン {{ nation.current_turn }}</p>
                    <p class="profile-owner">島主：{{ nation.owner_name }}</p>
                    <p v-if="nation.comment" class="profile-comment">「{{ nation.comment }}」</p>
                </div>
                <dl class="hud-primary">
                    <div><dt>人口</dt><dd>{{ nation.total_population.toLocaleString() }}人</dd></div>
                    <div><dt>面積</dt><dd>{{ nation.owned_land_cells.toLocaleString() }}セル</dd></div>
                    <div class="hud-money">
                        <dt>資金</dt>
                        <dd><strong class="hud-current-value">{{ formatExactMoney(nation.money) }}</strong></dd>
                    </div>
                    <div class="hud-food">
                        <dt>食料</dt>
                        <dd><strong class="hud-current-value">{{ formatResource(nation.total_food_tons, 'トン') }}</strong></dd>
                    </div>
                    <div><dt>農場規模</dt><dd>{{ nation.farm_capacity_people.toLocaleString() }}人</dd></div>
                    <div><dt>工場規模</dt><dd>{{ nation.factory_capacity_people.toLocaleString() }}人</dd></div>
                    <div><dt>採掘場規模</dt><dd>{{ nation.mine_capacity_people.toLocaleString() }}人</dd></div>
                </dl>
                <details class="hud-more">
                    <summary>詳細情報</summary>
                    <div class="hud-more-grid">
                        <section class="resource-forecast" aria-labelledby="resource-forecast-heading">
                            <h2 id="resource-forecast-heading">資源推計</h2>
                            <div class="resource-forecast-table-wrap">
                                <table>
                                    <thead>
                                        <tr><th scope="col">資源</th><th scope="col">生産</th><th scope="col">消費</th><th scope="col">予測</th><th scope="col">所持</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in nation.resource_forecast.rows" :key="row.key">
                                            <th scope="row">{{ row.name }}</th>
                                            <td>{{ row.production.toLocaleString('ja-JP') }}</td>
                                            <td>{{ row.consumption.toLocaleString('ja-JP') }}</td>
                                            <td :class="{ 'forecast-positive': row.delta > 0, 'forecast-negative': row.delta < 0 }">{{ formatForecastDelta(row.delta) }}</td>
                                            <td>{{ row.holding.toLocaleString('ja-JP') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="resource-forecast-note">{{ nation.resource_forecast.food_holding_note }}</p>
                            <p class="workforce-forecast" :class="`workforce-${nation.resource_forecast.workforce.status}`">
                                <strong>{{ nation.resource_forecast.workforce.label }}</strong>
                                {{ formatPercentageTenths(nation.resource_forecast.workforce.percentage_tenths) }}%
                            </p>
                        </section>
                        <section class="hud-support" aria-labelledby="hud-support-heading">
                            <h2 id="hud-support-heading">その他</h2>
                            <dl class="hud-details">
                                <div><dt>KARMA</dt><dd :class="{ 'karma-text': nation.karma_positive }">{{ nation.karma }}</dd></div>
                                <div><dt>資金上限</dt><dd>{{ formatExactMoney(nation.money_capacity) }}</dd></div>
                                <div><dt>食材上限</dt><dd>{{ formatResource(nation.food_capacity_tons, 'トン') }}</dd></div>
                                <div v-for="resource in nation.food_resources" :key="`food:${resource.key}`">
                                    <dt>{{ resource.name }}</dt><dd>{{ formatResource(resource.balance, resource.unit_label) }}</dd>
                                </div>
                                <div v-for="resource in nonFoodResources" :key="resource.key">
                                    <template v-if="resource.capacity !== null">
                                        <dt>{{ resource.name }}上限</dt>
                                        <dd>{{ formatResource(resource.capacity, resource.unit_label) }}</dd>
                                    </template>
                                </div>
                            </dl>
                        </section>
                    </div>
                </details>
            </header>
            <div class="island-workspace-region">
                <nav class="workspace-jump" aria-label="開発ワークスペース内の移動">
                    <button type="button" aria-controls="island-development-workspace" @click="scrollIslandWorkspaceTo('.command-panel')">セル・コマンド</button>
                    <button type="button" aria-controls="island-development-workspace" @click="scrollIslandWorkspaceTo('.map-column')">地図</button>
                    <button type="button" aria-controls="island-development-workspace" @click="scrollIslandWorkspaceTo('.plan-panel')">開発計画</button>
                </nav>
                <div
                    id="island-development-workspace"
                    ref="islandWorkspaceScroll"
                    class="island-workspace-scroll"
                    role="region"
                    aria-label="島開発ワークスペース（横スクロール）"
                    tabindex="0"
                >
                    <div class="island-grid">
                        <CommandQueuePanel
                            :nation-id="nation.id"
                            :map-space-id="mapSpace.id"
                            :selected="map.selected.value"
                            :selected-underground="selectedUndergroundSlot"
                            :nation-state="nation.state"
                            @queue="authoritativeCommandQueue = $event"
                            @ship="map.updateSelectedShip"
                        />
                        <div class="map-column">
                            <HexMap
                                :cells="map.visibleCells.value"
                                :selected="map.selected.value"
                                :capital="nation.capital"
                                :bounds="mapSpace.bounds"
                                :own-nation-id="nation.id"
                                :command-queue="authoritativeCommandQueue"
                                :loading="map.loading.value"
                                :error="map.error.value"
                                :empty-chunks="map.emptyChunks.value"
                                @select="selectSurfaceCell"
                                @move="map.moveSelection"
                                @request-range="map.loadVisibleRange"
                                @request-all="map.loadAllChunks"
                            />
                        </div>
                    </div>
                </div>
            </div>
            <UndergroundSurfaceMapView
                v-if="undergroundSurfaceMap"
                :map="undergroundSurfaceMap"
                :selected="selectedUndergroundSlot"
                @select="selectUndergroundSlot"
            />
            <MessageBoard
                :key="`development:${nation.id}`"
                :nation-id="nation.id"
                context="development"
                @posted="refreshMyNation"
            />
            <IslandEventLog :key="`owner:${nation.id}:${nation.current_turn}`" :nation-id="nation.id" audience="owner" />
        </section>

        <section v-else-if="page === 'preview' && previewNation?.capital && mapSpace" class="preview-page">
            <header class="preview-heading">
                <div>
                    <p class="eyebrow">PUBLIC ISLAND PREVIEW</p>
                    <h1 :class="{ 'karma-name': previewNation.karma > 0 }">N{{ previewNation.nation_number }} {{ previewNation.name }}</h1>
                    <p v-if="previewNation.state_label"><span class="state-badge">{{ previewNation.state_label }}</span></p>
                    <p v-if="previewNation.karma > 0" class="karma-emphasis">{{ previewNation.karma_badge }}</p>
                    <p class="profile-owner">島主：{{ previewNation.owner_name }}</p>
                    <p v-if="previewNation.comment" class="profile-comment">「{{ previewNation.comment }}」</p>
                    <button
                        v-if="previewNation.secretary_id !== null"
                        class="button secondary preview-secretary-link"
                        type="button"
                        @click="openPublicSecretary(previewNation.secretary_id, previewNation.world.id)"
                    >
                        秘書プロフィール
                    </button>
                </div>
                <dl>
                    <div><dt>KARMA</dt><dd :class="{ 'karma-text': previewNation.karma > 0 }">{{ previewNation.karma }}</dd></div>
                    <div><dt>人口</dt><dd>{{ previewNation.total_population.toLocaleString() }}人</dd></div>
                    <div><dt>面積</dt><dd>{{ previewNation.owned_land_cells.toLocaleString() }}セル</dd></div>
                    <div><dt>推定資金</dt><dd>{{ previewNation.money_display }}</dd></div>
                    <div><dt>食料</dt><dd>{{ previewNation.food_total_tons.toLocaleString() }}トン</dd></div>
                    <div><dt>農場規模</dt><dd>{{ previewNation.farm_capacity_people.toLocaleString() }}人</dd></div>
                    <div><dt>工場規模</dt><dd>{{ previewNation.factory_capacity_people.toLocaleString() }}人</dd></div>
                    <div><dt>採掘場規模</dt><dd>{{ previewNation.mine_capacity_people.toLocaleString() }}人</dd></div>
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
                    <p class="queue-notice">公開情報（人口・面積・推定資金・食料合計・施設規模）だけを表示しています。食料内訳、その他資源在庫・上限、非公開施設は取得していません。</p>
                </aside>
                <HexMap
                    :cells="map.visibleCells.value"
                    :selected="map.selected.value"
                    :capital="previewNation.capital"
                    :bounds="mapSpace.bounds"
                    :own-nation-id="nation?.id"
                    :loading="map.loading.value"
                    :error="map.error.value"
                    :empty-chunks="map.emptyChunks.value"
                    @select="map.select"
                    @move="map.moveSelection"
                    @request-range="map.loadVisibleRange"
                    @request-all="map.loadAllChunks"
                />
            </div>
            <UndergroundSurfaceMapView
                v-if="previewNation.underground_surface_map"
                :map="previewNation.underground_surface_map"
                :selected="selectedUndergroundSlot"
                @select="selectUndergroundSlot"
            />
            <MessageBoard
                :key="`public:${previewNation.id}`"
                :nation-id="previewNation.id"
                context="public"
                @posted="refreshMyNation"
            />
            <IslandEventLog :key="`public:${previewNation.id}:${previewNation.world.current_turn}`" :nation-id="previewNation.id" audience="public" />
        </section>

        <SalePolicyPanel v-else-if="user && nation && page === 'resources'" :nation-id="nation.id" />

        <TradingPostPanel v-else-if="user && nation && page === 'trading-post'" :nation-id="nation.id" :world-id="nation.world_id" />

        <UndergroundPanel
            v-else-if="page === 'underground' && user && secretary?.name"
            :secretary-image-url="viewedSecretaryProfile?.main_image.url ?? null"
            @return-to-secretary="returnFromUnderground"
        />

        <section v-else-if="page === 'secretary' && (viewedSecretaryProfile || secretary)" class="panel secretary-panel">
            <h1 class="secretary-page-title">秘書</h1>
            <template v-if="viewedSecretaryProfile?.is_owner && secretary?.name === null">
                <h2 class="secretary-name">？？？</h2>
                <div class="secretary-story">
                    <p>
                        今日も開発の計画を指示するあなたの元に一つの知らせが入り込んだ。<br>
                        どうやら、怪獣に踏み荒らされた地から妙な施設が見つかったという。<br>
                        恐らくは海賊のものだろう、非合法な組織が拉致した人々を収容していた施設。<br>
                        その最奥で、あなたは鎖に繋がれたその人物と出会う。
                    </p>
                    <p>
                        その人物は、耳が長く尖っていた。<br>
                        その人物の瞳は、不思議な淡い光を宿していた。<br>
                        恐らくは最高級の『商品』として保管されていたのだろう。<br>
                        その手の趣向の持ち主に合わせた整形の線も考えたが、そのような跡は見受けられなかった。<br>
                        あなたは名前を、尋ねた。
                    </p>
                    <p>「私の名前は——」</p>
                </div>
                <form class="secretary-naming-form" @submit.prevent="nameSecretary">
                    <label for="secretary-name">秘書の名前を決めてください。</label>
                    <input id="secretary-name" v-model="secretaryName" minlength="1" maxlength="30" required autocomplete="off" :disabled="busy">
                    <span v-if="secretaryErrors.name" class="field-error" role="alert">{{ secretaryErrors.name }}</span>
                    <button class="button primary" type="submit" :disabled="busy">OK</button>
                </form>
            </template>
            <template v-else-if="viewedSecretaryProfile">
                <h2 class="secretary-name">{{ viewedSecretaryProfile.name }}</h2>
                <nav class="secretary-tabs" role="tablist" aria-label="秘書メニュー">
                    <button id="secretary-tab-main" type="button" role="tab" aria-controls="secretary-panel-main" :aria-selected="secretarySection === 'main'" :tabindex="secretarySection === 'main' ? 0 : -1" @click="secretarySection = 'main'" @keydown="handleSecretaryTabKeydown">メイン</button>
                    <button v-if="viewedSecretaryProfile.is_owner" id="secretary-tab-skills" type="button" role="tab" aria-controls="secretary-panel-skills" :aria-selected="secretarySection === 'skills'" :tabindex="secretarySection === 'skills' ? 0 : -1" @click="secretarySection = 'skills'" @keydown="handleSecretaryTabKeydown">熟練度</button>
                    <button v-if="viewedSecretaryProfile.is_owner" id="secretary-tab-equipment" type="button" role="tab" aria-controls="secretary-panel-equipment" :aria-selected="secretarySection === 'equipment'" :tabindex="secretarySection === 'equipment' ? 0 : -1" @click="secretarySection = 'equipment'" @keydown="handleSecretaryTabKeydown">装備</button>
                    <button v-if="viewedSecretaryProfile.is_owner" id="secretary-tab-warehouse" type="button" role="tab" aria-controls="secretary-panel-warehouse" :aria-selected="secretarySection === 'warehouse'" :tabindex="secretarySection === 'warehouse' ? 0 : -1" @click="secretarySection = 'warehouse'" @keydown="handleSecretaryTabKeydown">倉庫</button>
                </nav>
                <section v-if="secretarySection === 'main'" id="secretary-panel-main" role="tabpanel" aria-labelledby="secretary-tab-main" class="secretary-main-profile">
                    <div v-if="!viewedSecretaryProfile.viewer_preferences.configured" class="secretary-image-preference-notice">
                        <span>秘書画像設定が未設定です</span>
                        <button v-if="viewedSecretaryProfile.viewer_preferences.can_update" type="button" @click="openSecretaryPreferencesModal">設定する</button>
                        <span v-else>（ログインすると設定できます）</span>
                    </div>
                    <div class="secretary-profile-hero">
                        <div class="secretary-portrait-column">
                            <div class="secretary-portrait-frame">
                                <img
                                    v-if="viewedSecretaryProfile.main_image.url"
                                    :src="viewedSecretaryProfile.main_image.url"
                                    :alt="`${viewedSecretaryProfile.name}のメイン画像`"
                                >
                                <span v-else class="secretary-no-image">No image</span>
                                <details v-if="viewedSecretaryProfile.main_image.display === 'uploaded'" class="secretary-image-info">
                                    <summary aria-label="画像について">ⓘ</summary>
                                    <div>
                                        <strong>画像について</strong>
                                        <p>制作方法：{{ viewedSecretaryProfile.main_image.creation_method_label }}</p>
                                        <p>作者・権利表記：{{ viewedSecretaryProfile.main_image.credit || '記載なし' }}</p>
                                    </div>
                                </details>
                            </div>
                            <button v-if="viewedSecretaryProfile.is_owner" class="button secondary" type="button" @click="openSecretaryImageModal">画像を変更</button>
                        </div>
                        <section class="secretary-profile-summary" aria-label="秘書基本情報">
                            <dl>
                                <div><dt>内政Lv</dt><dd>{{ viewedSecretaryProfile.domestic_level }}</dd></div>
                                <div v-if="viewedSecretaryProfile.combat_level !== null">
                                    <dt>戦闘Lv</dt><dd>{{ viewedSecretaryProfile.combat_level }}</dd>
                                </div>
                                <div><dt>資金・食糧最大</dt><dd>+{{ viewedSecretaryProfile.capacity_bonus_percent }}%</dd></div>
                                <div><dt>討伐経験値</dt><dd>{{ viewedSecretaryProfile.monster_experience }}</dd></div>
                            </dl>
                            <button
                                v-if="viewedSecretaryProfile.viewer_preferences.can_update && viewedSecretaryProfile.viewer_preferences.configured"
                                class="secretary-preferences-link"
                                type="button"
                                @click="openSecretaryPreferencesModal"
                            >
                                画像設定
                            </button>
                            <div v-if="viewedSecretaryProfile.is_owner && secretary?.name" class="secretary-underground-entry">
                                <button class="button primary" type="button" @click="openUnderground">地下へ</button>
                            </div>
                        </section>
                        <section class="secretary-biography" aria-labelledby="secretary-biography-title">
                            <h3 id="secretary-biography-title">経歴</h3>
                            <form v-if="viewedSecretaryProfile.is_owner" @submit.prevent="updateSecretaryBiography">
                                <textarea v-model="secretaryBiography" maxlength="1000" rows="10" aria-describedby="secretary-biography-count secretary-biography-error"></textarea>
                                <small id="secretary-biography-count">{{ secretaryBiography.length }} / 1000文字。改行のみ表示へ反映します。</small>
                                <span v-if="secretaryProfileErrors.biography" id="secretary-biography-error" class="field-error" role="alert">{{ secretaryProfileErrors.biography }}</span>
                                <button class="button primary" type="submit" :disabled="busy">経歴を保存</button>
                            </form>
                            <p v-else-if="viewedSecretaryProfile.biography" class="secretary-biography-text">{{ viewedSecretaryProfile.biography }}</p>
                            <p v-else class="empty-state">経歴はまだ公開されていません。</p>
                        </section>
                    </div>
                    <section class="secretary-profile-equipment" aria-labelledby="secretary-profile-equipment-title">
                        <div class="secretary-profile-section-heading">
                            <h3 id="secretary-profile-equipment-title">装備</h3>
                            <button v-if="viewedSecretaryProfile.is_owner" type="button" @click="secretarySection = 'equipment'">装備を変更</button>
                        </div>
                        <ol>
                            <li v-for="slot in viewedSecretaryProfile.equipment.slots" :key="slot.slot">
                                <span class="secretary-profile-slot">slot {{ slot.slot }}</span>
                                <template v-if="slot.item">
                                    <span class="secretary-profile-item-icon" aria-hidden="true">{{ slot.item.category_label.slice(0, 1) }}</span>
                                    <div>
                                        <strong>{{ slot.item.name }} <small>Lv.{{ slot.item.level }}</small></strong>
                                        <p>{{ slot.item.effect_text || slot.item.category_label }}</p>
                                    </div>
                                </template>
                                <span v-else class="empty-state">空きslot</span>
                            </li>
                        </ol>
                    </section>
                </section>
                <section v-else-if="secretarySection === 'skills' && secretary" id="secretary-panel-skills" role="tabpanel" aria-labelledby="secretary-tab-skills">
                    <h3 class="secretary-section-title">パッシブスキル</h3>
                    <dl class="secretary-skills">
                        <div v-for="skill in secretary.skills" :key="skill.key" class="secretary-skill">
                            <dt class="secretary-skill-name">{{ skill.name }}</dt>
                            <dd class="secretary-skill-progress">
                                <span>Lv{{ skill.level }}</span>
                                <span>XP {{ skill.experience }} / {{ skill.required_experience }}</span>
                            </dd>
                            <dd class="secretary-skill-effect">{{ skill.effect }}</dd>
                        </div>
                    </dl>
                </section>
                <section v-else-if="secretarySection === 'equipment' && secretary" id="secretary-panel-equipment" role="tabpanel" aria-labelledby="secretary-tab-equipment">
                    <h3 class="secretary-section-title">装備</h3>
                    <ol class="secretary-equipment">
                        <li v-for="slot in secretary.equipment.slots" :key="slot.slot">
                            <button type="button" :aria-label="`装備 slot ${slot.slot} を変更`" @click="openEquipmentModal(slot.slot)">
                                <span class="equipment-slot-number">{{ slot.slot }}</span>
                                <strong v-if="slot.item">{{ slot.item.name }} <small>Lv{{ slot.item.level }}</small></strong>
                                <span v-else class="empty-state">空き</span>
                            </button>
                        </li>
                    </ol>
                    <ul class="equipment-category-limits" aria-label="装備数の上限">
                        <li v-for="limit in secretary.equipment.category_limits" :key="limit.category">{{ limit.label }}・{{ limit.maximum_equipped }}個まで</li>
                    </ul>
                </section>
                <section v-else-if="secretary" id="secretary-panel-warehouse" role="tabpanel" aria-labelledby="secretary-tab-warehouse">
                    <h3 class="secretary-section-title">倉庫 {{ secretary.inventory.used }} / {{ secretary.inventory.capacity }}</h3>
                    <ul class="secretary-warehouse">
                        <li v-for="item in secretary.inventory.items" :key="item.id">
                            <div class="secretary-warehouse-heading">
                                <div><strong>{{ item.name }}</strong> <span>Lv{{ item.level }}</span></div>
                                <button class="button danger secretary-item-sell" type="button" :disabled="busy" @click="sellSecretaryItem(item)">{{ item.fixed_sale_label }}</button>
                            </div>
                            <p v-if="item.effect_text" class="item-effect">{{ item.effect_text }}</p>
                            <p>{{ item.rarity_label }}・{{ item.category_label }}<template v-if="item.is_equipped">・slot {{ item.equipped_slot }} に装備中</template><template v-if="item.is_escrowed">・交易場へ出品中</template></p>
                            <p class="item-flavor">{{ item.flavor_text }}</p>
                        </li>
                    </ul>
                    <p v-if="secretary.inventory.items.length === 0" class="empty-state">倉庫は空です。</p>
                </section>
            </template>
        </section>

        <section v-else-if="page === 'options'" class="panel profile-panel options-panel">
            <p class="eyebrow">OPTIONS</p>
            <h1>オプション</h1>
            <section class="options-section display-settings" aria-labelledby="display-settings-title">
                <h2 id="display-settings-title">表示設定</h2>
                <fieldset class="theme-options">
                    <legend>表示テーマ</legend>
                    <label class="theme-choice">
                        <input
                            type="radio"
                            name="display-theme"
                            value="system"
                            :checked="themeMode === 'system'"
                            @change="selectTheme('system')"
                        >
                        <span><strong>システム設定に従う</strong><small>OS・ブラウザのライト／ダーク設定に合わせます。</small></span>
                    </label>
                    <label class="theme-choice">
                        <input
                            type="radio"
                            name="display-theme"
                            value="light"
                            :checked="themeMode === 'light'"
                            @change="selectTheme('light')"
                        >
                        <span><strong>ライトテーマ</strong><small>常に明るい配色で表示します。</small></span>
                    </label>
                    <label class="theme-choice">
                        <input
                            type="radio"
                            name="display-theme"
                            value="dark"
                            :checked="themeMode === 'dark'"
                            @change="selectTheme('dark')"
                        >
                        <span><strong>ダークテーマ</strong><small>常に暗い紺・深緑系の配色で表示します。</small></span>
                    </label>
                </fieldset>
            </section>
            <section v-if="user && nation" class="options-section profile-settings" aria-labelledby="profile-settings-title">
                <h2 id="profile-settings-title">プロフィール</h2>
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
                <form v-if="secretary?.name !== null" class="profile-form secretary-rename-form" @submit.prevent="renameProfileSecretary">
                    <h2>秘書プロフィール</h2>
                    <label>
                        秘書名
                        <input v-model="profileSecretaryName" minlength="1" maxlength="30" required autocomplete="off" aria-describedby="profile-secretary-help profile-secretary-error">
                        <small id="profile-secretary-help" class="field-hint">1〜30文字。何度でも変更できます。過去のログの名前は変わりません。</small>
                        <span v-if="profileSecretaryErrors.name" id="profile-secretary-error" class="field-error" role="alert">{{ profileSecretaryErrors.name }}</span>
                    </label>
                    <div class="profile-actions">
                        <button class="button primary" type="submit" :disabled="busy">秘書名を保存</button>
                    </div>
                </form>
                <section class="danger-zone" aria-labelledby="danger-zone-title">
                    <h2 id="danger-zone-title">危険な操作</h2>
                    <section class="dormancy-block" aria-labelledby="dormancy-title">
                        <h3 id="dormancy-title">島を休止する</h3>
                        <template v-if="nation.state === 'dormant'">
                            <p><strong>現在休止中</strong></p>
                            <dl class="dormancy-status">
                                <div><dt>理由</dt><dd>{{ nation.state_reason === 'manual' ? '手動休止' : nation.state_reason === 'collapse' ? '人口・食料の枯渇' : '無活動' }}</dd></div>
                                <div><dt>指定期間</dt><dd>{{ nation.manual_dormancy_days === null ? '自動休止' : `${nation.manual_dormancy_days}日` }}</dd></div>
                                <div><dt>再開予定turn</dt><dd>{{ nation.resume_at_turn === null ? '通常command登録後の次official Turn' : `Turn ${nation.resume_at_turn}` }}</dd></div>
                                <div><dt>残りturn / 日数</dt><dd>{{ nation.dormancy_remaining_turns === null ? '期限なし' : `${nation.dormancy_remaining_turns} turn / 約${nation.dormancy_remaining_days}日` }}</dd></div>
                                <div><dt>自動破棄まで</dt><dd>{{ nation.abandonment_remaining_turns }} turn</dd></div>
                                <div><dt>表示</dt><dd>冬theme適用中</dd></div>
                            </dl>
                            <p class="field-hint">手動休止は指定期間が終わるまで解除できません。</p>
                        </template>
                        <form v-else class="dormancy-form" @submit.prevent="requestDormancy">
                            <p>1〜7日から期間を選択します。指定期間中は早期解除できません。</p>
                            <label for="dormancy-days">休止期間</label>
                            <select id="dormancy-days" v-model.number="dormancyDays" :disabled="busy">
                                <option v-for="days in 7" :key="days" :value="days">{{ days }}日</option>
                            </select>
                            <p class="field-hint">選択した期間と期限前解除不可の説明を確認して申請してください。</p>
                            <p v-if="dormancyError" class="field-error" role="alert">{{ dormancyError }}</p>
                            <button class="button secondary" type="submit" :disabled="busy || !nation.can_request_dormancy">この島を休止する</button>
                        </form>
                    </section>
                    <section class="abandonment-block" aria-labelledby="abandonment-block-title">
                        <h3 id="abandonment-block-title">島を破棄する</h3>
                        <p>島の破棄は元に戻せません。領土・人口・施設・資源・開発予定はすべて失われます。</p>
                        <button class="button danger" type="button" :disabled="busy || nation.state !== 'active'" @click="openAbandonmentModal">この島を破棄する</button>
                    </section>
                </section>
            </section>
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
            <p>
                雪国チップ / K.Y studio / <a href="https://web.archive.org/web/20080115185144/http://www.propel.ne.jp/~yysky/gallery/" target="_blank" rel="noopener noreferrer">配布元</a>
            </p>
            <a href="https://web.archive.org/web/20080115185144/http://www.propel.ne.jp/~yysky/gallery/" target="_blank" rel="noopener noreferrer">
                <img src="https://assets.pbwlove.com/hakoniwa/snow/banner_y.gif" alt="雪国チップ / K.Y studio" loading="lazy">
            </a>
            <p><a href="http://www.bekkoame.ne.jp/~tokuoka/hakoniwa.html" rel="external">原配布元</a></p>
            <p>原作GIFは本リポジトリとDocker imageに含まれません。未配置時はCSS fallbackを表示します。</p>
        </section>
    </main>

    <div v-if="secretaryImageModalOpen" class="modal-backdrop" @click.self="closeSecretaryImageModal">
        <section class="secretary-profile-modal" role="dialog" aria-modal="true" aria-labelledby="secretary-image-modal-title">
            <header>
                <h2 id="secretary-image-modal-title">メイン画像</h2>
                <button type="button" aria-label="閉じる" :disabled="busy" @click="closeSecretaryImageModal">×</button>
            </header>
            <form @submit.prevent="submitSecretaryImage">
                <label>
                    新しい画像
                    <input ref="secretaryImageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" :required="viewedSecretaryProfile?.editable_image_metadata === null" :disabled="busy" @change="selectSecretaryImage">
                    <small>PNG / JPEG / WebP / GIF、最大10MB。公開枠では3:4で表示します。</small>
                    <span v-if="secretaryImageErrors.image" class="field-error" role="alert">{{ secretaryImageErrors.image }}</span>
                </label>
                <label>
                    制作方法
                    <select v-model="secretaryImageCreationMethod" :disabled="busy">
                        <option value="self_made">自作</option>
                        <option value="ai_generated">AI生成</option>
                        <option value="commissioned_or_permitted">依頼・使用許諾済み</option>
                        <option value="other">その他</option>
                    </select>
                    <span v-if="secretaryImageErrors.creation_method" class="field-error" role="alert">{{ secretaryImageErrors.creation_method }}</span>
                </label>
                <label>
                    作者・権利表記（任意）
                    <input v-model="secretaryImageCredit" maxlength="160" :disabled="busy">
                    <span v-if="secretaryImageErrors.credit" class="field-error" role="alert">{{ secretaryImageErrors.credit }}</span>
                </label>
                <p v-if="secretaryImageFile && viewedSecretaryProfile?.editable_image_metadata" class="field-hint">保存すると旧メイン画像は削除され、最新の1枚だけが残ります。</p>
                <div class="modal-actions">
                    <button type="button" :disabled="busy" @click="closeSecretaryImageModal">キャンセル</button>
                    <button class="button primary" type="submit" :disabled="busy">{{ secretaryImageFile ? '画像を保存' : 'metadataを保存' }}</button>
                </div>
            </form>
        </section>
    </div>

    <div v-if="secretaryPreferencesModalOpen" class="modal-backdrop" @click.self="closeSecretaryPreferencesModal">
        <section class="secretary-profile-modal" role="dialog" aria-modal="true" aria-labelledby="secretary-preferences-modal-title">
            <header>
                <h2 id="secretary-preferences-modal-title">秘書画像設定</h2>
                <button type="button" aria-label="閉じる" :disabled="busy" @click="closeSecretaryPreferencesModal">×</button>
            </header>
            <form @submit.prevent="saveSecretaryImagePreferences">
                <fieldset>
                    <legend>閲覧するAI生成画像</legend>
                    <label><input v-model="secretaryShowAiImages" type="radio" :value="true"> 表示する</label>
                    <label><input v-model="secretaryShowAiImages" type="radio" :value="false"> 表示しない</label>
                </fieldset>
                <fieldset>
                    <legend>自分の秘書が画像未設定のとき</legend>
                    <label><input v-model="secretaryImageFallback" type="radio" value="silhouette"> silhouette版</label>
                    <label><input v-model="secretaryImageFallback" type="radio" value="peridot"> Peridot詳細版</label>
                </fieldset>
                <p v-if="secretaryPreferenceErrors.own_secretary_fallback || secretaryPreferenceErrors.fallback || secretaryPreferenceErrors.show_ai_generated_images" class="field-error" role="alert">
                    {{ secretaryPreferenceErrors.own_secretary_fallback || secretaryPreferenceErrors.fallback || secretaryPreferenceErrors.show_ai_generated_images }}
                </p>
                <div class="modal-actions">
                    <button type="button" :disabled="busy" @click="closeSecretaryPreferencesModal">キャンセル</button>
                    <button class="button primary" type="submit" :disabled="busy">保存</button>
                </div>
            </form>
        </section>
    </div>

    <SecretaryEquipmentModal
        v-if="equipmentModalSlot !== null"
        :target-slot="equipmentModalSlot"
        :options="equipmentOptions"
        :loading="equipmentOptionsLoading"
        :submitting="equipmentSubmitting"
        :error="equipmentError"
        :require-fresh-choice="equipmentRequireFreshChoice"
        @close="closeEquipmentModal"
        @submit="submitEquipment"
        @selection-change="equipmentRequireFreshChoice = false"
    />

    <div v-if="abandonmentModalOpen && nation" class="modal-backdrop" @click.self="closeAbandonmentModal">
        <section class="abandonment-modal" role="dialog" aria-modal="true" aria-labelledby="abandonment-title">
            <h2 id="abandonment-title">島の破棄</h2>
            <p>この操作を行うと、この島の領土・人口・施設・資源・開発予定はすべて失われます。</p>
            <p>同じ島名で作り直す事もできません。</p>
            <p>この操作は元に戻せません。</p>
            <p>過去の記録とアカウントは残ります。</p>
            <form @submit.prevent="abandonNation">
                <label for="abandonment-confirmation">確認のため、島名「{{ nation.name }}」を入力してください。</label>
                <input id="abandonment-confirmation" v-model="abandonmentConfirmationName" autocomplete="off" :disabled="busy">
                <p v-if="abandonmentError" class="field-error" role="alert">{{ abandonmentError }}</p>
                <div class="modal-actions">
                    <button type="button" :disabled="busy" @click="closeAbandonmentModal">キャンセル</button>
                    <button class="button danger" type="submit" :disabled="busy || !abandonmentConfirmed">島を破棄する</button>
                </div>
            </form>
        </section>
    </div>
</template>
