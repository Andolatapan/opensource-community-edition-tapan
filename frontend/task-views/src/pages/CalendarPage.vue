<script setup>
import { computed, ref } from "vue";
import { useTaskStore } from "@/store/useTaskStore";
import { formatDue, statusMeta } from "@/data/tasks";
import { fetchWorkload } from "@/data/mutations";

import { openTask } from "@/utils/taskLink";

const store = useTaskStore();

// Anchor month. `new Date()` at setup is fine — this is view state, not data.
const cursor = ref(startOfMonth(new Date()));

function startOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}

const monthLabel = computed(() =>
    cursor.value.toLocaleDateString(undefined, { month: "long", year: "numeric" })
);

const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

/**
 * The day a task sits on: its start date, or its due date when it has no
 * start date. Dragging moves the start date; the due date stays the deadline.
 */
const scheduledOn = (t) => t.start ?? t.due;

const byDate = computed(() => {
    const map = {};
    store.visible.forEach((t) => {
        const day = scheduledOn(t);
        if (!day) return;
        (map[day] ??= []).push(t);
    });
    return map;
});

const unscheduled = computed(() => store.visible.filter((t) => !scheduledOn(t)));

// Build a 6-row grid starting on Monday.
const cells = computed(() => {
    const first = cursor.value;
    const offset = (first.getDay() + 6) % 7; // Monday = 0
    const start = new Date(first);
    start.setDate(first.getDate() - offset);

    const out = [];
    const todayIso = isoOf(new Date());
    for (let i = 0; i < 42; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const iso = isoOf(d);
        out.push({
            iso,
            day: d.getDate(),
            inMonth: d.getMonth() === first.getMonth(),
            isToday: iso === todayIso,
            tasks: byDate.value[iso] ?? [],
        });
    }
    return out;
});

/*
 * A day keeps a fixed height that fits this many tasks, so one busy day can't
 * stretch its whole week row; past it the day's list scrolls, and the expand
 * icon opens the full detail.
 */
const MAX_VISIBLE = 5;

/** The day whose full list is open, or null. */
const dayIso = ref(null);
const dayOpen = computed({
    get: () => dayIso.value !== null,
    set: (open) => {
        if (!open) dayIso.value = null;
    },
});
// Read live from byDate so the list follows a reschedule made meanwhile.
const dayTasks = computed(() => (dayIso.value ? byDate.value[dayIso.value] ?? [] : []));
const dayDone = computed(() => dayTasks.value.filter((t) => t.status === "resolved" || t.status === "closed").length);

/*
 * The task detail opens as the legacy slider underneath this dialog's overlay,
 * so the dialog has to go once the task is open — otherwise it sits on top of
 * the detail it just opened.
 */
function openFromDay(task, event) {
    if (openTask(task, event)) dayIso.value = null;
}

/** Done reads as a tick, in progress as a filled dot, not started as an open circle. */
function statusGlyph(status) {
    if (status === "resolved" || status === "closed") return "mdi-check";
    if (status === "in_progress") return "mdi-circle-medium";
    return "mdi-circle-outline";
}

function isoOf(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function step(delta) {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + delta, 1);
}

function today() {
    cursor.value = startOfMonth(new Date());
}

/** "Fri, 9 Oct 2026" — the dialog names the exact day, not "Tomorrow". */
function longDate(iso) {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        weekday: "short",
        day: "numeric",
        month: "short",
        year: "numeric",
    });
}

/** Two-letter badge for the assignee; unassigned tasks get none. */
function initials(t) {
    if (!t.assigneeId) return "";
    return t.assignee
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0])
        .join("")
        .toUpperCase();
}

/** A click on a day's empty space opens Create Task with that day as the start date. */
function createOn(cell, event) {
    if (event.target.closest(".cal__task, .cal__expand")) return;
    if (typeof window.creatask !== "function") return;
    const d = new Date(`${cell.iso}T00:00:00`);
    const text = d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
    window.creatask("", "", "", "", text);
}

function shortName(t) {
    const name = (t.title || "").trim() || t.ref;
    return name.length > 60 ? `${name.slice(0, 59)}…` : name;
}

/*
 * Drag and drop reschedules. The rules are checked while hovering, so a day
 * shows whether it will take the task before it is let go; the server checks
 * them again on save.
 */
const dragging = ref(null);
const over = ref(null);
const dragToday = ref(null);

/** A short reason `task` can't go on `iso` (the cell hint), or null. */
function dropHint(task, iso) {
    if (iso < dragToday.value) return "Past date";
    if (task.due && iso > task.due) return "After due date";
    return null;
}

/** The full message for letting go over a day the task can't go on. */
function dropError(task, iso) {
    if (iso < dragToday.value) return "A task can't be scheduled on a past date.";
    if (task.due && iso > task.due) {
        return `This task is due on ${longDate(task.due)}; schedule it on or before its due date.`;
    }
    return null;
}

function onDragStart(task, event) {
    dragging.value = task;
    dragToday.value = isoOf(new Date());
    event.dataTransfer.effectAllowed = "move";
    // Firefox ignores a drag that carries no data.
    event.dataTransfer.setData("text/plain", task.id);
}

function onDragEnd() {
    dragging.value = null;
    over.value = null;
}

/*
 * Every day accepts the drop, valid or not, so letting go over a blocked day
 * says why instead of the task silently snapping back.
 */
function onDragOver(cell, event) {
    if (!dragging.value) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    over.value = cell.iso;
}

function onDragLeave(cell, event) {
    if (over.value === cell.iso && !event.currentTarget.contains(event.relatedTarget)) {
        over.value = null;
    }
}

function onDrop(cell) {
    const task = dragging.value;
    onDragEnd();
    if (!task || cell.iso === scheduledOn(task)) return;

    const error = dropError(task, cell.iso);
    if (error) {
        store.saveError = error;
        return;
    }
    // With no due date there is nothing to weigh the move against, so it saves
    // straight away.
    if (!task.due) {
        save(task, cell.iso);
        return;
    }
    ask(task, cell.iso);
}

async function save(task, iso) {
    const ok = await store.reschedule(task.id, iso);
    if (ok) store.notice = `${shortName(task)} scheduled for ${formatDue(iso)}`;
}

/*
 * A task with a due date changes only once the user confirms. For an assigned
 * task the dialog first shows what the assignee already has on the target day.
 */
const pending = ref(null);
const confirmOpen = computed({
    get: () => pending.value !== null,
    set: (open) => {
        if (!open && !pending.value?.saving) pending.value = null;
    },
});

async function ask(task, iso) {
    const entry = { task, iso, workload: null, loading: Boolean(task.assigneeId), error: null, saving: false };
    pending.value = entry;
    if (!task.assigneeId) return;

    let workload = null;
    let error = null;
    try {
        workload = await fetchWorkload(task, iso);
    } catch (e) {
        error = e?.message || "Could not check the assignee's workload.";
    }
    // Cancelled, or replaced by another drop, while this loaded.
    if (pending.value?.task !== task || pending.value?.iso !== iso) return;
    pending.value = { ...pending.value, workload, error, loading: false };
}

const workloadText = computed(() => {
    const w = pending.value?.workload;
    if (!w?.assigned) return null;
    if (!w.tasks) return "No other tasks assigned for this date.";
    const tasks = `${w.tasks} ${w.tasks === 1 ? "task" : "tasks"}`;
    const hours = `${w.hours} estimated ${w.hours === 1 ? "hour" : "hours"}`;
    return `${tasks} already assigned for this date, with ${hours} allocated.`;
});

async function confirmReschedule() {
    const entry = pending.value;
    if (!entry || entry.saving || entry.loading) return;
    pending.value = { ...entry, saving: true };
    await save(entry.task, entry.iso);
    pending.value = null;
}
</script>

<template>
    <div class="cal">
        <div class="cal__bar">
            <button type="button" class="cal__nav" aria-label="Previous month" @click="step(-1)">
                <v-icon icon="mdi-chevron-left" size="18" />
            </button>
            <h2 class="cal__month">{{ monthLabel }}</h2>
            <button type="button" class="cal__nav" aria-label="Next month" @click="step(1)">
                <v-icon icon="mdi-chevron-right" size="18" />
            </button>
            <button type="button" class="cal__today" @click="today">Today</button>
            <span class="cal__help tv-meta">Click a day to add a task; drag a task to another day to reschedule it.</span>
        </div>

        <div class="cal__grid" role="grid" :class="{ 'is-dragging': dragging }">
            <div v-for="w in WEEKDAYS" :key="w" class="cal__wd tv-label">{{ w }}</div>

            <div
                v-for="cell in cells"
                :key="cell.iso"
                class="cal__cell"
                :class="{
                    'is-out': !cell.inMonth,
                    'is-today': cell.isToday,
                    'is-blocked': dragging && dropHint(dragging, cell.iso),
                    'is-over': dragging && over === cell.iso,
                }"
                @dragover="onDragOver(cell, $event)"
                @dragleave="onDragLeave(cell, $event)"
                @drop.prevent="onDrop(cell)"
                @click="createOn(cell, $event)"
            >
                <div class="cal__head">
                    <span class="cal__date">{{ cell.day }}</span>
                    <button
                        v-if="cell.tasks.length > MAX_VISIBLE && !dragging"
                        type="button"
                        class="cal__expand"
                        :title="`Expand for full detail: all ${cell.tasks.length} tasks on ${longDate(cell.iso)}`"
                        :aria-label="`Expand ${longDate(cell.iso)}`"
                        @click="dayIso = cell.iso"
                    >
                        <v-icon icon="mdi-arrow-expand" size="14" />
                    </button>
                </div>
                <span v-if="dragging && over === cell.iso && dropHint(dragging, cell.iso)" class="cal__hint">
                    {{ dropHint(dragging, cell.iso) }}
                </span>
                <ul class="cal__tasks" :class="{ 'is-scrolling': cell.tasks.length > MAX_VISIBLE }">
                    <li
                        v-for="t in cell.tasks"
                        :key="t.id"
                        class="cal__task tv-rail"
                        :class="[`st-${t.status}`, { 'is-dragging': dragging?.id === t.id, 'is-saving': store.saving.has(t.id) }]"
                        :title="t.title"
                        draggable="true"
                        @dragstart="onDragStart(t, $event)"
                        @dragend="onDragEnd"
                        @click="openTask(t, $event)"
                    >
                        <span v-if="initials(t)" class="cal__avatar" :title="t.assignee">{{ initials(t) }}</span>
                        <span class="cal__task-txt">{{ t.ref }} {{ t.title }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <v-dialog v-model="dayOpen" max-width="520">
            <div v-if="dayIso" class="cal-day">
                <div class="cal-day__head">
                    <h2 class="cal-day__title tv-label">{{ longDate(dayIso) }}</h2>
                    <button type="button" class="cal-day__collapse" aria-label="Collapse" title="Collapse" @click="dayIso = null">
                        <v-icon icon="mdi-arrow-collapse" size="16" />
                    </button>
                </div>

                <div class="cal-day__body">
                    <div class="cal-day__progress">
                        <div class="cal-day__progress-row">
                            <span>Completed</span>
                            <strong>{{ dayDone }} / {{ dayTasks.length }}</strong>
                        </div>
                        <div class="cal-day__bar" role="progressbar" :aria-valuenow="dayDone" aria-valuemin="0" :aria-valuemax="dayTasks.length">
                            <span :style="{ inlineSize: `${dayTasks.length ? (dayDone / dayTasks.length) * 100 : 0}%` }" />
                        </div>
                    </div>

                    <ul class="cal-day__list">
                        <li
                            v-for="t in dayTasks"
                            :key="t.id"
                            class="cal-day__item"
                            :class="`st-${t.status}`"
                            :title="t.title"
                            @click="openFromDay(t, $event)"
                        >
                            <v-icon class="cal-day__glyph" :icon="statusGlyph(t.status)" size="14" />
                            <span class="cal-day__ref">{{ t.ref }}</span>
                            <span class="cal-day__name">
                                <span class="cal-day__text">{{ t.title }}</span>
                                <span class="cal-day__meta">{{ t.assignee }}<template v-if="t.due"> · Due {{ formatDue(t.due) }}</template></span>
                            </span>
                            <span class="cal-day__status">{{ t.statusLabel || statusMeta(t.status).label }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </v-dialog>

        <div v-if="unscheduled.length" class="cal__undated">
            <span class="tv-label">Not scheduled</span>
            <ul class="cal__undated-list">
                <li
                    v-for="t in unscheduled"
                    :key="t.id"
                    class="cal__task tv-rail"
                    :class="[`st-${t.status}`, { 'is-dragging': dragging?.id === t.id }]"
                    draggable="true"
                    @dragstart="onDragStart(t, $event)"
                    @dragend="onDragEnd"
                    @click="openTask(t, $event)"
                >
                    <span class="cal__task-txt">{{ t.ref }} {{ t.title }}</span>
                </li>
            </ul>
        </div>

        <v-dialog v-model="confirmOpen" max-width="440">
            <div v-if="pending" class="cal-dlg">
                <h2 class="cal-dlg__title">Reschedule “{{ shortName(pending.task) }}”?</h2>
                <p class="cal-dlg__body">
                    {{ pending.task.start ? longDate(pending.task.start) : "Not scheduled" }}
                    <v-icon icon="mdi-arrow-right" size="14" />
                    <strong>{{ longDate(pending.iso) }}</strong>
                </p>
                <p v-if="pending.task.due" class="cal-dlg__meta tv-meta">Due {{ longDate(pending.task.due) }}</p>

                <div v-if="pending.task.assigneeId" class="cal-dlg__load" aria-live="polite">
                    <span class="tv-label">Resource workload · {{ pending.task.assignee }}</span>
                    <p v-if="pending.loading" class="cal-dlg__load-txt tv-meta">Checking…</p>
                    <p v-else-if="pending.error" class="cal-dlg__load-txt cal-dlg__error">{{ pending.error }}</p>
                    <p v-else class="cal-dlg__load-txt">{{ workloadText }}</p>
                </div>
                <p v-else class="cal-dlg__meta tv-meta">Unassigned, so there is no workload to check.</p>

                <div class="cal-dlg__actions">
                    <button type="button" class="cal-btn" :disabled="pending.saving" @click="pending = null">Cancel</button>
                    <button
                        type="button"
                        class="cal-btn cal-btn--primary"
                        :disabled="pending.loading || pending.saving"
                        @click="confirmReschedule"
                    >
                        {{ pending.saving ? "Rescheduling…" : "Reschedule" }}
                    </button>
                </div>
            </div>
        </v-dialog>
    </div>
</template>

<style scoped>
.cal {
    padding: 16px 20px;
}

.cal__bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-block-end: 12px;
}

.cal__month {
    font-size: 16px;
    font-weight: 600;
    min-inline-size: 190px;
}

.cal__nav {
    display: grid;
    place-items: center;
    inline-size: 30px;
    block-size: 30px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: var(--tv-paper);
    cursor: pointer;
}

.cal__nav:hover {
    background: var(--tv-sub);
}

.cal__today {
    margin-inline-start: 4px;
    block-size: 30px;
    padding: 0 12px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: var(--tv-paper);
    font: inherit;
    font-size: var(--tv-size-meta);
    font-weight: 500;
    cursor: pointer;
}

.cal__grid {
    display: grid;
    /* minmax(0, …) so a long task title can't widen its column. */
    grid-template-columns: repeat(7, minmax(0, 1fr));
    border: 1px solid var(--tv-rule);
    border-radius: var(--tv-radius-lg);
    overflow: hidden;
}

.cal__wd {
    padding: 8px 10px;
    background: var(--tv-sub);
    border-block-end: 1px solid var(--tv-rule);
}

/*
 * Fixed height: 14px padding + 20px date + 4px gap + five 18px tasks with 3px
 * gaps (102px). The height doesn't depend on how many tasks the day has; a
 * longer list scrolls inside it.
 */
.cal__cell {
    display: flex;
    flex-direction: column;
    block-size: 142px;
    overflow: hidden;
    padding: 6px 6px 8px;
    border-inline-end: 1px solid var(--tv-rule);
    border-block-end: 1px solid var(--tv-rule);
    background: var(--tv-paper);
}

.cal__cell:nth-child(7n + 1) {
    /* first column has no left border already; nothing needed */
}

.cal__cell.is-out {
    background: var(--tv-sub);
    color: var(--tv-faint);
}

.cal__date {
    flex: none;
    line-height: 20px;
    font-size: var(--tv-size-meta);
    font-variant-numeric: tabular-nums;
    color: var(--tv-muted);
}

.cal__cell.is-today .cal__date {
    display: inline-grid;
    place-items: center;
    inline-size: 20px;
    block-size: 20px;
    border-radius: 50%;
    background: var(--tv-brand);
    color: #fff;
    font-weight: 600;
}

.cal__tasks {
    flex: none;
    list-style: none;
    margin: 4px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.cal__task {
    padding: 2px 6px 2px 8px;
    border-radius: 3px;
    background: var(--tv-sub);
    font-size: var(--tv-size-label);
    cursor: pointer;
    overflow: hidden;
}

.cal__task:hover {
    background: var(--tv-sub-2);
}

.cal__task {
    display: flex;
    align-items: center;
    gap: 5px;
}

.cal__avatar {
    flex: none;
    display: grid;
    place-items: center;
    inline-size: 14px;
    block-size: 14px;
    border-radius: 50%;
    background: var(--tv-brand-soft);
    color: var(--tv-brand);
    font-size: 8px;
    font-weight: 600;
    line-height: 1;
}

.cal__cell {
    cursor: cell;
}

.cal__task-txt {
    min-inline-size: 0;
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cal__undated {
    margin-block-start: 16px;
}

.cal__undated-list {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.cal__undated-list .cal__task {
    max-inline-size: 260px;
}

.cal__help {
    margin-inline-start: auto;
}

.cal__cell {
    position: relative;
    transition: background-color 80ms;
}

/* While dragging, days the task can't go to recede; the hovered valid day lights up. */
.cal__grid.is-dragging .cal__cell.is-blocked {
    background: repeating-linear-gradient(
        -45deg,
        var(--tv-sub),
        var(--tv-sub) 6px,
        var(--tv-sub-2) 6px,
        var(--tv-sub-2) 12px
    );
    color: var(--tv-faint);
}

.cal__grid.is-dragging .cal__cell.is-over:not(.is-blocked) {
    background: var(--tv-brand-soft);
    box-shadow: inset 0 0 0 2px var(--tv-brand-ring);
}

.cal__grid.is-dragging .cal__cell.is-over.is-blocked {
    box-shadow: inset 0 0 0 2px #e8b4b0;
}

.cal__hint {
    position: absolute;
    inset-block-start: 6px;
    inset-inline-end: 6px;
    font-size: var(--tv-size-label);
    font-weight: 500;
    color: #b3261e;
}

.cal__grid .cal__tasks .cal__task {
    flex: none;
    block-size: 18px;
    line-height: 14px;
}

/* Exactly five rows tall; the rest scroll. */
.cal__grid .cal__tasks {
    max-block-size: 102px;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: var(--tv-rule-strong) transparent;
}

/* Room for the scrollbar so it doesn't sit on top of the titles. */
.cal__grid .cal__tasks.is-scrolling {
    padding-inline-end: 2px;
}

.cal__head {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    block-size: 20px;
}

/* Same affordance as the expandable panels elsewhere: diagonal arrows, top right. */
.cal__expand {
    display: grid;
    place-items: center;
    inline-size: 20px;
    block-size: 20px;
    padding: 0;
    border: 0;
    border-radius: 3px;
    background: none;
    color: var(--tv-ink-2);
    cursor: pointer;
}

.cal__expand:hover,
.cal__expand:focus-visible {
    background: var(--tv-sub-2);
    color: var(--tv-ink);
}

.cal-day {
    background: var(--tv-paper);
    border-radius: var(--tv-radius-lg);
    overflow: hidden;
}

.cal-day__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-block-end: 1px solid var(--tv-rule);
}

.cal-day__title {
    margin: 0;
    font-size: var(--tv-size-meta);
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--tv-ink-2);
}

.cal-day__collapse {
    display: grid;
    place-items: center;
    inline-size: 28px;
    block-size: 28px;
    padding: 0;
    border: 0;
    border-radius: var(--tv-radius);
    background: none;
    color: var(--tv-ink-2);
    cursor: pointer;
}

.cal-day__collapse:hover,
.cal-day__collapse:focus-visible {
    background: var(--tv-sub);
    color: var(--tv-ink);
}

.cal-day__body {
    padding: 16px 18px 12px;
}

.cal-day__progress {
    padding-block-end: 14px;
    border-block-end: 1px solid var(--tv-rule);
}

.cal-day__progress-row {
    display: flex;
    justify-content: space-between;
    margin-block-end: 6px;
    font-size: var(--tv-size-meta);
    color: var(--tv-ink-2);
}

.cal-day__progress-row strong {
    color: var(--tv-ink);
    font-variant-numeric: tabular-nums;
}

.cal-day__bar {
    block-size: 6px;
    border-radius: 3px;
    background: var(--tv-sub-2);
    overflow: hidden;
}

.cal-day__bar span {
    display: block;
    block-size: 100%;
    border-radius: inherit;
    background: var(--tv-st-done);
    transition: inline-size 200ms;
}

.cal-day__list {
    list-style: none;
    margin: 0;
    padding: 6px 0 0;
    max-block-size: 55vh;
    overflow-y: auto;
}

.cal-day__item {
    display: grid;
    grid-template-columns: 16px auto minmax(0, 1fr) auto;
    align-items: center;
    column-gap: 10px;
    padding: 8px 6px;
    border-radius: var(--tv-radius);
    cursor: pointer;
}

.cal-day__item:hover {
    background: var(--tv-sub);
}

/*
 * Spelled out here: the dialog is teleported out of #taskViewsApp, so the
 * shared .st-* rail colours don't reach it.
 */
.cal-day__glyph {
    color: var(--tv-muted);
}

.cal-day__item.st-new .cal-day__glyph { color: var(--tv-st-new); }
.cal-day__item.st-in_progress .cal-day__glyph { color: var(--tv-st-progress); }
.cal-day__item.st-resolved .cal-day__glyph { color: var(--tv-st-resolved); }
.cal-day__item.st-closed .cal-day__glyph { color: var(--tv-st-done); }

.cal-day__ref {
    font-size: var(--tv-size-label);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--tv-ink-2);
}

.cal-day__name {
    display: flex;
    flex-direction: column;
    min-inline-size: 0;
}

.cal-day__text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--tv-ink);
}

.cal-day__meta {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--tv-size-label);
    color: var(--tv-muted);
}

.cal-day__status {
    font-size: var(--tv-size-label);
    color: var(--tv-muted);
    white-space: nowrap;
}

.cal__task.is-dragging {
    opacity: 0.4;
}

.cal__task.is-saving {
    opacity: 0.6;
}

.cal-dlg {
    padding: 20px;
    background: var(--tv-paper);
    border-radius: var(--tv-radius);
}

.cal-dlg__title {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 600;
    color: var(--tv-ink);
    overflow-wrap: anywhere;
}

.cal-dlg__body {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    color: var(--tv-ink-2);
}

.cal-dlg__meta {
    margin: 4px 0 0;
}

.cal-dlg__load {
    margin-block-start: 14px;
    padding: 10px 12px;
    border: 1px solid var(--tv-rule);
    border-radius: var(--tv-radius);
    background: var(--tv-sub);
}

.cal-dlg__load-txt {
    margin: 4px 0 0;
}

.cal-dlg__error {
    font-size: var(--tv-size-meta);
    color: #b3261e;
}

.cal-dlg__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-block-start: 18px;
}

.cal-btn {
    height: 32px;
    padding: 0 14px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: var(--tv-paper);
    font: inherit;
    font-size: var(--tv-size-meta);
    font-weight: 500;
    color: var(--tv-ink-2);
    cursor: pointer;
}

.cal-btn--primary {
    border-color: var(--tv-brand);
    background: var(--tv-brand);
    color: #fff;
}

.cal-btn:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
