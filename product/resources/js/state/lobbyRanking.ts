import { computed, onBeforeUnmount, ref, type Ref } from 'vue';
import type { PublicRankingEntry } from '../types';

interface RankingColumn {
    key: string;
    label: string;
    sortable: boolean;
    /** スマホの行カードで、主の値のほかに小さく並べる列。 */
    card: boolean;
    value: (entry: PublicRankingEntry) => number;
    format: (entry: PublicRankingEntry) => string;
}

const number = (value: number): string => value.toLocaleString('ja-JP');
const facility = (people: number): string => people === 0 ? '保有せず' : `${number(people)}人`;

// 資金は公開用の大まかな表記しか無いので、並べ替えには使わない。
const columns: RankingColumn[] = [
    { key: 'population', label: '人口', sortable: true, card: true, value: (entry) => entry.total_population, format: (entry) => `${number(entry.total_population)}人` },
    { key: 'area', label: '面積', sortable: true, card: true, value: (entry) => entry.owned_land_cells, format: (entry) => `${number(entry.owned_land_cells)}セル` },
    { key: 'money', label: '資金', sortable: false, card: true, value: () => 0, format: (entry) => entry.money_display },
    { key: 'food', label: '食料', sortable: true, card: true, value: (entry) => entry.food_total_tons, format: (entry) => `${number(entry.food_total_tons)}トン` },
    { key: 'farm', label: '農場', sortable: true, card: false, value: (entry) => entry.farm_capacity_people, format: (entry) => facility(entry.farm_capacity_people) },
    { key: 'factory', label: '工場', sortable: true, card: false, value: (entry) => entry.factory_capacity_people, format: (entry) => facility(entry.factory_capacity_people) },
    { key: 'mine', label: '採掘場', sortable: true, card: false, value: (entry) => entry.mine_capacity_people, format: (entry) => facility(entry.mine_capacity_people) },
    { key: 'survival', label: '生存', sortable: true, card: false, value: (entry) => entry.survival_turns, format: (entry) => number(entry.survival_turns) },
];

/** TOPの島一覧の、並べ替え・絞り込み・表示幅。順位そのものはserverの値をそのまま使う。 */
export function useLobbyRanking(rankings: Ref<PublicRankingEntry[]>) {
    const sort = ref('rank');
    const query = ref('');
    const media = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 860px)') : null;
    const narrow = ref(media?.matches === true);
    const onMediaChange = (event: MediaQueryListEvent): void => {
        narrow.value = event.matches;
    };
    media?.addEventListener?.('change', onMediaChange);
    onBeforeUnmount(() => media?.removeEventListener?.('change', onMediaChange));

    const shown = computed(() => {
        const needle = query.value.trim();
        const filtered = needle === ''
            ? rankings.value
            : rankings.value.filter((entry) => entry.name.includes(needle) || entry.owner_name.includes(needle));
        const column = columns.find((candidate) => candidate.key === sort.value && candidate.sortable);
        if (column === undefined) return filtered;
        return [...filtered].sort((left, right) => column.value(right) - column.value(left) || left.rank - right.rank);
    });
    const keyColumn = computed(() => columns.find((column) => column.key === sort.value && column.sortable) ?? columns[0]!);
    const cardColumns = computed(() => columns.filter((column) => column.card && column.key !== keyColumn.value.key));
    const sortColumns = computed(() => [{ key: 'rank', label: '順位' }, ...columns.filter((column) => column.sortable)]);

    return { sort, query, narrow, columns, shown, keyColumn, cardColumns, sortColumns };
}
