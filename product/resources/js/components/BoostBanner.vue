<script setup lang="ts">
import { boostKindLabels, formatBoostRemaining, type BoostView } from '../boosts';

defineProps<{
    boosts: BoostView[];
}>();

const icons = { turn_acceleration: '⏩', hunting_ground: '⚔' } as const;

function headline(boost: BoostView): string {
    if (boost.title) return boost.title;

    return boost.kind === 'turn_acceleration' ? 'お詫びターン加速' : 'お詫び狩場ブースト';
}

function effect(boost: BoostView): string | null {
    if (boost.description) return boost.description;
    if (boost.kind === 'turn_acceleration') return '通常の更新に加えて、期間中は追加ターンあり（未補填分のみ）';
    if (boost.multiplier) return `レア敵出現率・通常敵の宝物庫の鍵出現率・経験値が各${boost.multiplier}倍`;

    return null;
}

const tokyo = { timeZone: 'Asia/Tokyo' } as const;
const dateFormat = new Intl.DateTimeFormat('ja-JP', { ...tokyo, month: 'numeric', day: 'numeric', weekday: 'short' });
const timeFormat = new Intl.DateTimeFormat('ja-JP', { ...tokyo, hour: '2-digit', minute: '2-digit', hour12: false });

function period(boost: BoostView): string {
    const start = new Date(boost.starts_at);
    const end = new Date(boost.ends_at);
    const startDate = dateFormat.format(start);
    const endDate = dateFormat.format(end);

    return `${startDate} ${timeFormat.format(start)} 〜 ${endDate === startDate ? '' : `${endDate} `}${timeFormat.format(end)}`;
}
</script>

<template>
    <section v-if="boosts.length" class="boost-banners" aria-label="開催中・予告中のブースト">
        <article
            v-for="boost in boosts"
            :key="`${boost.kind}-${boost.id}`"
            class="boost-banner"
            :data-kind="boost.kind"
            :data-phase="boost.phase"
        >
            <span class="boost-icon" aria-hidden="true">{{ icons[boost.kind] }}</span>
            <div class="boost-main">
                <p class="boost-kind">
                    <span class="boost-phase">{{ boost.phase === 'active' ? '開催中' : '予告' }}</span>
                    {{ boostKindLabels[boost.kind] }}
                </p>
                <p class="boost-title"><strong>{{ headline(boost) }}</strong></p>
                <p v-if="effect(boost)" class="boost-effect">{{ effect(boost) }}</p>
                <p class="boost-period"><time :datetime="boost.starts_at">{{ period(boost) }}</time></p>
            </div>
            <p class="boost-countdown" role="timer">
                <small>{{ boost.phase === 'active' ? '終了まで' : '開始まで' }}</small>
                <strong class="num">{{ formatBoostRemaining(boost.remainingMs) }}</strong>
            </p>
        </article>
    </section>
</template>

<style scoped>
.boost-banners {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr));
    gap: 10px;
}

.boost-banner {
    --boost-accent: var(--coral);
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 6px 16px;
    padding: 16px 20px;
    border: 2px solid var(--boost-accent);
    border-radius: 10px;
    color: var(--ink);
    background:
        linear-gradient(110deg, color-mix(in srgb, var(--boost-accent) 26%, var(--paper)) 0%, color-mix(in srgb, var(--gold) 22%, var(--paper)) 100%);
    box-shadow: 0 10px 28px color-mix(in srgb, var(--boost-accent) 24%, transparent);
}

.boost-banner[data-kind="hunting_ground"] { --boost-accent: var(--navy); }
.boost-banner[data-phase="upcoming"] { border-style: dashed; }

.boost-icon { font-size: 2.6rem; line-height: 1; }
.boost-main { min-width: 0; }
.boost-main p { margin: 0; }

.boost-kind {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-size: .9rem;
    font-weight: 700;
}

.boost-phase {
    padding: 2px 10px;
    border: 1px solid var(--boost-accent);
    border-radius: 999px;
    background: var(--paper);
    font-size: .8rem;
}

.boost-banner[data-phase="active"] .boost-phase::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    margin-right: 6px;
    border-radius: 50%;
    background: var(--boost-accent);
    vertical-align: 1px;
    animation: boost-pulse 1.4s ease-in-out infinite;
}

.boost-main .boost-title {
    margin: 2px 0;
    font-size: clamp(1.35rem, 4.2vw, 2rem);
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.boost-effect { font-weight: 700; }
.boost-period { color: var(--muted); font-size: .88rem; }

.boost-countdown {
    display: grid;
    justify-items: end;
    margin: 0;
    text-align: right;
}

.boost-countdown small { color: var(--muted); font-weight: 700; }
.boost-countdown strong { font-size: clamp(1.4rem, 4.4vw, 2.1rem); line-height: 1.1; white-space: nowrap; }

@media (max-width: 560px) {
    .boost-banner { grid-template-columns: auto minmax(0, 1fr); padding: 12px 14px; }
    .boost-icon { grid-row: span 2; align-self: start; font-size: 2rem; }
    .boost-countdown { grid-column: 2; display: flex; align-items: baseline; justify-content: flex-start; gap: 8px; text-align: left; }
}

@keyframes boost-pulse {
    50% { opacity: .25; }
}

@media (prefers-reduced-motion: reduce) {
    .boost-banner[data-phase="active"] .boost-phase::before { animation: none; }
}
</style>
