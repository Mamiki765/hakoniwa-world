<script setup lang="ts">
import { computed } from 'vue';

interface State { hp: number; max_hp: number; mp: number; awakening_gauge?: number; awakening_gauge_max?: number; awakening_unlocked?: boolean; awakened?: boolean; }
interface ActorImageReferences {
    compact?: ImageReference | null;
    awakening_compact?: ImageReference | null;
    normal?: ImageReference | null;
    awakening?: ImageReference | null;
}
interface Actor { team: 'player' | 'enemy'; combatant_id: string; display_name: string; icon_url?: string | null; portrait_url?: string | null; image_references?: ActorImageReferences | null; state?: State; awakening_state?: 'ready' | 'awakened' | null; awakening_unlocked?: boolean; }
interface ImageReference { url?: string | null; credit?: string | null; creation_method_label?: string | null; }
interface PortraitEvent {
    type: 'start' | 'awakening' | 'final';
    round?: number;
    combatant_id: string;
    event_id?: string;
    state?: State | null;
    image_ref?: ImageReference | null;
    image_refs?: { normal?: ImageReference | null; awakening?: ImageReference | null };
}
const props = withDefaults(defineProps<{
    actors: Actor[];
    // A party actor carries immutable display data. Boundary states are passed
    // separately so a final state can never leak into the opening card.
    stateById?: Record<string, State | null> | null;
    portraitEvents?: PortraitEvent[];
    showCards?: boolean;
    portraitEventType?: PortraitEvent['type'] | null;
    portraitRound?: number | null;
}>(), { stateById: undefined, portraitEvents: () => [], showCards: true, portraitEventType: null, portraitRound: null });
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
const iconFor = (actor: Actor): string | null => {
    const references = actor.image_references;
    const state = stateFor(actor);
    const awakened = state?.awakened === true || (state === null && actor.awakening_state === 'awakened');
    const selected = awakened ? references?.awakening_compact : references?.compact;

    return selected?.url ?? actor.icon_url ?? null;
};
const eventImage = (event: PortraitEvent): string | null => {
    const selected = event.image_ref
        ?? (event.type === 'awakening' ? event.image_refs?.awakening : event.image_refs?.normal);
    const actor = actorFor(event);
    const actorReference = event.type === 'awakening'
        ? actor?.image_references?.awakening
        : actor?.image_references?.normal;

    return selected?.url ?? actorReference?.url ?? actor?.portrait_url ?? null;
};
const eventLabel = (event: PortraitEvent): string => actorFor(event)?.display_name ?? event.combatant_id;
const eventState = (event: PortraitEvent): State | null => {
    if (event.state !== undefined) return event.state;
    if (props.stateById !== undefined) return props.stateById?.[event.combatant_id] ?? null;

    return actorFor(event)?.state ?? null;
};
const eventAwakeningLabel = (event: PortraitEvent): string => {
    const state = eventState(event);
    if (!state) return '';
    if (state.awakened) return 'Awaken!';
    if (state.awakening_unlocked && state.awakening_gauge_max
        && (state.awakening_gauge ?? 0) >= state.awakening_gauge_max) return 'Ready';

    return '';
};
const eventCredit = (event: PortraitEvent): string | null => {
    const selected = event.image_ref
        ?? (event.type === 'awakening' ? event.image_refs?.awakening : event.image_refs?.normal);
    return selected?.credit ?? null;
};
const stateFor = (actor: Actor): State | null => {
    if (props.stateById !== undefined) return props.stateById?.[actor.combatant_id] ?? null;

    return actor.state ?? null;
};
const awakeningVisible = (actor: Actor): boolean => {
    const state = stateFor(actor);

    return actor.team === 'player'
        && (state !== null
            ? state.awakening_unlocked === true || state.awakened === true || actor.awakening_state === 'ready' || actor.awakening_state === 'awakened'
            : actor.awakening_unlocked === true);
};
const hpPercentFor = (actor: Actor): number => {
    const state = stateFor(actor);

    return state && state.max_hp > 0 ? Math.max(0, Math.min(100, Math.round(state.hp / state.max_hp * 100))) : 0;
};
const gaugePercentFor = (actor: Actor): number => {
    const state = stateFor(actor);

    return state && state.awakening_gauge_max ? Math.round((state.awakening_gauge ?? 0) / state.awakening_gauge_max * 100) : 0;
};
const awakeningLabelFor = (actor: Actor): string => {
    const state = stateFor(actor);
    if (state) {
        return state.awakened ? 'Awaken!' : state.awakening_gauge_max && (state.awakening_gauge ?? 0) >= state.awakening_gauge_max ? 'Ready' : '';
    }

    return actor.awakening_state === 'awakened' ? 'Awaken!' : actor.awakening_state === 'ready' ? 'Ready' : '';
};
const awakeningStateFor = (actor: Actor): 'charging' | 'ready' | 'awakened' => {
    const label = awakeningLabelFor(actor);

    return label === 'Awaken!' ? 'awakened' : label === 'Ready' ? 'ready' : 'charging';
};
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
                    <img v-if="iconFor(actor)" :src="iconFor(actor)!" :alt="`${actor.display_name}のアイコン`" class="underground-party-icon">
                    <span v-else class="underground-party-icon underground-party-icon-fallback" aria-hidden="true">{{ actor.team === 'enemy' ? '敵' : '秘' }}</span>
                    <div class="underground-party-battle-card-body">
                        <strong>{{ actor.display_name }}</strong>
                        <template v-if="stateFor(actor)">
                            <label><span>HP</span><span class="underground-party-meter is-hp" :class="{ 'is-long': `${stateFor(actor)!.hp}/${stateFor(actor)!.max_hp}`.length > 11 }"><progress :max="stateFor(actor)!.max_hp" :value="stateFor(actor)!.hp" :aria-label="`HP ${stateFor(actor)!.hp}/${stateFor(actor)!.max_hp}、${hpPercentFor(actor)}%`" /><span><span>{{ stateFor(actor)!.hp }}</span><span>/{{ stateFor(actor)!.max_hp }}</span></span></span></label>
                            <label><span>MP</span><span class="underground-party-meter is-mp"><progress max="10000" :value="stateFor(actor)!.mp" :aria-label="`MP ${stateFor(actor)!.mp}`" /><span>{{ stateFor(actor)!.mp }}</span></span></label>
                            <label v-if="awakeningVisible(actor) && stateFor(actor)!.awakening_gauge_max"><span>覚醒</span><span class="underground-party-meter is-awakening" :data-state="awakeningStateFor(actor)"><progress :max="stateFor(actor)!.awakening_gauge_max" :value="stateFor(actor)!.awakening_gauge ?? 0" :aria-label="`覚醒ゲージ ${gaugePercentFor(actor)}%、${awakeningLabelFor(actor) || '蓄積中'}`" /><span>{{ awakeningLabelFor(actor) }}</span></span></label>
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
                <small v-if="eventState(event)" class="underground-party-portrait-state">
                    HP {{ eventState(event)?.hp }}/{{ eventState(event)?.max_hp }}・MP {{ eventState(event)?.mp }}<template v-if="eventAwakeningLabel(event)">・{{ eventAwakeningLabel(event) }}</template>
                </small>
                <small v-if="eventCredit(event)">画像：{{ eventCredit(event) }}</small>
            </figcaption>
        </figure>
    </div>
</template>
