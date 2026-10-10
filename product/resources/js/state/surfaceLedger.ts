import { computed, onBeforeUnmount, ref } from 'vue';

export type LedgerTab = 'inspect' | 'plan' | 'log';
type Side = 'l' | 'r';

// [最小, 既定, 最大]
const COLUMN_LIMITS: Record<Side, [number, number, number]> = { l: [220, 300, 460], r: [240, 320, 480] };
const storageKey = (side: Side): string => `hakoniwa-surface-col-${side}`;

function readStoredWidth(side: Side): number | null {
    try {
        const stored = Number(window.localStorage.getItem(storageKey(side)));
        return Number.isFinite(stored) && stored > 0 ? stored : null;
    } catch {
        return null;
    }
}

function clampWidth(side: Side, width: number | null): number {
    const [minimum, base, maximum] = COLUMN_LIMITS[side];
    return Math.round(Math.min(maximum, Math.max(minimum, width ?? base)));
}

/** 地上の開発画面（帳簿）の、画面の組み方だけに関わる状態。ゲームの状態は持たない。 */
export function useSurfaceLedger() {
    const tab = ref<LedgerTab>('inspect');
    const grow = ref(false);
    const islandSheetOpen = ref(false);
    const menuOpen = ref(false);
    const logPane = ref<'log' | 'board'>('log');
    const mapPane = ref<'surface' | 'underground'>('surface');
    const columns = ref<Record<Side, number>>({ l: clampWidth('l', readStoredWidth('l')), r: clampWidth('r', readStoredWidth('r')) });
    const draggingSide = ref<Side | null>(null);
    // タブで切り替える狭い画面かどうか（CSSの切り替えと同じ幅）。
    const media = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 760px)') : null;
    const narrow = ref(media?.matches === true);
    const onMediaChange = (event: MediaQueryListEvent): void => {
        narrow.value = event.matches;
    };
    media?.addEventListener?.('change', onMediaChange);
    onBeforeUnmount(() => media?.removeEventListener?.('change', onMediaChange));
    // 伝言板がいま画面に出ているか。
    const boardVisible = computed(() => logPane.value === 'board' && (!narrow.value || tab.value === 'log'));
    const style = computed(() => ({
        '--sl-col-l': `${columns.value.l}px`,
        '--sl-col-r': `${columns.value.r}px`,
    }));

    function setColumn(side: Side, width: number | null): void {
        columns.value = { ...columns.value, [side]: clampWidth(side, width) };
        try {
            window.localStorage.setItem(storageKey(side), String(columns.value[side]));
        } catch {
            // 保存できなくても動く
        }
    }

    function beginGrip(side: Side, event: PointerEvent): void {
        const grip = event.currentTarget as HTMLElement;
        event.preventDefault();
        grip.setPointerCapture?.(event.pointerId);
        draggingSide.value = side;
        const startX = event.clientX;
        const startWidth = columns.value[side];
        const move = (moveEvent: PointerEvent): void => setColumn(side, startWidth + (side === 'l' ? moveEvent.clientX - startX : startX - moveEvent.clientX));
        const end = (): void => {
            draggingSide.value = null;
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', end);
            grip.removeEventListener('pointercancel', end);
        };
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', end);
        grip.addEventListener('pointercancel', end);
    }

    function gripKeydown(side: Side, event: KeyboardEvent): void {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        setColumn(side, columns.value[side] + ((event.key === 'ArrowRight') === (side === 'l') ? 20 : -20));
    }

    function showTab(next: LedgerTab): void {
        tab.value = next;
    }

    return { narrow, boardVisible, tab, grow, islandSheetOpen, menuOpen, logPane, mapPane, columns, style, draggingSide, setColumn, beginGrip, gripKeydown, showTab };
}
