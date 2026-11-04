import { nextTick, onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

// 上の帯の項目が幅に入りきらない時、右から順に「☰」へ回す（INQ-000018）。
// 横スクロールで隠すと、スマホで右に動かせることに気付かれないため。
// candidates は回してよい項目の key を、先に回す順（右端から）で並べる。
// 項目には data-nav-key を付けておく。全部回してもまだ入りきらない時だけ、従来どおり横スクロールになる。
export function useNavOverflow(nav: Ref<HTMLElement | null>, candidates: readonly string[]) {
    const overflowed = ref<string[]>([]);
    let observer: ResizeObserver | null = null;
    let frame = 0;
    let generation = 0;

    async function measure(): Promise<void> {
        const element = nav.value;
        if (element === null) return;
        const current = ++generation;
        // いったん全部出して、本来の幅を測る（描画の前に戻すので、ちらつかない）。
        if (overflowed.value.length > 0) {
            overflowed.value = [];
            await nextTick();
            if (current !== generation || nav.value !== element) return;
        }
        let excess = element.scrollWidth - element.clientWidth;
        if (excess <= 0) return;
        const gap = Number.parseFloat(getComputedStyle(element).columnGap) || 0;
        const hidden: string[] = [];
        for (const key of candidates) {
            if (excess <= 0) break;
            const item = element.querySelector<HTMLElement>(`[data-nav-key="${key}"]`);
            if (item === null) continue;
            hidden.push(key);
            excess -= item.offsetWidth + gap;
        }
        overflowed.value = hidden;
    }

    function schedule(): void {
        if (typeof requestAnimationFrame === 'undefined') {
            void measure();
            return;
        }
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => void measure());
    }

    onMounted(() => {
        if (typeof ResizeObserver !== 'undefined' && nav.value !== null) {
            observer = new ResizeObserver(schedule);
            observer.observe(nav.value);
        }
        schedule();
    });
    onBeforeUnmount(() => {
        observer?.disconnect();
        if (typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(frame);
    });

    return { overflowed, schedule };
}
