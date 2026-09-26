<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { useTaskStore } from "@/store/useTaskStore";
import { useSavedViewDialog } from "@/composables/useSavedViewDialog";

/**
 * Saved views: named snapshots of this page's filters, sort, grouping and
 * layout. Each page keeps its own list, private to the user.
 *
 * The applied view stays applied while you keep working; once the screen no
 * longer matches it, the button carries a dot and the menu offers to save the
 * changes into the view or as a new one.
 */
const store = useTaskStore();

const MAX_NAME = 100;

const menuOpen = ref(false);

/** Name dialog, shared by "Save as new view" and "Rename". */
const nameDialog = ref(false);
const nameMode = ref("create");
const nameTarget = ref(null);
const nameValue = ref("");
const nameError = ref("");
const nameInput = ref(null);

const deleteTarget = ref(null);
const deleteOpen = computed({
    get: () => deleteTarget.value !== null,
    set: (open) => { if (!open) deleteTarget.value = null; },
});

const buttonLabel = computed(() => store.activeView?.name ?? "Saved views");

function apply(view) {
    menuOpen.value = false;
    store.applySavedView(view.id);
}

function openNameDialog(mode, view = null) {
    menuOpen.value = false;
    nameMode.value = mode;
    nameTarget.value = view;
    nameValue.value = view?.name ?? "";
    nameError.value = "";
    nameDialog.value = true;
    // The dialog mounts on the next tick; focus once it is there.
    nextTick(() => setTimeout(() => nameInput.value?.focus(), 60));
}

async function submitName() {
    const name = nameValue.value.trim();
    if (!name) {
        nameError.value = "Give the view a name.";
        return;
    }
    if (name.length > MAX_NAME) {
        nameError.value = `A view name can have ${MAX_NAME} characters at most.`;
        return;
    }
    const exceptId = nameMode.value === "rename" ? nameTarget.value?.id : null;
    const taken = store.savedViews.some(
        (v) => v.id !== exceptId && v.name.toLowerCase() === name.toLowerCase(),
    );
    if (taken) {
        nameError.value = `You already have a view called "${name}".`;
        return;
    }

    const ok = nameMode.value === "rename"
        ? await store.renameView(nameTarget.value.id, name)
        : await store.saveViewAs(name);

    if (ok) {
        nameDialog.value = false;
    } else {
        // Show the refusal where the user is looking, not only in the banner.
        nameError.value = store.saveError || "Could not save the view.";
        store.dismissSaveError();
    }
}

// "Save filter" in the Filter popup opens this same dialog.
const { saveRequests } = useSavedViewDialog();
watch(saveRequests, () => openNameDialog("create"));

function showAll() {
    menuOpen.value = false;
    store.showAllTasks();
}

function saveChanges() {
    menuOpen.value = false;
    store.updateActiveView();
}

function askDelete(view) {
    menuOpen.value = false;
    deleteTarget.value = view;
}

async function confirmDelete() {
    const view = deleteTarget.value;
    deleteTarget.value = null;
    if (view) await store.deleteView(view.id);
}
</script>

<template>
    <!-- The button only appears once there is something saved; the first view
         is saved from the Filter popup. The dialogs below stay mounted either
         way, because that "Save filter" opens them. -->
    <v-menu
        v-if="store.savedViews.length"
        v-model="menuOpen"
        :close-on-content-click="false"
        location="bottom end"
        offset="4"
    >
        <template #activator="{ props: menu }">
            <button
                v-bind="menu"
                type="button"
                class="tv-ghost sv-trigger"
                :class="{ 'is-on': store.activeView }"
                :title="store.viewModified ? 'Changed since this view was saved' : 'Saved views'"
            >
                <v-icon :icon="store.activeView ? 'mdi-bookmark' : 'mdi-bookmark-outline'" size="15" aria-hidden="true" />
                <span class="sv-trigger__name">{{ buttonLabel }}</span>
                <span v-if="store.viewModified" class="sv-dot" aria-label="modified" />
            </button>
        </template>

        <div class="tv-pop sv-pop">
            <div class="sv-head">Saved views</div>

            <!-- "No view" as a real choice: apply it to drop the current view,
                 or star it so the page opens unfiltered. It is the default
                 whenever no saved view is. -->
            <div class="sv-row" :class="{ 'is-active': !store.activeViewId }">
                <button type="button" class="sv-row__main" @click="showAll">
                    <v-icon :icon="store.activeViewId ? 'mdi-blank' : 'mdi-check'" size="13" aria-hidden="true" />
                    <span class="sv-row__name">All tasks (no filter)</span>
                    <span v-if="!store.defaultView" class="sv-tag">Default</span>
                </button>
                <button
                    type="button"
                    class="sv-icon"
                    :class="{ 'is-on': !store.defaultView }"
                    :disabled="store.viewBusy || !store.defaultView"
                    :title="store.defaultView ? 'Open this page with all tasks, no filter' : 'This page opens with all tasks'"
                    aria-label="Make All tasks the default"
                    @click="store.clearDefaultView()"
                >
                    <v-icon :icon="store.defaultView ? 'mdi-star-outline' : 'mdi-star'" size="14" />
                </button>
                <!-- Keeps the star lined up with the saved views' stars, which
                     have rename and delete beside them. -->
                <span class="sv-icon-gap" aria-hidden="true" />
            </div>

            <div
                v-for="v in store.savedViews"
                :key="v.id"
                class="sv-row"
                :class="{ 'is-active': v.id === store.activeViewId }"
            >
                <button type="button" class="sv-row__main" @click="apply(v)">
                    <v-icon :icon="v.id === store.activeViewId ? 'mdi-check' : 'mdi-blank'" size="13" aria-hidden="true" />
                    <span class="sv-row__name">{{ v.name }}</span>
                    <span v-if="v.isDefault" class="sv-tag">Default</span>
                </button>
                <button
                    type="button"
                    class="sv-icon"
                    :class="{ 'is-on': v.isDefault }"
                    :disabled="store.viewBusy"
                    :title="v.isDefault ? 'Stop opening this view by default' : 'Open this page with this view'"
                    :aria-label="v.isDefault ? `Remove ${v.name} as default` : `Make ${v.name} the default`"
                    @click="store.toggleDefaultView(v.id)"
                >
                    <v-icon :icon="v.isDefault ? 'mdi-star' : 'mdi-star-outline'" size="14" />
                </button>
                <button
                    type="button"
                    class="sv-icon"
                    title="Rename"
                    :aria-label="`Rename ${v.name}`"
                    @click="openNameDialog('rename', v)"
                >
                    <v-icon icon="mdi-pencil-outline" size="14" />
                </button>
                <button
                    type="button"
                    class="sv-icon sv-icon--danger"
                    title="Delete"
                    :aria-label="`Delete ${v.name}`"
                    @click="askDelete(v)"
                >
                    <v-icon icon="mdi-trash-can-outline" size="14" />
                </button>
            </div>

            <div class="sv-sep" />

            <button
                v-if="store.activeView && store.viewModified"
                type="button"
                class="tv-pop__row"
                :disabled="store.viewBusy"
                @click="saveChanges"
            >
                <v-icon icon="mdi-content-save-outline" size="14" aria-hidden="true" />
                <span class="sv-row__name">Save changes to “{{ store.activeView.name }}”</span>
            </button>
            <button type="button" class="tv-pop__row" @click="openNameDialog('create')">
                <v-icon icon="mdi-bookmark-plus-outline" size="14" aria-hidden="true" />
                Save as new view…
            </button>
        </div>
    </v-menu>

    <v-dialog v-model="nameDialog" max-width="400">
        <form class="sv-dlg" @submit.prevent="submitName">
            <h2 class="sv-dlg__title">{{ nameMode === "rename" ? "Rename view" : "Save view" }}</h2>
            <p v-if="nameMode === 'create'" class="sv-dlg__body tv-meta">
                Saves this page's current filters, sort and grouping. Only you can see it.
            </p>
            <label class="sv-dlg__label tv-meta">
                Name
                <input
                    ref="nameInput"
                    v-model="nameValue"
                    type="text"
                    class="sv-dlg__input"
                    :maxlength="MAX_NAME"
                    autocomplete="off"
                    @input="nameError = ''"
                />
            </label>
            <p v-if="nameError" class="sv-dlg__error" role="alert">{{ nameError }}</p>
            <div class="sv-dlg__actions">
                <button type="button" class="sv-btn" @click="nameDialog = false">Cancel</button>
                <button type="submit" class="sv-btn sv-btn--primary" :disabled="store.viewBusy || !nameValue.trim()">
                    {{ nameMode === "rename" ? "Rename" : "Save" }}
                </button>
            </div>
        </form>
    </v-dialog>

    <v-dialog v-model="deleteOpen" max-width="400">
        <div class="sv-dlg">
            <h2 class="sv-dlg__title">Delete “{{ deleteTarget?.name }}”?</h2>
            <p class="sv-dlg__body tv-meta">
                Only the saved view is removed. No tasks are changed.
            </p>
            <div class="sv-dlg__actions">
                <button type="button" class="sv-btn" @click="deleteOpen = false">Cancel</button>
                <button type="button" class="sv-btn sv-btn--danger" @click="confirmDelete">Delete</button>
            </div>
        </div>
    </v-dialog>
</template>

<style scoped>
/* Same look as the toolbar's other menus; those styles are scoped to
   TaskToolbar, so they are repeated here. */
.tv-ghost {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    block-size: 30px;
    padding: 0 10px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: transparent;
    color: var(--tv-ink-2);
    font-size: var(--tv-size-meta);
    font-weight: 500;
    cursor: pointer;
}

.tv-ghost:hover {
    background: var(--tv-sub);
}

.tv-ghost.is-on {
    border-color: var(--tv-brand);
    background: var(--tv-brand-soft);
    color: var(--tv-ink);
}

.sv-trigger__name {
    max-inline-size: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sv-dot {
    inline-size: 7px;
    block-size: 7px;
    flex: none;
    border-radius: 50%;
    background: var(--tv-brand);
}

.tv-pop {
    min-inline-size: 176px;
    padding: 4px;
    background: var(--tv-paper);
    border-radius: var(--tv-radius-lg);
    box-shadow: var(--tv-shadow-pop);
}

.sv-pop {
    inline-size: 300px;
    max-block-size: 420px;
    overflow-y: auto;
}

.tv-pop__row {
    display: flex;
    align-items: center;
    gap: 8px;
    inline-size: 100%;
    padding: 6px 8px;
    border: 0;
    border-radius: var(--tv-radius);
    background: transparent;
    font-size: var(--tv-size-body);
    color: var(--tv-ink);
    cursor: pointer;
    text-align: start;
}

.tv-pop__row:hover:not(:disabled) {
    background: var(--tv-sub);
}

.tv-pop__row:disabled {
    opacity: 0.5;
    cursor: default;
}

.sv-head {
    padding: 6px 8px 4px;
    font-size: var(--tv-size-label);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--tv-faint);
}

.sv-row {
    display: flex;
    align-items: center;
    gap: 2px;
    border-radius: var(--tv-radius);
}

.sv-row:hover,
.sv-row:focus-within {
    background: var(--tv-sub);
}

.sv-row__main {
    display: flex;
    flex: 1;
    align-items: center;
    gap: 8px;
    min-inline-size: 0;
    padding: 6px 8px;
    border: 0;
    background: transparent;
    font-size: var(--tv-size-body);
    color: var(--tv-ink);
    cursor: pointer;
    text-align: start;
}

.sv-row.is-active .sv-row__main {
    font-weight: 600;
}

.sv-row__name {
    min-inline-size: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sv-tag {
    flex: none;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--tv-brand-soft);
    font-size: var(--tv-size-label);
    font-weight: 500;
    color: var(--tv-ink-2);
}

.sv-icon {
    display: grid;
    place-items: center;
    flex: none;
    inline-size: 24px;
    block-size: 24px;
    border: 0;
    border-radius: var(--tv-radius);
    background: transparent;
    color: var(--tv-faint);
    cursor: pointer;
    opacity: 0;
}

/* Action icons appear on hover or keyboard focus, so the list reads as names
   first. The default star stays visible: it is state, not only an action. */
.sv-row:hover .sv-icon,
.sv-row:focus-within .sv-icon,
.sv-icon.is-on {
    opacity: 1;
}

.sv-icon.is-on {
    color: var(--tv-brand);
}

.sv-icon:hover:not(:disabled) {
    background: var(--tv-sub-2);
    color: var(--tv-ink);
}

.sv-icon--danger:hover:not(:disabled) {
    color: #b3261e;
}

.sv-icon:disabled {
    cursor: default;
}

.sv-icon-gap {
    flex: none;
    inline-size: 50px; /* two .sv-icon buttons plus the row gap */
}

.sv-sep {
    margin: 4px 0;
    border-block-start: 1px solid var(--tv-rule);
}

.sv-dlg {
    padding: 20px;
    background: var(--tv-paper);
    border-radius: var(--tv-radius);
}

.sv-dlg__title {
    margin: 0 0 6px;
    font-size: var(--tv-size-lead);
    font-weight: 600;
    color: var(--tv-ink);
    overflow-wrap: anywhere;
}

.sv-dlg__body {
    margin: 0 0 14px;
}

.sv-dlg__label {
    display: block;
    margin-block-end: 6px;
}

.sv-dlg__input {
    display: block;
    inline-size: 100%;
    margin-block-start: 6px;
    padding: 7px 10px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: var(--tv-paper);
    font: inherit;
    color: var(--tv-ink);
}

.sv-dlg__input:focus {
    outline: 2px solid var(--tv-brand);
    outline-offset: -2px;
}

.sv-dlg__error {
    margin: 0;
    font-size: var(--tv-size-meta);
    color: #b3261e;
}

.sv-dlg__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-block-start: 18px;
}

.sv-btn {
    height: 32px;
    padding: 0 14px;
    border: 1px solid var(--tv-rule-strong);
    border-radius: var(--tv-radius);
    background: var(--tv-paper);
    font-size: var(--tv-size-meta);
    font-weight: 500;
    color: var(--tv-ink-2);
    cursor: pointer;
}

.sv-btn--primary {
    border-color: var(--tv-brand);
    background: var(--tv-brand);
    color: #fff;
}

.sv-btn--danger {
    border-color: #b3261e;
    background: #b3261e;
    color: #fff;
}

.sv-btn:disabled {
    opacity: 0.5;
    cursor: default;
}
</style>
