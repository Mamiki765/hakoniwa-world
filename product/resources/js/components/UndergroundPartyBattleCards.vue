<script setup lang="ts">
import { computed } from 'vue';

interface State { hp: number; max_hp: number; mp: number; awakening_gauge?: number; awakening_gauge_max?: number; awakened?: boolean; }
interface Actor { team: 'player' | 'enemy'; combatant_id: string; display_name: string; icon_url?: string | null; portrait_url?: string | null; state?: State; awakening_state?: 'ready' | 'awakened' | null; }
interface ImageReference { url?: string | null; credit?: string | null; creation_method_label?: string | null; }
interface PortraitEvent {
    type: 'start' | 'awakening' | 'final';
    round?: number;
    combatant_id: string;
    event_id?: string;
    image_ref?: ImageReference | null;
    image_refs?: { normal?: ImageReference | null; awakening?: ImageReference | null };
}
const props = withDefaults(defineProps<{
    actors: Actor[];
    portraitEvents?: PortraitEvent[];
    showCards?: boolean;
    portraitEventType?: PortraitEvent['type'] | null;
    portraitRound?: number | null;
}>(), { portraitEvents: () => [], showCards: true, portraitEventType: null, portraitRound: null });
const teamGroups = computed(() => [
    { key: 'player' as const, label: 'PARTY', actors: props.actors.filter((actor) => actor.team === 'player') },
    { key: 'enemy' as const, label: 'ENEMY', actors: props.actors.filter((actor) => actor.team === 'enemy') },
].filter((group) => group.actors.length > 0));
const eventKey = (event: PortraitEvent): string => event.event_id ?? `${event.type}:${event.combatant_id}`;
const visiblePortraitEvents = computed(() => {
    const events = new Map<string, PortraitEvent>();
    for (const event of props.portraitEvents ?? []) {
        if (props.portraitEventType !== null && event.type !== props.portraitEventType) continue;
        if (props.portraitRound !== null && event.round !== props.portraitRound) continue;
        events.set(eventKey(event), event);
    }
    return [...events.values()];
});
const actorFor = (event: PortraitEvent): Actor | undefined => props.actors.find((actor) => actor.combatant_id === event.combatant_id);
const eventImage = (event: PortraitEvent): string | null => {
    const selected = event.image_ref
        ?? (event.type === 'awakening' ? event.image_refs?.awakening : event.image_refs?.normal);
    return selected?.url ?? actorFor(event)?.portrait_url ?? null;
};
const eventLabel = (event: PortraitEvent): string => actorFor(event)?.display_name ?? event.combatant_id;
const eventCredit = (event: PortraitEvent): string | null => {
    const selected = event.image_ref
        ?? (event.type === 'awakening' ? event.image_refs?.awakening : event.image_refs?.normal);
    return selected?.credit ?? null;
};
const hpPercent = (actor: Actor) => actor.state && actor.state.max_hp > 0 ? Math.max(0, Math.min(100, Math.round(actor.state.hp / actor.state.max_hp * 100))) : 0;
const gaugePercent = (actor: Actor) => actor.state && actor.state.awakening_gauge_max ? Math.round((actor.state.awakening_gauge ?? 0) / actor.state.awakening_gauge_max * 100) : 0;
const awakeningLabel = (actor: Actor): string => actor.awakening_state === 'awakened' || actor.state?.awakened
    ? 'Awaken!'
    : actor.awakening_state === 'ready' ? 'Ready' : '';
const awakeningState = (actor: Actor): 'charging' | 'ready' | 'awakened' => actor.awakening_state === 'awakened' || actor.state?.awakened
    ? 'awakened'
    : actor.awakening_state === 'ready' ? 'ready' : 'charging';
</script>
<template>
    <div v-if="showCards" class="underground-party-teams">
        <section v-for="group in teamGroups" :key="group.key" class="underground-party-team" :data-team="group.key" :aria-label="group.label">
            <header class="underground-party-team-heading">
                <strong>{{ group.label }}</strong>
                <small>{{ group.actors.length }}体</small>
            </header>
            <div class="underground-party-battle-cards">
                <article v-for="actor in group.actors" :key="actor.combatant_id" class="underground-party-battle-card" :data-team="actor.team" :data-combatant-id="actor.combatant_id">
                    <img v-if="actor.icon_url" :src="actor.icon_url" :alt="`${actor.display_name}のアイコン`" class="underground-party-icon">
                    <span v-else class="underground-party-icon underground-party-icon-fallback" aria-hidden="true">{{ actor.team === 'enemy' ? '敵' : '秘' }}</span>
                    <div class="underground-party-battle-card-body">
                        <strong>{{ actor.display_name }}</strong>
                        <template v-if="actor.state">
                            <label><span>HP</span><span class="underground-party-meter is-hp"><progress :max="actor.state.max_hp" :value="actor.state.hp" :aria-label="`HP ${actor.state.hp}/${actor.state.max_hp}、${hpPercent(actor)}%`" /><span>{{ actor.state.hp }}/{{ actor.state.max_hp }}</span></span></label>
                            <label><span>MP</span><span class="underground-party-meter is-mp"><progress max="10000" :value="actor.state.mp" :aria-label="`MP ${actor.state.mp}`" /><span>{{ actor.state.mp }}</span></span></label>
                            <label v-if="actor.state.awakening_gauge_max"><span>覚醒</span><span class="underground-party-meter is-awakening" :data-state="awakeningState(actor)"><progress :max="actor.state.awakening_gauge_max" :value="actor.state.awakening_gauge ?? 0" :aria-label="`覚醒ゲージ ${gaugePercent(actor)}%、${awakeningLabel(actor) || '蓄積中'}`" /><span>{{ awakeningLabel(actor) }}</span></span></label>
                        </template>
                    </div>
                </article>
            </div>
        </section>
    </div>
    <div v-if="visiblePortraitEvents.length" class="underground-party-portrait-events" aria-label="戦闘画像">
        <figure v-for="event in visiblePortraitEvents" :key="eventKey(event)" class="underground-party-portrait-event">
            <img v-if="eventImage(event)" :src="eventImage(event)!" :alt="`${eventLabel(event)}の戦闘画像`" class="underground-party-large-art">
            <figcaption>
                {{ event.type === 'start' ? '戦闘開始' : event.type === 'awakening' ? '覚醒' : '戦闘終了' }}・{{ eventLabel(event) }}
                <small v-if="eventCredit(event)">画像：{{ eventCredit(event) }}</small>
            </figcaption>
        </figure>
    </div>
</template>
